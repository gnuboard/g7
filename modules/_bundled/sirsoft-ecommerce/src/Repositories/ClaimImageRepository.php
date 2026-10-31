<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
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
     * 해시 충돌 재시도 횟수 — 채번(`SequenceService`)과 같은 방어 형태다.
     */
    private const HASH_COLLISION_RETRIES = 3;

    /**
     * {@inheritDoc}
     *
     * `hash` 는 `ClaimImage::generateHash()` 가 "조회 후 삽입" 으로 만든다. 그 사이에 다른
     * 요청이 같은 값을 넣으면 UNIQUE 제약이 걸리는데, 그대로 두면 업로드가 500 이 된다 —
     * 사용자에게는 원인 없는 실패이고 방금 올린 파일도 되돌려진다. 제약 위반(SQLSTATE 23000)
     * 이면 해시를 비워 다시 채번하고 재시도한다(모델의 `creating` 훅이 새 값을 만든다).
     *
     * 이 테이블의 UNIQUE 는 `hash` 하나뿐이라 23000 은 곧 해시 충돌이다. 호출부가 해시를
     * 직접 넘긴 경우에는 그 값이 의도이므로 재채번하지 않고 그대로 올려보낸다.
     */
    public function create(array $data): ClaimImage
    {
        $callerSuppliedHash = array_key_exists('hash', $data) && ! empty($data['hash']);

        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->model->create($data);
            } catch (QueryException $e) {
                // getCode() 가 int/string 을 오가므로 느슨한 비교를 쓴다 (SequenceService 와 동일).
                if ($callerSuppliedHash || $e->getCode() != 23000 || $attempt >= self::HASH_COLLISION_RETRIES) {
                    throw $e;
                }

                Log::warning('[claim.image] 첨부 해시 충돌 — 재채번 후 재시도', [
                    'attempt' => $attempt + 1,
                    'claimable_type' => $data['claimable_type'] ?? null,
                    'claimable_id' => $data['claimable_id'] ?? null,
                ]);

                unset($data['hash']);
            }
        }
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
    public function listPendingByOrder(int $orderId): Collection
    {
        return $this->model->newQuery()
            ->where('claimable_type', ClaimImage::PENDING_CLAIMABLE_TYPE)
            ->where('claimable_id', $orderId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function listPendingOlderThan(Carbon $cutoff, int $limit): Collection
    {
        return $this->model->newQuery()
            ->where('claimable_type', ClaimImage::PENDING_CLAIMABLE_TYPE)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function listByIds(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return $this->model->newQuery()
            ->whereIn('id', $ids)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function update(ClaimImage $image, array $data): ClaimImage
    {
        $image->update($data);

        return $image->refresh();
    }
}
