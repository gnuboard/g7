<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderModificationException;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 회원/비회원 공유 주문 (Public\OrderController) 테스트
 *
 * 이슈 #55 — 회원/비회원 모두 POST /user/orders 단일 endpoint 로 주문을 생성하며,
 * 컨트롤러가 Auth::id() 로 회원/비회원을 분기 처리한다. 본 테스트는 비회원 주문 생성과
 * 비회원 토큰 후속 액션(verify, cancel, 배송지 변경 등) 의 회원 주문 보호/경계를 검증한다.
 *
 * @scenario actor=guest, change_mode=manual, e2e_browser=chromium
 *
 * @effects guest_update_shipping_address_succeeds_with_valid_token,
 *   guest_update_shipping_address_blocked_404_without_or_invalid_token,
 *   guest_update_shipping_address_validation_422_when_recipient_fields_missing
 */
class OrderControllerTest extends ModuleTestCase
{
    protected string $cartKey;

    protected Product $product;

    protected ProductOption $productOption;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cartKey = Str::uuid()->toString();

        $this->product = Product::create([
            'name' => ['ko' => '테스트 상품', 'en' => 'Test Product'],
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
            'option_values' => ['색상' => '검정', '사이즈' => 'M'],
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

    protected function tearDown(): void
    {
        // Feature 테스트가 작성한 마일리지 설정 파일 정리 (다른 테스트 오염 방지)
        $mileageFile = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/mileage.json');
        if (file_exists($mileageFile)) {
            unlink($mileageFile);
        }
        parent::tearDown();
    }

    /**
     * 비회원 임시 주문 생성 헬퍼 (user_id = null, cart_key 기준)
     */
    protected function createGuestTempOrder(): TempOrder
    {
        return TempOrder::create([
            'user_id' => null,
            'cart_key' => $this->cartKey,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_option_id' => $this->productOption->id,
                    'quantity' => 2,
                ],
            ],
            'calculation_result' => [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'product_option_id' => $this->productOption->id,
                        'quantity' => 2,
                        'unit_price' => 15000,
                        'subtotal' => 30000,
                        'final_amount' => 30000,
                    ],
                ],
                'summary' => [
                    'subtotal' => 30000,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => 30000,
                    'final_amount' => 30000,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * 비회원 주문 생성 요청 페이로드
     */
    protected function guestOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'orderer' => [
                'name' => '홍길동',
                'phone' => '010-1234-5678',
                'email' => 'guest@test.com',
            ],
            'shipping' => [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
                'address_detail' => '101동 1001호',
            ],
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'expected_total_amount' => 30000,
            'depositor_name' => '홍길동',
            'dbank' => [
                'bank_code' => 'KB',
                'bank_name' => '국민은행',
                'account_number' => '123-456-789012',
                'account_holder' => '주식회사 테스트',
            ],
            'guest_lookup_password' => 'guest1234',
            'guest_lookup_password_confirmation' => 'guest1234',
        ], $overrides);
    }

