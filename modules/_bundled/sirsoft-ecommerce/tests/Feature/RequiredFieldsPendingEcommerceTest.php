<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Fields\FieldContext;
use App\Fields\FieldSet;
use App\Fields\FieldSetRegistry;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Services\RequiredFieldsChecker;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Fields\UserPreferenceFieldHandler;
use Modules\Sirsoft\Ecommerce\Listeners\ExemptRequiredFieldsRoutesListener;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 추가 정보를 입력하지 않은 회원과 이커머스 (#490 11단계, 7단계 잔여)
 *
 * 비어 있는 필수 회원 항목이 있는 회원의 주문 요청은 코어가 403 `required_fields_pending` 으로 거부하고,
 * 결제 결과 처리와 통화, 배송국가 전환은 열어 둔다. 이커머스가 회원 항목으로 선언한 배송국가와 결제 통화가
 * 화면에 없는 사이트(해외배송을 끈 사이트, 통화가 하나뿐인 사이트)에서 회원이 추가 정보 화면에 갇히지 않는지,
 * 저장된 배송국가가 지금 배송할 수 없는 국가일 때 내 정보 수정에 안내가 나오는지 확인한다.
 */
#[Group('ecommerce')]
#[Group('fields')]
class RequiredFieldsPendingEcommerceTest extends ModuleTestCase
{
    private const ORDERS_URL = '/api/modules/sirsoft-ecommerce/user/orders';

    private const COUNTRY = UserPreferenceFieldHandler::SHIPPING_COUNTRY_FIELD;

    private const CURRENCY = UserPreferenceFieldHandler::CURRENCY_FIELD;

    private string $cartKey;

    /**
     * 해외배송을 켜고 배송 가능 국가(KR, US 활성, JP 비활성)를 준비한 뒤 항목 선언을 동기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('shipping.international_shipping_enabled', true);
        $settings->setSetting('shipping.default_country', 'KR');
        $settings->setSetting('shipping.available_countries', [
            ['code' => 'KR', 'name' => ['ko' => '대한민국', 'en' => 'South Korea'], 'is_active' => true],
            ['code' => 'US', 'name' => ['ko' => '미국', 'en' => 'United States'], 'is_active' => true],
            ['code' => 'JP', 'name' => ['ko' => '일본', 'en' => 'Japan'], 'is_active' => false],
        ]);

        $this->createDefaultRoles();
        app(FieldSetRegistry::class)->flush();
        $this->syncMemberFieldDeclarations();
        $this->cartKey = 'ck_'.strtolower(Str::random(32));
    }

    /**
     * 모듈 상태와 항목 정의 메모를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        ModuleManager::invalidateModuleStatusCache();
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
        app(FieldSetRegistry::class)->flush();

        parent::tearDown();
    }

    /**
     * 「기존 회원에게도 입력 요구」를 켠 필수 회원 추가 항목을 만듭니다.
     *
     * @return FieldDefinition 만든 항목
     */
    private function demandedField(): FieldDefinition
    {
        $definition = FieldDefinition::create([
            'field_set' => FieldSet::CORE_USER,
            'key' => 'rf_shop_demanded',
            'type' => 'text',
            'source_type' => 'custom',
            'source_identifier' => 'custom',
            'storage' => 'value',
            'name' => ['ko' => '직업', 'en' => 'Job'],
            'enabled' => true,
            'required' => true,
            'require_existing_members' => true,
            'edit_scope' => 'self',
            'sort_order' => 500,
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
    }

    /**
     * 이커머스가 선언한 회원 항목의 속성을 운영자가 바꾼 것처럼 고칩니다.
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return void 반환값 없음
     */
    private function overrideMemberField(string $key, array $attributes): void
    {
        FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', $key)->update($attributes);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 회원과 그 인증 헤더를 만듭니다.
     *
     * @return array{0: User, 1: array<string, string>} 회원과 인증 헤더
     */
    private function member(): array
    {
        $user = $this->createUser();
        $token = $user->fresh()->createToken('member')->plainTextToken;

        return [$user->fresh(), ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
    }

    /**
     * 앞선 요청이 남긴 인증 상태를 지웁니다 (요청마다 토큰으로 다시 인증하게).
     *
     * @return void 반환값 없음
     */
    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * 회원의 임시 주문과 주문 요청 본문을 준비합니다.
     *
     * @param  User  $user  회원
     * @return array<string, mixed> 주문 생성 요청 본문
     */
    private function prepareOrder(User $user): array
    {
        $product = Product::create([
            'name' => ['ko' => '추가 정보 상품', 'en' => 'Pending Product'],
            'product_code' => 'TEST-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'list_price' => 20000,
            'selling_price' => 15000,
            'currency_code' => 'KRW',
            'stock_quantity' => 100,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'has_options' => true,
        ]);
        $option = ProductOption::create([
            'product_id' => $product->id,
            'option_code' => 'OPT-'.Str::random(8),
            'option_values' => ['색상' => '검정'],
            'option_name' => null,
            'sku' => 'SKU-'.Str::random(8),
            'price_adjustment' => 0,
            'stock_quantity' => 50,
            'safe_stock_quantity' => 5,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        TempOrder::create([
            'user_id' => $user->id,
            'cart_key' => $this->cartKey,
            'items' => [['product_id' => $product->id, 'product_option_id' => $option->id, 'quantity' => 1]],
            'calculation_result' => [
                'items' => [[
                    'product_id' => $product->id,
                    'product_option_id' => $option->id,
                    'quantity' => 1,
                    'unit_price' => 15000,
                    'subtotal' => 15000,
                    'final_amount' => 15000,
                ]],
                'summary' => [
                    'subtotal' => 15000,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => 15000,
                    'final_amount' => 15000,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);

        return [
            'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'buyer@example.com'],
            'shipping' => [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
                'address_detail' => '101동 1001호',
            ],
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'expected_total_amount' => 15000,
            'depositor_name' => '홍길동',
            'dbank' => [
                'bank_code' => 'KB',
                'bank_name' => '국민은행',
                'account_number' => '123-456-789012',
                'account_holder' => '주식회사 테스트',
            ],
        ];
    }

    /**
     * 추가 정보 입력 화면의 응답을 받습니다.
     *
     * @param  array<string, string>  $auth  인증 헤더
     * @return array<string, mixed> 응답의 data
     */
    private function requiredInfo(array $auth): array
    {
        $this->resetAuth();

        return $this->getJson('/api/me/fields?context=required', $auth)->assertOk()->json('data');
    }

    // ==========================================
    // 쓰기 차단과 허용 라우트
    // ==========================================

    /**
     * 비어 있는 필수 회원 항목이 있는 회원의 주문 요청은 403 `required_fields_pending` 으로 거부되고, 채운 뒤에는 주문된다.
     *
     * @scenario case=order_blocked
     *
     * @effects pending_member_order_rejected_with_error_code, order_passes_after_fields_completed
     *
     * @return void 반환값 없음
     */
    public function test_pending_member_order_is_rejected_until_fields_are_completed(): void
    {
        $this->demandedField();
        [$user, $auth] = $this->member();
        $body = $this->prepareOrder($user);

        $this->postJson(self::ORDERS_URL, $body, $auth + ['X-Cart-Key' => $this->cartKey])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'required_fields_pending')
            ->assertJsonPath('data.pending_required_fields', ['rf_shop_demanded'])
            ->assertJsonPath('data.fields', [['key' => 'rf_shop_demanded', 'label' => '직업']]);
        $this->assertDatabaseCount('ecommerce_orders', 0);

        // 읽기(주문서 조회)는 막지 않는다
        $this->resetAuth();
        $this->getJson('/api/modules/sirsoft-ecommerce/checkout', $auth + ['X-Cart-Key' => $this->cartKey])->assertOk();

        app(FieldValueService::class)->write($user, FieldSet::CORE_USER, ['rf_shop_demanded' => '개발자'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));
        $this->resetAuth();
        $this->postJson(self::ORDERS_URL, $body, $auth + ['X-Cart-Key' => $this->cartKey])->assertStatus(201);
    }

    /**
     * 결제 결과 처리 2종과 통화, 배송국가 전환은 미입력 회원에게도 열려 있다. 결제 시작은 열지 않는다.
     *
     * @scenario case=exempt_routes
     *
     * @effects payment_result_and_preference_routes_exempt_for_pending_member, claim_payment_start_not_exempt
     *
     * @return void 반환값 없음
     */
    public function test_payment_result_and_preference_routes_stay_open_for_pending_member(): void
    {
        $this->assertContains(ExemptRequiredFieldsRoutesListener::class, (new Module)->getHookListeners());
        $this->assertSame([
            'api.modules.sirsoft-ecommerce.orders.cancel-payment',
            'api.modules.sirsoft-ecommerce.user.claim-payments.approve',
            'api.modules.sirsoft-ecommerce.user.currency.update',
            'api.modules.sirsoft-ecommerce.user.shipping-country.update',
        ], ExemptRequiredFieldsRoutesListener::ROUTES);
        foreach (ExemptRequiredFieldsRoutesListener::ROUTES as $name) {
            $this->assertTrue(Route::has($name), $name.' 라우트가 없다');
        }

        $exempt = HookManager::applyFilters('core.required_fields.filter_exempt_routes', ['already.there'], request());
        $this->assertContains('already.there', $exempt);
        foreach (ExemptRequiredFieldsRoutesListener::ROUTES as $name) {
            $this->assertContains($name, $exempt);
        }
        $this->assertNotContains('api.modules.sirsoft-ecommerce.user.claim-payments.pay', $exempt);

        $this->demandedField();
        [, $auth] = $this->member();

        $requests = [
            '결제 취소 기록' => ['POST', '/api/modules/sirsoft-ecommerce/orders/ORD-NOPE/cancel-payment', []],
            '클레임 추가 결제 승인' => ['POST', '/api/modules/sirsoft-ecommerce/user/claim-payments/PAY-NOPE/approve', []],
            '결제 통화 전환' => ['PUT', '/api/modules/sirsoft-ecommerce/user/currency', ['currency' => 'KRW']],
            '배송국가 전환' => ['PUT', '/api/modules/sirsoft-ecommerce/user/shipping-country', ['country_code' => 'KR']],
        ];
        foreach ($requests as $what => [$method, $url, $body]) {
            $this->resetAuth();
            $response = $this->json($method, $url, $body, $auth);
            $this->assertNotSame('required_fields_pending', $response->json('error_code'), $what.' 요청이 추가 정보 요구로 막혔다');
        }

        // 대조: 같은 회원의 주문 요청은 막힌다
        $this->resetAuth();
        $this->postJson(self::ORDERS_URL, [], $auth + ['X-Cart-Key' => $this->cartKey])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'required_fields_pending');
    }

    // ==========================================
    // 화면에 없는 항목이 필수일 때 (7단계 잔여 1)
    // ==========================================

    /**
     * 해외배송을 끈 사이트에서 운영자가 배송국가를 필수(+ 기존 회원에게도 입력 요구)로 바꿔도, 값이 없는 회원이 추가 정보 화면에 갇히지 않는다.
     *
     * 배송국가는 해외배송을 끈 동안 어떤 화면에도 나오지 않는다. 미입력으로 판정되면 회원은 채울 칸이 없는 추가 정보 화면으로
     * 끌려가고 모든 쓰기가 막힌다.
     *
     * @scenario case=hidden_shipping_country_required
     *
     * @effects hidden_shipping_country_not_pending_when_international_shipping_off, required_lock_shown_on_admin_table_while_off, shipping_country_pending_and_fillable_when_international_shipping_on
     *
     * @return void 반환값 없음
     */
    public function test_required_shipping_country_does_not_trap_members_when_international_shipping_is_off(): void
    {
        $this->overrideMemberField(self::COUNTRY, ['required' => true, 'require_existing_members' => true]);
        [$user, $auth] = $this->member();
        $this->assertNull(app(EcommerceUserProfileRepositoryInterface::class)->getPreferredShippingCountry($user->id));

        // 해외배송을 켠 동안: 배송국가가 미입력으로 잡히고, 추가 정보 화면에서 채울 수 있다
        $checker = app(RequiredFieldsChecker::class);
        $this->assertSame([self::COUNTRY], $checker->pendingKeys($user, fresh: true));
        $screen = $this->requiredInfo($auth);
        $this->assertSame([self::COUNTRY], $screen['pending_required_fields']);
        $this->assertSame([self::COUNTRY], array_column($screen['fields'], 'key'));

        // 해외배송을 끈다
        app(EcommerceSettingsService::class)->setSetting('shipping.international_shipping_enabled', false);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $this->assertSame([], $checker->pendingKeys($user, fresh: true), '화면에 없는 배송국가가 미입력으로 판정됐다');
        $screen = $this->requiredInfo($auth);
        $this->assertSame([], $screen['pending_required_fields']);
        $this->assertSame([], $screen['fields']);

        // 쓰기도 막히지 않는다
        $body = $this->prepareOrder($user);
        $this->resetAuth();
        $response = $this->postJson(self::ORDERS_URL, $body, $auth + ['X-Cart-Key' => $this->cartKey]);
        $this->assertNotSame('required_fields_pending', $response->json('error_code'));
        $response->assertStatus(201);

        // 운영자 표: 저장된 「필수」는 남기되 적용되지 않는다는 것을 잠금 사유로 알린다
        $this->assertTrue((bool) FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', self::COUNTRY)->value('required'));
        $admin = $this->createAdminUser(['core.settings.read']);
        $this->resetAuth();
        $row = collect($this->actingAs($admin, 'sanctum')->getJson('/api/admin/field-sets/core.user/fields', ['Accept-Language' => 'ko'])->assertOk()->json('data.fields'))
            ->firstWhere('key', self::COUNTRY);
        $this->assertFalse($row['required']);
        $this->assertSame('해외배송을 사용하지 않는 동안에는 회원에게 배송국가를 묻지 않아 필수가 적용되지 않습니다.', $row['locks']['required']);

        // 다시 켜면 저장해 둔 「필수」가 돌아온다
        app(EcommerceSettingsService::class)->setSetting('shipping.international_shipping_enabled', true);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
        $this->assertSame([self::COUNTRY], $checker->pendingKeys($user, fresh: true));
    }

    /**
     * 통화가 하나뿐인 사이트에서 결제 통화를 필수(+ 기존 회원에게도 입력 요구)로 바꾸면, 추가 정보 화면에 그 칸이 나오고 채우면 풀린다.
     *
     * 결제 통화는 가입 화면에서만 빠지고 추가 정보 화면에는 남으므로 갇히지 않는다.
     *
     * @scenario case=single_currency_required
     *
     * @effects single_currency_field_fillable_on_required_info_screen
     *
     * @return void 반환값 없음
     */
    public function test_required_currency_is_fillable_on_single_currency_site(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $currencies = $settings->getSettings('language_currency')['currencies'] ?? [];
        $onlyDefault = array_values(array_filter($currencies, fn ($currency) => $currency['is_default'] ?? false));
        $settings->setSetting('language_currency.currencies', $onlyDefault);
        $defaultCode = $onlyDefault[0]['code'];

        $this->overrideMemberField(self::CURRENCY, ['required' => true, 'require_existing_members' => true]);
        [$user, $auth] = $this->member();

        $this->assertSame([self::CURRENCY], app(RequiredFieldsChecker::class)->pendingKeys($user, fresh: true));
        $screen = $this->requiredInfo($auth);
        $this->assertSame([self::CURRENCY], array_column($screen['fields'], 'key'));
        $this->assertSame([$defaultCode], array_column($screen['fields'][0]['options'], 'value'));

        $this->resetAuth();
        $this->putJson('/api/me/required-fields', [self::CURRENCY => $defaultCode], $auth)
            ->assertOk()
            ->assertJsonPath('data.pending_required_fields', []);
        $this->assertSame($defaultCode, app(EcommerceUserProfileRepositoryInterface::class)->getPreferredCurrency($user->id));
    }

    // ==========================================
    // 저장된 배송국가가 지금 배송 불가일 때의 안내 (7단계 잔여 2)
    // ==========================================

    /**
     * 저장된 배송국가가 지금 배송할 수 없는 국가면 내 정보 수정의 배송국가 안내에 「적용되지 않는다」는 문장과 지금 적용 중인 국가가 실린다.
     *
     * @scenario case=saved_country_not_applied_notice
     *
     * @effects saved_unavailable_country_notice_in_profile_field_help, default_help_when_saved_country_available
     *
     * @return void 반환값 없음
     */
    public function test_profile_field_help_tells_when_saved_shipping_country_is_not_applied(): void
    {
        $profiles = app(EcommerceUserProfileRepositoryInterface::class);

        [$unavailable, $unavailableAuth] = $this->member();
        $profiles->setPreferredShippingCountry($unavailable->id, 'JP');
        $this->resetAuth();
        $field = collect($this->getJson('/api/me/fields?context=profile', $unavailableAuth)->assertOk()->json('data.fields'))
            ->firstWhere('key', self::COUNTRY);
        $this->assertSame(
            '저장된 배송국가 일본: 지금 배송할 수 없는 국가라 적용되지 않습니다. 지금 적용 중인 배송국가: 대한민국',
            $field['help'],
        );
        // 저장된 값은 선택지에 남아 다른 칸만 고친 저장이 막히지 않는다
        $this->assertContains('JP', array_column($field['options'], 'value'));

        [$available, $availableAuth] = $this->member();
        $profiles->setPreferredShippingCountry($available->id, 'US');
        $this->resetAuth();
        $field = collect($this->getJson('/api/me/fields?context=profile', $availableAuth)->assertOk()->json('data.fields'))
            ->firstWhere('key', self::COUNTRY);
        $this->assertSame('기본으로 사용할 배송국가입니다.', $field['help']);

        // 영어를 쓰는 회원
        [$english, $englishAuth] = $this->member();
        $english->update(['language' => 'en']);
        $profiles->setPreferredShippingCountry($english->id, 'JP');
        $this->resetAuth();
        $field = collect($this->getJson('/api/me/fields?context=profile', $englishAuth)->assertOk()->json('data.fields'))
            ->firstWhere('key', self::COUNTRY);
        $this->assertSame(
            'Your saved shipping country Japan is not available for shipping now, so it is not applied. Shipping country in use: South Korea',
            $field['help'],
        );
    }
}
