<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Controllers\Public;

use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\OrderController;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * OrderController::buildPgPaymentData() 단위 테스트
 *
 * PG 결제용 데이터 빌드 로직을 검증합니다:
 * - 주문명 로컬라이즈
 * - 배송지 주소 기반 고객 정보
 * - 결제 금액/통화
 * - 에스크로 상품 상세(escrow_products)
 * - 가상계좌 입금 기한(vbank_due_at)
 */
class BuildPgPaymentDataTest extends ModuleTestCase
{
    private ReflectionMethod $method;

    private OrderController $controller;

    /**
     * 테스트마다 주문 컨트롤러와 보호 메서드 buildPgPaymentData 의 리플렉션을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = app(OrderController::class);
        $this->method = new ReflectionMethod(OrderController::class, 'buildPgPaymentData');
    }

    /**
     * protected buildPgPaymentData 메서드 호출 헬퍼
     *
     * @param  Order  $order  주문
     * @return array PG 결제 데이터
     */
    private function callBuildPgPaymentData(Order $order): array
    {
        return $this->method->invoke($this->controller, $order);
    }

    /**
     * 한국어 로케일에서 주문명이 올바르게 생성되는지 확인
     *
     * @return void 반환값 없음
     *
     * @effects order_name_is_localized_with_a_count_suffix
     * @effects customer_fields_come_from_the_shipping_address
     */
    public function test_한국어_로케일에서_주문명_생성(): void
    {
        app()->setLocale('ko');

        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create([
            'total_due_amount' => 33000,
            'currency_snapshot' => ['order_currency' => 'KRW'],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'product_name' => ['ko' => '테스트 상품', 'en' => 'Test Product'],
        ]);

        OrderAddress::factory()->shipping()->forOrder($order)->create([
            'orderer_name' => '홍길동',
            'orderer_email' => 'hong@test.com',
            'orderer_phone' => '010-1234-5678',
        ]);

        $result = $this->callBuildPgPaymentData($order->fresh());

        $this->assertEquals('테스트 상품', $result['order_name']);
        $this->assertEquals($order->order_number, $result['order_number']);
        $this->assertEquals(33000, $result['amount']);
        $this->assertEquals('KRW', $result['currency']);
        $this->assertEquals('홍길동', $result['customer_name']);
        $this->assertEquals('hong@test.com', $result['customer_email']);
        $this->assertEquals('01012345678', $result['customer_phone']);
        $this->assertEquals("user_{$user->id}", $result['customer_key']);
    }

    /**
     * 영어 로케일에서 주문명이 올바르게 생성되는지 확인
     *
     * @return void 반환값 없음
     *
     * @effects order_name_is_localized_with_a_count_suffix
     */
    public function test_영어_로케일에서_주문명_생성(): void
    {
        app()->setLocale('en');

        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create([
            'total_due_amount' => 50000,
            'currency_snapshot' => ['order_currency' => 'KRW'],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'product_name' => ['ko' => '테스트 상품', 'en' => 'Test Product'],
        ]);

        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $result = $this->callBuildPgPaymentData($order->fresh());

        $this->assertEquals('Test Product', $result['order_name']);
    }

    /**
     * 여러 상품 주문 시 "외 N건" 표시 확인
     *
     * @return void 반환값 없음
     *
     * @effects order_name_is_localized_with_a_count_suffix
     */
    public function test_복수_상품_주문명에_외_n건_표시(): void
    {
        app()->setLocale('ko');

        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create([
            'total_due_amount' => 100000,
            'currency_snapshot' => ['order_currency' => 'KRW'],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'product_name' => ['ko' => '첫번째 상품', 'en' => 'First Product'],
        ]);
        OrderOption::factory()->forOrder($order)->create([
            'product_name' => ['ko' => '두번째 상품', 'en' => 'Second Product'],
        ]);
        OrderOption::factory()->forOrder($order)->create([
            'product_name' => ['ko' => '세번째 상품', 'en' => 'Third Product'],
        ]);

        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $result = $this->callBuildPgPaymentData($order->fresh());

        $this->assertEquals('첫번째 상품 외 2건', $result['order_name']);
    }

    /**
     * 전화번호에서 하이픈 등 숫자 외 문자가 제거되는지 확인
     *
     * @return void 반환값 없음
     *
     * @effects customer_fields_come_from_the_shipping_address
     */
    public function test_전화번호_숫자만_추출(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create([
            'total_due_amount' => 10000,
            'currency_snapshot' => ['order_currency' => 'KRW'],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'product_name' => ['ko' => '상품'],
        ]);

        OrderAddress::factory()->shipping()->forOrder($order)->create([
            'orderer_phone' => '010-9876-5432',
        ]);

        $result = $this->callBuildPgPaymentData($order->fresh());

        $this->assertEquals('01098765432', $result['customer_phone']);
    }

    /**
     * 비회원(user_id null) 주문 시 customer_key가 null인지 확인
     *
     * @return void 반환값 없음
     *
     * @effects guest_order_has_no_customer_key
     */
    public function test_비회원_주문시_customer_key_null(): void
    {
        $order = Order::factory()->create([
            'user_id' => null,
            'total_due_amount' => 20000,
            'currency_snapshot' => ['order_currency' => 'KRW'],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'product_name' => ['ko' => '상품'],
        ]);

        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $result = $this->callBuildPgPaymentData($order->fresh());

        $this->assertNull($result['customer_key']);
    }