    /**
     * 1. 비회원 주문 생성 성공
     */
    public function test_비회원_주문_생성_성공(): void
    {
        $this->createGuestTempOrder();

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->guestOrderPayload(),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'order' => ['order_number', 'order_status'],
                ],
            ]);

        // 비회원 주문은 user_id = null 로 저장된다.
        $this->assertDatabaseHas('ecommerce_orders', [
            'order_number' => $response->json('data.order.order_number'),
            'user_id' => null,
        ]);
    }

    /**
     * 1-1. 주문 완료 이동 주소는 상점 주소 설정을 따른다 (공개 #85)
     *
     * 기본값 상점(`/shop`) · 운영자가 바꾼 주소(`/store`) · 주소 없이 운영(no_route) 3축.
     * 서버가 기본값을 리터럴로 내려보내면 뒤의 두 상점에서는 결제를 마친 손님이
     * 존재하지 않는 페이지로 이동한다.
     *
     * @param  array<string, mixed>  $basicInfo  상점 기본 설정
     */
    #[DataProvider('shopRoutePathProvider')]
    public function test_주문_완료_이동주소가_상점_주소_설정을_따른다(array $basicInfo, string $expectedPrefix): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info', $basicInfo);

        $this->createGuestTempOrder();

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->guestOrderPayload(),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $orderNumber = $response->json('data.order.order_number');
        $this->assertSame(
            "{$expectedPrefix}/orders/{$orderNumber}/complete",
            $response->json('data.redirect_url')
        );
    }

    /**
     * 상점 주소 3축 (기본 / 변경 / 주소 없음)
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function shopRoutePathProvider(): array
    {
        return [
            '기본 상점 주소' => [['route_path' => 'shop'], '/shop'],
            '운영자가 바꾼 주소' => [['route_path' => 'store'], '/store'],
            '주소 없이 운영' => [['route_path' => 'shop', 'no_route' => true], ''],
        ];
    }

    /**
     * 2. 비회원 주문은 is_first_order = false 로 저장된다 (이슈 #55 정책)
     */
    public function test_비회원_주문은_is_first_order_false(): void
    {
        $this->createGuestTempOrder();

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->guestOrderPayload(),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $order = Order::where('order_number', $response->json('data.order.order_number'))->first();
        $this->assertNotNull($order);
        $this->assertFalse((bool) $order->is_first_order);
    }

    /**
     * 3. 비회원 주문 응답에 민감 필드(admin_memo, 내부 스냅샷)가 노출되지 않는다
     */
    public function test_비회원_주문_응답에_민감필드_미노출(): void
    {
        $this->createGuestTempOrder();

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->guestOrderPayload(),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);
        $order = $response->json('data.order');

        $this->assertArrayNotHasKey('admin_memo', $order);
        $this->assertArrayNotHasKey('guest_lookup_password_hash', $order);
        $this->assertArrayNotHasKey('promotions_applied_snapshot', $order);
    }

    /**
     * 4. 비회원 주문 생성 시 조회 비밀번호 누락이면 검증 실패
     */
    public function test_비회원_주문_조회비밀번호_누락_검증실패(): void
    {
        $this->createGuestTempOrder();

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->guestOrderPayload([
                'guest_lookup_password' => null,
                'guest_lookup_password_confirmation' => null,
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(422);
    }

    /**
     * 4-1. 비회원 주문 생성 시 주문자 이메일 누락이면 검증 실패
     *
     * 비회원은 주문 확인/배송/취소 알림을 받을 통로가 주문자 이메일뿐이므로 필수.
     */
    public function test_비회원_주문_이메일_누락_검증실패(): void
    {
        $this->createGuestTempOrder();

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->guestOrderPayload([
                'orderer' => [
                    'name' => '홍길동',
                    'phone' => '010-1234-5678',
                    'email' => '',
                ],
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('orderer.email');
    }

    /**
     * 5. 조회 비밀번호 길이(8자 이상) 미충족 시 검증 실패
     */
    public function test_비회원_조회비밀번호_길이_미충족_검증실패(): void
    {
        $this->createGuestTempOrder();

        // 8자 미만 → min 실패 (G7 회원가입 정책 min:8 과 일치)
        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->guestOrderPayload([
                'guest_lookup_password' => 'ab12',
                'guest_lookup_password_confirmation' => 'ab12',
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(422);
    }

    /**
     * 6. 임시 주문 없이 비회원 주문 생성 시 실패
     */
    public function test_임시주문_없으면_비회원_주문_생성_실패(): void
    {
        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->guestOrderPayload(),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(404);
    }

    /**
     * 7. 비회원은 회원 전용 주문 목록/상세 API에 접근할 수 없다 (401)
     */
    public function test_비회원은_회원_주문목록_ap_i_접근_불가(): void
    {
        $this->getJson('/api/modules/sirsoft-ecommerce/user/orders')
            ->assertStatus(401);
    }

    /**
     * 8. 비회원 비통장 주문 생성 후 임시 주문이 즉시 삭제된다 (non-PG)
     */
    public function test_비회원_무통장_주문_생성후_임시주문_삭제(): void
    {
        $tempOrder = $this->createGuestTempOrder();

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->guestOrderPayload(),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        // 무통장(dbank)은 non-PG → 주문 생성 시점에 임시주문 즉시 삭제
        $this->assertDatabaseMissing('ecommerce_temp_orders', [
            'id' => $tempOrder->id,
        ]);
    }

    /**
     * PG 플러그인이 하는 것과 동일하게 간편결제 수단·PG provider 를 카탈로그에 등록합니다.
     *
     * 확장 결제수단(nhnkcp_naverpay) 이 능력(PG 필요/고정/핸들러)을 갖도록 하여
     * 주문 생성 응답과 임시주문 보존이 실제 계약대로 동작하는지 검증할 수 있게 한다.
     */
    private function registerExtensionEasyPayMethod(): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.settings.filter_available_payment_methods',
            fn (array $methods) => array_merge($methods, [[
                'id' => 'nhnkcp_naverpay',
                'name' => ['ko' => '네이버페이', 'en' => 'Naver Pay'],
                'description' => ['ko' => '', 'en' => ''],
                'icon' => 'credit-card',
                'source' => 'plugin:sirsoft-pay_nhnkcp',
                'defaults' => [
                    'pg_provider' => 'nhnkcp',
                    'pg_locked' => true,
                    'needs_pg' => true,
                    'refund_method' => 'pg',
                    'is_active' => true,
                    'min_order_amount' => 0,
                    'stock_deduction_timing' => 'payment_complete',
                    'mileage_deduction_timing' => 'payment_complete',
                ],
            ]])
        );

        HookManager::addFilter(
            'sirsoft-ecommerce.payment.registered_pg_providers',
            fn (array $providers) => array_merge($providers, [[
                'id' => 'nhnkcp',
                'name' => 'NHN KCP',
                'payment_handler' => 'sirsoft-pay_nhnkcp.requestPayment',
            ]])
        );

        app(PaymentMethodResolver::class)->flushCache();
        app(EcommerceSettingsService::class)->clearCache();
    }

    /**
     * T9 — 간편결제(확장 결제수단) 주문 생성 응답이 PG 결제 계약을 내려준다 (#475).
     *
     * 과거에는 확장 ID 가 PG 결제로 인식되지 않아 응답이 non-PG 로 내려갔고, 프론트가
     * payment_method 를 'card' 로 위장해야 결제창이 떴다. 이제 응답이
     * `requires_pg_payment=true` 와 provider 가 선언한 `pg_payment_handler` 를 실어
     * 템플릿이 위장 없이 표준 dispatch 한다.
     *
     * @scenario method_kind=extension, capability_declared=declared, capability=needs_pg
     *
     * @effects requires_pg_payment_true, pg_payment_handler_present, extension_id_passes_validation
     */
    public function test_간편결제_주문_생성_응답이_p_g_결제_계약을_내려준다(): void
    {
        $this->registerExtensionEasyPayMethod();
        $this->createGuestTempOrder();

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->guestOrderPayload([
                'payment_method' => 'nhnkcp_naverpay',
                // 간편결제는 무통장 정보 불필요.
                'depositor_name' => null,
                'dbank' => null,
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.requires_pg_payment', true)
            ->assertJsonPath('data.pg_payment_handler', 'sirsoft-pay_nhnkcp.requestPayment');

        // 저장된 결제수단이 확장 ID 그대로여야 한다 (card 위장 없음).
        $order = Order::where('order_number', $response->json('data.order.order_number'))->first();
        $this->assertSame('nhnkcp_naverpay', $order->payment->payment_method);
    }

    /**
     * T10 — 간편결제 주문은 PG 결제 주문이므로 임시주문이 보존되어 재결제가 가능하다 (#475).
     *
     * 이슈의 핵심 증상: 결제 실패 후 재시도하면 "임시주문을 찾을 수 없습니다" 로 재결제가
     * 막혔다. 원인은 확장 결제수단이 non-PG 로 오인되어 주문 생성 시점에 TempOrder 가
     * 즉시 삭제된 것. 무통장(위 dbank 테스트)은 삭제가 정상이지만, PG 주문은 결제 완료
     * 콜백 전까지 TempOrder 를 남겨 재결제 진입을 보장해야 한다.
     *
     * @scenario method_kind=extension, capability_declared=declared, capability=needs_pg
     *
     * @effects temp_order_preserved
     */
    public function test_간편결제_주문_생성후_임시주문이_보존된다(): void
    {
        $this->registerExtensionEasyPayMethod();
        $tempOrder = $this->createGuestTempOrder();

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->guestOrderPayload([
                'payment_method' => 'nhnkcp_naverpay',
                'depositor_name' => null,
                'dbank' => null,
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        // 간편결제(PG)는 결제 전 pending → 임시주문이 살아 있어야 재결제가 가능하다.
        $this->assertDatabaseHas('ecommerce_temp_orders', [
            'id' => $tempOrder->id,
        ]);
    }

    /**
     * 9. 비회원 주문 생성 시 조회 비밀번호가 해시로 저장된다 (평문 미저장)
     */
    public function test_비회원_조회비밀번호_해시_저장(): void
    {
        $this->createGuestTempOrder();

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->guestOrderPayload(['guest_lookup_password' => 'guest1234', 'guest_lookup_password_confirmation' => 'guest1234']),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $order = Order::where('order_number', $response->json('data.order.order_number'))->first();
        $this->assertNotNull($order->guest_lookup_password_hash);
        // 평문이 아니라 검증 가능한 해시로 저장됨
        $this->assertNotSame('guest1234', $order->guest_lookup_password_hash);
        $this->assertTrue(Hash::check('guest1234', $order->guest_lookup_password_hash));
    }

    /**
     * 10. 회원 주문은 조회 비밀번호 해시가 null 이다 (회원은 미요구)
     */
    public function test_회원_주문은_조회비밀번호_해시_null(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 회원 임시주문 (user_id 보유)
        TempOrder::create([
            'user_id' => $user->id,
            'cart_key' => $this->cartKey,
            'items' => [[
                'product_id' => $this->product->id,
                'product_option_id' => $this->productOption->id,
                'quantity' => 2,
            ]],
            'calculation_result' => [
                'items' => [[
                    'product_id' => $this->product->id,
                    'product_option_id' => $this->productOption->id,
                    'quantity' => 2,
                    'unit_price' => 15000,
                    'subtotal' => 30000,
                    'final_amount' => 30000,
                ]],
                'summary' => [
                    'subtotal' => 30000,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => 30000,
                    'final_amount' => 30000,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);

        // 회원은 user/orders 경로 사용 (조회 비밀번호 미전송)
        $payload = $this->guestOrderPayload();
        unset($payload['guest_lookup_password'], $payload['guest_lookup_password_confirmation']);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $payload,
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest('id')->first();
        $this->assertNull($order->guest_lookup_password_hash);
    }

    /**
     * 문자열로 저장된 auto_cancel_days 설정 파일을 만들고, 테스트 후 정리합니다.
     *
     * 관리자 화면(HTML number 입력)을 한 번 저장하면 실제로 이 형태로 영속된다.
     *
     * @param  string  $storedValue  저장 형태 그대로의 값
     * @return callable 정리 콜백
     */
    private function withStringAutoCancelDays(string $storedValue): callable
    {
        $settingsDir = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($settingsDir)) {
            mkdir($settingsDir, 0755, true);
        }

        $path = $settingsDir.'/order_settings.json';
        file_put_contents($path, json_encode(['auto_cancel_days' => $storedValue]));

        return function () use ($path) {
            if (file_exists($path)) {
                unlink($path);
            }
        };
    }

    /**
     * 10-A. 입금기한 설정이 문자열로 저장되어 있어도 비회원 무통장 주문이 생성된다
     *
     * 수정 전: Carbon 이 strict 타입 경계라 문자열을 받으면 TypeError → 주문 생성 500.
     *
     * @effects order_creation_succeeds_with_string_setting
     */
    public function test_비회원_무통장_주문은_입금기한_설정이_문자열이어도_생성된다(): void
    {
        $cleanup = $this->withStringAutoCancelDays('5');

        try {
            $this->createGuestTempOrder();

            $response = $this->postJson(
                '/api/modules/sirsoft-ecommerce/user/orders',
                $this->guestOrderPayload(),
                ['X-Cart-Key' => $this->cartKey]
            );

            $response->assertStatus(201);

            $order = Order::latest('id')->first();
            $this->assertNotNull($order->payment->deposit_due_at);
            $this->assertSame(
                now()->addDays(5)->toDateString(),
                $order->payment->deposit_due_at->toDateString(),
            );
        } finally {
            $cleanup();
        }
    }

    /**
     * 10-B. 입금기한 설정이 문자열로 저장되어 있어도 회원 무통장 주문이 생성된다
     *
     * @effects order_creation_succeeds_with_string_setting
     */
    public function test_회원_무통장_주문은_입금기한_설정이_문자열이어도_생성된다(): void
    {
        $cleanup = $this->withStringAutoCancelDays('5');

        try {
            $user = $this->createUser();
            $this->actingAs($user);

            TempOrder::create([
                'user_id' => $user->id,
                'cart_key' => $this->cartKey,
                'items' => [[
                    'product_id' => $this->product->id,
                    'product_option_id' => $this->productOption->id,
                    'quantity' => 2,
                ]],
                'calculation_result' => [
                    'items' => [[
                        'product_id' => $this->product->id,
                        'product_option_id' => $this->productOption->id,
                        'quantity' => 2,
                        'unit_price' => 15000,
                        'subtotal' => 30000,
                        'final_amount' => 30000,
                    ]],
                    'summary' => [
                        'subtotal' => 30000,
                        'total_discount' => 0,
                        'total_shipping' => 0,
                        'payment_amount' => 30000,
                        'final_amount' => 30000,
                    ],
                ],
                'expires_at' => now()->addMinutes(30),
            ]);

            $payload = $this->guestOrderPayload();
            unset($payload['guest_lookup_password'], $payload['guest_lookup_password_confirmation']);

            $this->postJson(
                '/api/modules/sirsoft-ecommerce/user/orders',
                $payload,
                ['X-Cart-Key' => $this->cartKey]
            )->assertStatus(201);

            $order = Order::where('user_id', $user->id)->latest('id')->first();
            $this->assertSame(
                now()->addDays(5)->toDateString(),
                $order->payment->deposit_due_at->toDateString(),
            );
        } finally {
            $cleanup();
        }
    }

    /**
     * 10-C. 입금기한 설정이 문자열이어도 가상계좌(vbank) 주문이 생성된다
     *
     * dbank 와 같은 산정 경로(auto_cancel_days)를 쓰지만 기록 컬럼(`vbank_due_at`)이 달라
     * HTTP 레벨에서도 따로 고정한다.
     *
     * @effects order_creation_succeeds_with_string_setting
     */
    public function test_비회원_가상계좌_주문은_입금기한_설정이_문자열이어도_생성된다(): void
    {
        $cleanup = $this->withStringAutoCancelDays('5');

        try {
            $this->createGuestTempOrder();

            $payload = $this->guestOrderPayload([
                'payment_method' => PaymentMethodEnum::VBANK->value,
            ]);
            unset($payload['dbank']);

            $response = $this->postJson(
                '/api/modules/sirsoft-ecommerce/user/orders',
                $payload,
                ['X-Cart-Key' => $this->cartKey]
            );

            $response->assertStatus(201);

            $order = Order::latest('id')->first();
            $this->assertNotNull($order->payment->vbank_due_at);
            $this->assertSame(
                now()->addDays(5)->toDateString(),
                $order->payment->vbank_due_at->toDateString(),
            );
        } finally {
            $cleanup();
        }
    }

    /**
     * 10-1. 회원 주문은 주문자 이메일이 없어도 생성된다 (이메일 필수는 비회원 한정)
     */
    public function test_회원_주문은_이메일_없어도_생성_성공(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        TempOrder::create([
            'user_id' => $user->id,
            'cart_key' => $this->cartKey,
            'items' => [[
                'product_id' => $this->product->id,
                'product_option_id' => $this->productOption->id,
                'quantity' => 2,
            ]],
            'calculation_result' => [
                'items' => [[
                    'product_id' => $this->product->id,
                    'product_option_id' => $this->productOption->id,
                    'quantity' => 2,
                    'unit_price' => 15000,
                    'subtotal' => 30000,
                    'final_amount' => 30000,
                ]],
                'summary' => [
                    'subtotal' => 30000,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => 30000,
                    'final_amount' => 30000,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);

        // 회원은 조회 비밀번호 미전송 + 이메일 빈 값으로도 주문 가능해야 한다.
        $payload = $this->guestOrderPayload([
            'orderer' => [
                'name' => '홍길동',
                'phone' => '010-1234-5678',
                'email' => '',
            ],
        ]);
        unset($payload['guest_lookup_password'], $payload['guest_lookup_password_confirmation']);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $payload,
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);
    }

    /**
     * 전액 마일리지 결제(결제액 0원): 응답에 requires_pg_payment=false, 주문은 즉시 결제완료.
     *
     * 결제수단(card)을 선택했더라도 결제할 금액이 0원이면 PG 호출 없이 통과해야 한다.
     * 추후 예치금 등 다른 비현금 충당이 추가되어도 동일하게 동작한다(판정 기준 = total_due_amount).
     */
    public function test_전액_마일리지_결제는_p_g_없이_결제완료(): void
    {
        $this->enableMileageForFeature();

        $user = $this->createUser();
        $this->actingAs($user);

        // 결제액 전액(30,000) 충당용 마일리지 잔액 시드
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => 'purchase_earn',
            'amount' => 30000,
            'remaining_amount' => 30000,
            'balance_after' => 30000,
        ]);

        TempOrder::create([
            'user_id' => $user->id,
            'cart_key' => $this->cartKey,
            'items' => [[
                'product_id' => $this->product->id,
                'product_option_id' => $this->productOption->id,
                'quantity' => 2,
            ]],
            'calculation_input' => [
                'items' => [[
                    'product_id' => $this->product->id,
                    'product_option_id' => $this->productOption->id,
                    'quantity' => 2,
                ]],
                'use_points' => 30000,
            ],
            'calculation_result' => [
                'items' => [[
                    'product_id' => $this->product->id,
                    'product_option_id' => $this->productOption->id,
                    'quantity' => 2,
                    'unit_price' => 15000,
                    'subtotal' => 30000,
                    'final_amount' => 0,
                ]],
                'summary' => [
                    'subtotal' => 30000,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => 30000,
                    'points_used' => 30000,
                    'final_amount' => 0,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);

        $payload = $this->guestOrderPayload([
            'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => ''],
            'payment_method' => PaymentMethodEnum::CARD->value,
            'expected_total_amount' => 0,
        ]);
        unset(
            $payload['guest_lookup_password'],
            $payload['guest_lookup_password_confirmation'],
            $payload['depositor_name'],
            $payload['dbank']
        );

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $payload,
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);
        // PG 호출 불필요 — 결제할 금액이 0원
        $response->assertJsonPath('data.requires_pg_payment', false);

        $order = Order::where('user_id', $user->id)->latest('id')->first();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(0, (int) $order->total_due_amount);
        $this->assertEquals(30000, (int) $order->total_points_used_amount);
    }

    /**
     * 마일리지 기능을 활성화하는 Feature 테스트용 헬퍼 (결제액 100% 사용 허용).
     */
    protected function enableMileageForFeature(): void
    {
        $settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($settingsPath)) {
            mkdir($settingsPath, 0755, true);
        }
        file_put_contents(
            $settingsPath.'/mileage.json',
            json_encode([
                'enabled' => true,
                'default_earn_rate' => 0,
                'earn_trigger' => 'confirmed',
                'earn_delay_days' => 0,
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
                ],
                'expiry_enabled' => false,
                'expiry_days' => 365,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 11. 비회원 주문 응답에 조회 비밀번호 해시가 노출되지 않는다 (모델 $hidden)
     */
    public function test_비회원_주문_응답에_해시_미노출(): void
    {
        $order = Order::factory()->forGuest()->create();

        // 모델 직렬화 시 hidden 처리 확인 (API 응답 노출 차단의 1차 방어선)
        $this->assertArrayNotHasKey('guest_lookup_password_hash', $order->toArray());
    }

    /**
     * 비회원 주문을 생성하고 그 주문번호를 반환하는 헬퍼
     */
    private function placeGuestOrder(string $password = 'guest1234', string $phone = '010-1234-5678'): string
    {
        $this->createGuestTempOrder();

        $payload = $this->guestOrderPayload([
            'guest_lookup_password' => $password,
            'guest_lookup_password_confirmation' => $password,
        ]);
        $payload['orderer']['phone'] = $phone;
        $payload['shipping']['recipient_phone'] = $phone;

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $payload,
            ['X-Cart-Key' => $this->cartKey]
        );
        $response->assertStatus(201);

        return $response->json('data.order.order_number');
    }

    /**
     * 12. 비회원 조회 인증 성공 시 토큰 발급
     */
    public function test_비회원_조회_인증_성공_토큰_발급(): void
    {
        $orderNumber = $this->placeGuestOrder();

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => $orderNumber,
            'orderer_phone' => '010-1234-5678',
            'guest_lookup_password' => 'guest1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['guest_order_token', 'expires_at', 'order' => ['order_number']],
            ]);
    }

    /**
     * 13. 비밀번호 오류 시 "주문을 찾을 수 없습니다" (404 단일 응답)
     */
    public function test_비회원_조회_비밀번호_오류_404(): void
    {
        $orderNumber = $this->placeGuestOrder();

        $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => $orderNumber,
            'orderer_phone' => '010-1234-5678',
            'guest_lookup_password' => 'wrong99',
        ])->assertStatus(404);
    }

    /**
     * 14. 존재하지 않는 주문번호도 동일한 404 (존재 여부 비노출)
     */
    public function test_비회원_조회_존재하지_않는_주문_404(): void
    {
        $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => 'NO_SUCH_ORDER',
            'orderer_phone' => '010-1234-5678',
            'guest_lookup_password' => 'guest1234',
        ])->assertStatus(404);
    }

    /**
     * 15. 전화번호 불일치 시 404 (정규화 후에도 다른 번호)
     */
    public function test_비회원_조회_전화번호_불일치_404(): void
    {
        $orderNumber = $this->placeGuestOrder();

        $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => $orderNumber,
            'orderer_phone' => '010-9999-8888',
            'guest_lookup_password' => 'guest1234',
        ])->assertStatus(404);
    }

    /**
     * 16. 조회 인증 요청 필수 필드 누락 시 422
     */
    public function test_비회원_조회_필수필드_누락_422(): void
    {
        $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => 'ORD-1',
        ])->assertStatus(422);
    }

    /**
     * 주문 생성 + 조회 인증으로 토큰까지 발급받아 [주문번호, 토큰]을 반환하는 헬퍼
     */
    private function placeGuestOrderAndToken(string $password = 'guest1234', string $phone = '010-1234-5678'): array
    {
        $orderNumber = $this->placeGuestOrder($password, $phone);

        $verify = $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => $orderNumber,
            'orderer_phone' => $phone,
            'guest_lookup_password' => $password,
        ]);
        $verify->assertStatus(200);

        return [$orderNumber, $verify->json('data.guest_order_token')];
    }

    /**
     * 17. 토큰 없이 상세 조회 시 차단 (404)
     */
    public function test_비회원_상세_토큰_없으면_차단(): void
    {
        [$orderNumber] = $this->placeGuestOrderAndToken();

        $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$orderNumber}")
            ->assertStatus(404);
    }

    /**
     * 18. 토큰으로 상세 조회 성공
     */
    public function test_비회원_상세_토큰_조회_성공(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();

        $response = $this->getJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$orderNumber}",
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.order_number', $orderNumber);
    }

    /**
     * 19. 비회원 상세 응답에 민감 필드가 노출되지 않는다
     */
    public function test_비회원_상세_민감필드_미노출(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();

        $response = $this->getJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$orderNumber}",
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(200);
        $data = $response->json('data');

        // 회원 정보·관리자 메모·내부 스냅샷·해시는 비회원 상세에 노출 금지
        $this->assertArrayNotHasKey('admin_memo', $data);
        $this->assertArrayNotHasKey('customer_memo', $data);
        $this->assertArrayNotHasKey('user', $data);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('promotions_applied_snapshot', $data);
        $this->assertArrayNotHasKey('guest_lookup_password_hash', $data);
    }

    /**
     * 20. 다른 주문 토큰으로 접근 차단 (404)
     */
    public function test_비회원_상세_다른주문_토큰_차단(): void
    {
        [, $token] = $this->placeGuestOrderAndToken();

        // 토큰과 다른 주문번호로 접근
        $this->getJson(
            '/api/modules/sirsoft-ecommerce/user/orders/OTHER_ORDER',
            ['X-Guest-Order-Token' => $token]
        )->assertStatus(404);
    }

    /**
     * 20-a. 회원/비회원 통합 라우트 — 회원이 본인 주문 진입 시 OrderResource 반환 (200)
     *
     * user/orders/{orderNumber} 가 optional.sanctum 으로 회원/비회원 모두 받게 통합된 후,
     * 회원이 본인 회원 주문을 조회하면 기존과 동일하게 OrderResource 가 반환되어야 한다.
     */
    public function test_회원_상세_본인_회원주문_조회_성공(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 회원 임시주문 (test_회원_주문은_조회비밀번호_해시_null 과 동일 패턴)
        TempOrder::create([
            'user_id' => $user->id,
            'cart_key' => $this->cartKey,
            'items' => [[
                'product_id' => $this->product->id,
                'product_option_id' => $this->productOption->id,
                'quantity' => 2,
            ]],
            'calculation_result' => [
                'items' => [[
                    'product_id' => $this->product->id,
                    'product_option_id' => $this->productOption->id,
                    'quantity' => 2,
                    'unit_price' => 15000,
                    'subtotal' => 30000,
                    'final_amount' => 30000,
                ]],
                'summary' => [
                    'subtotal' => 30000,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => 30000,
                    'final_amount' => 30000,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);

        // 회원 주문 생성 (조회 비밀번호 미요구)
        $payload = $this->guestOrderPayload();
        unset($payload['guest_lookup_password'], $payload['guest_lookup_password_confirmation']);

        $createResponse = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $payload,
            ['X-Cart-Key' => $this->cartKey]
        );
        $createResponse->assertStatus(201);
        $orderNumber = $createResponse->json('data.order.order_number');

        // 본인 주문 조회 → 200 + OrderResource (회원 분기 동작 확인)
        $detail = $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$orderNumber}");
        $detail->assertStatus(200)
            ->assertJsonPath('data.order_number', $orderNumber);

        $data = $detail->json('data');

        // 회원 응답은 OrderResource — user 필드가 포함되어 비회원 응답(GuestOrderResource)과 구분됨
        $this->assertArrayHasKey('user', $data);

        // 회귀 차단: 회원 분기도 getDetail() 로 풀로드되어 shippings/shipping_address 가 응답에 포함되어야 한다.
        // (이전 회귀: getByOrderNumber() 만 호출 시 whenLoaded 가 빈 응답 → 화면에 배송 메모/배송 현황 미표시)
        $this->assertArrayHasKey('shippings', $data, '회원 응답에 shippings 누락 — getByOrderNumber 만 호출 시 whenLoaded 가 빈 응답');
        $this->assertIsArray($data['shippings']);
        $this->assertArrayHasKey('shipping_address', $data, '회원 응답에 shipping_address 누락');
    }

    /**
     * 20-b. 회원/비회원 통합 라우트 — 회원이 타인 주문 진입 시 404
     *
     * Auth::check() 분기가 user_id 일치를 검사하므로, 타인 회원 주문이나 비회원 주문 모두
     * 본인 것이 아니면 404 로 차단되어야 한다 (정보 노출 방지).
     */
    public function test_회원_상세_타인_주문_차단(): void
    {
        // 비회원 주문 1건 생성 (user_id = null)
        $guestOrderNumber = $this->placeGuestOrder();

        // 다른 회원으로 로그인
        $member = $this->createUser();
        $this->actingAs($member);

        // 본인이 아닌 비회원 주문 진입 → 404 + errors.redirect_to = '/mypage/orders'
        // 프론트 errorHandling 이 단일 sequence 로 백엔드 redirect_to 를 사용해 분기 없이 이동.
        $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$guestOrderNumber}")
            ->assertStatus(404)
            ->assertJsonPath('errors.redirect_to', '/mypage/orders');
    }

    /**
     * 20-c. 회원이 비회원 토큰을 들고 본인 비회원 주문 진입 — 회원 분기 우선으로 404
     *
     * 로그인 시 토큰을 자동 정리하는 프론트 규약이 있지만, 백엔드는 회원 분기를 우선해 토큰을 무시한다.
     * 회원 컨텍스트에서 비회원 주문(user_id NULL)은 본인 주문 아니므로 404. 토큰이 매칭되어도 200 으로
     * 전환되지 않아야 한다 (회원/비회원 컨텍스트 분리 보안 원칙).
     */
    public function test_회원_상세_비회원토큰_있어도_본인주문_아니면_404(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();

        // 다른 회원으로 로그인
        $member = $this->createUser();
        $this->actingAs($member);

        // 비회원 토큰을 들고 회원으로 진입해도 회원 분기가 우선
        // 응답은 회원 redirect_to (/mypage/orders) 로 반환되어 프론트가 마이페이지로 안내
        $this->getJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$orderNumber}",
            ['X-Guest-Order-Token' => $token]
        )
            ->assertStatus(404)
            ->assertJsonPath('errors.redirect_to', '/mypage/orders');
    }

    /**
     * 20-d. 비회원이 토큰 없이 통합 라우트 진입 시 errors.redirect_to = '/shop/guest/orders'
     *
     * 토큰 부재/만료/위조 모두 동일한 응답 — 프론트가 단일 sequence (clearGuestOrderToken → toast → navigate) 로
     * 비회원 조회 폼으로 이동. 프론트의 _global.shopBase 평가 타이밍 의존성을 제거하기 위해
     * 백엔드가 redirect_to 를 명시적으로 전송한다 (회원 분기 /mypage/orders 와 일관된 패턴).
     */
    public function test_비회원_상세_토큰_없으면_404_및_lookup_redirect(): void
    {
        [$orderNumber] = $this->placeGuestOrderAndToken();

        $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$orderNumber}")
            ->assertStatus(404)
            ->assertJsonPath('errors.redirect_to', '/shop/guest/orders');
    }

    /**
     * 20-1. 상점 주소를 바꾼 상점에서는 비회원 조회 안내도 그 주소를 가리킨다 (공개 #85)
     *
     * 기본값 `/shop` 을 리터럴로 내려보내면 주소를 바꾼 상점에서는 존재하지 않는 화면으로
     * 안내한다. 서버는 문자열을 만들어 보냈을 뿐이라 예외도 404 로그도 남지 않는다.
     */
    public function test_상점_주소_변경시_비회원_lookup_redirect도_따라간다(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info', ['route_path' => 'store']);

        [$orderNumber] = $this->placeGuestOrderAndToken();

        $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$orderNumber}")
            ->assertStatus(404)
            ->assertJsonPath('errors.redirect_to', '/store/guest/orders');
    }

    /**
     * 20-2. 주소 없이 운영하는 상점(no_route)에서는 세그먼트 없이 루트에 붙는다 (공개 #85)
     */
    public function test_주소_없는_상점은_비회원_lookup_redirect가_루트_경로다(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info', [
            'route_path' => 'shop',
            'no_route' => true,
        ]);

        [$orderNumber] = $this->placeGuestOrderAndToken();

        $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$orderNumber}")
            ->assertStatus(404)
            ->assertJsonPath('errors.redirect_to', '/guest/orders');
    }

    /**
     * 21. 토큰으로 주문 취소 성공 (결제완료 상태)
     */
    public function test_비회원_주문_취소_성공(): void
    {
        // 무통장(dbank)은 PENDING_PAYMENT 로 생성되므로, 취소 가능 상태로 만들기 위해
        // 결제완료 처리 후 토큰을 발급받는다.
        $orderNumber = $this->placeGuestOrder();
        $order = Order::where('order_number', $orderNumber)->first();
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $verify = $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => $orderNumber,
            'orderer_phone' => '010-1234-5678',
            'guest_lookup_password' => 'guest1234',
        ]);
        $verify->assertStatus(200);
        $token = $verify->json('data.guest_order_token');

        $reason = ClaimReason::where('type', 'refund')
            ->where('is_active', true)
            ->where('is_user_selectable', true)
            ->value('code');

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/cancel",
            ['reason' => $reason],
            ['X-Guest-Order-Token' => $token]
        );

        // 취소 사유 데이터가 없으면 422(검증), 있으면 200 — 둘 다 토큰 인증은 통과한 상태
        $this->assertContains($response->status(), [200, 422]);
        $this->assertNotEquals(404, $response->status());
    }

    /**
     * 22. 비회원 토큰으로 배송지 변경 성공 (배송 전 상태)
     *
     * 비회원은 저장된 주소(address_id) 없이 직접 입력한 배송지 필드를 전송한다.
     * 회원과 동일한 OrderService::updateShippingAddress 로 처리되며, 응답은 GuestOrderResource.
     *
     * @scenario actor=guest, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects guest_update_shipping_address_succeeds_with_valid_token
     */
    public function test_비회원_배송지_변경_성공(): void
    {
        $orderNumber = $this->placeGuestOrder();
        $order = Order::where('order_number', $orderNumber)->first();
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $verify = $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => $orderNumber,
            'orderer_phone' => '010-1234-5678',
            'guest_lookup_password' => 'guest1234',
        ]);
        $verify->assertStatus(200);
        $token = $verify->json('data.guest_order_token');

        $response = $this->putJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/shipping-address",
            [
                'recipient_name' => '변경수령인',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '06236',
                'address' => '서울 강남구 테헤란로 1',
                'address_detail' => '10층',
            ],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(200);

        $order->refresh()->load('addresses');
        $shipping = $order->addresses->firstWhere('address_type', 'shipping');
        $this->assertSame('변경수령인', $shipping->recipient_name);
        $this->assertSame('010-9999-8888', $shipping->recipient_phone);
    }

    /**
     * 23. 토큰 없이 배송지 변경 시 미들웨어가 차단 (404 — 정보 노출 차단 정책)
     *
     * VerifyGuestOrderToken 은 토큰 부재/만료/위조를 모두 동일한 404(order_not_found)로 처리해
     * 주문 존재 여부를 노출하지 않는다 (조회/취소 등 다른 비회원 액션과 일관).
     */
    public function test_비회원_배송지_변경_토큰_없으면_차단(): void
    {
        $orderNumber = $this->placeGuestOrder();

        $response = $this->putJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/shipping-address",
            [
                'recipient_name' => '변경수령인',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '06236',
                'address' => '서울 강남구 테헤란로 1',
            ]
        );

        $response->assertStatus(404);
    }

    /**
     * 24. 잘못된(위조) 토큰으로 배송지 변경 시 차단 (404)
     */
    public function test_비회원_배송지_변경_잘못된_토큰_차단(): void
    {
        $orderNumber = $this->placeGuestOrder();

        $response = $this->putJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/shipping-address",
            [
                'recipient_name' => '변경수령인',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '06236',
                'address' => '서울 강남구 테헤란로 1',
            ],
            ['X-Guest-Order-Token' => 'invalid-token-xxx']
        );

        $response->assertStatus(404);
    }

    /**
     * 25. 토큰은 유효하나 받는분/연락처 누락 시 검증 실패 (422)
     */
    public function test_비회원_배송지_변경_필수필드_누락_검증실패(): void
    {
        $orderNumber = $this->placeGuestOrder();
        $order = Order::where('order_number', $orderNumber)->first();
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $verify = $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => $orderNumber,
            'orderer_phone' => '010-1234-5678',
            'guest_lookup_password' => 'guest1234',
        ]);
        $verify->assertStatus(200);
        $token = $verify->json('data.guest_order_token');

        $response = $this->putJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/shipping-address",
            [
                // recipient_name / recipient_phone 누락
                'zipcode' => '06236',
                'address' => '서울 강남구 테헤란로 1',
            ],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_name', 'recipient_phone']);
    }

    /**
     * 26. 비회원 환불 예상 — 토큰 없으면 미들웨어가 404 차단
     */
    public function test_비회원_환불예상_토큰_없으면_차단(): void
    {
        [$orderNumber] = $this->placeGuestOrderAndToken();

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/estimate-refund",
            ['items' => [['order_option_id' => 1, 'cancel_quantity' => 1]]]
            // X-Guest-Order-Token 헤더 없음
        );

        $response->assertStatus(404);
    }

    /**
     * 27. 비회원 환불 예상 — 위조 토큰도 404 (정상 토큰과 동일 응답으로 정보 노출 차단)
     */
    public function test_비회원_환불예상_위조토큰_차단(): void
    {
        [$orderNumber] = $this->placeGuestOrderAndToken();

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/estimate-refund",
            ['items' => [['order_option_id' => 1, 'cancel_quantity' => 1]]],
            ['X-Guest-Order-Token' => 'invalid-token-xxx']
        );

        $response->assertStatus(404);
    }

    /**
     * 28. 비회원 구매확정 — 토큰 없으면 미들웨어가 404 차단
     */
    public function test_비회원_구매확정_토큰_없으면_차단(): void
    {
        [$orderNumber] = $this->placeGuestOrderAndToken();

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/options/1/confirm"
            // X-Guest-Order-Token 헤더 없음
        );

        $response->assertStatus(404);
    }

    /**
     * 29. 비회원 구매확정 — 위조 토큰도 404
     */
    public function test_비회원_구매확정_위조토큰_차단(): void
    {
        [$orderNumber] = $this->placeGuestOrderAndToken();

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/options/1/confirm",
            [],
            ['X-Guest-Order-Token' => 'invalid-token-xxx']
        );

        $response->assertStatus(404);
    }

    /**
     * 30. verify 라우트 throttle:20,1 — 분당 20회 초과 시 429
     *
     * 잠금 정책을 단순화하면서 유일한 무차별 대입 방어선이 라우트 throttle 이므로
     * 회귀 가드로 검증한다. 라우트 정의에서 throttle 미들웨어가 사라지면 본 테스트가 실패한다.
     */
    public function test_verify_throttle_20회_초과시_429(): void
    {
        $payload = [
            'order_number' => 'NO_SUCH_ORDER',
            'orderer_phone' => '010-1234-5678',
            'guest_lookup_password' => 'guest1234',
        ];

        // 첫 20 회는 throttle 통과 (전부 404 — 주문 없음)
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', $payload)
                ->assertStatus(404);
        }

        // 21회째는 throttle 발화 → 429
        $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', $payload)
            ->assertStatus(429);
    }

    // ================================================================
    // 비회원 배송지 국내/해외 조합 정합성 (F3)
    // ================================================================

    /**
     * 비회원 배송지: country_code 없이 해외 주소 조합만 보내면 검증으로 차단된다.
     *
     * 수정 전에는 검증을 통과해 서비스가 국내 분기로 떨어졌고, NOT NULL 인 zipcode 에
     * null 을 쓰다 SQL 23000 이 발생해 "배송 전에만 변경 가능" 메시지로 위장됐다.
     *
     * @scenario actor=guest, change_mode=manual, address_region=intl_without_country_code, e2e_browser=chromium
     *
     * @effects guest_shipping_address_intl_combination_without_country_code_returns_422_validation_errors
     */
    public function test_비회원_배송지_해외조합_country_code_누락시_검증차단(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();
        Order::where('order_number', $orderNumber)
            ->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $response = $this->putJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/shipping-address",
            [
                'recipient_name' => '김해외',
                'recipient_phone' => '010-9999-8888',
                'address_line_1' => '1600 Amphitheatre Parkway',
                'intl_city' => 'Mountain View',
                'intl_postal_code' => '94043',
            ],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['zipcode', 'address']);
    }

    /**
     * 비회원 배송지: country_code 를 해외로 명시하면 해외 컬럼이 기록되고 국내 컬럼은 초기화된다.
     *
     * @scenario actor=guest, change_mode=manual, address_region=intl, e2e_browser=chromium
     *
     * @effects guest_shipping_address_switching_to_intl_clears_domestic_columns
     */
    public function test_비회원_배송지_해외전환시_국내컬럼_초기화(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();
        $order = Order::where('order_number', $orderNumber)->first();
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $response = $this->putJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/shipping-address",
            [
                'recipient_name' => '김해외',
                'recipient_phone' => '010-9999-8888',
                'country_code' => 'US',
                'address_line_1' => '1600 Amphitheatre Parkway',
                'intl_city' => 'Mountain View',
                'intl_state' => 'CA',
                'intl_postal_code' => '94043',
            ],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(200);

        $shipping = $order->refresh()->load('addresses')
            ->addresses->firstWhere('address_type', 'shipping');

        $this->assertSame('US', $shipping->recipient_country_code);
        $this->assertSame('1600 Amphitheatre Parkway', $shipping->address_line_1);
        $this->assertSame('Mountain View', $shipping->intl_city);
        $this->assertSame('', $shipping->zipcode);
        $this->assertSame('', $shipping->address);
    }

    /**
     * 비회원 배송지: country_code 를 국내로 명시하면 국내 필수값이 강제된다.
     *
     * 회원 경로에만 있던 축이다 — F3 의 원 재현 경로가 비회원이었으므로 같은 폭으로 고정한다.
     *
     * @scenario actor=guest, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects guest_shipping_address_domestic_country_code_requires_zipcode_and_address
     */
    public function test_비회원_배송지_국내_country_code_는_zipcode_와_address_를_요구한다(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();
        $order = Order::where('order_number', $orderNumber)->first();
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $response = $this->putJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/shipping-address",
            [
                'recipient_name' => '김국내',
                'recipient_phone' => '010-9999-8888',
                'country_code' => 'KR',
                'address_line_1' => '1600 Amphitheatre Parkway',
            ],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['zipcode', 'address']);
    }

    /**
     * 비회원 배송지: country_code 가 해외인데 해외 필수값이 비면 검증으로 차단된다.
     *
     * 국내 방향(위)만 고정하면 게이트가 한쪽으로만 서 있는지 드러나지 않는다.
     * 회원 경로에는 이 역방향 짝이 있었고 비회원 경로에만 없었다.
     *
     * @scenario actor=guest, change_mode=manual, address_region=intl, e2e_browser=chromium
     *
     * @effects guest_shipping_address_foreign_country_code_requires_intl_fields
     */
    public function test_비회원_배송지_해외_country_code_는_해외_필수값을_요구한다(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();
        $order = Order::where('order_number', $orderNumber)->first();
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $response = $this->putJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/shipping-address",
            [
                'recipient_name' => '김해외',
                'recipient_phone' => '010-9999-8888',
                'country_code' => 'US',
            ],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['address_line_1', 'intl_city', 'intl_postal_code']);
    }

    /**
     * 비회원 배송지: 국내 → 해외 → 국내 왕복 시 반대편 컬럼이 매번 초기화된다.
     *
     * 한 방향만 검사하면 되돌아온 뒤 해외 컬럼이 잔존해 배송지가 두 벌로 남는 결함을 놓친다.
     *
     * @scenario actor=guest, change_mode=manual, address_region=intl, e2e_browser=chromium
     *
     * @effects guest_shipping_address_round_trip_between_domestic_and_intl_clears_opposite_columns
     */
    public function test_비회원_배송지_국내_해외_왕복시_반대편_컬럼_초기화(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();
        $order = Order::where('order_number', $orderNumber)->first();
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $url = "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/shipping-address";
        $headers = ['X-Guest-Order-Token' => $token];

        // ① 해외로 전환
        $this->putJson($url, [
            'recipient_name' => '김해외',
            'recipient_phone' => '010-9999-8888',
            'country_code' => 'US',
            'address_line_1' => '1600 Amphitheatre Parkway',
            'intl_city' => 'Mountain View',
            'intl_state' => 'CA',
            'intl_postal_code' => '94043',
        ], $headers)->assertStatus(200);

        // ② 다시 국내로 전환
        $this->putJson($url, [
            'recipient_name' => '김국내',
            'recipient_phone' => '010-1111-2222',
            'country_code' => 'KR',
            'zipcode' => '06134',
            'address' => '서울특별시 강남구 테헤란로 123',
            'address_detail' => '4층',
        ], $headers)->assertStatus(200);

        $shipping = $order->refresh()->load('addresses')
            ->addresses->firstWhere('address_type', 'shipping');

        $this->assertSame('KR', $shipping->recipient_country_code);
        $this->assertSame('06134', $shipping->zipcode);
        $this->assertSame('서울특별시 강남구 테헤란로 123', $shipping->address);

        $this->assertNull($shipping->address_line_1, '국내로 돌아오면 해외 주소가 남으면 안 됩니다.');
        $this->assertNull($shipping->intl_city);
        $this->assertNull($shipping->intl_state);
        $this->assertNull($shipping->intl_postal_code);
    }

    /**
     * 비회원 배송지 변경: 도메인 예외는 기존 422 + 안내 문구를 유지한다.
     *
     * @scenario actor=guest, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects guest_update_shipping_address_domain_exception_returns_422_infra_returns_500
     */
    public function test_비회원_배송지_도메인예외_422(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();
        $order = Order::where('order_number', $orderNumber)->first();
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $this->mock(OrderService::class, function ($mock) {
            $mock->shouldReceive('updateShippingAddress')
                ->andThrow(new OrderModificationException('배송 전 상태에서만 변경 가능'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->putJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/shipping-address",
            [
                'recipient_name' => '변경수령인',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '06236',
                'address' => '서울 강남구 테헤란로 1',
            ],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(422);
        $this->assertSame(
            __('sirsoft-ecommerce::messages.orders.cannot_modify_address'),
            $response->json('message')
        );
    }

    /**
     * 비회원 배송지 변경: 인프라 예외는 500 이며 예외 원문을 노출하지 않는다.
     *
     * 회원 경로(User\OrderController)와 쌍둥이 코드라 한쪽만 고치면 비회원 화면에서만
     * 장애가 "배송 전에만 변경 가능" 이라는 엉뚱한 입력 오류로 계속 위장된다.
     *
     * @scenario actor=guest, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects guest_update_shipping_address_domain_exception_returns_422_infra_returns_500, guest_action_5xx_responses_exclude_exception_message
     */
    public function test_비회원_배송지_인프라예외_500(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();
        $order = Order::where('order_number', $orderNumber)->first();
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $this->mock(OrderService::class, function ($mock) {
            $mock->shouldReceive('updateShippingAddress')
                ->andThrow(new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->putJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/shipping-address",
            [
                'recipient_name' => '변경수령인',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '06236',
                'address' => '서울 강남구 테헤란로 1',
            ],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    /**
     * 사용자 선택 가능한 환불 사유 코드를 반환한다.
     */
    private function userSelectableRefundReason(): string
    {
        $code = ClaimReason::where('type', 'refund')
            ->where('is_active', true)
            ->where('is_user_selectable', true)
            ->value('code');

        if ($code === null) {
            $this->markTestSkipped('사용자 선택 가능한 환불 사유(ClaimReason) 시드가 없다');
        }

        return $code;
    }

    /**
     * 비회원 주문 취소: 도메인 예외는 기존 422 를 유지한다.
     *
     * @effects guest_cancel_domain_exception_returns_422_infra_returns_500
     */
    public function test_비회원_주문취소_도메인예외_422(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();
        $order = Order::where('order_number', $orderNumber)->first();
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $this->mock(OrderCancellationService::class, function ($mock) {
            $mock->shouldReceive('cancelOrder')
                ->andThrow(new OrderCancellationException('취소 불가'));
            $mock->shouldReceive()->andReturnNull();
        });

        $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/cancel",
            ['reason' => $this->userSelectableRefundReason()],
            ['X-Guest-Order-Token' => $token]
        )->assertStatus(422);
    }

    /**
     * 비회원 주문 취소: 인프라 예외는 500 이며 예외 원문을 노출하지 않는다.
     *
     * @effects guest_cancel_domain_exception_returns_422_infra_returns_500, guest_action_5xx_responses_exclude_exception_message
     */
    public function test_비회원_주문취소_인프라예외_500(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();
        $order = Order::where('order_number', $orderNumber)->first();
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $this->mock(OrderCancellationService::class, function ($mock) {
            $mock->shouldReceive('cancelOrder')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: General error'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/cancel",
            ['reason' => $this->userSelectableRefundReason()],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    /**
     * 비회원 구매확정: typed 도메인 예외가 없으므로 모든 예외가 500 이어야 한다.
     *
     * @effects guest_confirm_option_has_no_domain_exception_so_generic_catch_returns_500, guest_action_5xx_responses_exclude_exception_message
     */
    public function test_비회원_구매확정_인프라예외_500(): void
    {
        [$orderNumber, $token] = $this->placeGuestOrderAndToken();
        $order = Order::where('order_number', $orderNumber)->first();
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $option = $order->options()->first();

        $this->mock(OrderService::class, function ($mock) {
            $mock->shouldReceive('confirmOption')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: General error'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$orderNumber}/options/{$option->id}/confirm",
            [],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }
}
