<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Controllers\Public;

use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\OrderController;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * OrderController::buildPgPaymentData() 단위 테스트
 *
 * PG 결제용 데이터 빌드 로직을 검증합니다:
 * - 주문명 로컬라이즈
 * - 배송지 주소 기반 고객 정보
 * - 결제 금액/통화
 */
class BuildPgPaymentDataTest extends ModuleTestCase
{
    private ReflectionMethod $method;

    private OrderController $controller;

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

    public function test_base_usd_결제통화_krw_환산_청구(): void
    {
        // $6(base) → KRW 7058 (화면 표시액과 일치), price min:100 통과
        $result = $this->callBuildPgPaymentData($this->makeOrderWithSnapshot($this->usdBaseSnapshot('KRW'), 6.0));

        $this->assertEquals('KRW', $result['currency']);
        $this->assertEquals(7058, $result['amount']);
    }

    public function test_base_usd_결제통화_usd_minor_unit_청구(): void
    {
        // $6(base=결제통화) → minor unit 600 (KG "1달러=100" 규칙)
        $result = $this->callBuildPgPaymentData($this->makeOrderWithSnapshot($this->usdBaseSnapshot('USD'), 6.0));

        $this->assertEquals('USD', $result['currency']);
        $this->assertEquals(600, $result['amount']);
    }

    public function test_base_usd_결제통화_jpy_정수_청구(): void
    {
        // $6(base) → JPY 942 (CBT 결제 경로로 전달될 정수)
        $result = $this->callBuildPgPaymentData($this->makeOrderWithSnapshot($this->usdBaseSnapshot('JPY'), 6.0));

        $this->assertEquals('JPY', $result['currency']);
        $this->assertEquals(942, $result['amount']);
    }
}
