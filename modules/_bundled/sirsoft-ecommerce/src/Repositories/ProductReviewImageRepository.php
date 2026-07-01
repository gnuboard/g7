<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewImageRepositoryInterface;

/**
 * 상품 리뷰 이미지 Repository 구현체
 */
class ProductReviewImageRepository implements ProductReviewImageRepositoryInterface
{
    public function __construct(
        protected ProductReviewImage $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ProductReviewImage
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function findByHash(string $hash): ?ProductReviewImage
    {
        return $this->model->where('hash', $hash)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(ProductReviewImage $image): bool
    {
        return (bool) $image->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        return $this->model->whereIn('id', $ids)->delete();
    }
}
