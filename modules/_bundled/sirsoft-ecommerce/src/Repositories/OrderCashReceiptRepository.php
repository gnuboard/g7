<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptTransactionType;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCashReceiptRepositoryInterface;

/**
 * 주문 현금영수증 이력 Repository 구현체
 */
class OrderCashReceiptRepository implements OrderCashReceiptRepositoryInterface
{
    public function __construct(
        protected OrderCashReceipt $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderCashReceipt
    {
        return $this->model->newQuery()->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function findByOrder(Order $order): Collection
    {
        return $this->model->newQuery()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findActiveReceipt(Order $order): ?OrderCashReceipt
    {
        return $this->findActiveReceipts($order)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findActiveReceipts(Order $order): Collection
    {
        // DB 에서 후보(발급 완료 건 + 취소 완료 건)만 좁힌 뒤,
        // 활성 판정은 OrderCashReceipt::filterActive() SSoT 에 위임한다.
        $rows = $this->model->newQuery()
            ->where('order_id', $order->id)
            ->whereIn('transaction_type', [
                CashReceiptTransactionType::ISSUE->value,
                CashReceiptTransactionType::CANCEL->value,
            ])
            ->where('issue_status', CashReceiptIssueStatus::COMPLETED->value)
            ->orderByDesc('id')
            ->get();

        return $this->model->newCollection(OrderCashReceipt::filterActive($rows));
    }

    /**
     * {@inheritDoc}
     */
    public function findByReceiptKey(string $receiptKey): ?OrderCashReceipt
    {
        return $this->model->newQuery()
            ->where('receipt_key', $receiptKey)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function countIssues(Order $order): int
    {
        return $this->model->newQuery()
            ->where('order_id', $order->id)
            ->where('transaction_type', CashReceiptTransactionType::ISSUE->value)
            ->count();
    }
}
