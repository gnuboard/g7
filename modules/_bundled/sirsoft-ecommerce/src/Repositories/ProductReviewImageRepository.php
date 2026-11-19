<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewImageRepositoryInterface;

/**
 * 상품 리뷰 이미지 Repository 구현체
 */
class ProductReviewImageRepository implements ProductReviewImageRepositoryInterface
{
    /**
     * @param  ProductReviewImage  $model  리뷰 이미지 모델
     */
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

    /**
     * {@inheritDoc}
     */
    public function countByMimeTypes(array $mimeTypes): int
    {
        return $this->model->newQuery()
            ->whereIn('mime_type', $mimeTypes)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function chunkByMimeTypes(array $mimeTypes, int $afterId, int $limit): Collection
    {
        return $this->model->newQuery()
            ->whereIn('mime_type', $mimeTypes)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'disk', 'path', 'mime_type', 'width', 'height']);
    }
}
