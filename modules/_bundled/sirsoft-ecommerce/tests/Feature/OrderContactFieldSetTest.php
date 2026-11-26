<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\Helpers\FieldDefinitionSyncHelper;
use App\Extension\HookManager;
use App\Fields\FieldRegistry;
use App\Fields\FieldSetRegistry;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Services\FieldDefinitionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Fields\OrderContactFields;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminStoreExchangeRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateClaimRequestOnBehalfRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\CreateOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\GuestUpdateShippingAddressRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\VerifyGuestOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimPreviewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreExchangeRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateClaimRequest;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Services\OrderPrivacyRetentionService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * 주문서 항목 집합
 *
 * 집합 `sirsoft-ecommerce.order_contact` 의 선언과 잠금, 주문 요청 규칙(설정을 바꾸지 않은 사이트는 종전과 같음),
 * 주문자 이메일과 상세 주소와 배송 메모의 사용과 필수, 추가 항목의 검증과 값 저장과 응답과 파기,
 * 다른 확장이 기여한 항목, 주문서 응답의 항목 설정, 운영자 표의 권한, 훅이 없던 요청 8개의 검증 필터.
 */
#[Group('ecommerce')]
#[Group('fields')]
class OrderContactFieldSetTest extends ModuleTestCase
{
    private const SET = OrderContactFields::SET;

    private const ORDERS_URL = '/api/modules/sirsoft-ecommerce/user/orders';

    private const CHECKOUT_URL = '/api/modules/sirsoft-ecommerce/checkout';

    private const TABLE_URL = '/api/admin/field-sets/'.OrderContactFields::SET.'/fields';

    private string $cartKey;

    private Product $product;

    private ProductOption $productOption;

    /**
     * 집합과 항목 선언을 동기화하고 주문할 상품을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->createDefaultRoles();
        app(FieldSetRegistry::class)->flush();
        $this->syncMemberFieldDeclarations();

        $this->cartKey = $this->newCartKey();
        $this->product = Product::create([
            'name' => ['ko' => '주문서 항목 상품', 'en' => 'Checkout Field Product'],
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
        $this->productOption = ProductOption::create([
            'product_id' => $this->product->id,
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
    }

    /**
     * 항목 정의 메모와 집합 등록소를 비웁니다 (롤백된 행이 다음 테스트에 남지 않게).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
        app(FieldSetRegistry::class)->flush();

        parent::tearDown();
    }

    /**
     * 새 장바구니 키를 만듭니다 (비회원 주문서 요청이 받는 형식).
     *
     * @return string 장바구니 키
     */
    private function newCartKey(): string
    {
        return 'ck_'.strtolower(Str::random(32));
    }