    /**
     * 배송지 주소 없는 경우 고객 정보가 null인지 확인
     *
     * @return void 반환값 없음
     *
     * @effects customer_fields_come_from_the_shipping_address
     */
    public function test_배송지_없는_경우_고객정보_null(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create([
            'total_due_amount' => 15000,
            'currency_snapshot' => ['order_currency' => 'KRW'],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'product_name' => ['ko' => '디지털 상품'],
        ]);

        // 배송지 주소를 생성하지 않음

        $result = $this->callBuildPgPaymentData($order->fresh());

        $this->assertNull($result['customer_name']);
        $this->assertNull($result['customer_email']);
        $this->assertEquals('', $result['customer_phone']);
    }

    /**
     * product_name이 문자열인 경우 처리 확인
     *
     * @return void 반환값 없음
     *
     * @effects order_name_is_localized_with_a_count_suffix
     */
    public function test_product_name이_문자열인_경우(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create([
            'total_due_amount' => 10000,
            'currency_snapshot' => ['order_currency' => 'KRW'],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'product_name' => '단순 상품명',
        ]);

        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $result = $this->callBuildPgPaymentData($order->fresh());

        $this->assertEquals('단순 상품명', $result['order_name']);
    }

    /**
     * currency_snapshot에 order_currency가 없는 경우 기본값 KRW 확인
     *
     * @return void 반환값 없음
     *
     * @effects charge_currency_defaults_to_krw
     */
    public function test_통화_기본값_krw(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create([
            'total_due_amount' => 10000,
            'currency_snapshot' => [],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'product_name' => ['ko' => '상품'],
        ]);

        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $result = $this->callBuildPgPaymentData($order->fresh());

        $this->assertEquals('KRW', $result['currency']);
    }

    // ──────────────────────────────────────────────
    // base ≠ 결제통화: PG 청구 금액/통화 환산 (실제 버그 시나리오)
    // ──────────────────────────────────────────────

