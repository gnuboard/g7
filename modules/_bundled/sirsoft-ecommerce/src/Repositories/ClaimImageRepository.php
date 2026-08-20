<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimImageRepositoryInterface;

/**
 * 클레임 첨부 이미지 리포지토리 구현체
 */
class ClaimImageRepository implements ClaimImageRepositoryInterface
{
    /**
     * @param  ClaimImage  $model  클레임 첨부 이미지 모델
     */
    public function __construct(
        protected ClaimImage $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ClaimImage
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?ClaimImage
    {
        return $this->model->newQuery()->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByHash(string $hash): ?ClaimImage
    {
        return $this->model->newQuery()
            ->where('hash', $hash)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function listByClaimable(string $claimableType, int $claimableId): Collection
    {
        return $this->model->newQuery()
            ->where('claimable_type', $claimableType)
            ->where('claimable_id', $claimableId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function countByClaimable(string $claimableType, int $claimableId): int
    {
        return $this->model->newQuery()
            ->where('claimable_type', $claimableType)
            ->where('claimable_id', $claimableId)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(ClaimImage $image): bool
    {
        return (bool) $image->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByClaimable(string $claimableType, int $claimableId): int
    {
        return $this->model->newQuery()
            ->where('claimable_type', $claimableType)
            ->where('claimable_id', $claimableId)
            ->delete();
    }
}
