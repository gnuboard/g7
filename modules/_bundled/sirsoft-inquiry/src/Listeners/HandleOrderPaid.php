<?php

namespace Modules\Sirsoft\Inquiry\Listeners;

use Modules\Sirsoft\Inquiry\Services\InquiryPaymentBridge;

class HandleOrderPaid
{
    public function __construct(
        private readonly InquiryPaymentBridge $bridge,
    ) {}

    public function handle(object $event): void
    {
        $order = $event->order ?? $event; // event payload shape varies
        // ecommerce OrderStatusEnum values: payment_complete, confirmed, delivered
        $status = null;
        if (isset($order->order_status)) {
            $status = $order->order_status instanceof \BackedEnum
                ? $order->order_status->value
                : (string) $order->order_status;
        } elseif (isset($order->status)) {
            $status = $order->status instanceof \BackedEnum
                ? $order->status->value
                : (string) $order->status;
        }

        if (in_array($status, ['payment_complete', 'confirmed', 'paid', 'completed', 'PAID', 'COMPLETED'], true)) {
            $this->bridge->handleOrderPaid($order);
        }
    }
}
