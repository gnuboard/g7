<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ContentMediaRepositoryInterface;

/**
 * 이커머스 본문 미디어 Repository 구현체
 *
 * 이 모듈 범용 첨부 표(`ecommerce_attachments`)의 본문 미디어 행만 다룬다 — `ContentMedia` 의 전역 범위가 컬렉션(`content_media`)을
 * 묶는다(#887). 소유 종류는 `attachmentable_type`, 소유자는 `attachmentable_id`(null 이면 저장 전 임시)다.
 */
class ContentMediaRepository implements ContentMediaRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function create(ContentMediaOwnerType $ownerType, ?int $ownerId, array $data): ContentMedia
    {
        return ContentMedia::create(array_merge($data, [
            'attachmentable_type' => $ownerType->morphType(),
            'attachmentable_id' => $ownerId,
        ]));
    }

    /**
     * {@inheritDoc}
     */
    public function countTemp(ContentMediaOwnerType $ownerType, string $tempKey): int
    {
        return $this->pendingUpload($ownerType, $tempKey)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function getTempByHashes(ContentMediaOwnerType $ownerType, string $tempKey, array $hashes): Collection
    {
        if ($hashes === []) {
            return new Collection;
        }

        return $this->pendingUpload($ownerType, $tempKey)->whereIn('hash', $hashes)->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getByOwner(ContentMediaOwnerType $ownerType, int $ownerId): Collection
    {
        return ContentMedia::query()
            ->where('attachmentable_type', $ownerType->morphType())
            ->where('attachmentable_id', $ownerId)
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getByOwnerWithTrashed(ContentMediaOwnerType $ownerType, int $ownerId): Collection
    {
        return ContentMedia::withTrashed()
            ->where('attachmentable_type', $ownerType->morphType())
            ->where('attachmentable_id', $ownerId)
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByHash(string $hash): ?ContentMedia
    {
        return ContentMedia::query()->where('hash', $hash)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function link(ContentMedia $media, int $ownerId, int $order = 0): void
    {
        $media->update(['attachmentable_id' => $ownerId, 'temp_key' => null, 'order' => $order]);
    }

    /**
     * {@inheritDoc}
     */
    public function softDelete(ContentMedia $media): void
    {
        $media->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function findStaleTemp(Carbon $threshold, int $limit): Collection
    {
        return ContentMedia::query()
            ->whereNull('attachmentable_id')
            ->whereNotNull('temp_key')
            ->where('created_at', '<', $threshold)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findStaleRemoved(Carbon $threshold, int $limit): Collection
    {
        return ContentMedia::onlyTrashed()
            ->whereNotNull('attachmentable_id')
            ->where('deleted_at', '<', $threshold)
            ->orderBy('deleted_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function forceDelete(ContentMedia $media): bool
    {
        return (bool) $media->forceDelete();
    }

    /**
     * 그 소유 종류·임시 키로 올린, 아직 잇지 않은 임시 행 질의.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  string  $tempKey  임시 키
     * @return Builder<ContentMedia> 질의
     */
    private function pendingUpload(ContentMediaOwnerType $ownerType, string $tempKey): Builder
    {
        return ContentMedia::query()
            ->where('attachmentable_type', $ownerType->morphType())
            ->whereNull('attachmentable_id')
            ->where('temp_key', $tempKey);
    }
}
