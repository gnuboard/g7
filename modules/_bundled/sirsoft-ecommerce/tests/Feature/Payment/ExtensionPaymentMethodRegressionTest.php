<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Payment;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\TempOrderFactory;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\PromotionsSummary;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\CreateOrderRequest;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 확장 결제수단(간편결제) 1급화 회귀 테스트 — 이슈 #475
 *
 * PG 플러그인이 `filter_available_payment_methods` 로 등록하는 간편결제 수단
 * (예: `nhnkcp_naverpay`) 은 코어 `PaymentMethodEnum` 에 case 가 없다. 이 때문에
 * 서버가 해당 주문을 "PG 결제가 아닌 주문" 으로 오인하여 두 결함이 발생했다:
 *
 *   1. 결제 실패했는데 관리자에게 "신규 주문 접수" 알림이 발송됨
 *   2. 결제 실패 후 TempOrder 가 즉시 삭제되어 재결제 불가 ("임시주문을 찾을 수 없습니다")
 *
 * 두 결함은 하나의 원인이다 — 확장 결제수단의 "능력(PG 필요 여부 / 라벨 / 환불수단)" 을
 * enum 이 답하지 못한다. 본 테스트는 능력 해석이 결제수단 설정 카탈로그를 SSoT 로
 * 삼도록 전환된 뒤 green 이 된다.
 *
 * @see https://github.com/gnuboard/dev-g7/issues/475
 */
class ExtensionPaymentMethodRegressionTest extends ModuleTestCase
{
    /**
     * PG 플러그인이 등록하는 확장 결제수단 ID (NHN KCP 네이버페이).
     */
    private const EXT_METHOD = 'nhnkcp_naverpay';

