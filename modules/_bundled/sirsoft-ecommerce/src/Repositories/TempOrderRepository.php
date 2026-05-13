<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\TempOrderRepositoryInterface;

/**
 * 임시 주문 Repository 구현체
 */
class TempOrderRepository implements TempOrderRepositoryInterface
{
    public function __construct(
        protected TempOrder $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function find(int $id): ?TempOrder
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByCartKey(string $cartKey): ?TempOrder
    {
        return $this->model
            ->where('cart_key', $cartKey)
            ->whereNull('user_id')
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserId(int $userId): ?TempOrder
    {
        return $this->model
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): TempOrder
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(TempOrder $tempOrder, array $data): TempOrder
    {
        $tempOrder->update($data);

        return $tempOrder->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(TempOrder $tempOrder): bool
    {
        return $tempOrder->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function upsert(array $data): TempOrder
    {
        $existing = null;

        // 회원인 경우 user_id로 조회
        if (! empty($data['user_id'])) {
            $existing = $this->findByUserId($data['user_id']);
        }
        // 비회원인 경우 cart_key로 조회
        elseif (! empty($data['cart_key'])) {
            $existing = $this->findByCartKey($data['cart_key']);
        }

        if ($existing) {
            return $this->update($existing, $data);
        }

        return $this->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteExpired(): int
    {
        return $this->model
            ->where('expires_at', '<', Carbon::now())
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByCartKey(string $cartKey): bool
    {
        $tempOrder = $this->findByCartKey($cartKey);

        if (! $tempOrder) {
            return false;
        }

        return $this->delete($tempOrder);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByUserId(int $userId): bool
    {
        $tempOrder = $this->findByUserId($userId);

        if (! $tempOrder) {
            return false;
        }

        return $this->delete($tempOrder);
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserOrCartKey(?int $userId, ?string $cartKey): ?TempOrder
    {
        if ($userId !== null) {
            return $this->findByUserId($userId);
        }

        if ($cartKey !== null) {
            return $this->findByCartKey($cartKey);
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function findValidByUserOrCartKey(?int $userId, ?string $cartKey): ?TempOrder
    {
        $tempOrder = $this->findByUserOrCartKey($userId, $cartKey);

        if ($tempOrder === null) {
            return null;
        }

        // 만료된 경우 null 반환
        if ($tempOrder->isExpired()) {
            return null;
        }

        return $tempOrder;
    }
}
