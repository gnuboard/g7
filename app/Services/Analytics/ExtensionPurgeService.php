<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Contracts\Repositories\Analytics\ConversionRepositoryInterface;
use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Extension\HookManager;
use App\Services\Analytics\Concerns\BroadcastsRunProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 확장이 남긴 통계 데이터 정리
 *
 * 확장을 데이터까지 지우며 제거할 때, 그 확장이 남긴 전환과 집계 차원을 지웁니다.
 * 지우지 않으면 사라진 확장의 목표·차원이 리포트에 영원히 남아, 운영자는 어디서
 * 온 수치인지 알 수 없고 라벨도 풀 수 없습니다.
 *
 * **원장은 건드리지 않습니다.** 방문·액션은 확장이 아니라 방문자의 기록이고,
 * `custom_dimensions` 안의 확장 키까지 지우려면 원장 전량을 훑어야 합니다. 그 비용은
 * 제거 절차가 감당할 것이 아니므로 집계와 전환만 지웁니다.
 */
class ExtensionPurgeService
{
    use BroadcastsRunProgress;

    /**
     * 정리기를 생성합니다.
     *
     * @param  ConversionRepositoryInterface  $conversions  전환 원장 저장소
     * @param  StatsRepositoryInterface  $stats  집계 저장소
     * @param  ArchiveRunRepositoryInterface  $runs  집계 실행 이력 저장소
     */
    public function __construct(
        private readonly ConversionRepositoryInterface $conversions,
        private readonly StatsRepositoryInterface $stats,
        private readonly ArchiveRunRepositoryInterface $runs,
    ) {}

    /**
     * 확장이 남긴 통계 데이터를 지웁니다.
     *
     * 실행 이력을 남기는 이유는 이 삭제가 운영자 눈에 보이지 않는 곳에서 일어나기
     * 때문입니다. 「어제까지 있던 전환 수치가 왜 사라졌는지」를 진단 화면의 실행
     * 이력에서 찾을 수 있어야 합니다.
     *
     * @param  string  $type  확장 종류 (module, plugin)
     * @param  string  $identifier  확장 식별자
     * @return array{conversions: int, dimensions: int, failed: bool} 대상별 삭제 건수와 실패 여부
     */
    public function purge(string $type, string $identifier): array
    {
        $now = AnalyticsClock::now();

        $run = $this->runs->create([
            'run_type' => ArchiveRunType::ExtensionPurge->value,
            'status' => ArchiveRunStatus::Running->value,
            'period_start' => $now,
            'period_end' => $now,
            // `trigger` 는 10자 컬럼이고 실행 주체(scheduled·manual)를 담는 자리다.
            // 어느 확장을 지웠는지는 `error` 가 아니라 훅·로그로 남긴다.
            'trigger' => 'manual',
            'started_at' => $now,
        ]);

        // 진단 화면은 이 표의 행 하나를 진행률의 출처로 삼는다 — 집계가 아닌 실행도 같은 자리에
        // 뜨므로 같은 채널로 알린다(#682 S3 라운드 2).
        $this->broadcastRunProgress($run);

        try {
            // 두 삭제를 한 트랜잭션으로 묶는다. 전환만 지워지고 차원이 남으면 리포트에
            // 라벨 없는 집계 행이 떠도는데, 실행 이력은 실패로 적혀 있어 운영자가
            // 「아무것도 안 지워졌다」고 읽고 되돌릴 근거도 잃는다.
            $counts = DB::transaction(fn (): array => [
                'conversions' => $this->conversions->deleteForExtension($identifier),
                'dimensions' => $this->stats->deleteDimensionsForExtension($identifier),
            ]);
        } catch (\Throwable $e) {
            $this->runs->update($run, [
                'status' => ArchiveRunStatus::Failed->value,
                'error' => $e->getMessage(),
                'finished_at' => AnalyticsClock::now(),
            ]);

            $this->broadcastRunProgress($run->refresh());

            // 확장 제거 자체는 이미 끝났다 — 통계 정리가 실패했다고 제거를 되돌릴 수는
            // 없으므로, 예외를 삼키고 운영자가 볼 수 있는 자리에 남긴다.
            Log::warning('확장 통계 데이터 정리 실패', [
                'extension_type' => $type,
                'extension_identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            // 훅은 성공·실패 양쪽에서 쏜다. 활동 로그가 이 훅만 구독하므로, 실패에
            // 침묵하면 「지워진 줄 알았는데 남아 있다」를 운영자가 알 길이 없다.
            // 트랜잭션이 되돌렸으므로 실제로 지워진 행은 없다 — 0 이 사실이다.
            HookManager::doAction('core.analytics.extension_purged', [
                'extension_type' => $type,
                'extension_identifier' => $identifier,
                'counts' => ['conversions' => 0, 'dimensions' => 0],
                'failed' => true,
            ]);

            return ['conversions' => 0, 'dimensions' => 0, 'failed' => true];
        }

        $this->runs->update($run, [
            'status' => ArchiveRunStatus::Success->value,
            'processed_visits' => $counts['conversions'],
            'processed_actions' => $counts['dimensions'],
            'progress_percent' => 100,
            'finished_at' => AnalyticsClock::now(),
        ]);

        $this->broadcastRunProgress($run->refresh());

        HookManager::doAction('core.analytics.extension_purged', [
            'extension_type' => $type,
            'extension_identifier' => $identifier,
            'counts' => $counts,
            'failed' => false,
        ]);

        return [...$counts, 'failed' => false];
    }
}
