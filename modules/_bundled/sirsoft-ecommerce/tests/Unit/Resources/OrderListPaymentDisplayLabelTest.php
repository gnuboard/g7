<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderListResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 주문 목록 결제수단 표시 라벨 — 결제 플러그인이 필터 훅으로 채우는 자리
 *
 * 이 모듈은 PG 를 모르므로 간편결제 원 수단(PAYCO·네이버페이 등)을 스스로 조립하지 않는다. 결제 플러그인이
 * `sirsoft-ecommerce.order.filter_payment_method_display_label` 로 자기 결제의 라벨을 채우고, 목록 화면은
 * `payment_method_display_label ?? payment_method_label` 로 그린다.
 */
class OrderListPaymentDisplayLabelTest extends ModuleTestCase
{
    /** 검증 대상 훅 이름 */
    private const HOOK = 'sirsoft-ecommerce.order.filter_payment_method_display_label';

    /**
     * 각 테스트 뒤 이 테스트가 등록한 필터를 걷어냅니다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(self::HOOK);

        parent::tearDown();
    }

    /**
     * 결제 레코드를 가진 주문을 만들고 결제 관계를 로드해 반환합니다.
     *
     * @param  string  $pgProvider  결제 레코드의 PG 식별자
     * @return Order 결제 관계가 로드된 주문
     */
    private function orderWithPayment(string $pgProvider): Order
    {
        $order = OrderFactory::new()->create();

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => $pgProvider,
        ]);

        return $order->load('payment');
    }

    /**
     * 구독자가 없으면 표시 라벨은 null 이고 기본 라벨은 그대로다.
     *
     * @scenario payment_plugin=none
     *
     * @effects display_label_is_null_without_subscribers
     *
     * @return void 없음
     */
    public function test_display_label_is_null_without_subscribers(): void
    {
        HookManager::clearFilter(self::HOOK);

        $resource = (new OrderListResource($this->orderWithPayment('nhnkcp')))->resolve();

        $this->assertArrayHasKey('payment_method_display_label', $resource['payment']);
        $this->assertNull($resource['payment']['payment_method_display_label']);
        $this->assertNotNull($resource['payment']['payment_method_label']);
    }

    /**
     * 결제 플러그인이 채운 라벨이 목록 응답에 실리고, 구독자는 결제 레코드를 받는다.
     *
     * @scenario payment_plugin=nhnkcp
     *
     * @effects display_label_comes_from_the_payment_plugin_filter
     *
     * @return void 없음
     */
    public function test_display_label_comes_from_the_payment_plugin_filter(): void
    {
        $received = null;
        HookManager::addFilter(self::HOOK, function (?string $label, $payment) use (&$received) {
            $received = $payment;

            return $payment->pg_provider === 'nhnkcp' ? 'PAYCO (신용카드)' : $label;
        });

        $resource = (new OrderListResource($this->orderWithPayment('nhnkcp')))->resolve();

        $this->assertSame('PAYCO (신용카드)', $resource['payment']['payment_method_display_label']);
        $this->assertInstanceOf(OrderPayment::class, $received);
    }

    /**
     * 빈 문자열·문자열이 아닌 값은 표시 라벨로 쓰지 않는다 — 화면이 기본 라벨로 되돌아가야 한다.
     *
     * @scenario payment_plugin=nhnkcp
     *
     * @effects display_label_is_null_without_subscribers
     *
     * @return void 없음
     */
    public function test_blank_or_non_string_filter_result_falls_back_to_null(): void
    {
        HookManager::addFilter(self::HOOK, fn () => '');
        $blank = (new OrderListResource($this->orderWithPayment('nhnkcp')))->resolve();
        $this->assertNull($blank['payment']['payment_method_display_label']);

        HookManager::clearFilter(self::HOOK);
        HookManager::addFilter(self::HOOK, fn () => ['label' => 'x']);
        $nonString = (new OrderListResource($this->orderWithPayment('nhnkcp')))->resolve();
        $this->assertNull($nonString['payment']['payment_method_display_label']);
    }
}
