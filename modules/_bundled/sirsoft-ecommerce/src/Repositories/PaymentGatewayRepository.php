<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Helpers\PermissionHelper;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\PaymentGatewayRepositoryInterface;

/**
 * 결제사 플러그인 공개 계약 구현 전용 저장소
 */
class PaymentGatewayRepository implements PaymentGatewayRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function isTransactionPaid(array $providers, string $transactionId): bool
    {
        return OrderPayment::query()
            ->whereIn('pg_provider', $providers)
            ->where('transaction_id', $transactionId)
            ->where('payment_status', PaymentStatusEnum::PAID->value)
            ->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function firstPayment(?array $providers, array $criteria): ?OrderPayment
    {
        return $this->paymentQuery($providers, $criteria)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function listPayments(?array $providers, array $criteria): Collection
    {
        return $this->paymentQuery($providers, $criteria)->get()->values();
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>|null  $providers  결제사 id 목록
     * @param  array<string, mixed>  $criteria  조건
     * @return Collection<int, OrderPayment> 세 칸만 채운 결제 행 목록
     */
    public function listPaymentBriefs(?array $providers, array $criteria): Collection
    {
        $p = (new OrderPayment)->getTable();
        $o = (new Order)->getTable();

        // 조건은 listPayments() 와 같고 읽는 칸만 줄인다 — 목록 소비자가 결제 행 전체를 메모리에 올리지 않게 한다.
        return $this->paymentQuery($providers, $criteria)
            ->select("{$p}.transaction_id", "{$p}.payment_meta", "{$o}.order_number as joined_order_number")
            ->get()
            ->values();
    }

    /**
     * {@inheritDoc}
     */
    public function latestRefundForOrder(int $orderId): ?OrderRefund
    {
        return OrderRefund::query()->where('order_id', $orderId)->orderByDesc('id')->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findOrderByNumber(string $orderNumber, array $columns = ['*']): ?Order
    {
        return Order::query()->where('order_number', $orderNumber)->first($columns);
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $orderId  주문 id
     * @return string|null 주문이 없으면 null
     */
    public function orderNumberById(int $orderId): ?string
    {
        $number = Order::query()->whereKey($orderId)->value('order_number');

        return $number === null ? null : (string) $number;
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $orderId  주문 id
     * @return bool 있으면 true
     */
    public function hasActiveOrderOptions(int $orderId): bool
    {
        return OrderOption::query()
            ->where('order_id', $orderId)
            ->where('option_status', '!=', OrderStatusEnum::CANCELLED->value)
            ->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function findOrderByProviderTransaction(array $providers, string $transactionId): ?Order
    {
        return Order::query()
            ->whereIn('id', OrderPayment::query()
                ->whereIn('pg_provider', $providers)
                ->where('transaction_id', $transactionId)
                ->select('order_id'))
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function filterOrderNumbersByScope(array $orderNumbers, string $permission): array
    {
        $query = Order::query()->whereIn('order_number', $orderNumbers);
        PermissionHelper::applyPermissionScope($query, $permission);

        return $query->pluck('order_number')->map(fn ($number) => (string) $number)->all();
    }

    /**
     * {@inheritDoc}
     */
    public function updatePayment(OrderPayment $payment, array $attributes): void
    {
        $payment->update($attributes);
    }

    /**
     * {@inheritDoc}
     */
    public function forceSavePayment(OrderPayment $payment, array $attributes): void
    {
        $payment->forceFill($attributes)->save();
    }

    /**
     * {@inheritDoc}
     */
    public function updatePaymentColumns(int $paymentId, array $attributes): void
    {
        OrderPayment::query()->whereKey($paymentId)->update($attributes);
    }

    /**
     * {@inheritDoc}
     */
    public function writeUnescapedPaymentMeta(int $paymentId, array $meta, bool $touch): void
    {
        $values = ['payment_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)];
        if ($touch) {
            $values['updated_at'] = now();
        }

        OrderPayment::query()->whereKey($paymentId)->toBase()->update($values);
    }

    /**
     * {@inheritDoc}
     */
    public function lockPaymentsForProvider(int $orderId, string $provider): Collection
    {
        return OrderPayment::query()
            ->where('order_id', $orderId)
            ->where('pg_provider', $provider)
            ->lockForUpdate()
            ->get(['id', 'payment_meta']);
    }

    /**
     * {@inheritDoc}
     */
    public function lockOrderMetaByNumber(string $orderNumber): ?Order
    {
        return Order::query()->where('order_number', $orderNumber)->lockForUpdate()->first(['id', 'order_meta']);
    }

    /**
     * {@inheritDoc}
     */
    public function saveOrderMeta(Order $order, array $meta): void
    {
        $order->forceFill(['order_meta' => $meta, 'updated_at' => now()])->save();
    }

    /**
     * {@inheritDoc}
     */
    public function firstOrCreateSampleOrder(string $orderNumber, User $owner): Order
    {
        return Order::query()->where('order_number', $orderNumber)->first()
            ?? Order::factory()->forUser($owner)->create(['order_number' => $orderNumber]);
    }

    /**
     * {@inheritDoc}
     */
    public function ensureSamplePayment(Order $order, string $provider, array $attributes): void
    {
        $exists = OrderPayment::query()->where('order_id', $order->id)->where('pg_provider', $provider)->exists();
        if (! $exists) {
            OrderPayment::factory()->forOrder($order)->create(['pg_provider' => $provider] + $attributes);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function ensureSampleShippingAddress(Order $order, array $attributes): void
    {
        $exists = OrderAddress::query()->where('order_id', $order->id)->where('address_type', 'shipping')->exists();
        if (! $exists) {
            OrderAddress::factory()->forOrder($order)->shipping()->create($attributes);
        }
    }

    /**
     * 주문번호를 함께 읽는 결제 행 조회를 조건과 함께 만듭니다.
     *
     * @param  array<int, string>|null  $providers  결제사 id 목록
     * @param  array<string, mixed>  $criteria  조건
     * @return Builder<OrderPayment> 조회
     */
    private function paymentQuery(?array $providers, array $criteria): Builder
    {
        $p = (new OrderPayment)->getTable();
        $o = (new Order)->getTable();

        $query = OrderPayment::query()
            ->join($o, "{$o}.id", '=', "{$p}.order_id")
            ->select("{$p}.*", "{$o}.order_number as joined_order_number");

        if ($providers !== null) {
            $query->whereIn("{$p}.pg_provider", $providers);
        }
        if (isset($criteria['order_number'])) {
            $query->where("{$o}.order_number", $criteria['order_number']);
        }
        if (isset($criteria['order_id'])) {
            $query->where("{$p}.order_id", $criteria['order_id']);
        }
        if (isset($criteria['order_key'])) {
            $query->where("{$o}.id", $criteria['order_key']);
        }
        if (isset($criteria['transaction_id'])) {
            $query->where("{$p}.transaction_id", $criteria['transaction_id']);
        }
        if (isset($criteria['escrow'])) {
            $query->where("{$p}.is_escrow", $criteria['escrow'] ? 1 : 0);
        }
        if (! empty($criteria['has_transaction_id'])) {
            $query->whereNotNull("{$p}.transaction_id");
        }
        if (! empty($criteria['non_empty_transaction_id'])) {
            $query->whereNotNull("{$p}.transaction_id")->where("{$p}.transaction_id", '!=', '');
        }
        if (isset($criteria['status'])) {
            $query->where("{$p}.payment_status", $criteria['status']);
        }
        if (isset($criteria['owner_user_id'])) {
            $query->where("{$o}.user_id", $criteria['owner_user_id']);
        }
        if (! empty($criteria['guest_only'])) {
            $query->whereNull("{$o}.user_id");
        }
        if (isset($criteria['created_since'])) {
            $query->where("{$p}.created_at", '>=', $criteria['created_since']);
        }
        if (! empty($criteria['require_meta'])) {
            $query->whereNotNull("{$p}.payment_meta");
        }

        return $query;
    }
}
