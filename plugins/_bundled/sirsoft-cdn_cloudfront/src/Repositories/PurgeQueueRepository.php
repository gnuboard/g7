<?php

namespace Plugins\Sirsoft\CdnCloudfront\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\CdnCloudfront\Models\CloudfrontPurge;
use Plugins\Sirsoft\CdnCloudfront\Repositories\Contracts\PurgeQueueRepositoryInterface;

/**
 * 실패한 캐시 무효화 대기열 저장소 (Eloquent)
 */
class PurgeQueueRepository implements PurgeQueueRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function create(array $paths, string $callerReference, int $attempts, string $error, ?Carbon $nextAttemptAt): CloudfrontPurge
    {
        return CloudfrontPurge::query()->create([
            'caller_reference' => $callerReference,
            'paths' => array_values($paths),
            'attempts' => $attempts,
            'last_error' => $error,
            'next_attempt_at' => $nextAttemptAt,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function due(Carbon $now, int $limit): Collection
    {
        return CloudfrontPurge::query()
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', $now)
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function recordFailure(CloudfrontPurge $purge, int $attempts, string $error, ?Carbon $nextAttemptAt): void
    {
        $purge->forceFill([
            'attempts' => $attempts,
            'last_error' => $error,
            'next_attempt_at' => $nextAttemptAt,
        ])->save();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(CloudfrontPurge $purge): void
    {
        $purge->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteAbandonedBefore(Carbon $before): int
    {
        return CloudfrontPurge::query()->whereNull('next_attempt_at')->where('updated_at', '<', $before)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function countPending(): int
    {
        return CloudfrontPurge::query()->whereNotNull('next_attempt_at')->count();
    }

    /**
     * {@inheritDoc}
     */
    public function countAbandoned(): int
    {
        return CloudfrontPurge::query()->whereNull('next_attempt_at')->count();
    }

    /**
     * {@inheritDoc}
     */
    public function latestFailure(): ?CloudfrontPurge
    {
        return CloudfrontPurge::query()->orderByDesc('updated_at')->orderByDesc('id')->first();
    }
}