    /**
     * 확장 결제수단이 소속된 PG.
     */
    private const EXT_PG = 'nhnkcp';

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerExtensionPaymentMethod();
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * PG 플러그인이 하는 것과 동일하게 간편결제 수단을 카탈로그에 등록합니다.
     *
     * `RegisterEasyPayMethodsListener::buildEntry()` 가 내려주는 형태를 그대로 재현하되,
     * 수정 후 선언될 능력 키(`pg_provider` / `needs_pg` / `refund_method` / `pg_locked`)를
     * 포함한다 — 이 선언을 코어가 존중하는지가 본 회귀 테스트의 검증 대상이다.
     */
    private function registerExtensionPaymentMethod(): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.settings.filter_available_payment_methods',
            fn (array $methods) => array_merge($methods, [[
                'id' => self::EXT_METHOD,
                'name' => ['ko' => '네이버페이', 'en' => 'Naver Pay'],
                'description' => ['ko' => '', 'en' => ''],
                'icon' => 'credit-card',
                'source' => 'plugin:sirsoft-pay_nhnkcp',
                'defaults' => [
                    'pg_provider' => self::EXT_PG,
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
                'id' => self::EXT_PG,
                'name' => 'NHN KCP',
                'payment_handler' => 'sirsoft-pay_nhnkcp.requestPayment',
            ]])
        );
    }

    /**
     * 확장 결제수단으로 결제 레코드를 만든 주문을 생성합니다.
     *
     * `payment_method` 컬럼에 확장 ID 를 1급 시민으로 저장한다 — 캐스트가 살아있는
     * 현재는 Eloquent 의 enum 캐스트(`from()`) 가 ValueError 를 던진다.
     */
    private function createExtensionPaymentOrder(array $paymentOverrides = []): OrderPayment
    {
        $order = OrderFactory::new()->create(['total_due_amount' => 50000]);

        return OrderPaymentFactory::new()->forOrder($order)->create(array_merge([
            'payment_method' => self::EXT_METHOD,
            'pg_provider' => self::EXT_PG,
        ], $paymentOverrides));
    }

    /**
     * 실제 주문 생성 흐름(createFromTempOrder)을 태우기 위한 최소 TempOrder 를 만듭니다.
     *
     * @param  User  $user  주문자
     * @param  int  $finalAmount  최종 결제금액
     * @return TempOrder 계산결과가 채워진 임시주문
     */
    private function makeTempOrder(User $user, int $finalAmount = 50000): TempOrder
    {
        return TempOrderFactory::new()
            ->forUser($user)
            ->withCalculationResult([
                'summary' => [
                    'subtotal' => $finalAmount,
                    'total_discount' => 0,
                    'product_coupon_discount' => 0,
                    'code_discount' => 0,
                    'total_shipping' => 0,
                    'final_amount' => $finalAmount,
                    'taxable_amount' => $finalAmount,
                    'tax_free_amount' => 0,
                    'points_used' => 0,
                ],
                'items' => [],
                'shippings' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();
    }

    /**
     * OrderCalculationService 를 mock 해 재계산 결과를 고정하고 서비스를 재바인딩합니다.
     *
     * createFromTempOrder 는 내부에서 재계산을 수행하므로, 저장금액과 동일한 결과를
     * 주입해야 금액 불일치 예외 없이 주문 생성 경로를 끝까지 태울 수 있다.
     *
     * @param  int  $finalAmount  재계산 최종금액 (저장 금액과 동일해야 함)
     * @return OrderProcessingService mock 이 주입된 서비스 인스턴스
     */
    private function orderServiceWithFixedCalculation(int $finalAmount = 50000): OrderProcessingService
    {
        $summary = new Summary(
            subtotal: $finalAmount,
            totalDiscount: 0,
            productCouponDiscount: 0,
            codeDiscount: 0,
            totalShipping: 0,
            taxableAmount: $finalAmount,
            taxFreeAmount: 0,
            pointsUsed: 0,
            pointsEarning: 0,
            paymentAmount: $finalAmount,
            finalAmount: $finalAmount,
        );

        $result = new OrderCalculationResult(
            items: [],
            summary: $summary,
            promotions: new PromotionsSummary,
            validationErrors: [],
        );

        $mock = $this->createMock(OrderCalculationService::class);
        $mock->method('calculate')->willReturn($result);
        $this->app->instance(OrderCalculationService::class, $mock);

        return app(OrderProcessingService::class);
    }

    /**
     * 지정 훅의 발화 횟수를 잡는 스파이를 부착하고, 라이브 카운터 객체를 돌려줍니다.
     *
     * Reflection 으로 가드 조건만 확인하면 호출지점 로직이 바뀌어도 회귀를 못 잡는다.
     * 실제 주문 생성/취소 흐름에서 훅이 발화하는지를 직접 관측한다.
     *
     * 배열이 아닌 ArrayObject(참조형)를 돌려준다 — PHP 배열은 값 복사라
     * `return $arr` 이후 클로저가 카운트를 올려도 호출자 쪽 사본은 갱신되지 않는다.
     *
     * @param  string  $hook  관측할 액션 훅 이름
     * @return \ArrayObject 발화 횟수를 담는 라이브 카운터 (`$c['count']`)
     */
    private function spyOnHook(string $hook): \ArrayObject
    {
        $counter = new \ArrayObject(['count' => 0]);
        HookManager::addAction($hook, function () use ($counter): void {
            $counter['count']++;
        });

        return $counter;
    }

    // ─────────────────────────────────────────────────────────────
    // 6. 확장 ID 저장/조회 — 캐스트가 ValueError 를 던지지 않아야 한다
    // ─────────────────────────────────────────────────────────────

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=needs_pg
     *
     * @effects extension_id_persisted_as_is
     */
    #[Test]
    public function extension_payment_method_id_can_be_persisted_and_read_back(): void
    {
        // 현재 red: 'payment_method' => PaymentMethodEnum::class 캐스트가 내부적으로
        // from() 을 호출 → 확장 ID 는 enum case 가 없어 ValueError → 주문 생성 500.
        $payment = $this->createExtensionPaymentOrder();

        $this->assertSame(self::EXT_METHOD, $payment->fresh()->payment_method);

        $this->assertDatabaseHas('ecommerce_order_payments', [
            'id' => $payment->id,
            'payment_method' => self::EXT_METHOD,
            'pg_provider' => self::EXT_PG,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // 1. 관리자 신규주문 알림이 주문 생성 시점에 발화되지 않아야 한다
    // ─────────────────────────────────────────────────────────────

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=needs_pg
     *
     * @effects requires_pg_payment_true, temp_order_preserved, admin_new_order_notification_deferred
     */
    #[Test]
    public function extension_payment_order_does_not_require_pg_is_false(): void
    {
        // orderRequiresPgPayment() 가 확장 결제수단을 PG 주문으로 인식해야 한다.
        // 현재 red: PaymentMethodEnum::tryFrom('nhnkcp_naverpay') === null → false 반환
        //          → 주문 생성 즉시 order.after_admin_notify 발화 (관리자 548명 오발송).
        $payment = $this->createExtensionPaymentOrder();
        $order = $payment->order->load('payment');

        $service = app(OrderProcessingService::class);

        $method = new \ReflectionMethod($service, 'orderRequiresPgPayment');
        $method->setAccessible(true);

        $this->assertTrue(
            $method->invoke($service, $order),
            '확장 결제수단 주문은 PG 결제 주문으로 판정되어야 한다 '
            .'(false 면 주문 생성 즉시 관리자 알림이 오발송된다)'
        );
    }

    /**
     * 실제 주문 생성 흐름에서 관리자 신규주문 알림 훅이 발화하지 않아야 한다.
     *
     * 위 Reflection 테스트가 판정 값만 본다면, 이 테스트는 createFromTempOrder 를
     * 끝까지 태워 호출지점(OrderProcessingService:216-217)에서 실제로
     * `order.after_admin_notify` 훅이 발화하는지를 스파이로 관측한다 — 판정과
     * 호출지점 사이 로직이 바뀌어 오발송이 재발하면 이 테스트가 잡는다 (#475).
     *
     * @scenario method_kind=extension, capability_declared=declared, capability=needs_pg
     *
     * @effects admin_new_order_notification_deferred, temp_order_preserved, requires_pg_payment_true
     */
    #[Test]
    public function extension_payment_order_creation_does_not_fire_admin_notification(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->makeTempOrder($user, 50000);
        $service = $this->orderServiceWithFixedCalculation(50000);

        $notify = $this->spyOnHook('sirsoft-ecommerce.order.after_admin_notify');

        $order = $service->createFromTempOrder(
            $tempOrder,
            ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'buyer@example.com'],
            ['recipient_name' => '홍길동', 'recipient_phone' => '010-1234-5678', 'zipcode' => '12345', 'address' => '서울시 강남구', 'address_detail' => '1동 2호'],
            self::EXT_METHOD,
            50000,
            null,
        );

        // 확장 결제수단(PG 결제) 주문은 생성 시점(결제 전 pending)에서 알림을 미룬다.
        $this->assertSame(
            0,
            $notify['count'],
            '확장 결제수단(PG) 주문 생성 시 관리자 신규주문 알림이 발화되면 #475 오알림 재발이다',
        );
        // 주문·결제 레코드가 실제로 만들어졌는지(경로가 끝까지 탔는지) 확인.
        $this->assertNotNull($order->payment);
        $this->assertSame(self::EXT_METHOD, $order->payment->payment_method);
    }

    /**
     * 대조군: 무통장입금(비-PG) 주문은 생성 시점에 관리자 알림이 발화되어야 한다.
     *
     * 스파이가 실제로 발화를 잡는지 검증하는 대조군이다 — 위 테스트가 항상 0 을
     * 반환하는 거짓 통과가 아님을 보증한다(비-PG 는 접수 시점 발송이 정상 동작).
     *
     * @scenario method_kind=builtin, capability_declared=declared, capability=needs_pg
     *
     * @effects builtin_capability_unchanged
     */
    #[Test]
    public function builtin_bank_transfer_order_creation_fires_admin_notification(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->makeTempOrder($user, 50000);
        $service = $this->orderServiceWithFixedCalculation(50000);

        $notify = $this->spyOnHook('sirsoft-ecommerce.order.after_admin_notify');

        $order = $service->createFromTempOrder(
            $tempOrder,
            ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'buyer@example.com'],
            ['recipient_name' => '홍길동', 'recipient_phone' => '010-1234-5678', 'zipcode' => '12345', 'address' => '서울시 강남구', 'address_detail' => '1동 2호'],
            'dbank',
            50000,
            null,
        );

        $this->assertSame(
            1,
            $notify['count'],
            '무통장입금(비-PG) 주문은 접수 시점에 관리자 알림이 발화되어야 한다 (스파이 동작 확인)',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // 2. determinePgProvider 가 'none' 이 아닌 실제 PG 를 반환해야 한다
    //    (= TempOrder 보존 / requires_pg_payment=true 의 근거)
    // ─────────────────────────────────────────────────────────────

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=needs_pg
     *
     * @effects pg_provider_resolved_not_none, temp_order_preserved
     */
    #[Test]
    public function extension_payment_method_resolves_to_its_own_pg_provider(): void
    {
        // 현재 red: 확장수단의 카탈로그 pg_provider 가 null 이면 default_pg_provider 로
        //          폴백하고 그것도 null 이면 'none' → requiresPg=false → TempOrder 즉시 삭제.
        $provider = app(OrderProcessingService::class)->determinePgProvider(self::EXT_METHOD);

        $this->assertSame(self::EXT_PG, $provider);
        $this->assertNotContains(
            $provider,
            ['manual', 'internal', 'none'],
            "확장 결제수단이 'none' 으로 해석되면 TempOrder 가 즉시 삭제되어 재결제가 불가능해진다"
        );
    }

    // ─────────────────────────────────────────────────────────────
    // 4. 취소 시 PG 환불 훅이 호출되어야 한다 (안전 이슈)
    // ─────────────────────────────────────────────────────────────

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=refund_method
     *
     * @effects refund_method_is_pg, pg_refund_hook_invoked
     */
    #[Test]
    public function extension_payment_order_refund_method_is_pg(): void
    {
        // 현재 red: determineRefundMethod() 의 match 가 enum case 만 다뤄 default → BANK.
        //          PG 환불이 스킵되어 "취소는 됐는데 카드 취소가 안 되는" 실패가 발생한다.
        $payment = $this->createExtensionPaymentOrder();
        $order = $payment->order->load('payment');

        $service = app(OrderCancellationService::class);

        $method = new \ReflectionMethod($service, 'determineRefundMethod');
        $method->setAccessible(true);

        $this->assertSame(
            RefundMethodEnum::PG,
            $method->invoke($service, $order),
            '확장 결제수단(PG 결제) 의 환불수단은 PG 여야 한다 (BANK 면 카드 취소가 누락된다)'
        );
    }

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=refund_method
     *
     * @effects pg_refund_hook_invoked
     */
    #[Test]
    public function extension_payment_needs_pg_provider_for_refund_hook(): void
    {
        // OrderCancellationService:281 의 PG 환불 훅 진입 가드.
        // 현재 red: $payment->payment_method?->needsPgProvider() 가 확장 ID 에서 동작 불가.
        $payment = $this->createExtensionPaymentOrder();

        $this->assertTrue(
            $payment->needsPgProvider(),
            'PG 환불 훅 진입 가드가 확장 결제수단을 PG 결제로 인식해야 한다'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // 5. 확장 ID 가 주문 생성 검증(CreateOrderRequest)을 통과해야 한다
    // ─────────────────────────────────────────────────────────────

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=needs_pg
     *
     * @effects extension_id_passes_validation, pg_payment_handler_present
     */
    #[Test]
    public function extension_payment_method_id_passes_create_order_validation(): void
    {
        // 현재 red: Rule::in(PaymentMethodEnum::cases()) 가 확장 ID 를 422 로 막는다.
        //          이것이 프론트 인터셉터가 payment_method 를 'card' 로 위장하게 만든 원인.
        $rules = (new CreateOrderRequest)->rules();

        $validator = Validator::make(
            ['payment_method' => self::EXT_METHOD],
            ['payment_method' => $rules['payment_method']]
        );

        $this->assertFalse(
            $validator->errors()->has('payment_method'),
            '확장 결제수단 ID 가 검증을 통과해야 한다 '
            .'(422 로 막히면 프론트가 payment_method 를 card 로 위장할 수밖에 없다)'
        );
    }

    /**
     * @scenario method_kind=extension, capability_declared=undeclared, capability=needs_pg
     *
     * @effects unregistered_id_still_rejected
     */
    #[Test]
    public function unregistered_payment_method_id_is_still_rejected(): void
    {
        // 화이트리스트가 넓어지되 임의 문자열은 여전히 막아야 한다 (검증 우회 방지).
        $rules = (new CreateOrderRequest)->rules();

        $validator = Validator::make(
            ['payment_method' => 'totally_unknown_method'],
            ['payment_method' => $rules['payment_method']]
        );

        $this->assertTrue(
            $validator->errors()->has('payment_method'),
            '카탈로그에 등록되지 않은 결제수단은 여전히 거부되어야 한다'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // 카탈로그 SSoT — 설정 병합이 확장수단의 PG 선언을 보존해야 한다
    // ─────────────────────────────────────────────────────────────

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=pg_locked
     *
     * @effects saved_null_pg_provider_self_healed, admin_shows_pg_locked_badge
     */
    #[Test]
    public function catalog_preserves_extension_method_pg_declaration(): void
    {
        // mergePaymentMethodSettings() 가 확장수단의 defaults(pg_provider/needs_pg 등) 를
        // 병합 결과에 반영해야 한다. 현재 red: 신규 키 3종이 merge 대상이 아니다.
        $config = app(EcommerceSettingsService::class)->getPaymentMethodConfig(self::EXT_METHOD);

        $this->assertNotNull($config, '확장 결제수단이 카탈로그에 존재해야 한다');
        $this->assertSame(self::EXT_PG, $config['pg_provider'] ?? null);
        $this->assertTrue((bool) ($config['needs_pg'] ?? false));
        $this->assertTrue((bool) ($config['pg_locked'] ?? false));
        $this->assertSame('pg', $config['refund_method'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────
    // 하위호환 — builtin 8종의 능력 판정이 변하지 않아야 한다
    // ─────────────────────────────────────────────────────────────

    /**
     * @scenario method_kind=builtin, capability_declared=declared, capability=needs_pg
     *
     * @effects builtin_capability_unchanged
     */
    #[Test]
    public function builtin_card_payment_still_requires_pg(): void
    {
        $payment = $this->createExtensionPaymentOrder([
            'payment_method' => 'card',
            'pg_provider' => 'inicis',
        ]);

        $this->assertTrue($payment->needsPgProvider());
        $this->assertTrue($payment->isCardPayment());
        $this->assertFalse($payment->isVirtualAccount());
    }

    /**
     * @scenario method_kind=builtin, capability_declared=declared, capability=needs_pg
     *
     * @effects builtin_capability_unchanged
     */
    #[Test]
    public function builtin_dbank_payment_still_does_not_require_pg(): void
    {
        // 무통장입금은 수동 입금확인 — PG 불필요. 캐스트 제거 후에도 유지되어야 한다.
        // (ConfirmDepositRequest 의 입금확인 가드가 여기에 의존한다)
        $payment = $this->createExtensionPaymentOrder([
            'payment_method' => 'dbank',
            'pg_provider' => '',
        ]);

        $this->assertFalse($payment->needsPgProvider());
        $this->assertTrue($payment->isBankTransfer());
        $this->assertFalse($payment->isCardPayment());
    }

    /**
     * @scenario method_kind=builtin, capability_declared=declared, capability=needs_pg
     *
     * @effects builtin_capability_unchanged
     */
    #[Test]
    public function builtin_vbank_payment_is_still_recognized_as_virtual_account(): void
    {
        // PG 플러그인의 가상계좌 분기(입금통보/환불)가 이 판정에 의존한다.
        // 캐스트 제거로 이것이 조용히 false 가 되면 가상계좌 환불이 통째로 막힌다.
        $payment = $this->createExtensionPaymentOrder([
            'payment_method' => 'vbank',
            'pg_provider' => 'nicepay',
        ]);

        $this->assertTrue($payment->isVirtualAccount());
        $this->assertTrue($payment->needsPgProvider());
        $this->assertFalse($payment->isCardPayment());
    }
}
