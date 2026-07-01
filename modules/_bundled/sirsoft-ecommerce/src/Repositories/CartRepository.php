<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
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
            ->with(['product.images', 'product.additionalOptions.values', 'product.shippingPolicy.countrySettings', 'productOption'])
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
            ->with(['product.images', 'product.additionalOptions.values', 'product.shippingPolicy.countrySettings', 'productOption'])
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
    public function findAllByUserAndOption(int $userId, int $productOptionId): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('product_option_id', $productOptionId)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findAllByCartKeyAndOption(string $cartKey, int $productOptionId): Collection
    {
        return $this->model
            ->where('cart_key', $cartKey)
            ->whereNull('user_id')
            ->where('product_option_id', $productOptionId)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIds(array $ids): Collection
    {
        return $this->model
            ->with(['product.images', 'product.additionalOptions.values', 'product.shippingPolicy.countrySettings', 'productOption'])
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

    /**
     * {@inheritDoc}
     */
    public function sumQuantityByProduct(int $productId, ?int $userId, ?string $cartKey, ?int $excludeCartId = null): int
    {
        $query = $this->model->where('product_id', $productId);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } elseif ($cartKey !== null) {
            $query->where('cart_key', $cartKey)->whereNull('user_id');
        } else {
            return 0;
        }

        if ($excludeCartId !== null) {
            $query->where('id', '!=', $excludeCartId);
        }

        return (int) $query->sum('quantity');
    }

    /**
     * {@inheritDoc}
     */
    public function pruneExpiredItems(int $days, ?int $limit = null): int
    {
        // 만료 비활성 정책 — days < 1 이면 한 건도 삭제하지 않음 (전체 삭제 사고 차단)
        if ($days < 1) {
            return 0;
        }

        $threshold = Carbon::now()->subDays($days);

        // limit 미지정 시 단일 delete (정각/직전 보존 위해 '<' 비교)
        if ($limit === null) {
            return $this->model->where('updated_at', '<', $threshold)->delete();
        }

        // limit 지정 시 대상 id 를 청크 조회 후 위임 삭제 (대량 삭제 안전)
        $ids = $this->model
            ->where('updated_at', '<', $threshold)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            return 0;
        }

        return $this->deleteByIds($ids);
    }
}
