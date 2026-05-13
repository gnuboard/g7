<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;

/**
 * 장바구니 Repository 구현체
 */
class CartRepository implements CartRepositoryInterface
{
    public function __construct(
        protected Cart $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function find(int $id): ?Cart
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Cart
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(Cart $cart, array $data): Cart
    {
        $cart->update($data);

        return $cart->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(Cart $cart): bool
    {
        return $cart->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserId(int $userId): Collection
    {
        return $this->model
            ->with(['product.images', 'productOption'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByCartKeyWithoutUser(string $cartKey): Collection
    {
        return $this->model
            ->with(['product.images', 'productOption'])
            ->where('cart_key', $cartKey)
            ->whereNull('user_id')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserAndOption(int $userId, int $productOptionId): ?Cart
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('product_option_id', $productOptionId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByCartKeyAndOption(string $cartKey, int $productOptionId): ?Cart
    {
        return $this->model
            ->where('cart_key', $cartKey)
            ->whereNull('user_id')
            ->where('product_option_id', $productOptionId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIds(array $ids): Collection
    {
        return $this->model
            ->with(['product.images', 'productOption'])
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByIds(array $ids): int
    {
        return $this->model->whereIn('id', $ids)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByUserId(int $userId): int
    {
        return $this->model->where('user_id', $userId)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByCartKey(string $cartKey): int
    {
        return $this->model
            ->where('cart_key', $cartKey)
            ->whereNull('user_id')
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function countItems(?int $userId, ?string $cartKey): int
    {
        $query = $this->model->newQuery();

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } elseif ($cartKey !== null) {
            $query->where('cart_key', $cartKey)->whereNull('user_id');
        } else {
            return 0;
        }

        return $query->count();
    }

    /**
     * {@inheritDoc}
     */
    public function existsByCartKey(string $cartKey): bool
    {
        return $this->model->where('cart_key', $cartKey)->exists();
    }
}
