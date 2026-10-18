<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ClaimReasonSeeder;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\GetSettingRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateSettingRequest;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * 클레임 설정 탭 저장·조회 (EcommerceSettingsController) 테스트 (S10 · D23/D26)
 *
 * 이 탭이 여는 것은 둘이다:
 *
 *  1. `claim.*` **스칼라 축의 저장 경로**. 지금까지 이 값들은 `defaults.json` 에만 있고
 *     검증 규칙도 화면도 없어, 운영자가 값을 바꿀 수단이 영구히 없었다. 소비 쪽은 정상이라
 *     "설정이 있는데 못 바꾼다" 는 사실이 어디에도 드러나지 않는다.
 *  2. `claim.exchange_reasons` / `claim.return_reasons` **왕복 배선**. 시드와 서비스는
 *     준비돼 있었지만 설정 응답·저장 어느 쪽에도 노출 경로가 없었다.
 *
 * 삭제 가드도 함께 본다 — 설정 화면의 삭제는 `syncReasons()` 를 타는데, 그 경로가 상세
 * 삭제(`deleteReason()`)보다 약하면 교환·반품에서 사용 중인 사유가 이 경로로만 지워진다.
 */
class EcommerceSettingsClaimTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    private User $adminUser;

    /**
     * 설정 읽기·수정 권한을 가진 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }

    /**
     * 클레임 정책 스칼라 축의 유효한 페이로드를 만듭니다.
     *
     * @param  array  $overrides  덮어쓸 축
     * @return array 저장 페이로드의 claim 섹션
     */
    private function claimPolicyPayload(array $overrides = []): array
    {
        // 모든 축의 값은 defaults.json 의 기본값과 **달라야** 한다. getAllSettings() 가
        // array_merge(defaults, saved) 라 누락 키는 기본값으로 되돌아오므로, 기본값과 같은
        // 값을 보내면 그 축의 검증 규칙이 통째로 빠져 validated() 가 버려도 재조회가 같은
        // 값을 돌려준다 — 아래 단언이 초록인 채 저장 경로 부재를 놓친다.
        return array_merge([
            'exchange_requestable_statuses' => ['delivered'],
            'return_requestable_statuses' => ['delivered'],
            'instant_cancel_statuses' => ['payment_complete', 'preparing'],
            'refund_request_statuses' => ['shipping_ready', 'shipping'],
            'allowed_charge_methods' => ['pay_on_request', 'deduct_from_refund'],
            'charge_payment_methods' => ['card'],
            'auto_approve_zero_charge' => true,
            'auto_approve_after_payment' => true,
            'auto_approve_refund_request' => true,
            'auto_approve_refund_statuses' => ['preparing', 'shipping'],
            'auto_approve_refund_payment_methods' => ['card', 'point'],
            'max_images' => 8,
            'image_max_size_kb' => 4096,
            'request_period_days' => 14,
            'complete_defaults' => [
                'create_refund_request' => false,
                'restore_stock' => false,
            ],
        ], $overrides);
    }

    /**
     * 사유 카탈로그 1행을 만듭니다.
     *
     * @param  string  $code  사유 코드
     * @param  array  $overrides  덮어쓸 필드
     * @return array 사유 행
     */
    private function reasonRow(string $code, array $overrides = []): array
    {
        return array_merge([
            'code' => $code,
            'name' => ['ko' => '사유 '.$code, 'en' => 'Reason '.$code],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides);
    }

    /**
     * 유저 카탈로그를 조회할 수 있는 구매자를 만듭니다.
     *
     * `user/claim-reasons` 는 비회원도 쓰지만 `permission:user,sirsoft-ecommerce.user-orders.cancel`
     * 게이트가 걸려 있어, 테스트 환경의 기본 역할에는 그 권한이 없다.
     *
     * @return User 권한을 가진 구매자
     */
    private function createShopperWithCancelPermission(): User
    {
        $user = $this->createUser();

        $role = $user->roles()->first();
        if ($role) {
            $permission = Permission::firstOrCreate(
                ['identifier' => 'sirsoft-ecommerce.user-orders.cancel'],
                ['name' => ['ko' => '주문 취소', 'en' => 'Cancel Order'], 'type' => 'user'],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return $user;
    }

    // ==================== 정책 스칼라 ====================

    /**
     * 모든 카테고리의 조회 결과가 전체 설정 조회와 일치합니다.
     *
     * @effects settings_all_categories_match_full_response
     *
     * @return void 반환값 없음
     */
    public function test_category_settings_match_full_settings_for_every_category(): void
    {
        $this->actingAs($this->adminUser);
        $all = $this->getJson($this->apiBase)->assertOk()->json('data');
        foreach (array_keys(app(EcommerceSettingsService::class)->getAllSettings()) as $category) {
            $part = $this->getJson($this->apiBase.'/'.$category)->assertOk()->json('data.settings');
            $this->assertSame($all[$category], $part, $category.' 카테고리의 조회 값이 전체 조회와 다릅니다.');
        }
    }

    /**
     * 카테고리 조회와 개별 설정 메서드도 사유의 실제 저장소인 DB를 사용합니다.
     *
     * @effects settings_single_catalog_writes_database_and_reads_same_catalog
     *
     * @return void 반환값 없음
     */
    public function test_single_catalog_setting_uses_database_and_category_reads_it(): void
    {
        $this->actingAs($this->adminUser);
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('claim.max_images', 7);

        $request = UpdateSettingRequest::create('/', 'PUT', [
            'key' => 'claim.exchange_reasons',
            'value' => [$this->reasonRow('audit_exchange')],
        ]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();
        $controller = app(EcommerceSettingsController::class);
        $this->assertSame(200, $controller->updateSetting($request)->getStatusCode());
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['code' => 'audit_exchange', 'type' => 'exchange']);
        $this->assertSame(7, $settings->getSetting('claim.max_images'));

        $category = $this->getJson($this->apiBase.'/claim')->assertOk()->json('data.settings');
        $this->assertContains('audit_exchange', array_column($category['exchange_reasons'], 'code'));
        $read = GetSettingRequest::create('/', 'GET', ['key' => 'claim.exchange_reasons']);
        $read->setContainer($this->app)->setRedirector($this->app['redirect']);
        $read->validateResolved();
        $value = $controller->getSetting($read)->getData(true)['data']['value'];
        $this->assertSame($category['exchange_reasons'], $value);
    }

    /**
     * 개별 저장이 숫자 문자열 정규화와 상태 허용값 검증을 우회하지 않습니다.
     *
     * @effects settings_single_value_normalizes_and_rejects_invalid_status
     *
     * @return void 반환값 없음
     */
    public function test_single_setting_normalizes_numeric_values_and_validates_statuses(): void
    {
        $request = UpdateSettingRequest::create('/', 'PUT', ['key' => 'order_settings.auto_cancel_days', 'value' => '19']);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();
        $this->assertSame(19, $request->validated('value'));

        $invalid = UpdateSettingRequest::create('/', 'PUT', ['key' => 'order_settings.cancellable_statuses', 'value' => ['cancelled']]);
        $invalid->setContainer($this->app)->setRedirector($this->app['redirect']);
        $this->expectException(ValidationException::class);
        $invalid->validateResolved();
    }

    /**
     * 정책 스칼라 12축이 저장되고 재조회에서 그대로 읽힌다 (`max_images`·`image_max_size_kb` 는 numeric_axes_are_stored_as_integers 가 소유).
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_policy_scalars_persist
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function claim_policy_scalars_are_saved_and_read_back(): void
    {
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $this->claimPolicyPayload()])
            ->assertOk();

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();

        $this->assertSame(['delivered'], $settings->getSetting('claim.exchange_requestable_statuses'));
        // 아래 3축은 규칙에서 빠져도 `validated()` 가 조용히 버려 응답 200 · 화면 "저장됨" · 값 불변이
        // 된다 — S10 이 없애려던 결함("운영자가 값을 바꿀 수단이 없다")과 같은 형태라 함께 고정한다.
        $this->assertSame(['delivered'], $settings->getSetting('claim.return_requestable_statuses'));
        $this->assertSame(['shipping_ready', 'shipping'], $settings->getSetting('claim.refund_request_statuses'));
        $this->assertTrue((bool) $settings->getSetting('claim.auto_approve_after_payment'));
        $this->assertSame(['payment_complete', 'preparing'], $settings->getSetting('claim.instant_cancel_statuses'));
        $this->assertSame(['pay_on_request', 'deduct_from_refund'], $settings->getSetting('claim.allowed_charge_methods'));
        $this->assertSame(['card'], $settings->getSetting('claim.charge_payment_methods'));
        $this->assertTrue((bool) $settings->getSetting('claim.auto_approve_zero_charge'));
        $this->assertTrue((bool) $settings->getSetting('claim.auto_approve_refund_request'));
        // D8 — 토글만으로는 "출고 전만 자동승인" 같은 운영을 표현할 수 없다. 대상 상태 목록이
        // 저장 경로를 갖지 못하면 그 축이 통째로 닫힌 채 응답만 200 이 된다.
        $this->assertSame(['preparing', 'shipping'], $settings->getSetting('claim.auto_approve_refund_statuses'));
        $this->assertSame(14, $settings->getSetting('claim.request_period_days'));
        $this->assertFalse((bool) $settings->getSetting('claim.complete_defaults.create_refund_request'));
        $this->assertFalse((bool) $settings->getSetting('claim.complete_defaults.restore_stock'));
    }

    /**
     * 숫자 축은 문자열이 아니라 정수로 영속된다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_numeric_axes_stored_as_integers
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function numeric_axes_are_stored_as_integers_not_strings(): void
    {
        // 화면의 number 입력은 문자열을 보낸다. 캐스트하지 않으면 문자열이 설정 파일에
        // 그대로 영속되어, 이후 strict 타입 경계에서 TypeError 로만 드러난다.
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => $this->claimPolicyPayload([
                    'max_images' => '9',
                    'image_max_size_kb' => '5120',
                    'request_period_days' => '30',
                ]),
            ])
            ->assertOk();

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();

        $this->assertSame(9, $settings->getSetting('claim.max_images'));
        $this->assertSame(5120, $settings->getSetting('claim.image_max_size_kb'));
        $this->assertSame(30, $settings->getSetting('claim.request_period_days'));
    }

    /**
     * 설정에서 만든 사유가 그 유형의 유저 카탈로그에 나타난다 (저장 → 설정 API → 유저 API 3단).
     *
     * §7 S10 DoD 의 "유저 카탈로그 반영 3단 영속" 축이다. 관리자 화면만 보면 저장은 성공으로
     * 보이므로, 유형별 sync 나 유저 조회의 유형 필터가 깨져도 그 사실이 어디에도 드러나지 않는다 —
     * 고객 신청서의 사유 드롭다운만 비거나 남의 유형 사유가 섞인다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_reaches_user_catalog_of_its_own_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saved_reason_reaches_the_user_catalog_of_its_own_type(): void
    {
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'exchange_reasons' => [$this->reasonRow('s10_ux_probe', ['name' => ['ko' => '교환 확인용', 'en' => 'Exchange Probe']])],
                ],
            ])
            ->assertOk();

        // 설정 API 왕복 — 저장된 카탈로그가 그 유형으로 되읽힌다
        $settingsCodes = collect(
            $this->actingAs($this->adminUser)->getJson($this->apiBase)->json('data.claim.exchange_reasons') ?? []
        )->pluck('code');
        $this->assertTrue($settingsCodes->contains('s10_ux_probe'));

        // 유저 카탈로그 — 자기 유형에는 있고
        $shopper = $this->createShopperWithCancelPermission();
        $exchange = $this->actingAs($shopper)->getJson('/api/modules/sirsoft-ecommerce/user/claim-reasons?type=exchange');
        $exchange->assertOk();
        $this->assertTrue(
            collect($exchange->json('data.data') ?? $exchange->json('data'))->pluck('code')->contains('s10_ux_probe'),
            '설정에서 만든 교환 사유가 교환 카탈로그에 나타나야 한다'
        );

        // 다른 유형에는 없다 — 유형 필터가 무너지면 고객이 남의 유형 사유를 고르게 된다
        $refund = $this->actingAs($shopper)->getJson('/api/modules/sirsoft-ecommerce/user/claim-reasons?type=refund');
        $refund->assertOk();
        $this->assertFalse(
            collect($refund->json('data.data') ?? $refund->json('data'))->pluck('code')->contains('s10_ux_probe'),
            '교환 사유가 환불 카탈로그에 새어 나오면 안 된다'
        );
    }

    /**
     * 사유 라벨은 열람자 언어를 따르고, 그 언어가 비어 있으면 ko 로 폴백한다.
     *
     * §7 S10 DoD 의 "언어 전환 시 사유 라벨 전환" 축이다. 형제 도메인(`ShippingPolicy`·
     * `ShippingType`)은 같은 축을 각각 테스트로 고정하고 있는데 클레임 사유만 비어 있었다.
     * 리소스가 `getLocalizedName()` 대신 `name['ko']` 를 돌려주도록 바뀌어도 아무 검사가 걸리지
     * 않아, en 운영자·구매자에게 한국어가 그대로 나간다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_label_follows_viewer_locale
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function reason_label_follows_viewer_locale_and_falls_back_to_ko(): void
    {
        // 사유명 필수는 "현재 로케일" 이다(2차 라운드 확정). ko 만 담은 행을 만들려면 저장
        // 요청의 로케일이 ko 여야 한다 — 이 전제 자체가 그 확정을 실물로 고정한다. 로케일은
        // 요청마다 SetLocale 미들웨어가 다시 정하므로 `app()->setLocale()` 이 아니라 헤더로 준다.
        $this->actingAs($this->adminUser)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'exchange_reasons' => [
                        $this->reasonRow('s10_both', ['name' => ['ko' => '양쪽 입력', 'en' => 'Both Filled']]),
                        $this->reasonRow('s10_ko_only', ['name' => ['ko' => '한국어만']]),
                    ],
                ],
            ])
            ->assertOk();

        $labelsFor = function (string $locale): array {
            $res = $this->actingAs($this->adminUser)
                ->withHeaders(['Accept-Language' => $locale])
                ->getJson($this->apiBase);
            $res->assertOk();

            return collect($res->json('data.claim.exchange_reasons') ?? [])
                ->pluck('localized_name', 'code')->all();
        };

        $ko = $labelsFor('ko');
        $this->assertSame('양쪽 입력', $ko['s10_both']);
        $this->assertSame('한국어만', $ko['s10_ko_only']);

        $en = $labelsFor('en');
        $this->assertSame('Both Filled', $en['s10_both'], 'en 열람자에게는 영문 라벨이 나가야 한다');
        $this->assertSame('한국어만', $en['s10_ko_only'], 'en 이 비어 있으면 ko 로 폴백해야 한다');
    }

    /**
     * 확장 결제수단도 클레임 추가결제 수단으로 저장된다.
     *
     * 이 축의 화이트리스트 단일 출처는 `PaymentMethodResolver::allValidIds()` 다 — 그 docblock
     * 이 스스로 소유를 선언하고 `CreateOrderRequest`·`OrderListRequest` 가 그것을 쓴다. 여기만
     * builtin Enum 으로 좁히면, 일반 주문 결제에서는 1급으로 받아들여지는 확장 수단(간편결제)이
     * 클레임 추가결제 수단으로는 저장 거부된다 — 운영자에게는 원인 불명의 422 로만 보인다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_charge_payment_methods_accept_extension_ids
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function extension_payment_methods_are_accepted_as_claim_charge_methods(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->saveSettings([
            'order_settings' => [
                'payment_methods' => [
                    ['id' => 'card', 'pg_provider' => null, 'sort_order' => 1, 'is_active' => true],
                    ['id' => 'toss_tosspay', 'pg_provider' => 'tosspayments', 'sort_order' => 2, 'is_active' => true],
                ],
            ],
        ]);
        $settings->clearCache();
        app(PaymentMethodResolver::class)->flushCache();
        $paymentMethodsBefore = $settings->getSetting('order_settings.payment_methods');

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => $this->claimPolicyPayload(['charge_payment_methods' => ['card', 'toss_tosspay']]),
            ])
            ->assertOk();

        $settings->clearCache();
        $this->assertSame(['card', 'toss_tosspay'], $settings->getSetting('claim.charge_payment_methods'));

        // 탭 간 격리 — claim 탭 저장이 order_settings 를 건드리지 않는다 (§8-1 T7 의 서버 축).
        // 같은 게터로 저장 전후를 견준다 (게터는 카탈로그 기본 항목을 병합해 돌려주므로 리터럴 대조는 안 된다).
        $this->assertSame(
            $paymentMethodsBefore,
            $settings->getSetting('order_settings.payment_methods'),
            'claim 탭 저장이 order_settings.payment_methods 를 바꿨다',
        );
    }

    /**
     * 환불 자동 승인 결제수단을 저장하면 **화면이 보여 준 수단 전부**가 결정 이력에 남는다. (P17)
     *
     * 이력이 없으면 운영자가 목록에서 뺀 즉시 환불 수단이 다음 조회의 자동 합류로 되살아난다 —
     * 화면에는 체크가 풀린 채 보이는데 실제 게이트는 열려 있는 상태가 되어, 운영자는 자기가
     * 뺀 수단이 왜 계속 자동 환불되는지 알 방법이 없다.
     *
     * @scenario gate_case=save_records_decision_history
     *
     * @effects auto_approve_save_records_operator_decision_history
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saving_the_auto_approve_list_records_the_operator_decision_history(): void
    {
        $settings = app(EcommerceSettingsService::class);

        // 저장 전에는 이력이 비어 있다 — 선언 수단은 자동 합류할 수 있는 상태다.
        $this->assertSame([], $settings->getSetting('claim.auto_approve_refund_payment_methods_decided') ?? []);

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => $this->claimPolicyPayload(['auto_approve_refund_payment_methods' => ['card']]),
            ])
            ->assertOk();

        $settings->clearCache();

        $this->assertSame(
            ['card'],
            $settings->getSetting('claim.auto_approve_refund_payment_methods'),
            '운영자가 고른 목록이 그대로 저장된다',
        );

        $decided = $settings->getSetting('claim.auto_approve_refund_payment_methods_decided');
        $this->assertIsArray($decided);
        foreach (['card', 'point', 'free'] as $declared) {
            $this->assertContains(
                $declared,
                $decided,
                "선언 수단 {$declared} 은 저장 시점에 결정 이력으로 남아야 한다 — 빼 둔 것도 결정이다",
            );
        }

        // 이력에 남았으므로, 목록 밖인 point·free 는 더 이상 자동 합류하지 않는다.
        app(PaymentMethodResolver::class)->flushCache();
        $this->assertTrue(app(PaymentMethodResolver::class)->autoApprovesRefund('card'));
        $this->assertFalse(app(PaymentMethodResolver::class)->autoApprovesRefund('point'));
        $this->assertFalse(app(PaymentMethodResolver::class)->autoApprovesRefund('free'));
    }

    /**
     * 카탈로그에 없는 임의 문자열은 확장 허용 뒤에도 여전히 거절된다.
     *
     * 화이트리스트를 넓히는 것이지 여는 것이 아니다 — `allValidIds()` 는 builtin + 카탈로그 키
     * 이므로 등록되지 않은 ID 는 그대로 422 여야 한다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_charge_payment_methods_accept_extension_ids
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function unregistered_payment_method_ids_are_still_rejected(): void
    {
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => $this->claimPolicyPayload(['charge_payment_methods' => ['never_registered_pay']]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim.charge_payment_methods.0');
    }

    /**
     * 페이로드의 모든 축이 defaults.json 의 기본값과 다르다. (위 전제의 강제)
     *
     * 이 전제는 지금까지 주석으로만 있었다. `defaults.json` 이 한 축이라도 페이로드 값으로
     * 바뀌면 그 축의 영속 단언이 조용히 항상-참이 되는데, 그 변화는 **다른 파일**에서 일어나
     * 이 테스트의 diff 에는 나타나지 않는다 — 그래서 전제를 단언으로 못박는다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_policy_scalars_persist
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function claim_policy_payload_differs_from_defaults_on_every_axis(): void
    {
        $defaultsPath = __DIR__.'/../../../../../config/settings/defaults.json';
        $this->assertFileExists($defaultsPath, 'defaults.json 을 찾지 못했다 — 경로가 바뀌었는지 확인할 것');

        $defaults = json_decode((string) file_get_contents($defaultsPath), true)['defaults']['claim'] ?? null;
        $this->assertIsArray($defaults, 'defaults.json 에 claim 기본값이 없다');

        foreach ($this->claimPolicyPayload() as $axis => $value) {
            $this->assertArrayHasKey($axis, $defaults, "defaults.json 에 `{$axis}` 가 없다 — 페이로드가 존재하지 않는 축을 보낸다");
            $this->assertNotSame(
                $defaults[$axis],
                $value,
                "`{$axis}` 의 페이로드 값이 기본값과 같다 — 그 축의 영속 단언이 항상 참이 되어 저장 경로 부재를 놓친다"
            );
        }
    }

    /**
     * 어휘·범위를 벗어난 값은 422 로 거절된다.
     *
     * @return array<string, array{array<string, mixed>, string}> 데이터 이름 => [기본 페이로드에 덮어쓸 정책 값, 422 오류가 나야 하는 필드 키]
     */
    public static function invalidPolicyProvider(): array
    {
        return [
            // 네 상태 축은 서로 **다른 목록**을 게이트로 쓴다(교환·반품은 같은 목록, 즉시취소와
            // 환불신청은 각자 다르다). 한 축만 검증하면 나머지 축의 `Rule::in` 이 통째로
            // 사라져도 red 가 없다 — 그러면 화면으로는 만들 수 없는 조합이 API 로 저장되고,
            // `branchForStatus()` 가 그 목록을 2차 축소 없이 옵션 상태와 비교하므로 이미
            // 종결된 옵션이 취소 분기로 들어간다.
            '교환 신청 가능 상태 어휘 밖' => [['exchange_requestable_statuses' => ['not_a_status']], 'claim.exchange_requestable_statuses.0'],
            '반품 신청 가능 상태 어휘 밖' => [['return_requestable_statuses' => ['not_a_status']], 'claim.return_requestable_statuses.0'],
            '즉시취소 가능 상태 어휘 밖' => [['instant_cancel_statuses' => ['cancelled']], 'claim.instant_cancel_statuses.0'],
            '자동승인 대상 상태 어휘 밖' => [['auto_approve_refund_statuses' => ['delivered']], 'claim.auto_approve_refund_statuses.0'],
            '환불신청 가능 상태 어휘 밖' => [['refund_request_statuses' => ['not_a_status']], 'claim.refund_request_statuses.0'],
            '청구 방식 어휘 밖' => [['allowed_charge_methods' => ['pay_someday']], 'claim.allowed_charge_methods.0'],
            // 환불 자동 승인 결제수단(P16·P17)도 `allValidIds()` 화이트리스트를 지난다. 이 축만
            // 규칙이 없으면 카탈로그 밖 ID 가 설정에 남고, 게이트는 그 ID 를 그대로 비교하므로
            // 아무 수단도 매칭되지 않는 목록이 조용히 저장된다.
            '환불 자동 승인 결제수단 어휘 밖' => [['auto_approve_refund_payment_methods' => ['never_registered_pay']], 'claim.auto_approve_refund_payment_methods.0'],
            '결제수단 어휘 밖' => [['charge_payment_methods' => ['crypto']], 'claim.charge_payment_methods.0'],
            '첨부 장수 상한 초과' => [['max_images' => 999], 'claim.max_images'],
            '첨부 장수 하한 미만' => [['max_images' => 0], 'claim.max_images'],
            '용량 상한 초과' => [['image_max_size_kb' => 999999], 'claim.image_max_size_kb'],
            '신청 기간 상한 초과' => [['request_period_days' => 400], 'claim.request_period_days'],

            // null 은 min:/max: 를 우회해 저장되는데, getAllSettings() 의
            // array_merge(defaults, saved) 에서 저장된 null 이 기본값을 이겨 소비처가
            // (int) null = 0 을 본다 — request_period_days 는 신청 기간 제한이 통째로 꺼지고
            // max_images·image_max_size_kb 는 max(1, 0) 으로 떨어져 첨부가 사실상 거부된다.
            // 규칙을 nullable 로 되돌리면 이 세 케이스가 red 가 된다.
            '첨부 장수 null' => [['max_images' => null], 'claim.max_images'],
            '용량 null' => [['image_max_size_kb' => null], 'claim.image_max_size_kb'],
            '신청 기간 null' => [['request_period_days' => null], 'claim.request_period_days'],

            // 배열 축의 null 도 같은 기전이다 — (array) null = [] 가 되어 그 유형의 신청이
            // 전건 거절되거나 추가결제 수단이 하나도 없게 된다. 빈 배열 []("전부 내림")은
            // 정당한 운영 의도라 여기 없다.
            '교환 신청 가능 상태 null' => [['exchange_requestable_statuses' => null], 'claim.exchange_requestable_statuses'],
            '반품 신청 가능 상태 null' => [['return_requestable_statuses' => null], 'claim.return_requestable_statuses'],
            '즉시취소 가능 상태 null' => [['instant_cancel_statuses' => null], 'claim.instant_cancel_statuses'],
            '환불신청 가능 상태 null' => [['refund_request_statuses' => null], 'claim.refund_request_statuses'],
            '청구 방식 null' => [['allowed_charge_methods' => null], 'claim.allowed_charge_methods'],
            '추가결제 수단 null' => [['charge_payment_methods' => null], 'claim.charge_payment_methods'],

            // 불리언·중첩 그룹도 같은 기전이다. 자동 승인 3축은 소비처가 `(bool) ..., false` 라
            // 꺼지는 방향이지만, 완료 처리 기본값은 소비처가 `?? true` 라 저장된 null 이 **켜짐**
            // 으로 뒤집힌다 — 운영자가 꺼 둔 재고 복구·환불신청서 접수가 모달에 켜진 채 열려
            // 그대로 실행된다. 규칙을 nullable 로 되돌리면 이 케이스들이 red 가 된다.
            '자동 승인 null' => [['auto_approve_zero_charge' => null], 'claim.auto_approve_zero_charge'],
            '완료 기본값 그룹 null' => [['complete_defaults' => null], 'claim.complete_defaults'],
            '재고 복구 기본값 null' => [
                ['complete_defaults' => ['create_refund_request' => true, 'restore_stock' => null]],
                'claim.complete_defaults.restore_stock',
            ],
        ];
    }

    /**
     * 어휘·범위를 벗어난 값은 422 로 거절된다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_invalid_vocabulary_rejected, auto_approve_refund_statuses_rejects_unknown_values
     *
     * @param  array<string, mixed>  $override  기본 페이로드에 덮어쓸 정책 값
     * @param  string  $errorKey  422 오류가 나야 하는 필드 키
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('invalidPolicyProvider')]
    public function invalid_claim_policy_values_are_rejected(array $override, string $errorKey): void
    {
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $this->claimPolicyPayload($override)])
            ->assertStatus(422)
            ->assertJsonValidationErrors($errorKey);
    }

    /**
     * 화면이 경계값을 리터럴 없이 세울 수 있도록 서버가 `_meta.limits` 를 싣는다.
     *
     * 화면은 min/max 를 `_meta?.limits?.claim_*` 에서 읽는다. 그 키가 응답에서 사라져도
     * 화면은 같은 값의 `??` 폴백으로 정상처럼 보이므로, 서버가 실제로 싣는지는 여기서만
     * 드러난다 — 매니페스트가 선언한 "경계값은 서버에서 온다" 가 그 전까지 아무것도
     * 보증하지 않았다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_policy_limits_come_from_server
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_response_carries_claim_limit_bounds(): void
    {
        $meta = $this->actingAs($this->adminUser)->getJson($this->apiBase)->assertOk()->json('data._meta.limits');

        $this->assertIsArray($meta, '_meta.limits 가 설정 응답에 없다');

        foreach ([
            'claim_max_images_min', 'claim_max_images_max',
            'claim_image_max_size_kb_min', 'claim_image_max_size_kb_max',
            'claim_request_period_days_min', 'claim_request_period_days_max',
        ] as $key) {
            $this->assertArrayHasKey($key, $meta, "_meta.limits.{$key} 가 없다 — 화면이 리터럴 폴백으로 조용히 넘어간다");
            $this->assertSame(config('sirsoft-ecommerce.limits.'.$key), $meta[$key], "_meta.limits.{$key} 가 config 값과 다르다");
        }
    }

    /**
     * `complete_defaults` 를 보내면 두 키를 모두 밝혀야 한다.
     *
     * 카테고리 병합이 얕아, 저장본에 맵이 있고 키만 없으면 defaults.json 으로 폴백하지 않고
     * 소비처 `AdminClaimService::completionDefaults()` 의 `?? true` 를 만난다 — 운영자가
     * 꺼 둔 재고 복구·환불신청서 접수가 모달에 켜진 채 열린다. null 을 막은 것과 같은
     * 피해가 키를 빼는 것만으로 재현되므로 부분 맵을 거절한다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_completion_defaults_reject_partial_map
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function partial_completion_defaults_map_is_rejected(): void
    {
        $payload = $this->claimPolicyPayload();
        $payload['complete_defaults'] = ['restore_stock' => false];

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $payload])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim.complete_defaults.create_refund_request');
    }

    // ==================== 사유 카탈로그 3종 ====================

    /**
     * 사유의 `is_active` 에 null 을 보내면 거절한다.
     *
     * 규칙이 `nullable` 이던 동안에는 명시적 null 이 통과했고, 동기화의 `?? true` 가 그 null 을
     * **true** 로 바꿨다 — 운영자가 내려 둔 사유가 저장 한 번에 구매자 선택지로 되살아난다.
     * 무너지는 방향이 "끈 것을 켜진 것으로" 라 형제 `claim.*` 불리언과 같은 강도로 맞췄다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_visibility_flags_reject_null
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function null_visibility_flags_on_a_reason_are_rejected(): void
    {
        $payload = $this->claimPolicyPayload();
        $payload['exchange_reasons'] = [$this->reasonRow('exchange_null_flag', ['is_active' => null])];

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $payload])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim.exchange_reasons.0.is_active');
    }

    /**
     * 새 사유 코드는 엄격한 형식을 지켜야 하고, **이미 저장된 코드는 그대로 통과**한다.
     *
     * 종전에는 설정 저장 경로와 상세 REST 경로가 서로 다른 정규식을 썼다. 느슨한 경로로
     * 만든 코드가 한 건이라도 접수되면 — 설정 탭은 저장할 때마다 카탈로그 전체를 되보내므로 —
     * 운영자가 손대지 않은 행 하나 때문에 클레임 탭이 영구히 422 가 되고, 사용 중인 사유는
     * 코드 변경까지 잠겨 제품 안에 복구 수단이 없었다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_code_format_unified_with_detail_path, settings_claim_reason_existing_code_is_exempt_from_format
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function reason_code_format_is_strict_for_new_codes_but_exempts_stored_ones(): void
    {
        // 규칙이 좁혀지기 전에 저장된 코드(이중 언더스코어)
        $legacy = ClaimReason::create([
            'type' => 'exchange',
            'code' => 'legacy__code',
            'name' => ['ko' => '기존 사유', 'en' => 'Legacy reason'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // ① 그 행을 그대로 되보내는 저장은 통과해야 한다
        $keep = $this->claimPolicyPayload();
        // 탭은 기존 행을 id 와 함께 되보낸다 — id 없이 보내면 신규 생성이라 unique 에서 죽는다(S0-D2 이후
        // 페이로드 부재는 삭제가 아니므로 옛 행이 남는다).
        $keep['exchange_reasons'] = [$this->reasonRow('legacy__code', ['id' => $legacy->id])];

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $keep])
            ->assertOk();

        // ② 새 코드는 같은 형식으로 거절된다
        $add = $this->claimPolicyPayload();
        $add['exchange_reasons'] = [$this->reasonRow('legacy__code', ['id' => $legacy->id]), $this->reasonRow('brand__new')];

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $add])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim.exchange_reasons.1.code');
    }

    /**
     * 설정 응답이 교환/반품/환불 카탈로그를 각각의 유형으로 싣는다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_response_carries_three_catalogs
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_response_carries_all_three_reason_catalogs(): void
    {
        foreach (['exchange', 'return', 'refund'] as $type) {
            ClaimReason::create([
                'type' => $type,
                'code' => $type.'_seeded',
                'name' => ['ko' => $type.' 사유', 'en' => $type.' reason'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]);
        }

        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase)->assertOk();

        foreach (['exchange', 'return', 'refund'] as $type) {
            $rows = $response->json("data.claim.{$type}_reasons");

            $this->assertIsArray($rows, "claim.{$type}_reasons 가 응답에 없다");
            $this->assertContains($type.'_seeded', array_column($rows, 'code'));

            // 유형이 섞이면 한 표가 다른 표의 사유를 그린다
            foreach ($rows as $row) {
                $this->assertSame($type, $row['type']);
            }
        }
    }

    /**
     * 저장이 각 카탈로그를 자기 유형으로 동기화하고 JSON 설정에는 남기지 않는다.
     *
     * @scenario reason_type=return
     *
     * @effects settings_claim_save_syncs_each_catalog_to_its_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saving_the_claim_tab_syncs_each_catalog_to_its_own_type(): void
    {
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => $this->claimPolicyPayload([
                    // 시드된 코드와 겹치지 않는 코드 — 페이로드 부재는 삭제가 아니므로(S0-D2) 시드 행은 남는다.
                    'exchange_reasons' => [$this->reasonRow('sync_probe_size')],
                    'return_reasons' => [$this->reasonRow('sync_probe_mind'), $this->reasonRow('sync_probe_defect', ['fault_type' => 'seller'])],
                    'refund_reasons' => [$this->reasonRow('sync_probe_mistake')],
                ]),
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_claim_reasons', ['type' => 'exchange', 'code' => 'sync_probe_size']);
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['type' => 'return', 'code' => 'sync_probe_mind']);
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['type' => 'return', 'code' => 'sync_probe_defect', 'fault_type' => 'seller']);
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['type' => 'refund', 'code' => 'sync_probe_mistake']);

        // 카탈로그는 DB 관리 대상이라 JSON 설정에는 남지 않는다 (남으면 두 출처가 갈린다)
        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $this->assertNull($settings->getSetting('claim.exchange_reasons'));
        $this->assertNull($settings->getSetting('claim.return_reasons'));
    }

    /**
     * 현재 로케일 사유명이 비면 저장을 거절한다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_name_requires_current_locale
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function reason_name_requires_the_current_locale(): void
    {
        // 현재 로케일 값이 비면 그 사유는 목록에서 원시 코드로 노출된다. 어느 로케일로
        // 렌더되든 걸리도록 실재하지 않는 로케일 키만 담아 보낸다 — 테스트 프로세스의
        // 로케일과 요청 처리 시점의 로케일이 갈려도 판정이 흔들리지 않는다.
        $name = ['zz' => 'not a supported locale'];

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => ['exchange_reasons' => [$this->reasonRow('no_locale_name', ['name' => $name])]],
            ])
            ->assertStatus(422)
            // 상태코드만 보면 이름 규칙이 사라지고 **다른** 규칙(코드 형식 등)이 422 를
            // 내도 통과한다 — 형제 4케이스와 같이 어느 필드가 걸렸는지까지 고정한다.
            ->assertJsonValidationErrors('claim.exchange_reasons.0.name');

        $this->assertDatabaseMissing('ecommerce_claim_reasons', ['code' => 'no_locale_name']);
    }

    /**
     * 같은 카탈로그 안의 코드 중복을 거절한다.
     *
     * @scenario reason_type=return
     *
     * @effects settings_claim_invalid_vocabulary_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function duplicate_reason_codes_are_rejected_per_catalog(): void
    {
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => ['return_reasons' => [$this->reasonRow('dup'), $this->reasonRow('dup')]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim.return_reasons.1.code');
    }

    /**
     * 같은 코드라도 **유형이 다르면** 허용한다. (`per_catalog` 의 반쪽)
     *
     * 중복 판정은 카탈로그 안에서만 이뤄진다 — 세 유형은 `etc`·`changed_mind` 처럼 겹치는
     * 코드를 실제로 쓰고 있고(기본 시드가 그렇다), 유니크 제약이 `(type, code)` 에서 `code`
     * 로 좁아지면 그 시드가 저장 불가가 된다. 거절 케이스만 있으면 그 회귀에 red 가 없다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_catalog_reads_and_writes_own_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_same_reason_code_is_allowed_across_different_types(): void
    {
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'exchange_reasons' => [$this->reasonRow('shared_code')],
                    'return_reasons' => [$this->reasonRow('shared_code')],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_claim_reasons', ['type' => 'exchange', 'code' => 'shared_code']);
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['type' => 'return', 'code' => 'shared_code']);
    }

    /**
     * 설정 화면의 삭제 경로가 그 사유의 유형에 맞는 사용량을 세고, 그 판정이 파일 쓰기보다 앞선다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_delete_guard_counts_own_type_usage, settings_claim_delete_guard_precedes_the_write
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_delete_path_counts_usage_for_the_reason_own_type(): void
    {
        // 설정 화면의 삭제는 syncReasons() 를 탄다 — 그 가드가 취소 한 표만 세면 교환에서
        // 사용 중인 사유가 이 경로로만 지워지고, 그 순간 해당 신청서 전건의 사유 라벨이
        // 카탈로그를 잃어 원시 코드로 노출된다.
        $order = $this->deliveredClaimOrder();

        $reason = ClaimReason::create([
            'type' => 'exchange',
            'code' => 'in_use_via_settings',
            'name' => ['ko' => '사용 중 교환 사유', 'en' => 'Exchange reason in use'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        OrderExchange::factory()->create(['order_id' => $order->id, 'reason_code' => $reason->code]);

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $before = $settings->getSetting('claim.max_images');

        // 휴지통으로 지목한 삭제 요청(S0-D2 이후 페이로드 부재는 삭제가 아니다). 스칼라 축도 함께
        // 바꿔 보낸다 — 거절이 쓰기보다 늦으면 사유는 남고 스칼라만 바뀐 반쪽 상태가 된다.
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => ['exchange_reasons' => [], 'exchange_reasons_deleted' => [$reason->id], 'max_images' => 17],
            ])
            ->assertStatus(400)
            // 상태코드만 보면 다른 사유의 400 도 통과한다 — 어느 가드가 걸렸는지 전용 키로 고정한다.
            ->assertJsonPath('message', trans_count('sirsoft-ecommerce::exceptions.claim_reason_in_use', ['count' => 1]));

        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $reason->id]);

        $settings->clearCache();
        $this->assertSame($before, $settings->getSetting('claim.max_images'), '거절된 저장이 스칼라 축을 남겼다');
    }

    /**
     * 아무 데서도 쓰지 않는 사유는 휴지통으로 지목한 설정 저장으로 지워진다.
     *
     * @scenario reason_type=return
     *
     * @effects settings_claim_delete_guard_counts_own_type_usage
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_delete_path_removes_a_reason_that_nothing_uses(): void
    {
        $reason = ClaimReason::create([
            'type' => 'return',
            'code' => 'unused_via_settings',
            'name' => ['ko' => '미사용 반품 사유', 'en' => 'Unused return reason'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => ['return_reasons' => [], 'return_reasons_deleted' => [$reason->id]]])
            ->assertOk();

        $this->assertDatabaseMissing('ecommerce_claim_reasons', ['id' => $reason->id]);
    }

    /**
     * 설정 화면의 삭제 가드가 교환 말고 반품 유형에서도 자기 표를 센다.
     *
     * `countUsage()` 는 유형별로 다른 표를 본다. 교환 하나만 검증하면 반품·환불 분기가
     * 끊겨도 초록이다 — 그 유형에서만 사용 중인 사유가 조용히 지워지고, 접수된 신청서
     * 전건이 사유 라벨을 잃는다.
     *
     * @scenario reason_type=return
     *
     * @effects settings_claim_delete_guard_counts_own_type_usage
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_delete_path_counts_usage_for_the_return_type(): void
    {
        $order = $this->deliveredClaimOrder();

        $reason = ClaimReason::create([
            'type' => 'return',
            'code' => 'in_use_return_via_settings',
            'name' => ['ko' => '사용 중 반품 사유', 'en' => 'Return reason in use'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        OrderReturn::factory()->create(['order_id' => $order->id, 'reason_code' => $reason->code]);

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => ['return_reasons' => [], 'return_reasons_deleted' => [$reason->id]]])
            ->assertStatus(400)
            // 상태코드만 보면 다른 사유의 400 도 통과한다 — 어느 가드가 걸렸는지 전용 키로 고정한다.
            ->assertJsonPath('message', trans_count('sirsoft-ecommerce::exceptions.claim_reason_in_use', ['count' => 1]));

        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $reason->id]);
    }

    /**
     * 반품 유형에서도 사용 중인 사유의 코드를 바꿀 수 없다.
     *
     * @scenario reason_type=return
     *
     * @effects settings_claim_code_change_blocked_while_in_use
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_save_cannot_change_the_code_of_a_return_reason_in_use(): void
    {
        $order = $this->deliveredClaimOrder();

        $reason = ClaimReason::create([
            'type' => 'return',
            'code' => 'code_locked_return',
            'name' => ['ko' => '사용 중 반품 사유', 'en' => 'Return reason in use'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        OrderReturn::factory()->create(['order_id' => $order->id, 'reason_code' => $reason->code]);

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'return_reasons' => [[
                        'id' => $reason->id,
                        'code' => 'code_locked_return_renamed',
                        'name' => ['ko' => '사용 중 반품 사유', 'en' => 'Return reason in use'],
                        'fault_type' => 'customer',
                        'is_user_selectable' => true,
                        'is_active' => true,
                    ]],
                ],
            ])
            ->assertStatus(400)
            // 상태코드만 보면 다른 사유의 400 도 통과한다 — 어느 가드가 걸렸는지 전용 키로 고정한다.
            ->assertJsonPath('message', trans_count('sirsoft-ecommerce::exceptions.claim_reason_code_locked_in_use', ['count' => 1]));

        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'id' => $reason->id,
            'code' => 'code_locked_return',
        ]);
    }

    /**
     * 환불 유형의 삭제 가드는 **환불 표와 취소 표를 합산**해 센다.
     *
     * `countUsage()` 의 세 갈래 중 환불만 두 표를 더한다 — 즉시취소는 `order_cancels` 의
     * `cancel_reason_type` 에, 환불신청은 `order_refunds` 의 `reason_code` 에 사유를 남기는데
     * 둘 다 같은 환불 카탈로그를 쓰기 때문이다. 그래서 한쪽 합산항만 끊겨도 그 경로로만
     * 쓰인 사유가 "아무도 안 쓴다" 로 판정되어 조용히 지워진다. 교환·반품 케이스는 이
     * 갈래를 전혀 지나가지 않으므로 여기서만 잡힌다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_delete_guard_counts_own_type_usage
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_delete_path_counts_refund_usage_from_the_cancel_table(): void
    {
        $order = $this->deliveredClaimOrder();

        $reason = ClaimReason::create([
            'type' => 'refund',
            'code' => 'in_use_refund_via_cancel',
            'name' => ['ko' => '사용 중 환불 사유', 'en' => 'Refund reason in use'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // 환불 표에는 한 건도 없고 **취소 표에만** 있는 상태 — 합산항 하나가 끊기면 여기서 red 다.
        OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CN'.uniqid(),
            'cancel_type' => 'full',
            'cancel_status' => 'completed',
            'cancel_reason_type' => $reason->code,
            'items_snapshot' => [],
            'shipping_snapshot' => [],
        ]);

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => ['refund_reasons' => [], 'refund_reasons_deleted' => [$reason->id]]])
            ->assertStatus(400)
            // 상태코드만 보면 다른 사유의 400 도 통과한다 — 어느 가드가 걸렸는지 전용 키로 고정한다.
            ->assertJsonPath('message', trans_count('sirsoft-ecommerce::exceptions.claim_reason_in_use', ['count' => 1]));

        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $reason->id]);
    }

    /**
     * 환불 유형의 삭제 가드는 환불신청 표에 쓰인 사유도 센다. (합산의 나머지 절반)
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_delete_guard_counts_own_type_usage
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_delete_path_counts_refund_usage_from_the_refund_table(): void
    {
        $order = $this->deliveredClaimOrder();

        $reason = ClaimReason::create([
            'type' => 'refund',
            'code' => 'in_use_refund_via_refund',
            'name' => ['ko' => '사용 중 환불 사유', 'en' => 'Refund reason in use'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // 환불 카탈로그의 사유는 **관리자 직접 환불**로만 기록된다(`OrderRefundRequestService`).
        // 출처를 비워 두면 어느 생산 경로도 만들지 않는 행이 되어 계약을 대표하지 못한다.
        OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_number' => 'RF'.uniqid(),
            'refund_status' => 'requested',
            'refund_method' => 'pg',
            'reason_code' => $reason->code,
        ]);

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => ['refund_reasons' => [], 'refund_reasons_deleted' => [$reason->id]]])
            ->assertStatus(400)
            // 상태코드만 보면 다른 사유의 400 도 통과한다 — 어느 가드가 걸렸는지 전용 키로 고정한다.
            ->assertJsonPath('message', trans_count('sirsoft-ecommerce::exceptions.claim_reason_in_use', ['count' => 1]));

        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $reason->id]);
    }

    /**
     * 반품 발 환불이 복사해 온 코드는 환불 카탈로그의 사용량이 아니다.
     *
     * 코드 유일성이 `(type, code)` 쌍이라 같은 문자열이 유형 간 공존한다(기본 시드만 해도
     * 환불∩반품 4종). 반품 발 환불행은 **반품 카탈로그**의 코드를 그대로 복사하므로, 그것을
     * 환불 사유의 사용량으로 세면 한 번도 쓰인 적 없는 환불 사유가 삭제·코드 변경 영구 400 이
     * 되고 안내의 "사용 횟수" 는 운영자가 환불·취소 어디에서도 찾을 수 없는 수치가 된다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_delete_guard_counts_own_type_usage
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_delete_path_ignores_refunds_sourced_from_another_catalog(): void
    {
        $order = $this->deliveredClaimOrder();

        $reason = ClaimReason::create([
            'type' => 'refund',
            'code' => 'shared_code_across_types',
            'name' => ['ko' => '유형 간 코드 겹침', 'en' => 'Shared code'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // 반품 카탈로그의 같은 코드가 복사돼 온 환불행 — 환불 사유가 쓰인 것이 아니다.
        OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::RETURN,
            'refund_number' => 'RF'.uniqid(),
            'refund_status' => 'requested',
            'refund_method' => 'pg',
            'reason_code' => $reason->code,
        ]);

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => ['refund_reasons' => [], 'refund_reasons_deleted' => [$reason->id]]])
            ->assertOk();

        $this->assertDatabaseMissing('ecommerce_claim_reasons', ['id' => $reason->id]);
    }

    /**
     * 환불 유형에서도 사용 중인 사유의 코드를 바꿀 수 없다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_code_change_blocked_while_in_use
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_save_cannot_change_the_code_of_a_refund_reason_in_use(): void
    {
        $order = $this->deliveredClaimOrder();

        $reason = ClaimReason::create([
            'type' => 'refund',
            'code' => 'code_locked_refund',
            'name' => ['ko' => '사용 중 환불 사유', 'en' => 'Refund reason in use'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CN'.uniqid(),
            'cancel_type' => 'full',
            'cancel_status' => 'completed',
            'cancel_reason_type' => $reason->code,
            'items_snapshot' => [],
            'shipping_snapshot' => [],
        ]);

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'refund_reasons' => [[
                        'id' => $reason->id,
                        'code' => 'code_locked_refund_renamed',
                        'name' => ['ko' => '사용 중 환불 사유', 'en' => 'Refund reason in use'],
                        'fault_type' => 'customer',
                        'is_user_selectable' => true,
                        'is_active' => true,
                    ]],
                ],
            ])
            ->assertStatus(400)
            // 상태코드만 보면 다른 사유의 400 도 통과한다 — 어느 가드가 걸렸는지 전용 키로 고정한다.
            ->assertJsonPath('message', trans_count('sirsoft-ecommerce::exceptions.claim_reason_code_locked_in_use', ['count' => 1]));

        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'id' => $reason->id,
            'code' => 'code_locked_refund',
        ]);
    }

    /**
     * 사용 중인 사유의 코드는 설정 저장으로도 바꿀 수 없다.
     *
     * 삭제만 막고 코드 변경을 열어 두면 결과가 같다 — 접수된 신청서는 사유를 `reason_code`
     * 문자열로만 기억하고(`ecommerce_order_exchanges.reason_code`), 라벨 해석은 카탈로그에서
     * 코드를 못 찾으면 원시 코드로 폴백한다(`ClaimResource::resolveReasonLabel()`). 즉 오타
     * 교정처럼 평범한 수정 한 번으로 그 사유로 접수된 전건이 `dmgd_prod` 같은 내부 문자열을
     * 화면에 드러낸다. 오류도 로그도 남지 않는다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_code_change_blocked_while_in_use, settings_claim_delete_guard_precedes_the_write
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_save_cannot_change_the_code_of_a_reason_in_use(): void
    {
        $order = $this->deliveredClaimOrder();

        $reason = ClaimReason::create([
            'type' => 'exchange',
            'code' => 'code_locked_exchange',
            'name' => ['ko' => '사용 중 교환 사유', 'en' => 'Exchange reason in use'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        OrderExchange::factory()->create(['order_id' => $order->id, 'reason_code' => $reason->code]);

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $before = $settings->getSetting('claim.max_images');

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'exchange_reasons' => [[
                        'id' => $reason->id,
                        'code' => 'code_locked_exchange_typo_fixed',
                        'name' => ['ko' => '사용 중 교환 사유', 'en' => 'Exchange reason in use'],
                        'fault_type' => 'customer',
                        'is_user_selectable' => true,
                        'is_active' => true,
                    ]],
                    'max_images' => 17,
                ],
            ])
            ->assertStatus(400)
            // 상태코드만 보면 다른 사유의 400 도 통과한다 — 어느 가드가 걸렸는지 전용 키로 고정한다.
            ->assertJsonPath('message', trans_count('sirsoft-ecommerce::exceptions.claim_reason_code_locked_in_use', ['count' => 1]));

        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'id' => $reason->id,
            'code' => 'code_locked_exchange',
        ]);

        // 가드는 파일 쓰기보다 앞선다 — 뒤에 있으면 사유는 그대로인데 스칼라만 바뀐다.
        $settings->clearCache();
        $this->assertSame($before, $settings->getSetting('claim.max_images'), '거절된 저장이 스칼라 축을 남겼다');
    }

    /**
     * 사용 중이어도 코드 말고 다른 항목은 고칠 수 있다.
     *
     * 가드가 넓으면 운영자가 사유명 오타조차 고치지 못한다 — 막는 것은 코드 하나다.
     *
     * 이 케이스는 **음성 대조군**(200)이다. 19차 이전에는 거절 effect
     * (`settings_claim_code_change_blocked_while_in_use`, 400 을 이름에 담는다)를 마킹해,
     * 그 커버리지 셀이 **200 단언만으로도 채워졌다** — 400 가드가 회귀해도 이 테스트가
     * effect 를 계속 마킹해 초록이 유지된다. 자기 계약을 말하는 effect 로 옮긴다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_remains_editable_except_code_and_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_save_can_still_edit_other_fields_of_a_reason_in_use(): void
    {
        $order = $this->deliveredClaimOrder();

        $reason = ClaimReason::create([
            'type' => 'exchange',
            'code' => 'editable_exchange',
            'name' => ['ko' => '오타 잇는 사유명', 'en' => 'Typoed name'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        OrderExchange::factory()->create(['order_id' => $order->id, 'reason_code' => $reason->code]);

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'exchange_reasons' => [[
                        'id' => $reason->id,
                        'code' => 'editable_exchange',
                        'name' => ['ko' => '오타 있는 사유명', 'en' => 'Fixed name'],
                        'fault_type' => 'seller',
                        'is_user_selectable' => false,
                        'is_active' => true,
                    ]],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'id' => $reason->id,
            'code' => 'editable_exchange',
            'fault_type' => 'seller',
        ]);
    }

    /**
     * 한 유형만 보낸 저장이 다른 유형의 카탈로그를 비우지 않는다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_save_of_one_catalog_keeps_others
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saving_one_catalog_does_not_touch_the_others(): void
    {
        // 한 유형만 보낸 저장이 다른 유형을 비우면, 운영자는 교환 사유를 고치다가 반품 사유를
        // 통째로 잃는다 — 응답은 200 이고 화면은 저장 성공으로 보인다.
        $kept = ClaimReason::create([
            'type' => 'refund',
            'code' => 'kept_refund',
            'name' => ['ko' => '유지되는 환불 사유', 'en' => 'Kept refund reason'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => ['exchange_reasons' => [$this->reasonRow('only_exchange')]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $kept->id]);
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['type' => 'exchange', 'code' => 'only_exchange']);
    }

    /**
     * 카탈로그만 담긴 저장은 정책 스칼라 축을 지우지 않는다.
     *
     * 컨트롤러가 카탈로그 3키를 떼어 내면 `claim` 섹션이 빈 배열로 남는데, 설정 저장은
     * 그 값으로 카테고리 파일을 통째로 덮어쓴다(File::put). 그대로 넘기면 운영자가 저장해
     * 둔 13축이 전부 지워지고 다음 조회에서 기본값으로 되살아난다 — 응답 200, 토스트는
     * "저장됨", 오류도 로그도 없다. 되돌아간 값이 기본값이라 `getSetting()` 은 여전히
     * "값이 있는" 응답을 주므로 카탈로그 단언만으로는 드러나지 않는다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_catalog_only_save_keeps_scalars
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saving_only_a_catalog_keeps_the_saved_policy_scalars(): void
    {
        // 기본값과 다른 값을 먼저 저장해 둔다 — 기본값과 같으면 지워져도 구분되지 않는다.
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $this->claimPolicyPayload()])
            ->assertOk();

        $saved = $this->claimPolicyPayload();

        // 카탈로그만 보낸다 — 스칼라 축은 페이로드에 없다.
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => ['exchange_reasons' => [$this->reasonRow('catalog_only')]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_claim_reasons', ['type' => 'exchange', 'code' => 'catalog_only']);

        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase)->assertOk();
        $claim = $response->json('data.claim');

        foreach (['max_images', 'image_max_size_kb', 'request_period_days', 'instant_cancel_statuses', 'allowed_charge_methods'] as $axis) {
            $this->assertSame(
                $saved[$axis],
                $claim[$axis],
                "카탈로그만 담긴 저장이 claim.{$axis} 를 기본값으로 되돌렸다",
            );
        }
    }

    /**
     * 수정 권한 없이는 클레임 설정을 저장할 수 없다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_requires_update_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function claim_settings_require_update_permission(): void
    {
        $readOnly = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);

        $this->actingAs($readOnly)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $this->claimPolicyPayload()])
            ->assertForbidden();
    }

    /**
     * 이미 저장된 사유는 현재 로케일 이름이 비어 있어도 탭 저장을 막지 않는다.
     *
     * 이 탭은 저장할 때마다 카탈로그 **전체**를 되보낸다. ko 만 채운 사유가 한 건이라도 있으면
     * en 로케일 운영자는 손대지 않은 그 행 때문에 클레임 탭 전체가 422 가 된다 — 코드 형식
     * (`ClaimReasonCodeFormat`) 이 기존 코드를 면제하는 것과 같은 이유로 이름도 기존 행을 면제한다
     * (10차 확정). 신규 행은 여전히 현재 로케일 이름이 필수다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_name_required_locale_is_current_locale
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function existing_reason_without_current_locale_name_does_not_block_the_tab(): void
    {
        $this->actingAs($this->adminUser)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => ['exchange_reasons' => [$this->reasonRow('s10_ko_only_legacy', ['name' => ['ko' => '한국어만']])]],
            ])
            ->assertOk();

        $legacyId = ClaimReason::query()->where('type', 'exchange')->where('code', 's10_ko_only_legacy')->value('id');
        $this->assertNotNull($legacyId);

        // en 운영자가 그 행을 손대지 않고 새 행만 더한다 — 기존 행은 통과, 신규 행은 사이트 기본 언어(ko) 필수
        // (필수 언어 = 사이트 기본 언어, 제품 결정 2026-10-08 — 보는 사람의 언어가 아니다).
        config(['app.site_locale' => 'ko']);

        $this->actingAs($this->adminUser)
            ->withHeaders(['Accept-Language' => 'en'])
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => ['exchange_reasons' => [
                    $this->reasonRow('s10_ko_only_legacy', ['id' => $legacyId, 'name' => ['ko' => '한국어만']]),
                    $this->reasonRow('s10_ko_only_new', ['name' => ['ko' => '신규인데 한국어만']]),
                ]],
            ])
            ->assertOk();

        $this->actingAs($this->adminUser)
            ->withHeaders(['Accept-Language' => 'en'])
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => ['exchange_reasons' => [
                    $this->reasonRow('s10_ko_only_legacy', ['id' => $legacyId, 'name' => ['ko' => '한국어만']]),
                    $this->reasonRow('s10_ko_only_new', ['name' => ['ko' => '신규인데 한국어만']]),
                    $this->reasonRow('s10_en_new', ['name' => ['en' => 'New in English']]),
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim.exchange_reasons.2.name.ko');
    }

    /**
     * 사유 행의 노출/활성 플래그는 **키 부재**도 거절한다.
     *
     * `sometimes` 는 null 만 막는다. 키를 빼면 소비처 `?? true` 가 운영자가 내려 둔 사유를
     * 켜진 채 되살린다 — 같은 파일의 `complete_defaults` 가 이 이유로 `required_with` 인 것과
     * 같은 강도로 맞춘다 (10차 확정).
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_visibility_flags_reject_null
     *
     * @param  string  $flag  사유 행에서 빼 보는 노출 여부 플래그 이름
     * @return void 반환값 없음
     */
    #[DataProvider('visibilityFlagProvider')]
    #[Test]
    public function missing_visibility_flag_on_a_reason_is_rejected(string $flag): void
    {
        $row = $this->reasonRow('exchange_missing_flag');
        unset($row[$flag]);

        $payload = $this->claimPolicyPayload();
        $payload['exchange_reasons'] = [$row];

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $payload])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim.exchange_reasons.0.'.$flag);
    }

    /**
     * 사유 행에서 빼 볼 노출 여부 플래그 목록입니다.
     *
     * @return array<string, array{0: string}> 데이터 이름 => [플래그 이름]
     */
    public static function visibilityFlagProvider(): array
    {
        return [
            'is_active' => ['is_active'],
            'is_user_selectable' => ['is_user_selectable'],
        ];
    }

    /**
     * 사유 행의 `is_user_selectable` 도 null 을 거절한다 (형제 `is_active` 와 같은 강도).
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_visibility_flags_reject_null
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function null_user_selectable_flag_on_a_reason_is_rejected(): void
    {
        $payload = $this->claimPolicyPayload();
        $payload['exchange_reasons'] = [$this->reasonRow('exchange_null_selectable', ['is_user_selectable' => null])];

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $payload])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim.exchange_reasons.0.is_user_selectable');
    }

    /**
     * 실재하지 않는 사유 id 로는 기존 행의 이름 필수 면제를 빌릴 수 없다(이름 없는 사유가 새로 생기지 않는다).
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_unknown_id_is_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function unknown_reason_id_cannot_borrow_the_existing_row_name_exemption(): void
    {
        // `id` 는 "기존 행" 판정의 유일한 근거다. 실재하지 않는 id 가 통과하면 이름의 현재 로케일
        // 필수를 면제받고, sync 는 그 id 를 모르니 create 로 흘러 **이름 없는 사유가 새로 생긴다**.
        $this->actingAs($this->adminUser)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => ['exchange_reasons' => [
                    $this->reasonRow('s11_ghost_id', ['id' => 999999, 'name' => ['en' => 'English only']]),
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim.exchange_reasons.0.id');

        $this->assertDatabaseMissing('ecommerce_claim_reasons', ['type' => 'exchange', 'code' => 's11_ghost_id']);
    }

    /**
     * 다른 유형(교환) 사유의 id 를 반품 페이로드에 실으면 거절된다.
     *
     * @scenario reason_type=return
     *
     * @effects settings_claim_reason_unknown_id_is_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function reason_id_of_another_type_is_rejected(): void
    {
        // 교환 사유의 id 를 반품 페이로드에 실으면 반품 sync 는 그 id 를 모르니 create 다 — 유형 스코프까지 건다.
        $exchange = ClaimReason::create($this->reasonRow('s11_cross_type', ['type' => 'exchange']));

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => ['return_reasons' => [
                    $this->reasonRow('s11_cross_type', ['id' => $exchange->id, 'name' => ['en' => 'English only']]),
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim.return_reasons.0.id');
    }

    /**
     * 기존 사유끼리 코드를 맞바꾸는 저장은 설정 파일을 쓰기 전에 전용 문구로 거절한다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_code_swap_is_rejected_before_the_write, settings_claim_delete_guard_precedes_the_write
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function swapping_codes_between_existing_reasons_is_rejected_before_the_file_write(): void
    {
        // 페이로드 안에서는 코드가 유일해도, 행 단위 update 는 DB unique(type, code) 에서 죽는다.
        // 그 시점엔 설정 파일이 이미 기록된 뒤라 스칼라만 반영된 반쪽 상태 + 500 이 남는다.
        $a = ClaimReason::create($this->reasonRow('s11_swap_a', ['type' => 'exchange']));
        $b = ClaimReason::create($this->reasonRow('s11_swap_b', ['type' => 'exchange', 'sort_order' => 1]));

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $before = $settings->getSetting('claim.max_images');

        $response = $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'exchange_reasons' => [
                        $this->reasonRow('s11_swap_b', ['id' => $a->id]),
                        $this->reasonRow('s11_swap_a', ['id' => $b->id, 'sort_order' => 1]),
                    ],
                    'max_images' => 19,
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['claim.exchange_reasons.0.code', 'claim.exchange_reasons.1.code']);

        // 전용 문구의 도달 — 같은 키에는 중복(`duplicate_code`)·형식(`ClaimReasonCodeFormat`) 규칙도
        // 붙는다. 키만 단언하면 맞바꾸기 판정을 통째로 지우고 그 자리에 아무 규칙이나 남겨도
        // 초록이다(형제 배송사 테스트가 이미 문구까지 본다).
        $this->assertSame(
            __('sirsoft-ecommerce::validation.custom.claim.exchange_reasons.code_swap'),
            $response->json('errors')['claim.exchange_reasons.0.code'][0] ?? null
        );

        $settings->clearCache();
        $this->assertSame($before, $settings->getSetting('claim.max_images'), '거절된 저장이 정책 스칼라를 파일에 남겼다');
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $a->id, 'code' => 's11_swap_a']);
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $b->id, 'code' => 's11_swap_b']);
    }

    /**
     * 같은 저장에서 지워지는 행의 코드를 물려받는 것은 허용한다.
     *
     * 이 케이스는 **음성 대조군**(200)이다. 19차 이전에는 거절 effect
     * (`settings_claim_reason_code_swap_is_rejected_before_the_write`, 422 를 이름에 담는다)를
     * 마킹해, 그 커버리지 셀이 **200 단언만으로도 채워졌다** — 맞바꾸기 거절이 회귀해도
     * 이 테스트가 effect 를 계속 마킹해 초록이 유지된다. 자기 계약을 말하는 effect 로 옮긴다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_code_takeover_from_a_deleted_row_is_allowed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function taking_over_the_code_of_a_reason_deleted_in_the_same_save_is_allowed(): void
    {
        // 맞바꾸기 거절이 "지워지는 행의 코드 물려받기" 까지 막으면 안 된다 — 삭제가 update 보다 먼저다.
        $a = ClaimReason::create($this->reasonRow('s11_keep', ['type' => 'exchange']));
        $gone = ClaimReason::create($this->reasonRow('s11_gone', ['type' => 'exchange', 'sort_order' => 1]));

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'exchange_reasons' => [$this->reasonRow('s11_gone', ['id' => $a->id])],
                    'exchange_reasons_deleted' => [$gone->id],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $a->id, 'code' => 's11_gone']);
        $this->assertSame(1, ClaimReason::query()->where('type', 'exchange')->where('code', 's11_gone')->count());
    }

    /**
     * 신규 행이 "이 저장에서 코드가 바뀌는 기존 행" 의 현재 코드를 가져가는 조합도 맞바꾸기다.
     *
     * 기존↔기존만 보면 이 조합은 페이로드 안 유일성도, 기존 행만 모은 대조도 통과한다. 그런데
     * `syncReasons()` 는 페이로드 순서대로 create/update 하므로 신규 행이 앞에 오면 아직 그 코드를
     * 쥔 기존 행과 unique 에서 죽는다 — 파일은 이미 기록된 뒤다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_code_swap_is_rejected_before_the_write
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function new_reason_taking_the_current_code_of_a_reason_being_renamed_is_rejected(): void
    {
        $a = ClaimReason::create($this->reasonRow('s12_held', ['type' => 'exchange']));

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $before = $settings->getSetting('claim.max_images');

        $response = $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'exchange_reasons' => [
                        $this->reasonRow('s12_held'),
                        $this->reasonRow('s12_moved', ['id' => $a->id, 'sort_order' => 1]),
                    ],
                    'max_images' => 19,
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('claim.exchange_reasons.0.code');

        // 전용 문구의 도달 — 같은 키에는 중복(`duplicate_code`)·형식(`ClaimReasonCodeFormat`) 규칙도
        // 붙는다. 키만 단언하면 맞바꾸기 판정을 통째로 지우고 그 자리에 아무 규칙이나 남겨도
        // 초록이다(형제 배송사 테스트가 이미 문구까지 본다).
        $this->assertSame(
            __('sirsoft-ecommerce::validation.custom.claim.exchange_reasons.code_swap'),
            $response->json('errors')['claim.exchange_reasons.0.code'][0] ?? null
        );

        $settings->clearCache();
        $this->assertSame($before, $settings->getSetting('claim.max_images'), '거절된 저장이 정책 스칼라를 파일에 남겼다');
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $a->id, 'code' => 's12_held']);
        $this->assertDatabaseMissing('ecommerce_claim_reasons', ['code' => 's12_moved']);
    }

    /**
     * 맞바꾸기 대조는 대소문자를 가리지 않는다.
     *
     * 형식 규칙이 이미 저장된 코드를 면제하므로 DB 에 대문자 코드가 남아 있을 수 있다. MySQL CI
     * 콜레이션은 `whereIn(['s12_upper'])` 로 `S12_Upper` 행을 돌려주는데 엄격 비교가 그것을 놓치면
     * 충돌을 감지하지 못한 채 unique 위반이 CI 로 터진다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_code_swap_is_rejected_before_the_write
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function code_swap_detection_ignores_letter_case_of_stored_codes(): void
    {
        $upper = ClaimReason::create($this->reasonRow('S12_Upper', ['type' => 'exchange']));
        $other = ClaimReason::create($this->reasonRow('s12_other', ['type' => 'exchange', 'sort_order' => 1]));

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $before = $settings->getSetting('claim.max_images');

        $response = $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'exchange_reasons' => [
                        $this->reasonRow('s12_renamed', ['id' => $upper->id]),
                        $this->reasonRow('s12_upper', ['id' => $other->id, 'sort_order' => 1]),
                    ],
                    'max_images' => 19,
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('claim.exchange_reasons.1.code');

        $this->assertSame(
            __('sirsoft-ecommerce::validation.custom.claim.exchange_reasons.code_swap'),
            $response->json('errors')['claim.exchange_reasons.1.code'][0] ?? null
        );

        // 형제 두 케이스와 같은 강도 — 거절이 "쓰기 전" 이라는 것은 파일과 두 행이 모두
        // 그대로일 때에만 증명된다(13차: 이 케이스만 상대 행 하나만 보고 있었다).
        $settings->clearCache();
        $this->assertSame($before, $settings->getSetting('claim.max_images'), '거절된 저장이 정책 스칼라를 파일에 남겼다');
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $upper->id, 'code' => 'S12_Upper']);
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $other->id, 'code' => 's12_other']);
    }

    /**
     * 빈 `complete_defaults` 맵은 부분 맵과 같은 피해를 낸다.
     *
     * `required_with` 는 상대가 비어 있으면 발동하지 않고, 빈 맵은 `validated()` 에서 부모 키째
     * 탈락해 저장본에서 사라진다 — 얕은 병합이 defaults 를 되살려 소비처 `?? true` 를 만난다.
     * 하위 키 하나를 뺀 케이스(`partial_completion_defaults_map_is_rejected`)와는 다른 코드 경로다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_completion_defaults_reject_partial_map
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function empty_completion_defaults_map_is_rejected(): void
    {
        $payload = $this->claimPolicyPayload();
        $payload['complete_defaults'] = [];

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $payload])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim.complete_defaults');
    }

    /**
     * 시더의 stale 정리가 설정 경로와 **같은 강도**인지 본다 (18차).
     *
     * 같은 표에 삭제 경로가 둘이다 — 설정 저장(`syncReasons`)과 모듈 재설치 시 도는 시더의
     * stale 정리다. 앞엣것은 사용 중이면 400 으로 막는데 뒤엣것은 사용량을 보지 않아,
     * 재설치 한 번이 운영자가 만든 사유를 전건 지웠다. 지워지는 순간 접수된 신청서의
     * `reason_code` 는 카탈로그를 잃어 화면에 원시 코드로 노출된다 — 설정 경로가 400 으로
     * 막으려던 바로 그 상태이고, 그 경로만 보는 테스트는 전부 초록이었다.
     *
     * 쓰이지 않는 사유까지 보존하면 stale 정리 자체가 무의미해지므로, **사용 중인 것만**
     * 살아남고 미사용 잔여는 계속 걷히는지 두 축을 함께 단언한다.
     *
     * @effects settings_claim_seeder_preserves_used_and_removes_unused_reasons
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_seeder_stale_cleanup_keeps_reasons_that_claims_still_reference(): void
    {
        $order = $this->deliveredClaimOrder();

        $inUse = ClaimReason::create([
            'type' => 'exchange',
            'code' => 'operator_made_in_use',
            'name' => ['ko' => '운영자가 만든 사용 중 사유', 'en' => 'Operator reason in use'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $unused = ClaimReason::create([
            'type' => 'exchange',
            'code' => 'operator_made_unused',
            'name' => ['ko' => '운영자가 만든 미사용 사유', 'en' => 'Operator reason unused'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        OrderExchange::factory()->create(['order_id' => $order->id, 'reason_code' => $inUse->code]);

        $this->seed(ClaimReasonSeeder::class);

        $this->assertNotNull(
            ClaimReason::where('type', 'exchange')->where('code', $inUse->code)->first(),
            '접수된 신청서가 참조하는 사유를 시더가 지웠다 — 그 신청서는 사유 이름을 잃는다'
        );

        $this->assertNull(
            ClaimReason::where('type', 'exchange')->where('code', $unused->code)->first(),
            '쓰이지 않는 잔여까지 보존하면 stale 정리가 무의미해진다'
        );
    }

    /**
     * 자동 전이 대상 상태에 **미결제 2종**을 지정할 수 있다. (#599 후속 D10)
     *
     * 이 축은 `order_settings.cancellable_statuses` 의 별칭이다. `Order::isCancellable()` 은
     * 미결제를 목록과 무관하게 **항상** 허용하므로 주문 단위 취소 판정은 달라지지 않는다 —
     * 달라지는 것은 **상품 단위 신청 자격**(`branchForStatus`)이고, 그것이 D10 이 여는 축이다.
     * 허용 어휘에서 빠져 있으면 화면에서 고를 수 없고 API 로도 422 가 된다.
     *
     * @scenario reason_type=refund
     *
     * @effects instant_cancel_statuses_accepts_pending_states
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function claim_auto_advance_status_list_accepts_pending_payment_states(): void
    {
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $this->claimPolicyPayload([
                'instant_cancel_statuses' => ['pending_order', 'pending_payment', 'payment_complete'],
            ])])
            ->assertOk();

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();

        $this->assertSame(
            ['pending_order', 'pending_payment', 'payment_complete'],
            $settings->getSetting('claim.instant_cancel_statuses'),
        );
    }

    /**
     * 기본값에 미결제 2종이 들어 있다. (#599 후속 D10)
     *
     * @scenario reason_type=refund
     *
     * @effects instant_cancel_statuses_default_includes_pending_states
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function claim_default_auto_advance_status_list_includes_pending_payment_states(): void
    {
        $defaults = app(EcommerceSettingsService::class)->getSetting('claim.instant_cancel_statuses');

        $this->assertContains('pending_order', $defaults);
        $this->assertContains('pending_payment', $defaults);
        $this->assertContains('payment_complete', $defaults);
    }
}
