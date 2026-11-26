<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderPaymentResource;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 상세 결제 리소스의 결제수단 표시 라벨
 *
 * 관리자 주문 목록만 간편결제 원 수단 이름(「카카오페이 (토스페이먼츠)」)을 보이고, 관리자 주문 상세·관리자 클레임 상세의
 * 주문 결제 카드·마이페이지 주문 상세는 「신용카드」만 보였다. 주문 결제 리소스도 같은 훅·같은 규칙
 * (`OrderPayment::paymentMethodDisplayLabel()`)으로 `payment_method_display_label` 을 싣는다.
 */
class OrderPaymentDisplayLabelTest extends ModuleTestCase
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
     * 결제 레코드를 만들어 반환합니다.
     *
     * @param  string  $pgProvider  결제 레코드의 PG 식별자
     * @return OrderPayment 결제 레코드
     */
    private function payment(string $pgProvider): OrderPayment
    {
        $order = OrderFactory::new()->create();

        return OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => $pgProvider,
            'embedded_pg_provider' => '카카오페이',
        ]);
    }

    /**
     * 구독자가 없으면 표시 라벨은 null 이고 기본 라벨은 그대로다.
     *
     * @scenario payment_plugin=none
     *
     * @effects order_payment_resource_carries_display_label, display_label_is_null_without_subscribers
     *
     * @return void 없음
     */
    public function test_display_label_is_null_without_subscribers(): void
    {
        HookManager::clearFilter(self::HOOK);

        $resource = (new OrderPaymentResource($this->payment('tosspayments')))->resolve();

        $this->assertArrayHasKey('payment_method_display_label', $resource);
        $this->assertNull($resource['payment_method_display_label']);
        $this->assertNotNull($resource['payment_method_label']);
    }

    /**
     * 결제 플러그인이 채운 라벨이 상세 결제 응답에 실리고, 구독자는 결제 레코드를 받는다.
     *
     * @scenario payment_plugin=tosspayments
     *
     * @effects order_payment_resource_carries_display_label, display_label_comes_from_the_payment_plugin_filter
     *
     * @return void 없음
     */
    public function test_display_label_comes_from_the_payment_plugin_filter(): void
    {
        HookManager::clearFilter(self::HOOK);
        $received = null;
        HookManager::addFilter(self::HOOK, function (?string $label, $payment) use (&$received) {
            $received = $payment;

            return $payment->pg_provider === 'tosspayments' ? '카카오페이 (토스페이먼츠)' : $label;
        });

        $resource = (new OrderPaymentResource($this->payment('tosspayments')))->resolve();

        $this->assertSame('카카오페이 (토스페이먼츠)', $resource['payment_method_display_label']);
        $this->assertInstanceOf(OrderPayment::class, $received);
    }

    /**
     * 빈 문자열·문자열이 아닌 값은 표시 라벨로 쓰지 않는다 — 화면이 기본 라벨로 되돌아가야 한다.
     *
     * @scenario payment_plugin=tosspayments
     *
     * @effects order_payment_resource_carries_display_label
     *
     * @return void 없음
     */
    public function test_blank_or_non_string_filter_result_falls_back_to_null(): void
    {
        HookManager::clearFilter(self::HOOK);
        HookManager::addFilter(self::HOOK, fn () => '');
        $blank = (new OrderPaymentResource($this->payment('tosspayments')))->resolve();
        $this->assertNull($blank['payment_method_display_label']);

        HookManager::clearFilter(self::HOOK);
        HookManager::addFilter(self::HOOK, fn () => ['label' => 'x']);
        $nonString = (new OrderPaymentResource($this->payment('tosspayments')))->resolve();
        $this->assertNull($nonString['payment_method_display_label']);
    }

    /**
     * 목록 리소스와 상세 결제 리소스가 같은 값을 낸다 — 해석이 `OrderPayment::paymentMethodDisplayLabel()` 한 곳이다.
     *
     * @scenario payment_plugin=nicepayments
     *
     * @effects order_payment_resource_carries_display_label
     *
     * @return void 없음
     */
    public function test_detail_and_model_share_one_resolution(): void
    {
        HookManager::clearFilter(self::HOOK);
        HookManager::addFilter(self::HOOK, fn (?string $label, $payment) => $payment->pg_provider === 'nicepayments' ? '카카오페이 (나이스페이먼츠)' : $label);

        $payment = $this->payment('nicepayments');
        $resource = (new OrderPaymentResource($payment))->resolve();

        $this->assertSame($payment->paymentMethodDisplayLabel(), $resource['payment_method_display_label']);
        $this->assertSame('카카오페이 (나이스페이먼츠)', $resource['payment_method_display_label']);
    }
}