    /**
     * base=USD 주문 스냅샷 (실제 버그 재현 형태).
     *
     * @param  string  $orderCurrency  결제 통화 코드
     * @return array<string, mixed> 주문 통화 스냅샷
     */
    private function usdBaseSnapshot(string $orderCurrency): array
    {
        return [
            'base_currency' => 'USD',
            'order_currency' => $orderCurrency,
            'exchange_rates' => [
                'KRW' => ['rate' => 1176470, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
                'USD' => ['rate' => 1, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
                'JPY' => ['rate' => 157000, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
                'CNY' => ['rate' => 0, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
            ],
        ];
    }

    /**
     * 통화 스냅샷과 기준 통화 결제예정액으로 상품 1개·배송지가 있는 주문을 만듭니다.
     *
     * @param  array<string, mixed>  $snapshot  주문 통화 스냅샷
     * @param  float  $baseDue  기준 통화 결제예정액
     * @return Order 새로 읽은 주문
     */
    private function makeOrderWithSnapshot(array $snapshot, float $baseDue): Order
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create([
            'total_due_amount' => $baseDue,
            'currency_snapshot' => $snapshot,
        ]);
        OrderOption::factory()->forOrder($order)->create(['product_name' => ['ko' => '상품']]);
        OrderAddress::factory()->shipping()->forOrder($order)->create();

        return $order->fresh();
    }

    /**
     * 기준 통화 USD 주문을 KRW 로 결제하면 주문 스냅샷 환율로 환산한 원화 정수를 청구한다($6 → 7,058원, 화면 표시액과 같음).
     *
     * @return void 반환값 없음
     *
     * @effects charge_amount_is_converted_to_the_payment_currency_minor_unit
     */
    public function test_base_usd_결제통화_krw_환산_청구(): void
    {
        // $6(base) → KRW 7058 (화면 표시액과 일치), price min:100 통과
        $result = $this->callBuildPgPaymentData($this->makeOrderWithSnapshot($this->usdBaseSnapshot('KRW'), 6.0));

        $this->assertEquals('KRW', $result['currency']);
        $this->assertEquals(7058, $result['amount']);
    }

    /**
     * 기준 통화와 결제 통화가 모두 USD 이면 센트 단위 정수를 청구한다($6 → 600, KG 「1달러=100」 규칙).
     *
     * @return void 반환값 없음
     *
     * @effects charge_amount_is_converted_to_the_payment_currency_minor_unit
     */
    public function test_base_usd_결제통화_usd_minor_unit_청구(): void
    {
        // $6(base=결제통화) → minor unit 600 (KG "1달러=100" 규칙)
        $result = $this->callBuildPgPaymentData($this->makeOrderWithSnapshot($this->usdBaseSnapshot('USD'), 6.0));

        $this->assertEquals('USD', $result['currency']);
        $this->assertEquals(600, $result['amount']);
    }

    /**
     * 기준 통화 USD 주문을 JPY 로 결제하면 엔화 정수를 청구한다($6 → 942엔, CBT 결제 경로로 전달될 값).
     *
     * @return void 반환값 없음
     *
     * @effects charge_amount_is_converted_to_the_payment_currency_minor_unit
     */
    public function test_base_usd_결제통화_jpy_정수_청구(): void
    {
        // $6(base) → JPY 942 (CBT 결제 경로로 전달될 정수)
        $result = $this->callBuildPgPaymentData($this->makeOrderWithSnapshot($this->usdBaseSnapshot('JPY'), 6.0));

        $this->assertEquals('JPY', $result['currency']);
        $this->assertEquals(942, $result['amount']);
    }

    // ──────────────────────────────────────────────
    // 에스크로 상품 상세 (escrow_products) — 토스 에스크로 필수 파라미터
    // ──────────────────────────────────────────────

    /**
     * escrow_products 가 주문 옵션 수만큼, 개당가·수량·로컬라이즈 상품명으로 구성되는지 확인.
     *
     * @return void 반환값 없음
     *
     * @effects escrow_products_localized_unit_price_per_item
     */
    public function test_escrow_products_옵션별_개당가_수량_상품명(): void
    {
        app()->setLocale('ko');

        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create([
            'total_due_amount' => 30000,
            'currency_snapshot' => ['order_currency' => 'KRW'],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'product_name' => ['ko' => '상품 A', 'en' => 'Product A'],
            'unit_price' => 10000,
            'quantity' => 2,
        ]);
        OrderOption::factory()->forOrder($order)->create([
            'product_name' => ['ko' => '상품 B', 'en' => 'Product B'],
            'unit_price' => 5000,
            'quantity' => 2,
        ]);

        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $result = $this->callBuildPgPaymentData($order->fresh());

        $this->assertArrayHasKey('escrow_products', $result);
        $this->assertCount(2, $result['escrow_products']);

        $first = $result['escrow_products'][0];
        $this->assertSame('상품 A', $first['name']);
        // unitPrice 는 개당가 (합계 아님)
        $this->assertSame(10000, $first['unitPrice']);
        $this->assertSame(2, $first['quantity']);
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('code', $first);
    }

    /**
     * escrow_products 상품명이 영어 로케일에서 로컬라이즈되는지 확인.
     *
     * @return void 반환값 없음
     *
     * @effects escrow_products_localized_unit_price_per_item
     */
    public function test_escrow_products_상품명_영어_로컬라이즈(): void
    {
        app()->setLocale('en');

        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create([
            'total_due_amount' => 10000,
            'currency_snapshot' => ['order_currency' => 'KRW'],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'product_name' => ['ko' => '상품 A', 'en' => 'Product A'],
            'unit_price' => 10000,
            'quantity' => 1,
        ]);

        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $result = $this->callBuildPgPaymentData($order->fresh());

        $this->assertSame('Product A', $result['escrow_products'][0]['name']);
    }

    /**
     * 가상계좌 주문은 주문이 정한 입금 기한을 한국 시각 ISO 8601 로 싣는다.
     *
     * PG 결제창이 이 값을 계좌 만료 시각으로 받는다 — 싣지 않으면 PG 가 자기 기본 기한(KG 10~30일·나이스 10일)으로
     * 계좌를 열고 콜백이 그 값으로 주문 기한을 덮어써 주문서 안내(자동취소 기한)와 실제 기한이 어긋난다.
     *
     * @return void 반환값 없음
     *
     * @scenario payment_method=vbank
     *
     * @effects pg_payment_data_carries_the_order_vbank_due_in_kst
     */
    public function test_가상계좌_주문은_입금_기한을_한국_시각으로_싣는다(): void
    {
        $order = Order::factory()->forUser($this->createUser())->create([
            'total_due_amount' => 23000,
            'currency_snapshot' => ['order_currency' => 'KRW'],
        ]);
        OrderOption::factory()->forOrder($order)->create(['product_name' => ['ko' => '상품 A'], 'unit_price' => 23000, 'quantity' => 1]);
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::VBANK,
            'vbank_due_at' => Carbon::parse('2026-10-10 04:39:23', 'UTC'),
        ]);

        $result = $this->callBuildPgPaymentData($order->fresh());

        $this->assertSame('2026-10-10T13:39:23+09:00', $result['vbank_due_at'] ?? null);
    }

    /**
     * 가상계좌가 아닌 주문에는 입금 기한 키를 싣지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario payment_method=card
     *
     * @effects non_vbank_order_omits_the_vbank_due
     */
    public function test_가상계좌가_아닌_주문은_입금_기한을_싣지_않는다(): void
    {
        $order = Order::factory()->forUser($this->createUser())->create([
            'total_due_amount' => 23000,
            'currency_snapshot' => ['order_currency' => 'KRW'],
        ]);
        OrderOption::factory()->forOrder($order)->create(['product_name' => ['ko' => '상품 A'], 'unit_price' => 23000, 'quantity' => 1]);
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::CARD,
        ]);

        $result = $this->callBuildPgPaymentData($order->fresh());

        $this->assertArrayNotHasKey('vbank_due_at', $result);
    }
}
