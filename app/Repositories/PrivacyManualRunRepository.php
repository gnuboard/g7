<?php

namespace App\Repositories;

use App\Contracts\Repositories\PrivacyManualRunRepositoryInterface;
use App\Enums\PrivacyManualRunStatus;
use App\Models\PrivacyManualRun;

/**
 * 개인정보 조치 직권 실행 기록 Repository
 */
class PrivacyManualRunRepository implements PrivacyManualRunRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?PrivacyManualRun
    {
        return PrivacyManualRun::query()->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): PrivacyManualRun
    {
        return PrivacyManualRun::query()->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(PrivacyManualRun $run, array $data): bool
    {
        return $run->update($data);
    }

    /**
     * {@inheritDoc}
     */
    public function updateIfStatus(int $id, string $expectedStatus, array $data): bool
    {
        return PrivacyManualRun::query()
            ->whereKey($id)
            ->where('status', $expectedStatus)
            ->update($data + ['updated_at' => now()]) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function findActiveByTarget(string $target): ?PrivacyManualRun
    {
        return PrivacyManualRun::query()
            ->where('target', $target)
            ->whereIn('status', PrivacyManualRunStatus::activeValues())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function latestByTargets(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        // 대상별 최대 id 한 번 + 본문 한 번 — (target, id) 인덱스로 끝난다.
        $latestIds = PrivacyManualRun::query()
            ->whereIn('target', $targets)
            ->groupBy('target')
            ->selectRaw('MAX(id) as id')
            ->pluck('id');

        return PrivacyManualRun::query()
            ->with('requester:id,name')
            ->whereKey($latestIds)
            ->get()
            ->keyBy('target')
            ->all();
    }
}
