<?php

namespace App\Repositories;

use App\Contracts\Repositories\UpgradeStepRunRepositoryInterface;
use App\Enums\UpgradeStepRunStatus;
use App\Models\UpgradeStepRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * 업그레이드 스텝 실행 대장 Repository.
 *
 * @since 7.2.0
 */
class UpgradeStepRunRepository implements UpgradeStepRunRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function available(): bool
    {
        try {
            return Schema::hasTable('upgrade_step_runs');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function find(string $scope, string $identifier, string $version, string $step): ?UpgradeStepRun
    {
        return UpgradeStepRun::query()
            ->where('scope', $scope)
            ->where('identifier', $identifier)
            ->where('version', $version)
            ->where('step', $step)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function insertRunning(string $scope, string $identifier, string $version, string $step, ?string $host): bool
    {
        try {
            UpgradeStepRun::query()->create([
                'scope' => $scope,
                'identifier' => $identifier,
                'version' => $version,
                'step' => $step,
                'status' => UpgradeStepRunStatus::Running->value,
                'host' => $host,
                'started_at' => now(),
            ]);

            return true;
        } catch (QueryException $e) {
            // 유니크 위반 — 다른 실행이 먼저 행을 만들었다
            if (in_array((string) ($e->errorInfo[0] ?? ''), ['23000', '23505'], true)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function reclaim(UpgradeStepRun $run, array $fromStatuses, ?string $host, ?\DateTimeInterface $staleRunningBefore = null): bool
    {
        $settled = [];
        $includesRunning = false;
        foreach ($fromStatuses as $status) {
            if ($status === UpgradeStepRunStatus::Running) {
                $includesRunning = true;
            } else {
                $settled[] = $status->value;
            }
        }

        return UpgradeStepRun::query()
            ->whereKey($run->getKey())
            ->where(function ($query) use ($settled, $includesRunning, $run, $staleRunningBefore): void {
                $query->whereIn('status', $settled);

                if ($includesRunning) {
                    // 읽은 뒤 다른 실행이 먼저 다시 선점했으면 시작 시각이 바뀌어 있다 — 그때는 선점하지 않는다
                    $query->orWhere(function ($running) use ($run): void {
                        $running->where('status', UpgradeStepRunStatus::Running->value);
                        $startedAt = $run->getRawOriginal('started_at');
                        $startedAt === null
                            ? $running->whereNull('started_at')
                            : $running->where('started_at', $startedAt);
                    });
                } elseif ($staleRunningBefore !== null) {
                    $query->orWhere(function ($running) use ($staleRunningBefore): void {
                        $running->where('status', UpgradeStepRunStatus::Running->value)
                            ->where(function ($stale) use ($staleRunningBefore): void {
                                $stale->whereNull('started_at')->orWhere('started_at', '<', $staleRunningBefore);
                            });
                    });
                }
            })
            ->update([
                'status' => UpgradeStepRunStatus::Running->value,
                'host' => $host,
                'started_at' => now(),
                'finished_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function finish(string $scope, string $identifier, string $version, string $step, UpgradeStepRunStatus $status, ?string $fingerprint = null): void
    {
        $attributes = ['status' => $status->value, 'finished_at' => now(), 'updated_at' => now()];
        if ($status === UpgradeStepRunStatus::Done) {
            $attributes['fingerprint'] = $fingerprint;
        }

        UpgradeStepRun::query()
            ->where('scope', $scope)
            ->where('identifier', $identifier)
            ->where('version', $version)
            ->where('step', $step)
            ->update($attributes);
    }
}
