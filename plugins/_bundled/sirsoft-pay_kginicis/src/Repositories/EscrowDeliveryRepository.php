<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Repositories;

use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

class EscrowDeliveryRepository implements EscrowDeliveryRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function scrubDeliveryKeys(int $orderId, string $pgProvider, array $keys): int
    {
        $payments = OrderPayment::query()
            ->where('order_id', $orderId)
            ->where('pg_provider', $pgProvider)
            ->get(['id', 'payment_meta']);

        $scrubbed = 0;

        foreach ($payments as $payment) {
            $meta = $payment->payment_meta;

            if (! is_array($meta) || ! is_array($meta['escrow_delivery'] ?? null)) {
                continue;
            }

            foreach ($keys as $key) {
                if (array_key_exists($key, $meta['escrow_delivery'])) {
                    $meta['escrow_delivery'][$key] = null;
                }
            }

            // toBase(): Eloquent update 는 updated_at 을 바꾼다 — 파기는 결제 변경이 아니다.
            OrderPayment::query()->whereKey($payment->id)->toBase()
                ->update(['payment_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);

            $scrubbed++;
        }

        return $scrubbed;
    }
}