    /**
     * 임시 주문을 만듭니다.
     *
     * @param  User|null  $user  회원 (비회원 주문이면 null)
     * @return TempOrder 상품 1개, 15,000원으로 계산된 임시 주문
     */
    private function tempOrder(?User $user = null): TempOrder
    {
        return TempOrder::create([
            'user_id' => $user?->id,
            'cart_key' => $this->cartKey,
            'items' => [[
                'product_id' => $this->product->id,
                'product_option_id' => $this->productOption->id,
                'quantity' => 1,
            ]],
            'calculation_result' => [
                'items' => [[
                    'product_id' => $this->product->id,
                    'product_option_id' => $this->productOption->id,
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
    }

    /**
     * 주문 생성 요청 본문을 만듭니다 (국내 배송, 무통장 입금).
     *
     * @param  bool  $guest  비회원 주문이면 조회 비밀번호를 싣는다
     * @param  array<string, mixed>  $overrides  덮어쓸 최상위 키
     * @param  array<int, string>  $forget  뺄 점 표기 키
     * @return array<string, mixed> 요청 본문
     */
    private function payload(bool $guest, array $overrides = [], array $forget = []): array
    {
        $body = array_merge([
            'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'buyer@example.com'],
            'shipping' => [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
                'address_detail' => '101동 1001호',
            ],
            'shipping_memo' => '문 앞에 놓아 주세요',
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'expected_total_amount' => 15000,
            'depositor_name' => '홍길동',
            'dbank' => [
                'bank_code' => 'KB',
                'bank_name' => '국민은행',
                'account_number' => '123-456-789012',
                'account_holder' => '주식회사 테스트',
            ],
        ], $guest ? ['guest_lookup_password' => 'guest1234', 'guest_lookup_password_confirmation' => 'guest1234'] : [], $overrides);

        foreach ($forget as $key) {
            data_forget($body, $key);
        }

        return $body;
    }

    /**
     * 비회원으로 주문 생성 요청을 보냅니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 최상위 키
     * @param  array<int, string>  $forget  뺄 점 표기 키
     * @return TestResponse 응답
     */
    private function guestOrder(array $overrides = [], array $forget = []): TestResponse
    {
        if (! TempOrder::where('cart_key', $this->cartKey)->exists()) {
            $this->tempOrder();
        }

        return $this->postJson(self::ORDERS_URL, $this->payload(true, $overrides, $forget), ['X-Cart-Key' => $this->cartKey, 'Accept-Language' => 'ko']);
    }

    /**
     * 회원으로 주문 생성 요청을 보냅니다.
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $overrides  덮어쓸 최상위 키
     * @param  array<int, string>  $forget  뺄 점 표기 키
     * @return TestResponse 응답
     */
    private function memberOrder(User $user, array $overrides = [], array $forget = []): TestResponse
    {
        // 임시 주문은 장바구니 키로도 찾으므로 회원마다 새 키를 쓴다
        if (! TempOrder::where('user_id', $user->id)->exists()) {
            $this->cartKey = $this->newCartKey();
            $this->tempOrder($user);
        }

        return $this->actingAs($user)->postJson(self::ORDERS_URL, $this->payload(false, $overrides, $forget), ['X-Cart-Key' => $this->cartKey, 'Accept-Language' => 'ko']);
    }

    /**
     * 선언 항목의 속성을 운영자가 바꾼 것처럼 고칩니다.
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return void 반환값 없음
     */
    private function override(string $key, array $attributes): void
    {
        FieldDefinition::where('field_set', self::SET)->where('key', $key)->update($attributes);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 운영자 추가 항목을 만듭니다.
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return FieldDefinition 만든 항목
     */
    private function custom(string $key, array $attributes = []): FieldDefinition
    {
        $definition = FieldDefinition::create(array_merge([
            'field_set' => self::SET,
            'key' => $key,
            'type' => 'text',
            'source_type' => 'custom',
            'source_identifier' => 'custom',
            'storage' => 'value',
            'name' => ['ko' => $key.' 이름', 'en' => $key.' name'],
            'enabled' => true,
            'required' => false,
            'sort_order' => 500,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
    }

    /**
     * 다른 확장(플러그인)이 이 집합에 기여한 항목을 만들고, 그 플러그인이 켜져 있는 상황을 만듭니다.
     *
     * 등록소는 꺼진 확장의 행을 빼므로, 그 뒤에 도는 필터로 다시 싣는다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return FieldDefinition 기여 항목
     */
    private function contributed(array $attributes = []): FieldDefinition
    {
        $contributed = FieldDefinition::create(array_merge([
            'field_set' => self::SET,
            'key' => 'giftwrap_enabled',
            'type' => 'text',
            'source_type' => 'plugin',
            'source_identifier' => 'demo-giftwrap',
            'storage' => 'value',
            'name' => ['ko' => '선물 포장 문구', 'en' => 'Gift wrap message'],
            'enabled' => true,
            'required' => false,
            'ui' => ['group' => 'orderer'],
            'sort_order' => 600,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        HookManager::addFilter('core.field.filter_definitions', function ($rows, $set = null) use ($contributed) {
            if ($set === self::SET && $rows instanceof Collection && ! $rows->contains(fn ($row) => $row->key === $contributed->key)) {
                $rows->push(clone $contributed->refresh());
            }

            return $rows;
        }, 5);

        return $contributed;
    }

    /**
     * 주문에 저장된 추가 항목 값을 항목 키로 묶어 반환합니다.
     *
     * @param  int  $orderId  주문 ID
     * @return array<string, mixed> 항목 키 => 저장한 글자 값
     */
    private function storedValues(int $orderId): array
    {
        return DB::table('field_values')
            ->join('field_definitions', 'field_definitions.id', '=', 'field_values.field_definition_id')
            ->where('field_values.owner_type', OrderContactFields::OWNER_TYPE)
            ->where('field_values.owner_id', $orderId)
            ->pluck('field_values.value_text', 'field_definitions.key')
            ->all();
    }

    /**
     * 주문 생성 요청의 규칙을 요청을 보내지 않고 계산합니다.
     *
     * @param  User|null  $user  회원 (비회원이면 null)
     * @return array<string, mixed> 규칙
     */
    private function orderRules(?User $user): array
    {
        $request = CreateOrderRequest::create(self::ORDERS_URL, 'POST', []);
        $request->setContainer($this->app);
        $request->setUserResolver(fn () => $user);

        return $request->rules();
    }

    /**
     * 수정 전 주문 생성 요청의 규칙 (객체나 클로저가 없는 키만).
     *
     * @param  bool  $guest  비회원 주문 여부
     * @return array<string, mixed> 규칙
     */
    private function legacyRules(bool $guest): array
    {
        return [
            'orderer.name' => 'required|string|max:50',
            'orderer.phone' => 'required|string|max:20',
            'shipping.recipient_name' => 'required|string|max:50',
            'shipping.recipient_phone' => 'required_without:shipping.recipient_tel|nullable|string|max:20',
            'shipping.recipient_tel' => 'required_without:shipping.recipient_phone|nullable|string|max:20',
            'shipping.country_code' => 'nullable|string|size:2',
            'shipping.zipcode' => 'required_without:shipping.intl_postal_code|nullable|string|max:10',
            'shipping.address' => 'required_without:shipping.address_line_1|nullable|string|max:255',
            'shipping.address_detail' => 'required_without:shipping.address_line_1|nullable|string|max:255',
            'shipping.address_type_code' => 'nullable|string|in:R,J',
            'shipping.address_line_1' => 'required_without:shipping.address|nullable|string|max:255',
            'shipping.address_line_2' => 'nullable|string|max:255',
            'shipping.intl_city' => 'required_with:shipping.address_line_1|nullable|string|max:100',
            'shipping.intl_state' => 'nullable|string|max:100',
            'shipping.intl_postal_code' => 'required_with:shipping.address_line_1|nullable|string|max:20',
            'expected_total_amount' => 'required|numeric|min:0',
            'shipping_memo' => 'nullable|string|max:500',
            'depositor_name' => 'required_if:payment_method,vbank|required_if:payment_method,dbank|nullable|string|max:50',
            'dbank.bank_code' => 'required_if:payment_method,dbank|nullable|string|max:10',
            'dbank.bank_name' => 'nullable|string|max:50',
            'dbank.account_number' => 'required_if:payment_method,dbank|nullable|string|max:50',
            'dbank.account_holder' => 'required_if:payment_method,dbank|nullable|string|max:50',
            'save_shipping_address' => 'nullable|boolean',
            'save_shipping_address_id' => 'nullable|integer|min:1',
            'cash_receipt_requested' => 'nullable|boolean',
            'refund_bank.bank_code' => 'nullable|string|max:10',
            'refund_bank.account_number' => 'nullable|string|max:50',
            'refund_bank.holder' => 'nullable|string|max:50',
            'orderer.email' => $guest ? ['required', 'email', 'max:255'] : ['nullable', 'email', 'max:255'],
            'guest_lookup_password' => $guest ? ['required', 'string', 'min:8', 'max:255', 'confirmed'] : ['nullable'],
            'guest_lookup_password_confirmation' => $guest ? ['required', 'string'] : ['nullable'],
        ];
    }

    // ==========================================
    // 선언과 잠금
    // ==========================================

    /**
     * 집합이 이커머스 설정 권한으로 등록되고, 기본 항목 8개가 컬럼 저장과 구획과 잠금과 함께 동기화된다.
     *
     * @scenario case=declaration
     *
     * @effects order_contact_set_registered_with_settings_permissions, eight_fields_synced_with_groups_and_locks
     *
     * @return void 반환값 없음
     */
    public function test_declares_set_and_eight_fields_with_locks(): void
    {
        $set = app(FieldSetRegistry::class)->get(self::SET);
        $this->assertNotNull($set);
        $this->assertSame('module', $set->ownerType);
        $this->assertSame('sirsoft-ecommerce', $set->ownerIdentifier);
        $this->assertSame(['ecommerce_order'], $set->ownerTypes);
        $this->assertSame(['checkout'], $set->surfaces);
        $this->assertSame('extra_fields', $set->bundleKey);
        $this->assertSame('sirsoft-ecommerce.settings.read', $set->readPermission);
        $this->assertSame('sirsoft-ecommerce.settings.update', $set->managePermission);
        $this->assertTrue($set->acceptsContributions);
        $this->assertTrue($set->allowsCustom);
        $this->assertFalse($set->allowsType('file'));
        $this->assertFalse($set->allowsType('image'));
        $this->assertTrue($set->allowsType('address'));
        $this->assertSame('주문서 항목', $set->localizedLabel());

        $rows = FieldDefinition::where('field_set', self::SET)->orderBy('sort_order')->get()->keyBy('key');
        $this->assertSame(
            ['orderer_name', 'orderer_phone', 'orderer_email', 'recipient_name', 'recipient_phone', 'address', 'address_detail', 'shipping_memo'],
            $rows->keys()->all(),
        );

        $expected = [
            // 키 => [구획, 필수, 잠금 여부]
            'orderer_name' => ['orderer', true, true],
            'orderer_phone' => ['orderer', true, true],
            'orderer_email' => ['orderer', false, false],
            'recipient_name' => ['shipping', true, true],
            'recipient_phone' => ['shipping', true, true],
            'address' => ['shipping', true, true],
            'address_detail' => ['shipping', true, false],
            'shipping_memo' => ['shipping', false, false],
        ];
        foreach ($expected as $key => [$group, $required, $locked]) {
            $row = $rows[$key];
            $this->assertSame('module', $row->source_type, $key);
            $this->assertSame('sirsoft-ecommerce', $row->source_identifier, $key);
            $this->assertSame('column', $row->storage, $key);
            $this->assertTrue((bool) $row->enabled, $key);
            $this->assertSame($required, (bool) $row->required, $key);
            $this->assertSame($group, $row->ui['group'], $key);
            $this->assertSame(
                $locked ? ['enabled', 'required'] : [],
                array_keys($row->locks ?? []),
                $key,
            );
        }

        $this->assertSame('비회원 주문은 늘 필수입니다.', $rows['orderer_email']->description['ko']);
        $this->assertSame('sirsoft-ecommerce::fields.order_contact.locked', $rows['address']->locks['enabled']);
        $this->assertSame(OrderContactFields::OWNER_TYPE, (new Order)->fieldOwnerType());
    }

    /**
     * 운영자 표는 이커머스 설정 조회 권한이 있어야 열리고, 잠긴 항목은 사유 문구와 함께 내려간다.
     *
     * @scenario case=table_permission
     *
     * @effects table_forbidden_without_settings_read, table_lists_locked_rows_with_reason
     *
     * @return void 반환값 없음
     */
    public function test_admin_table_requires_ecommerce_settings_permission(): void
    {
        $outsider = $this->createAdminUser(['core.settings.read', 'core.settings.update']);
        $this->actingAs($outsider, 'sanctum')->getJson(self::TABLE_URL, ['Accept-Language' => 'ko'])->assertStatus(403);

        $reader = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);
        $response = $this->actingAs($reader, 'sanctum')->getJson(self::TABLE_URL, ['Accept-Language' => 'ko'])->assertOk();

        $response->assertJsonPath('data.set.key', self::SET)
            ->assertJsonPath('data.set.owner', 'sirsoft-ecommerce')
            ->assertJsonPath('data.set.allows_custom', true)
            ->assertJsonPath('data.abilities.can_update', false);
        $fields = collect($response->json('data.fields'))->keyBy('key');
        $this->assertCount(8, $fields);
        $this->assertSame('주문과 배송에 꼭 필요한 항목이라 사용과 필수를 바꿀 수 없습니다.', $fields['orderer_name']['locks']['enabled']);
        $this->assertSame('orderer', $fields['orderer_email']['ui']['group']);
        $this->assertSame([], (array) $fields['shipping_memo']['locks']);
        $this->assertNotContains('file', array_column($response->json('data.types'), 'key'));

        // 조회 권한만 있는 관리자는 고칠 수 없다
        $this->actingAs($reader, 'sanctum')
            ->putJson(self::TABLE_URL.'/'.$fields['shipping_memo']['id'], ['required' => true])
            ->assertStatus(403);
    }

    /**
     * 잠긴 항목은 코어 관리자 API 로 사용과 필수를 꺼도 바뀌지 않고, 설정 가능한 항목은 바뀐다.
     *
     * @scenario case=locked_update
     *
     * @effects locked_field_update_ignored, configurable_field_update_saved
     *
     * @return void 반환값 없음
     */
    public function test_locked_fields_cannot_be_changed_through_admin_api(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update']);
        $ids = FieldDefinition::where('field_set', self::SET)->pluck('id', 'key');

        foreach (['orderer_name', 'orderer_phone', 'recipient_name', 'recipient_phone', 'address'] as $key) {
            $this->actingAs($admin, 'sanctum')
                ->putJson(self::TABLE_URL.'/'.$ids[$key], ['enabled' => false, 'required' => false])
                ->assertOk()
                ->assertJsonPath('data.field.enabled', true)
                ->assertJsonPath('data.field.required', true);

            $row = FieldDefinition::find($ids[$key]);
            $this->assertTrue((bool) $row->enabled, $key);
            $this->assertTrue((bool) $row->required, $key);
        }

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::TABLE_URL.'/'.$ids['shipping_memo'], ['required' => true])
            ->assertOk()
            ->assertJsonPath('data.field.required', true);
        $this->assertTrue((bool) FieldDefinition::find($ids['shipping_memo'])->required);

        // 선언 항목은 지울 수 없다
        $this->actingAs($admin, 'sanctum')->deleteJson(self::TABLE_URL.'/'.$ids['orderer_email'])->assertStatus(403);
    }

    // ==========================================
    // 설정을 바꾸지 않은 사이트의 규칙
    // ==========================================

    /**
     * 설정을 바꾸지 않았고 추가 항목이 없으면 주문 생성 요청의 규칙이 수정 전과 키와 값까지 같다.
     *
     * @scenario case=rules_unchanged
     *
     * @effects create_order_rules_identical_to_legacy_for_member_and_guest
     *
     * @param  bool  $guest  비회원 주문 여부
     * @return void 반환값 없음
     */
    #[DataProvider('actorProvider')]
    public function test_rules_are_unchanged_without_operator_changes(bool $guest): void
    {
        $rules = $this->orderRules($guest ? null : $this->createUser());
        $objectKeys = ['payment_method', 'cash_receipt_type', 'cash_receipt_identifier_type', 'cash_receipt_identifier'];
        $legacy = $this->legacyRules($guest);

        $expectedKeys = [...array_keys($legacy), ...$objectKeys];
        sort($expectedKeys);
        $actualKeys = array_keys($rules);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys, '규칙 키가 수정 전과 다르다');

        foreach ($legacy as $key => $rule) {
            $this->assertSame($rule, $rules[$key], "{$key} 규칙이 수정 전과 다르다");
        }
        $this->assertCount(6, $rules['payment_method']);
        $this->assertCount(4, $rules['cash_receipt_type']);
        $this->assertCount(4, $rules['cash_receipt_identifier_type']);
        $this->assertCount(5, $rules['cash_receipt_identifier']);
    }

    /**
     * 항목 정의 행이 아직 없는 사이트(모듈을 갱신하기 전)에서도 규칙과 주문서 응답의 기본 항목 값이 종전과 같다.
     *
     * @scenario case=rules_unchanged
     *
     * @effects create_order_rules_identical_to_legacy_before_declaration_sync
     *
     * @return void 반환값 없음
     */
    public function test_rules_fall_back_to_legacy_before_declarations_are_synced(): void
    {
        FieldDefinition::where('field_set', self::SET)->delete();
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $member = $this->createUser();
        foreach ([false => $member, true => null] as $guest => $actor) {
            $rules = $this->orderRules($actor);
            foreach ($this->legacyRules((bool) $guest) as $key => $rule) {
                $this->assertSame($rule, $rules[$key], "{$key} 규칙이 수정 전과 다르다");
            }
        }

        $this->assertSame([
            'orderer_email' => ['enabled' => true, 'required' => false],
            'address_detail' => ['enabled' => true, 'required' => true],
            'shipping_memo' => ['enabled' => true, 'required' => false],
        ], app(OrderContactFields::class)->builtin($member));
    }

    /**
     * 회원과 비회원 두 경우.
     *
     * @return array<string, array{0: bool}> 데이터 묶음
     */
    public static function actorProvider(): array
    {
        return ['회원' => [false], '비회원' => [true]];
    }

    /**
     * 기존 필터 `sirsoft-ecommerce.order.create_validation_rules` 는 항목 규칙이 합쳐진 뒤에 그대로 돈다.
     *
     * @scenario case=rules_unchanged
     *
     * @effects legacy_create_filter_runs_after_field_rules
     *
     * @return void 반환값 없음
     */
    public function test_existing_create_filter_still_runs_last(): void
    {
        $this->custom('company', ['required' => true]);
        $seen = null;
        HookManager::addFilter('sirsoft-ecommerce.order.create_validation_rules', function ($rules, $request = null) use (&$seen) {
            $seen = ['keys' => array_keys($rules), 'request' => $request];
            $rules['probe_from_filter'] = ['required'];

            return $rules;
        });

        $rules = $this->orderRules(null);

        $this->assertInstanceOf(CreateOrderRequest::class, $seen['request']);
        $this->assertContains('extra_fields.company', $seen['keys']);
        $this->assertContains('orderer.email', $seen['keys']);
        $this->assertSame(['required'], $rules['probe_from_filter']);
    }

    // ==========================================
    // 주문자 이메일, 상세 주소, 배송 메모
    // ==========================================

    /**
     * 주문자 이메일: 회원은 설정을 따르고(꺼짐이면 저장하지 않음, 필수면 422), 비회원은 설정과 무관하게 늘 필수다.
     *
     * @scenario case=orderer_email
     *
     * @effects member_email_dropped_when_disabled, member_email_required_when_required, guest_email_always_required
     *
     * @return void 반환값 없음
     */
    public function test_orderer_email_follows_setting_for_members_and_stays_required_for_guests(): void
    {
        // 기본(사용, 선택): 회원은 이메일 없이도 주문한다
        $optional = $this->createUser();
        $this->memberOrder($optional, [], ['orderer.email'])->assertStatus(201);

        // 필수: 회원도 이메일이 없으면 422
        $this->override('orderer_email', ['required' => true]);
        $required = $this->createUser();
        $this->memberOrder($required, [], ['orderer.email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['orderer.email']);
        // 주문서에서 칸을 비워 빈 문자열로 보내도 같다 — 서버는 회원 계정 이메일로 채우지 않고 422 로 막는다
        $this->memberOrder($required, ['orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => '']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['orderer.email']);
        $this->assertSame(['required', 'email', 'max:255'], $this->orderRules($required)['orderer.email']);

        // 꺼짐: 회원이 보낸 이메일은 규칙에서 빠지고 저장하지 않는다
        $this->override('orderer_email', ['enabled' => false, 'required' => false]);
        $disabled = $this->createUser();
        $this->assertArrayNotHasKey('orderer.email', $this->orderRules($disabled));
        $response = $this->memberOrder($disabled, ['orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'not-an-email']])
            ->assertStatus(201);
        $order = Order::where('order_number', $response->json('data.order.order_number'))->firstOrFail();
        $this->assertSame('', (string) $order->shippingAddress->orderer_email);
        $this->assertNull($order->payment->buyer_email ?: null);

        // 꺼짐이어도 비회원은 늘 필수
        $this->app['auth']->forgetGuards();
        $this->assertSame(['required', 'email', 'max:255'], $this->orderRules(null)['orderer.email']);
        $this->guestOrder([], ['orderer.email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['orderer.email']);
        $guest = $this->guestOrder()->assertStatus(201);
        $guestOrder = Order::where('order_number', $guest->json('data.order.order_number'))->firstOrFail();
        $this->assertSame('buyer@example.com', $guestOrder->shippingAddress->orderer_email);
    }

    /**
     * 상세 주소: 필수면 종전 규칙(해외 주소 1행이 없을 때만 필수), 선택이면 비워도 통과, 꺼짐이면 보내도 저장하지 않는다.
     *
     * @scenario case=address_detail
     *
     * @effects address_detail_required_by_default, address_detail_optional_passes_when_empty, address_detail_dropped_when_disabled
     *
     * @return void 반환값 없음
     */
    public function test_address_detail_follows_setting(): void
    {
        // 기본(사용, 필수): 국내 주소에 상세 주소가 없으면 422
        $this->guestOrder([], ['shipping.address_detail'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shipping.address_detail']);

        // 선택: 비워도 주문된다
        $this->override('address_detail', ['required' => false]);
        $this->assertSame('nullable|string|max:255', $this->orderRules(null)['shipping.address_detail']);
        $optional = $this->guestOrder([], ['shipping.address_detail'])->assertStatus(201);
        $order = Order::where('order_number', $optional->json('data.order.order_number'))->firstOrFail();
        $this->assertSame('', (string) $order->shippingAddress->address_detail);

        // 꺼짐: 규칙에서 빠지고, 요청에 실려 와도 저장하지 않는다
        $this->override('address_detail', ['enabled' => false]);
        $this->assertArrayNotHasKey('shipping.address_detail', $this->orderRules(null));
        $this->cartKey = $this->newCartKey();
        $disabled = $this->guestOrder(['shipping' => [
            'recipient_name' => '김철수',
            'recipient_phone' => '010-9876-5432',
            'country_code' => 'KR',
            'zipcode' => '12345',
            'address' => '서울시 강남구 테헤란로 123',
            'address_detail' => str_repeat('가', 300),
        ]])->assertStatus(201);
        $order = Order::where('order_number', $disabled->json('data.order.order_number'))->firstOrFail();
        $this->assertSame('', (string) $order->shippingAddress->address_detail);
        $this->assertSame('서울시 강남구 테헤란로 123', $order->shippingAddress->address);
    }

    /**
     * 배송 메모: 필수로 바꾸면 없을 때 422, 꺼짐이면 보내도 저장하지 않는다.
     *
     * @scenario case=shipping_memo
     *
     * @effects shipping_memo_saved_by_default, shipping_memo_required_when_required, shipping_memo_dropped_when_disabled
     *
     * @return void 반환값 없음
     */
    public function test_shipping_memo_follows_setting(): void
    {
        // 기본(사용, 선택): 보낸 메모가 저장된다
        $default = $this->guestOrder()->assertStatus(201);
        $order = Order::where('order_number', $default->json('data.order.order_number'))->firstOrFail();
        $this->assertSame('문 앞에 놓아 주세요', $order->shippingAddress->delivery_memo);

        // 필수: 없으면 422
        $this->override('shipping_memo', ['required' => true]);
        $this->assertSame('required|string|max:500', $this->orderRules(null)['shipping_memo']);
        $this->cartKey = $this->newCartKey();
        $this->guestOrder([], ['shipping_memo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shipping_memo']);

        // 꺼짐: 보내도 저장하지 않는다
        $this->override('shipping_memo', ['enabled' => false, 'required' => false]);
        $this->assertArrayNotHasKey('shipping_memo', $this->orderRules(null));
        $disabled = $this->guestOrder(['shipping_memo' => str_repeat('가', 600)])->assertStatus(201);
        $order = Order::where('order_number', $disabled->json('data.order.order_number'))->firstOrFail();
        $this->assertNull($order->shippingAddress->delivery_memo);
    }

    // ==========================================
    // 추가 항목
    // ==========================================

    /**
     * 필수 추가 항목을 보내지 않으면 `extra_fields.{키}` 오류로 422, 꺼진 추가 항목은 보내도 무시한다.
     *
     * @scenario case=extra_required
     *
     * @effects missing_required_extra_field_rejected_with_bundle_key, disabled_extra_field_ignored
     *
     * @return void 반환값 없음
     */
    public function test_required_extra_field_missing_returns_422_with_bundle_key(): void
    {
        $this->custom('company', ['required' => true, 'constraints' => ['max_length' => 20]]);
        $this->custom('legacy_note', ['enabled' => false]);

        // 화면은 값이 빈 항목의 키를 아예 보내지 않는다
        $response = $this->guestOrder()->assertStatus(422)->assertJsonValidationErrors(['extra_fields.company']);
        $this->assertStringContainsString('company 이름', $response->json('errors')['extra_fields.company'][0]);

        $this->guestOrder(['extra_fields' => ['company' => str_repeat('가', 21)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['extra_fields.company']);

        $created = $this->guestOrder(['extra_fields' => ['company' => '시르소프트', 'legacy_note' => '꺼진 항목', 'unknown' => '없는 항목']])
            ->assertStatus(201);
        $order = Order::where('order_number', $created->json('data.order.order_number'))->firstOrFail();
        $this->assertSame(['company' => '시르소프트'], $this->storedValues($order->id));
    }

    /**
     * 추가 항목 값은 주문과 함께 저장되고(소유자 종류 `ecommerce_order`), 주문 응답의 `extra_fields` 로 구획과 함께 나간다.
     *
     * @scenario case=extra_values
     *
     * @effects extra_values_stored_with_order_owner_type, extra_fields_in_guest_member_and_admin_order_responses, empty_extra_fields_without_values
     *
     * @return void 반환값 없음
     */
    public function test_extra_field_values_are_stored_and_returned_in_order_responses(): void
    {
        $this->custom('company', ['ui' => ['group' => 'orderer']]);
        $this->custom('door_code', ['sort_order' => 510]);
        $this->custom('office', ['type' => 'address', 'sort_order' => 520]);
        $this->custom('gift', [
            'type' => 'choice',
            'options' => [['value' => 'yes', 'label' => ['ko' => '예']], ['value' => 'no', 'label' => ['ko' => '아니오']]],
            'sort_order' => 530,
        ]);

        // 비회원 주문
        $guest = $this->guestOrder(['extra_fields' => [
            'company' => '시르소프트',
            'door_code' => '#1234',
            'office' => ['zipcode' => '06236', 'address' => '서울시 강남구 역삼동', 'address_detail' => '5층'],
            'gift' => 'yes',
        ]])->assertStatus(201);
        $guestOrder = Order::where('order_number', $guest->json('data.order.order_number'))->firstOrFail();

        $this->assertSame(4, DB::table('field_values')
            ->where('owner_type', 'ecommerce_order')->where('owner_id', $guestOrder->id)->count());
        $this->assertSame([
            ['key' => 'company', 'label' => 'company 이름', 'section' => 'orderer', 'display_value' => '시르소프트'],
            ['key' => 'door_code', 'label' => 'door_code 이름', 'section' => 'shipping', 'display_value' => '#1234'],
        ], array_slice($guest->json('data.order.extra_fields'), 0, 2));
        $byKey = collect($guest->json('data.order.extra_fields'))->keyBy('key');
        $this->assertSame('shipping', $byKey['office']['section']);
        $this->assertStringContainsString('서울시 강남구 역삼동', $byKey['office']['display_value']);
        $this->assertSame('예', $byKey['gift']['display_value']);

        // 회원 주문: 값을 보내지 않으면 빈 목록
        $this->app['auth']->forgetGuards();
        $member = $this->createUser();
        $empty = $this->memberOrder($member)->assertStatus(201);
        $this->assertSame([], $empty->json('data.order.extra_fields'));

        $filled = $this->memberOrder($member, ['extra_fields' => ['company' => '회원 회사']])->assertStatus(201);
        $this->assertSame(
            [['key' => 'company', 'label' => 'company 이름', 'section' => 'orderer', 'display_value' => '회원 회사']],
            $filled->json('data.order.extra_fields'),
        );
        $memberOrder = Order::where('order_number', $filled->json('data.order.order_number'))->firstOrFail();

        // 회원 주문 상세
        $this->actingAs($member)
            ->getJson(self::ORDERS_URL.'/'.$memberOrder->id)
            ->assertOk()
            ->assertJsonPath('data.extra_fields.0.key', 'company')
            ->assertJsonPath('data.extra_fields.0.display_value', '회원 회사');

        // 관리자 주문 상세
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders/'.$guestOrder->id)
            ->assertOk()
            ->assertJsonPath('data.extra_fields.0.key', 'company')
            ->assertJsonPath('data.extra_fields.0.section', 'orderer')
            ->assertJsonCount(4, 'data.extra_fields');
    }

    /**
     * 추가 항목이 하나도 없는 사이트의 주문 응답은 값 테이블을 읽지 않고 빈 `extra_fields` 를 싣는다.
     *
     * @scenario case=extra_values
     *
     * @effects no_value_query_without_extra_fields
     *
     * @return void 반환값 없음
     */
    public function test_order_response_does_not_read_value_table_without_extra_fields(): void
    {
        $created = $this->guestOrder()->assertStatus(201);
        $order = Order::where('order_number', $created->json('data.order.order_number'))->firstOrFail();

        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'field_values')) {
                $queries++;
            }
        });

        $this->assertSame([], app(OrderContactFields::class)->valuesFor($order));
        $this->assertSame(0, $queries);
    }

    /**
     * 보유기간 익명화, 보유기간 삭제, 주문 삭제는 주문의 추가 항목 값을 함께 지운다. 다른 주문의 값은 남는다.
     *
     * @scenario case=purge
     *
     * @effects extra_values_purged_on_anonymize, extra_values_purged_on_retention_delete, extra_values_purged_on_order_delete
     *
     * @return void 반환값 없음
     */
    public function test_extra_field_values_are_purged_with_the_order(): void
    {
        $this->custom('company');

        $orders = [];
        foreach (['anonymize', 'purge', 'delete', 'kept'] as $name) {
            $this->cartKey = $this->newCartKey();
            $response = $this->guestOrder(['extra_fields' => ['company' => $name.' 회사']])->assertStatus(201);
            $orders[$name] = Order::where('order_number', $response->json('data.order.order_number'))->firstOrFail();
            $this->assertSame(['company' => $name.' 회사'], $this->storedValues($orders[$name]->id));
        }

        app(OrderPrivacyRetentionService::class)->anonymize($orders['anonymize']);
        $this->assertSame([], $this->storedValues($orders['anonymize']->id));

        app(OrderPrivacyRetentionService::class)->purge($orders['purge']);
        $this->assertSame([], $this->storedValues($orders['purge']->id));

        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.orders.delete']), 'sanctum');
        app(OrderService::class)->delete($orders['delete']);
        $this->assertSame([], $this->storedValues($orders['delete']->id));

        $this->assertSame(['company' => 'kept 회사'], $this->storedValues($orders['kept']->id));
    }

    /**
     * 운영자가 주문서 추가 항목을 지워도 지난 주문 상세에는 그 항목의 이름과 값이 남는다.
     * 주문을 저장할 때 남긴 스냅숏(`order_meta`)으로 그리고, 보유기간 익명화는 스냅숏도 함께 지운다.
     *
     * @scenario case=deleted_field_keeps_order_value
     *
     * @effects deleted_extra_field_value_stays_on_past_order, anonymize_removes_extra_field_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_deleted_extra_field_value_stays_visible_on_past_orders(): void
    {
        $company = $this->custom('company', ['ui' => ['group' => 'orderer']]);
        $created = $this->guestOrder(['extra_fields' => ['company' => '시르소프트']])->assertStatus(201);
        $order = Order::where('order_number', $created->json('data.order.order_number'))->firstOrFail();

        app(FieldDefinitionService::class)->delete($company);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $this->assertSame(
            [['key' => 'company', 'label' => 'company 이름', 'section' => 'orderer', 'display_value' => '시르소프트']],
            app(OrderContactFields::class)->valuesFor($order->fresh()),
            '지운 항목의 지난 주문 값이 주문 상세에서 사라졌다',
        );

        app(OrderPrivacyRetentionService::class)->anonymize($order->fresh());
        $this->assertSame([], app(OrderContactFields::class)->valuesFor($order->fresh()), '익명화 뒤에도 추가 항목 스냅숏이 남았다');
    }

    /**
     * 스냅숏이 있는 주문을 익명화해도 수정 시각은 그대로다 — `updated_at` 은 레거시 주문의 종결 시각 대용이라
     * 바뀌면 보유기간 삭제가 밀린다(독립 검토 2026-11-12, `OrderPrivacyRetentionService::anonymize()` 계약).
     *
     * @scenario case=anonymize_keeps_updated_at_with_snapshot
     *
     * @effects anonymize_removes_extra_field_snapshot, anonymize_keeps_order_updated_at
     *
     * @return void 반환값 없음
     */
    public function test_anonymize_keeps_updated_at_when_order_has_extra_field_snapshot(): void
    {
        $this->custom('company', ['ui' => ['group' => 'orderer']]);
        $created = $this->guestOrder(['extra_fields' => ['company' => '시르소프트']])->assertStatus(201);
        $order = Order::where('order_number', $created->json('data.order.order_number'))->firstOrFail();
        $this->assertArrayHasKey(OrderContactFields::SNAPSHOT_META_KEY, $order->order_meta ?? []);

        $past = now()->subYears(3)->startOfSecond();
        Order::whereKey($order->id)->toBase()->update(['updated_at' => $past]);

        app(OrderPrivacyRetentionService::class)->anonymize($order->fresh());

        $after = $order->fresh();
        $this->assertArrayNotHasKey(OrderContactFields::SNAPSHOT_META_KEY, $after->order_meta ?? [], '익명화 뒤에도 스냅숏이 남았다');
        $this->assertSame($past->toDateTimeString(), $after->updated_at?->toDateTimeString(), '익명화가 주문 수정 시각을 바꿨다');
    }

    // ==========================================
    // 다른 확장이 기여한 항목
    // ==========================================

    /**
     * 다른 확장이 기여한 항목은 주문서 계약과 운영자 표에 출처와 함께 나오고, 검증과 저장을 같은 길로 타며, 지울 수 없다.
     *
     * @scenario case=contributed_field
     *
     * @effects contributed_field_in_checkout_contract, contributed_field_in_admin_table_with_source, contributed_field_delete_forbidden, contributed_field_validated_and_stored
     *
     * @return void 반환값 없음
     */
    public function test_contributed_field_appears_in_contract_and_table_and_cannot_be_deleted(): void
    {
        $contributed = $this->contributed(['required' => true]);

        // 주문서 계약: 주문자 구획에 폼 경로 extra_fields.{키} 로 나온다
        $payload = app(OrderContactFields::class)->formPayload(null);
        $this->assertSame(['giftwrap_enabled'], array_column($payload['orderer'], 'key'));
        $this->assertSame('extra_fields.giftwrap_enabled', $payload['orderer'][0]['name']);
        $this->assertSame('plugin', $payload['orderer'][0]['owner_type']);
        $this->assertTrue($payload['orderer'][0]['required']);
        $this->assertSame([], $payload['shipping']);

        // 운영자 표: 출처가 플러그인인 행으로 나오고 지울 수 없다
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update']);
        $table = $this->actingAs($admin, 'sanctum')->getJson(self::TABLE_URL, ['Accept-Language' => 'ko'])->assertOk();
        $row = collect($table->json('data.fields'))->firstWhere('key', 'giftwrap_enabled');
        $this->assertNotNull($row);
        $this->assertSame('plugin', $row['source_type']);
        $this->assertSame('demo-giftwrap', $row['source_identifier']);
        $this->assertFalse($row['is_custom']);
        $this->actingAs($admin, 'sanctum')->deleteJson(self::TABLE_URL.'/'.$contributed->id)->assertStatus(403);
        $this->assertNotNull(FieldDefinition::find($contributed->id));

        // 주문 요청: 필수 누락은 422, 값은 주문에 저장된다
        $this->app['auth']->forgetGuards();
        $this->guestOrder()->assertStatus(422)->assertJsonValidationErrors(['extra_fields.giftwrap_enabled']);
        $created = $this->guestOrder(['extra_fields' => ['giftwrap_enabled' => '생일 축하해']])->assertStatus(201);
        $order = Order::where('order_number', $created->json('data.order.order_number'))->firstOrFail();
        $this->assertSame(['giftwrap_enabled' => '생일 축하해'], $this->storedValues($order->id));
        $this->assertSame('orderer', $created->json('data.order.extra_fields.0.section'));

        // 다른 확장은 잠긴 항목의 키로 선언을 덮어쓸 수 없다 (먼저 등록된 쪽을 유지)
        $result = app(FieldDefinitionSyncHelper::class)->sync('plugin', 'demo-giftwrap', [[
            'field_set' => self::SET,
            'key' => 'orderer_name',
            'type' => 'text',
            'name' => ['ko' => '덮어쓰기'],
            'enabled' => false,
            'required' => false,
        ]]);
        $this->assertArrayHasKey(self::SET.'|orderer_name', $result['skipped']);
        $this->assertTrue((bool) FieldDefinition::where('field_set', self::SET)->where('key', 'orderer_name')->value('enabled'));
    }

    /**
     * 필터로 잠긴 항목의 사용과 필수를 바꿔도 주문 요청의 고정 규칙은 그대로다.
     *
     * @scenario case=contributed_field
     *
     * @effects locked_rules_not_affected_by_definition_filter
     *
     * @return void 반환값 없음
     */
    public function test_definition_filter_cannot_relax_locked_fields(): void
    {
        HookManager::addFilter('core.field.filter_definitions', function ($rows, $set = null) {
            if ($set === self::SET && $rows instanceof Collection) {
                foreach ($rows as $row) {
                    $row->enabled = false;
                    $row->required = false;
                }
            }

            return $rows;
        });

        $rules = $this->orderRules(null);
        $this->assertSame('required|string|max:50', $rules['orderer.name']);
        $this->assertSame('required|string|max:20', $rules['orderer.phone']);
        $this->assertSame('required|string|max:50', $rules['shipping.recipient_name']);
        $this->assertSame('required_without:shipping.address_line_1|nullable|string|max:255', $rules['shipping.address']);
        // 비회원 이메일도 필터로 풀리지 않는다
        $this->assertSame(['required', 'email', 'max:255'], $rules['orderer.email']);

        $this->guestOrder([], ['orderer.name'])->assertStatus(422)->assertJsonValidationErrors(['orderer.name']);
    }

    // ==========================================
    // 주문서 응답
    // ==========================================

    /**
     * 주문서 조회(GET)와 다시 계산(PUT) 응답이 같은 `order_fields` 를 싣는다. 비회원의 이메일은 늘 사용, 늘 필수다.
     *
     * @scenario case=order_fields
     *
     * @effects order_fields_in_checkout_show_and_update, guest_email_builtin_always_required, extra_contracts_grouped_by_section, builtin_and_locked_fields_not_listed
     *
     * @return void 반환값 없음
     */
    public function test_checkout_show_and_update_carry_order_fields_for_member_and_guest(): void
    {
        $this->override('orderer_email', ['enabled' => false]);
        $this->override('address_detail', ['required' => false]);
        $this->override('shipping_memo', ['required' => true]);
        $this->custom('company', ['required' => true, 'ui' => ['group' => 'orderer']]);
        $this->custom('door_code', ['description' => ['ko' => '배송 기사에게만 보입니다.'], 'sort_order' => 510]);
        $this->custom('hidden_note', ['enabled' => false, 'sort_order' => 520]);

        $version = app(FieldRegistry::class)->version(self::SET);

        // 회원
        $member = $this->createUser();
        $this->cartKey = $this->newCartKey();
        $this->tempOrder($member);
        foreach (['GET' => [], 'PUT' => ['use_points' => 0]] as $method => $body) {
            $response = $this->actingAs($member, 'sanctum')
                ->json($method, self::CHECKOUT_URL, $body, ['X-Cart-Key' => $this->cartKey])
                ->assertOk();

            $fields = $response->json('data.order_fields');
            $this->assertSame($version, $fields['fields_version'], $method);
            $this->assertSame([
                'orderer_email' => ['enabled' => false, 'required' => false],
                'address_detail' => ['enabled' => true, 'required' => false],
                'shipping_memo' => ['enabled' => true, 'required' => true],
            ], $fields['builtin'], $method);
            $this->assertSame(['company'], array_column($fields['orderer'], 'key'), $method);
            $this->assertSame(['door_code'], array_column($fields['shipping'], 'key'), $method);
            $this->assertSame('extra_fields.company', $fields['orderer'][0]['name'], $method);
            $this->assertTrue($fields['orderer'][0]['required'], $method);
            $this->assertSame('builtin', $fields['orderer'][0]['renderer'], $method);
            $this->assertSame('배송 기사에게만 보입니다.', $fields['shipping'][0]['help'], $method);
        }

        // 비회원
        $this->app['auth']->forgetGuards();
        $this->cartKey = $this->newCartKey();
        $this->tempOrder();
        foreach (['GET' => [], 'PUT' => ['use_points' => 0]] as $method => $body) {
            $fields = $this->json($method, self::CHECKOUT_URL, $body, ['X-Cart-Key' => $this->cartKey])
                ->assertOk()
                ->json('data.order_fields');

            $this->assertSame(['enabled' => true, 'required' => true], $fields['builtin']['orderer_email'], $method);
            $this->assertSame(['enabled' => true, 'required' => false], $fields['builtin']['address_detail'], $method);
            $this->assertSame(['company'], array_column($fields['orderer'], 'key'), $method);
        }
    }

    /**
     * 설정을 바꾸지 않은 사이트의 `order_fields` 는 종전 화면과 같은 값이다 (추가 항목 없음).
     *
     * @scenario case=order_fields
     *
     * @effects default_order_fields_match_legacy_screen
     *
     * @return void 반환값 없음
     */
    public function test_default_order_fields_match_legacy_screen(): void
    {
        $member = $this->createUser();

        $payload = app(OrderContactFields::class)->formPayload($member);
        $this->assertSame([
            'orderer_email' => ['enabled' => true, 'required' => false],
            'address_detail' => ['enabled' => true, 'required' => true],
            'shipping_memo' => ['enabled' => true, 'required' => false],
        ], $payload['builtin']);
        $this->assertSame([], $payload['orderer']);
        $this->assertSame([], $payload['shipping']);

        $this->assertSame(['enabled' => true, 'required' => true], app(OrderContactFields::class)->formPayload(null)['builtin']['orderer_email']);
    }

    // ==========================================
    // 훅이 없던 요청 8개의 검증 필터
    // ==========================================

    /**
     * 요청 8개가 `rules()` 끝에서 자기 필터를 부르고(인자: 규칙, 요청), 필터가 더한 규칙이 결과에 들어간다.
     *
     * @scenario case=validation_hooks
     *
     * @effects eight_requests_fire_validation_rules_filter
     *
     * @param  class-string<FormRequest>  $requestClass  요청 클래스
     * @param  string  $hook  필터 이름
     * @return void 반환값 없음
     */
    #[DataProvider('validationHookProvider')]
    public function test_request_fires_its_validation_rules_filter(string $requestClass, string $hook): void
    {
        $calls = [];
        HookManager::addFilter($hook, function ($rules, $request = null) use (&$calls) {
            $calls[] = [$rules, $request];
            $rules['probe_from_extension'] = ['required', 'string'];

            return $rules;
        });

        $request = $requestClass::create('/probe', 'POST', []);
        $request->setContainer($this->app);
        $request->setUserResolver(fn () => null);
        $rules = $request->rules();

        $this->assertCount(1, $calls, $hook.' 필터가 한 번 불려야 한다');
        $this->assertIsArray($calls[0][0]);
        $this->assertNotEmpty($calls[0][0]);
        $this->assertSame($request, $calls[0][1]);
        $this->assertSame(['required', 'string'], $rules['probe_from_extension']);
        // 원래 규칙은 그대로 남는다
        $this->assertSame([], array_diff(array_keys($calls[0][0]), array_keys($rules)));
    }

    /**
     * 필터를 더한 요청 8개.
     *
     * @return array<string, array{0: class-string<FormRequest>, 1: string}> 데이터 묶음
     */
    public static function validationHookProvider(): array
    {
        return [
            '비회원 주문 조회' => [VerifyGuestOrderRequest::class, 'sirsoft-ecommerce.guest_order.verify_validation_rules'],
            '비회원 배송지 수정' => [GuestUpdateShippingAddressRequest::class, 'sirsoft-ecommerce.guest_order.shipping_address_validation_rules'],
            '교환 신청' => [StoreExchangeRequest::class, 'sirsoft-ecommerce.exchange.store_validation_rules'],
            '클레임 수정' => [UpdateClaimRequest::class, 'sirsoft-ecommerce.claim.update_validation_rules'],
            '클레임 미리보기' => [ClaimPreviewRequest::class, 'sirsoft-ecommerce.claim.preview_validation_rules'],
            '관리자 교환 접수' => [AdminStoreExchangeRequest::class, 'sirsoft-ecommerce.admin_exchange.store_validation_rules'],
            '관리자 클레임 대리 수정' => [UpdateClaimRequestOnBehalfRequest::class, 'sirsoft-ecommerce.admin_claim.update_on_behalf_validation_rules'],
            '관리자 주문 수정' => [UpdateOrderRequest::class, 'sirsoft-ecommerce.order.update_validation_rules'],
        ];
    }

    /**
     * 필터가 더한 규칙은 실제 요청 검증에 적용된다 (비회원 주문 조회).
     *
     * @scenario case=validation_hooks
     *
     * @effects filter_added_rule_enforced_on_http_request
     *
     * @return void 반환값 없음
     */
    public function test_filter_added_rule_is_enforced_on_real_request(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.guest_order.verify_validation_rules', function ($rules) {
            $rules['probe_from_extension'] = ['required', 'string'];

            return $rules;
        });

        $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => 'ORD-NOPE',
            'orderer_phone' => '010-0000-0000',
            'guest_lookup_password' => 'guest1234',
        ])->assertStatus(422)->assertJsonValidationErrors(['probe_from_extension']);
    }

    /**
     * 모듈 선언이 집합과 항목 8개를 함께 내놓는다 (집합 없이 항목만 선언되어 동기화에서 건너뛰는 일이 없게).
     *
     * @scenario case=declaration
     *
     * @effects module_declares_set_and_fields_together
     *
     * @return void 반환값 없음
     */
    public function test_module_declares_set_and_fields_together(): void
    {
        $module = new Module;

        $this->assertSame([self::SET], array_column($module->getFieldSets(), 'key'));
        $declared = collect($module->getFields())->where('field_set', self::SET);
        $this->assertCount(8, $declared);
        $this->assertSame(['column'], $declared->pluck('storage')->unique()->values()->all());
        $this->assertSame([], array_diff($declared->pluck('type')->unique()->all(), OrderContactFields::ALLOWED_TYPES));
    }
}
