<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
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
    /**
     * 현금영수증 이력 Repository 를 생성합니다.
     *
     * @param  OrderCashReceipt  $model  현금영수증 이력 모델
     */
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

    /**
     * {@inheritDoc}
     */
    public function reserveIssue(array $attributes): ?OrderCashReceipt
    {
        $attributes = array_merge($attributes, [
            'transaction_type' => CashReceiptTransactionType::ISSUE->value,
            'issue_status' => CashReceiptIssueStatus::IN_PROGRESS->value,
            'slot_released_at' => null,
            'cancel_target_id' => null,
        ]);

        return $this->insertOrNullOnConflict($attributes);
    }

    /**
     * {@inheritDoc}
     */
    public function findSlotHolder(Order $order): ?OrderCashReceipt
    {
        // 생성 컬럼 active_issue_order_key 의 식과 같은 조건이다 — 유니크가 지키는 바로 그 행을 찾는다.
        return $this->model->newQuery()
            ->where('order_id', $order->id)
            ->where('transaction_type', CashReceiptTransactionType::ISSUE->value)
            ->whereIn('issue_status', [
                CashReceiptIssueStatus::IN_PROGRESS->value,
                CashReceiptIssueStatus::COMPLETED->value,
            ])
            ->whereNull('slot_released_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function reserveCancel(OrderCashReceipt $issue, array $attributes): ?OrderCashReceipt
    {
        $attributes = array_merge($attributes, [
            'order_id' => $issue->order_id,
            'transaction_type' => CashReceiptTransactionType::CANCEL->value,
            'issue_status' => CashReceiptIssueStatus::IN_PROGRESS->value,
            'cancel_target_id' => $issue->id,
            'slot_released_at' => null,
        ]);

        return $this->insertOrNullOnConflict($attributes);
    }

    /**
     * {@inheritDoc}
     */
    public function findOpenCancel(OrderCashReceipt $issue): ?OrderCashReceipt
    {
        // 생성 컬럼 open_cancel_target_key 의 식과 같은 조건이다.
        return $this->model->newQuery()
            ->where('cancel_target_id', $issue->id)
            ->where('transaction_type', CashReceiptTransactionType::CANCEL->value)
            ->whereIn('issue_status', [
                CashReceiptIssueStatus::IN_PROGRESS->value,
                CashReceiptIssueStatus::COMPLETED->value,
            ])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function updateIfStatus(int $id, CashReceiptIssueStatus $expected, array $attributes): int
    {
        // 쿼리 빌더 update 는 모델 캐스트를 거치지 않는다 — enum·배열(JSON)·일시를 저장 형태로 바꾸려고
        // 빈 모델에 채운 뒤 저장 형태 속성을 꺼낸다.
        $values = $this->model->newInstance()->forceFill($attributes)->getAttributes();

        return $this->model->newQuery()
            ->whereKey($id)
            ->where('issue_status', $expected->value)
            ->update($values);
    }

    /**
     * {@inheritDoc}
     */
    public function completeCancel(OrderCashReceipt $cancel, OrderCashReceipt $issue, array $attributes): bool
    {
        return $this->model->getConnection()->transaction(function () use ($cancel, $issue, $attributes) {
            $updated = $this->updateIfStatus($cancel->id, CashReceiptIssueStatus::IN_PROGRESS, array_merge($attributes, [
                'issue_status' => CashReceiptIssueStatus::COMPLETED->value,
                'error_code' => null,
                'error_message' => null,
            ]));

            if ($updated === 0) {
                return false;
            }

            $this->model->newQuery()
                ->whereKey($issue->id)
                ->whereNull('slot_released_at')
                ->update(['slot_released_at' => now()]);

            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function countIssuesUpTo(Order $order, int $id): int
    {
        return $this->model->newQuery()
            ->where('order_id', $order->id)
            ->where('transaction_type', CashReceiptTransactionType::ISSUE->value)
            ->where('id', '<=', $id)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function findInProgressIssue(Order $order): ?OrderCashReceipt
    {
        return $this->model->newQuery()
            ->where('order_id', $order->id)
            ->where('transaction_type', CashReceiptTransactionType::ISSUE->value)
            ->where('issue_status', CashReceiptIssueStatus::IN_PROGRESS->value)
            ->whereNull('slot_released_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 행을 INSERT 하고, 유니크 위반이면 null 을 돌려줍니다.
     *
     * 세이브포인트(중첩 트랜잭션) 안에서 INSERT 해 위반 시 그 문장만 되돌린다 — 호출부가 이미 트랜잭션 안에
     * 있어도 바깥 트랜잭션이 깨지지 않는다.
     *
     * @param  array<string, mixed>  $attributes  이력 데이터
     * @return OrderCashReceipt|null 생성된 행 (유니크 위반이면 null)
     */
    private function insertOrNullOnConflict(array $attributes): ?OrderCashReceipt
    {
        try {
            return $this->model->getConnection()->transaction(
                fn () => $this->model->newQuery()->create($attributes)
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
