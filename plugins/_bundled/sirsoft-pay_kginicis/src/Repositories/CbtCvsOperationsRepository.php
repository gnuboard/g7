<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Repositories;

use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

class CbtCvsOperationsRepository implements CbtCvsOperationsRepositoryInterface
{
    /**
     * 주문번호로 결제 row 를 포함한 주문을 조회합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return Order|null 주문 모델
     */
    public function findOrderWithPayment(string $orderNumber): ?Order
    {
        return Order::query()
            ->with('payment')
            ->where('order_number', $orderNumber)
            ->first();
    }

    /**
     * 주문의 대표 결제 row 를 반환합니다.
     *
     * @param  Order  $order  주문 모델
     * @return OrderPayment|null 결제 row
     */
    public function firstPaymentForOrder(Order $order): ?OrderPayment
    {
        $payment = $order->payment;
        if ($payment instanceof OrderPayment) {
            return $payment;
        }

        return $order->payment()->first();
    }

    /**
     * 결제 row 를 배타 잠금으로 다시 조회합니다.
     *
     * @param  OrderPayment  $payment  결제 row
     * @return OrderPayment|null 잠금된 결제 row
     */
    public function lockPayment(OrderPayment $payment): ?OrderPayment
    {
        return OrderPayment::query()
            ->whereKey($payment->getKey())
            ->lockForUpdate()
            ->first();
    }

    /**
     * 결제 row 속성을 갱신하고 최신 모델을 반환합니다.
     *
     * @param  OrderPayment  $payment  결제 row
     * @param  array<string, mixed>  $attributes  갱신 속성
     * @return OrderPayment 갱신된 결제 row
     */
    public function updatePayment(OrderPayment $payment, array $attributes): OrderPayment
    {
        $payment->forceFill($attributes)->save();
        $payment->refresh();

        return $payment;
    }

    /**
     * 주문의 대표 결제 row 에 PG 제공자 ID 를 기록합니다.
     *
     * @param  Order  $order  주문 모델
     * @param  string  $provider  PG 제공자 ID
     * @return void
     */
    public function updatePaymentProvider(Order $order, string $provider): void
    {
        $order->payment()->update(['pg_provider' => $provider]);
    }
}
