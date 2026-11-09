<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ContentMediaRepositoryInterface;

/**
 * 이커머스 본문 미디어 Repository 구현체
 */
class ContentMediaRepository implements ContentMediaRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function create(array $data): ContentMedia
    {
        return ContentMedia::create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function countTemp(ContentMediaOwnerType $ownerType, string $tempKey): int
    {
        return ContentMedia::query()
            ->where('owner_type', $ownerType->value)
            ->whereNull('owner_id')
            ->where('temp_key', $tempKey)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function getTempByHashes(ContentMediaOwnerType $ownerType, string $tempKey, array $hashes): Collection
    {
        if ($hashes === []) {
            return new Collection;
        }

        return ContentMedia::query()
            ->where('owner_type', $ownerType->value)
            ->whereNull('owner_id')
            ->where('temp_key', $tempKey)
            ->whereIn('hash', $hashes)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getByOwner(ContentMediaOwnerType $ownerType, int $ownerId): Collection
    {
        return ContentMedia::query()
            ->where('owner_type', $ownerType->value)
            ->where('owner_id', $ownerId)
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
    public function link(ContentMedia $media, int $ownerId): void
    {
        $media->update(['owner_id' => $ownerId, 'temp_key' => null]);
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
            ->whereNull('owner_id')
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
    public function forceDelete(ContentMedia $media): bool
    {
        return (bool) $media->forceDelete();
    }
}
