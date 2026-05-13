<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Controllers\User;

use Modules\Sirsoft\Ecommerce\Http\Controllers\User\OrderController;
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
     * @param Order $order 주문
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
    public function test_복수_상품_주문명에_외_N건_표시(): void
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
    public function test_통화_기본값_KRW(): void
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
}
