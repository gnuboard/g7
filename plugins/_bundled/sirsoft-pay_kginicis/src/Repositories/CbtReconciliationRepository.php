<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Order;

class CbtReconciliationRepository implements CbtReconciliationRepositoryInterface
{
    /**
     * 주문 메타에 저장된 조정 레코드를 조회합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $key  주문 메타 키
     * @return array<string, mixed>|null 조정 레코드
     */
    public function findRecord(string $orderNumber, string $key): ?array
    {
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->first(['id', 'order_meta']);

        if (! $order instanceof Order) {
            return null;
        }

        $record = $this->meta($order)[$key] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * 주문 메타에 조정 레코드를 저장합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $key  주문 메타 키
     * @param  array<string, mixed>  $record  저장할 조정 레코드
     * @return array<string, mixed>|null 저장된 조정 레코드
     */
    public function saveRecord(string $orderNumber, string $key, array $record): ?array
    {
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->first(['id', 'order_meta']);

        if (! $order instanceof Order) {
            return null;
        }

        $meta = $this->meta($order);
        $meta[$key] = $record;
        $this->saveMeta($order, $meta);

        return $record;
    }

    /**
     * 주문 row 를 잠근 상태로 조정 레코드를 원자적으로 변경합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $key  주문 메타 키
     * @param  callable(array<string, mixed>): (array<string, mixed>|null)  $mutator  레코드 변경 콜백
     * @return array<string, mixed>|null 변경된 조정 레코드
     */
    public function mutateRecordWithLock(string $orderNumber, string $key, callable $mutator): ?array
    {
        return DB::transaction(function () use ($orderNumber, $key, $mutator): ?array {
            $order = Order::query()
                ->where('order_number', $orderNumber)
                ->lockForUpdate()
                ->first(['id', 'order_meta']);

            if (! $order instanceof Order) {
                return null;
            }

            $meta = $this->meta($order);
            $existing = $meta[$key] ?? [];
            if (! is_array($existing)) {
                return null;
            }

            $record = $mutator($existing);
            if (! is_array($record)) {
                return null;
            }

            $meta[$key] = $record;
            $this->saveMeta($order, $meta);

            return $record;
        });
    }

    /**
     * 주문 메타를 배열로 반환합니다.
     *
     * @param  Order  $order  주문 모델
     * @return array<string, mixed> 주문 메타
     */
    private function meta(Order $order): array
    {
        return is_array($order->order_meta) ? $order->order_meta : [];
    }

    /**
     * 주문 메타를 저장합니다.
     *
     * @param  Order  $order  주문 모델
     * @param  array<string, mixed>  $meta  저장할 주문 메타
     * @return void
     */
    private function saveMeta(Order $order, array $meta): void
    {
        $order->forceFill([
            'order_meta' => $meta,
            'updated_at' => now(),
        ])->save();
    }
}
