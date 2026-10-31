<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateSettingRequest;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsAdminService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 환경설정 저장 조립·서버 전용 이력·미지 키 결함 수정 검증 (#599 validate P3 HTTP #7)
 *
 * 검증 축:
 * - 단건 저장(updateSetting)도 환불 자동 승인 결제수단의 결정 이력(P17)을 남긴다.
 * - 결정 이력 키는 서버만 쓴다 — 전체 저장·단건 저장 모두 클라이언트 값이 422 다.
 * - 조회 응답은 결정 이력을 내려주지 않아, 화면이 claim 섹션을 되보내도 422 가 나지 않는다.
 * - 저장 규칙이 다스리지 않는 단건 키는 원시값으로 저장되지 않고 422 다.
 * - 저장 조립(카탈로그 분리·빈 섹션 가드·저장 카테고리 산출)이 서비스에 있다.
 *
 * 격리: ModuleTestCase 의 트랜잭션 격리(마이그레이션 완료 뒤 테스트마다 롤백)와
 * 설정 디렉터리 초기화를 그대로 쓴다 — 형제 ValidateP2* 와 같다.
 */
class ValidateP3SettingsTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    private User $adminUser;

    /**
     * 설정 조회·수정 권한을 가진 관리자를 준비합니다.
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
     * 단건 저장 요청을 검증까지 마친 상태로 만듭니다.
     *
     * 단건 저장(`updateSetting`)은 HTTP 라우트가 없어 컨트롤러를 직접 호출한다
     * (형제 EcommerceSettingsSeoCacheInvalidationTest 와 같은 방식).
     *
     * @param  string  $key  점 표기 설정 키
     * @param  mixed  $value  저장할 값
     * @return UpdateSettingRequest 검증을 통과한 요청
     *
     * @throws ValidationException 검증에 실패한 경우
     */
    private function resolvedSingleKeyRequest(string $key, mixed $value): UpdateSettingRequest
    {
        $request = UpdateSettingRequest::create('/', 'PUT', ['key' => $key, 'value' => $value]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request;
    }

    /**
     * 단건 저장으로 환불 자동 승인 결제수단을 고쳐도 운영자 결정 이력이 남는다.
     *
     * 이 경로만 이력을 건너뛰면 운영자가 뺀 즉시 환불 수단(point·free)이 다음 조회의 자동
     * 합류로 되살아난다. 저장 훅도 종전처럼 컨트롤러가 claim 카테고리로 발화해야 한다.
     * 수정 전 실패 이유: 단건 경로가 setSetting 만 불러 `_decided` 가 빈 배열로 남는다.
     *
     * @scenario save_path=single_key, target_key=auto_approve_refund_payment_methods
     *
     * @effects settings_single_key_refund_auto_approve_records_decision_history
     *
     * @return void 반환값 없음
     */
    public function test_single_key_refund_auto_approve_update_records_decision_history(): void
    {
        $this->actingAs($this->adminUser);
        $settings = app(EcommerceSettingsService::class);
        $this->assertSame([], $settings->getSetting('claim.auto_approve_refund_payment_methods_decided') ?? []);

        $savedCategories = null;
        HookManager::addAction('sirsoft-ecommerce.settings.after_save', function ($categories) use (&$savedCategories) {
            $savedCategories = $categories;
        });

        $request = $this->resolvedSingleKeyRequest('claim.auto_approve_refund_payment_methods', ['card']);
        $this->assertSame(200, app(EcommerceSettingsController::class)->updateSetting($request)->getStatusCode());

        $settings->clearCache();
        $this->assertSame(['card'], $settings->getSetting('claim.auto_approve_refund_payment_methods'));

        $decided = $settings->getSetting('claim.auto_approve_refund_payment_methods_decided');
        $this->assertIsArray($decided);
        foreach (['card', 'point', 'free'] as $declared) {
            $this->assertContains($declared, $decided, "선언 수단 {$declared} 이 단건 저장의 결정 이력에 남지 않았다");
        }

        $this->assertSame(['claim'], $savedCategories, '단건 저장 훅이 claim 카테고리로 발화되지 않았다');
    }

    /**
     * 전체 저장에 결정 이력 키를 실어 보내면 422 다.
     *
     * 결정 이력은 서버가 저장 직전에 채우는 값이다. 클라이언트가 심을 수 있으면 운영자가 한 번도
     * 결정하지 않은 수단이 "결정됨" 이 되어 플러그인 선언 수단의 자동 합류가 막힌다.
     * 수정 전 실패 이유: 규칙이 `sometimes|array` + 카탈로그 ID 라 200 으로 저장된다.
     *
     * @scenario save_path=bulk, payload=decided_key_from_client
     *
     * @effects settings_decided_history_is_server_only
     *
     * @return void 반환값 없음
     */
    public function test_bulk_save_with_decided_history_from_client_is_rejected(): void
    {
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'max_images' => 7,
                    'auto_approve_refund_payment_methods_decided' => ['card'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['claim.auto_approve_refund_payment_methods_decided']);
    }

    /**
     * 단건 저장으로 결정 이력 키를 고치려 하면 값이 빈 배열이어도 422 다.
     *
     * `prohibited` 는 빈 배열을 통과시키므로 키 자체를 막지 않으면 `[]` 한 번으로 이력이 지워진다.
     * 수정 전 실패 이유: 키가 저장 규칙을 통과해 이력이 빈 배열로 덮인다.
     *
     * @scenario save_path=single_key, payload=decided_key_from_client
     *
     * @effects settings_decided_history_is_server_only
     *
     * @return void 반환값 없음
     */
    public function test_single_key_update_of_decided_history_is_rejected(): void
    {
        $this->actingAs($this->adminUser);
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('claim.auto_approve_refund_payment_methods_decided', ['card', 'point']);

        try {
            $request = $this->resolvedSingleKeyRequest('claim.auto_approve_refund_payment_methods_decided', []);
            app(EcommerceSettingsController::class)->updateSetting($request);
            $this->fail('서버 전용 결정 이력 키가 단건 저장으로 422 없이 통과했다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('key', $e->errors());
        }

        $settings->clearCache();
        $this->assertSame(['card', 'point'], $settings->getSetting('claim.auto_approve_refund_payment_methods_decided'));
    }

    /**
     * 관리자 조회 응답은 결정 이력을 내려주지 않고, 그 claim 섹션을 되보내도 저장된다.
     *
     * 화면은 조회 응답을 폼 상태로 들고 있다가 claim 섹션을 통째로 되보낸다. 이력을 내려주면
     * 서버 전용 규칙 때문에 이력이 한 번 쌓인 뒤의 클레임 탭 저장이 전부 422 가 된다.
     * 수정 전 실패 이유: 조회 응답의 claim 섹션에 `_decided` 가 실려 온다.
     *
     * @scenario save_path=bulk, payload=read_response_round_trip
     *
     * @effects settings_read_omits_decided_history_and_round_trip_saves
     *
     * @return void 반환값 없음
     */
    public function test_read_response_omits_decided_history_and_round_trips(): void
    {
        $this->actingAs($this->adminUser);
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('claim.auto_approve_refund_payment_methods_decided', ['card', 'point']);

        $claim = $this->getJson($this->apiBase)->assertOk()->json('data.claim');
        $this->assertArrayNotHasKey('auto_approve_refund_payment_methods_decided', $claim);

        $category = $this->getJson($this->apiBase.'/claim')->assertOk()->json('data.settings');
        $this->assertArrayNotHasKey('auto_approve_refund_payment_methods_decided', $category);

        $this->putJson($this->apiBase, ['_tab' => 'claim', 'claim' => $claim])->assertOk();

        $settings->clearCache();
        $decided = $settings->getSetting('claim.auto_approve_refund_payment_methods_decided');
        $this->assertContains('point', $decided, '되보낸 저장이 이전 결정 이력을 잃었다');
    }

    /**
     * 저장 규칙이 없는 단건 키는 422 이고 아무것도 저장되지 않는다.
     *
     * 검증된 설정에 없는 키를 원시 입력으로 메우면 검증을 한 번도 거치지 않은 값이 설정 파일에
     * 그대로 영속된다.
     * 수정 전 실패 이유: `Arr::get(..., $key, 원시값)` 폴백으로 검증이 통과하고 값이 저장된다.
     *
     * @scenario save_path=single_key, payload=unknown_key
     *
     * @effects settings_single_key_unknown_key_rejected
     *
     * @return void 반환값 없음
     */
    public function test_single_key_update_with_unknown_key_is_rejected_and_not_persisted(): void
    {
        $this->actingAs($this->adminUser);
        $settings = app(EcommerceSettingsService::class);

        try {
            $request = $this->resolvedSingleKeyRequest('claim.not_a_real_key', 'raw-value');
            app(EcommerceSettingsController::class)->updateSetting($request);
            $this->fail('규칙이 없는 설정 키가 422 없이 통과했다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('key', $e->errors());
        }

        $settings->clearCache();
        $this->assertNull($settings->getSetting('claim.not_a_real_key'));
    }

    /**
     * 규칙이 있는 단건 키는 계속 저장된다 (미지 키 거절의 경계).
     *
     * 미지 키 판정이 규칙 있는 스칼라·카테고리 통째 키까지 막으면 단건 저장 경로가 통째로 닫힌다.
     * 수정 전 실패 이유: 없음 — 수정 뒤 과잉 거절을 막는 경계 고정이다.
     *
     * @scenario save_path=single_key, payload=known_key
     *
     * @effects settings_single_key_unknown_key_rejected
     *
     * @return void 반환값 없음
     */
    public function test_single_key_update_with_known_keys_still_saves(): void
    {
        $this->actingAs($this->adminUser);
        $settings = app(EcommerceSettingsService::class);
        $controller = app(EcommerceSettingsController::class);

        $request = $this->resolvedSingleKeyRequest('claim.max_images', 9);
        $this->assertSame(200, $controller->updateSetting($request)->getStatusCode());

        $request = $this->resolvedSingleKeyRequest('seo.meta_product_title', '{product_name}');
        $this->assertSame(200, $controller->updateSetting($request)->getStatusCode());

        $settings->clearCache();
        $this->assertSame(9, $settings->getSetting('claim.max_images'));
        $this->assertSame('{product_name}', $settings->getSetting('seo.meta_product_title'));
    }

    /**
     * 저장 조립은 서비스가 맡는다 — 사유 카탈로그만 보낸 저장도 claim 을 저장 카테고리로 센다.
     *
     * 카탈로그만 담긴 claim 섹션은 빈 섹션 가드로 JSON 저장에서 빠지지만 DB 동기화는 일어났으므로
     * 저장 카테고리에 claim 이 있어야 한다. 운영자가 저장해 둔 정책 스칼라도 그대로여야 한다.
     * 수정 전 실패 이유: EcommerceSettingsAdminService 가 없고 조립이 컨트롤러 private 메서드에 있다.
     *
     * @scenario save_path=service, payload=reason_catalog_only
     *
     * @effects settings_save_assembly_lives_in_service
     *
     * @return void 반환값 없음
     */
    public function test_save_assembly_service_counts_claim_when_only_reason_catalog_is_sent(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('claim.max_images', 7);

        $outcome = app(EcommerceSettingsAdminService::class)->saveSettings([
            'claim' => [
                'refund_reasons' => [[
                    'code' => 'p3_catalog_only',
                    'name' => ['ko' => '카탈로그만 저장', 'en' => 'Catalog only'],
                    'fault_type' => 'customer',
                    'is_user_selectable' => true,
                    'is_active' => true,
                    'sort_order' => 0,
                ]],
            ],
        ]);

        $this->assertTrue($outcome['result']);
        $this->assertContains('claim', $outcome['saved_categories']);
        $this->assertArrayNotHasKey('claim', $outcome['settings'], '카탈로그만 담긴 claim 섹션이 JSON 저장 대상으로 남았다');
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['code' => 'p3_catalog_only', 'type' => 'refund']);

        $settings->clearCache();
        $this->assertSame(7, $settings->getSetting('claim.max_images'), '카탈로그만 보낸 저장이 정책 스칼라를 지웠다');
    }
}
