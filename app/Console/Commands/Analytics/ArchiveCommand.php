<?php

namespace App\Console\Commands\Analytics;

use App\Analytics\AnalyticsClock;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Services\Analytics\ArchiveCatchUpService;
use App\Services\Analytics\ArchiveService;
use App\Services\Analytics\StaleRunService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 방문자 통계 집계 커맨드
 *
 * 매시 실행은 오늘치를 다시 계산하고, 매일 실행은 어제치를 확정합니다. 기간을 주면
 * 그 구간을 하루씩 다시 계산합니다 — 집계 규칙이 바뀌었을 때 과거를 맞추는 길입니다.
 *
 * 집계가 실패해도 종료 코드로만 알리고 예외를 밖으로 던지지 않습니다. 스케줄러가
 * 멈추면 그날 이후 모든 통계가 함께 멈추기 때문입니다.
 *
 * 스케줄러 등록(`routes/console.php`)에만 `--scheduled` 를 붙인다. 통계 화면은 「예약 실행이 최근에
 * 돌았는가」로 스케줄러 생존을 판정하는데, 운영자가 손으로 돌린 실행까지 예약으로 적히면 cron 이
 * 없는데도 살아 있는 것으로 보인다.
 *
 * @example php artisan analytics:archive --period=hour
 * @example php artisan analytics:archive --period=day
 * @example php artisan analytics:archive --from=2026-09-01 --to=2026-09-15 --force
 */
class ArchiveCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'analytics:archive
                            {--period=day : 집계 단위 (hour, day)}
                            {--from= : 재집계 시작일 (Y-m-d)}
                            {--to= : 재집계 종료일 (Y-m-d)}
                            {--force : 이미 확정된 날도 다시 집계}
                            {--dry-run : 집계 표를 바꾸지 않고 대상만 확인}
                            {--scheduled : 스케줄러 등록 실행으로 기록 (없으면 수동 실행으로 기록)}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '방문자 통계 원장을 시간·일·차원 집계 표로 만듭니다.';

    /**
     * 커맨드 실행
     *
     * @param  ArchiveService  $service  집계 서비스
     * @param  StaleRunService  $staleRuns  멈춘 실행 정리 서비스
     * @param  ArchiveCatchUpService  $catchUp  밀린 날 따라잡기
     * @return int 종료 코드
     */
    public function handle(ArchiveService $service, StaleRunService $staleRuns, ArchiveCatchUpService $catchUp): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $dryRun = (bool) $this->option('dry-run');

        if ($from !== null || $to !== null) {
            return $this->handleRange($service, (string) ($from ?? $to), (string) ($to ?? $from), $dryRun);
        }

        $period = (string) $this->option('period');

        if (! in_array($period, ['hour', 'day'], true)) {
            $this->error('집계 단위는 hour 또는 day 여야 합니다.');

            return self::FAILURE;
        }

        // 매시 실행이 끝을 알리지 못하고 멈춘 실행 이력을 닫는다 — 워커가 도중에 죽은 행이
        // 「실행 중」으로 남아 진단 화면의 수동 집계 버튼을 잠그지 않게(#682 S3 라운드 13).
        if ($period === 'hour' && ! $dryRun) {
            $closed = $staleRuns->failStale();

            if ($closed > 0) {
                $this->warn(sprintf('끝을 알리지 못하고 멈춘 실행 %d건을 실패로 닫았습니다.', $closed));
            }
        }

        return $period === 'hour'
            ? $this->handleHour($service, $dryRun)
            : $this->handleDay($service, $catchUp, $dryRun);
    }

    /**
     * 이 실행을 이력에 무엇으로 남길지 정합니다.
     *
     * @return string 실행 주체 (scheduled, manual)
     */
    private function trigger(): string
    {
        return $this->option('scheduled') ? 'scheduled' : 'manual';
    }

    /**
     * 매시 집계를 수행합니다.
     *
     * @param  ArchiveService  $service  집계 서비스
     * @param  bool  $dryRun  마른 실행 여부
     * @return int 종료 코드
     */
    private function handleHour(ArchiveService $service, bool $dryRun): int
    {
        $today = AnalyticsClock::today();

        $run = $service->archiveHour([
            'trigger' => $this->trigger(),
            'dry_run' => $dryRun,
        ]);

        return $this->report($run, $today, $dryRun);
    }

    /**
     * 어제치를 확정 집계하고, 스케줄러가 멈춘 동안 밀린 날도 확정합니다.
     *
     * @param  ArchiveService  $service  집계 서비스
     * @param  ArchiveCatchUpService  $catchUp  밀린 날 따라잡기
     * @param  bool  $dryRun  마른 실행 여부
     * @return int 종료 코드
     */
    private function handleDay(ArchiveService $service, ArchiveCatchUpService $catchUp, bool $dryRun): int
    {
        $yesterday = Carbon::parse(AnalyticsClock::today())->subDay()->toDateString();

        $run = $service->archiveDay($yesterday, [
            'run_type' => ArchiveRunType::Day->value,
            'trigger' => $this->trigger(),
            'is_final' => true,
            'dry_run' => $dryRun,
        ]);

        $exitCode = $this->report($run, $yesterday, $dryRun);

        if ($dryRun) {
            return $exitCode;
        }

        // 어제 말고도 확정되지 않은 날(스케줄러가 멈췄던 날)이 보존기간 안에 있으면 최근 날부터 확정한다.
        $dates = array_keys($catchUp->candidates(ArchiveCatchUpService::MAX_DAYS));

        foreach ($catchUp->finalize($dates, $this->trigger()) as $caughtUp) {
            $date = AnalyticsClock::dateOf($caughtUp->period_start ?? AnalyticsClock::now());

            if ($this->report($caughtUp, $date, false) === self::FAILURE) {
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    /**
     * 기간을 하루씩 다시 집계합니다.
     *
     * @param  ArchiveService  $service  집계 서비스
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  bool  $dryRun  마른 실행 여부
     * @return int 종료 코드
     */
    private function handleRange(ArchiveService $service, string $from, string $to, bool $dryRun): int
    {
        if (strtotime($from) === false || strtotime($to) === false) {
            $this->error('기간은 Y-m-d 형식이어야 합니다.');

            return self::FAILURE;
        }

        if (strtotime($from) > strtotime($to)) {
            $this->error('시작일이 종료일보다 늦습니다.');

            return self::FAILURE;
        }

        $runs = $service->archiveRange($from, $to, [
            'run_type' => ArchiveRunType::Reaggregate->value,
            'trigger' => 'manual',
            'force' => (bool) $this->option('force'),
            'dry_run' => $dryRun,
        ]);

        if ($dryRun) {
            $this->info("재집계 대상 기간 {$from} ~ {$to} (마른 실행 — 집계 표를 바꾸지 않았습니다)");

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($runs as $run) {
            // `status` 는 열거형으로 캐스팅된다 — `->value` 문자열과 비교하면 언제나
            // 거짓이라 실패한 날을 한 건도 세지 못하고 성공으로 끝난다.
            if ($this->valueOf($run->status) === ArchiveRunStatus::Failed->value) {
                $failed++;
            }
        }

        $this->info(sprintf('재집계 %d일 수행 (실패 %d일)', count($runs), $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 실행 결과를 알립니다.
     *
     * @param  AnalyticsArchiveRun|null  $run  실행 이력
     * @param  string  $date  대상 일자
     * @param  bool  $dryRun  마른 실행 여부
     * @return int 종료 코드
     */
    private function report(?AnalyticsArchiveRun $run, string $date, bool $dryRun): int
    {
        if ($dryRun || $run === null) {
            $this->info("{$date} 집계 대상 확인 (마른 실행 — 집계 표를 바꾸지 않았습니다)");

            return self::SUCCESS;
        }

        $status = $this->valueOf($run->status);

        if ($status === ArchiveRunStatus::Failed->value) {
            $this->error("{$date} 집계 실패: {$run->error}");

            return self::FAILURE;
        }

        if ($status === ArchiveRunStatus::Skipped->value) {
            $this->warn("{$date} 집계를 건너뛰었습니다 — 같은 기간을 이미 집계 중입니다.");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s 집계 완료 — 방문 %d건, 액션 %d건, 청크 %d개',
            $date,
            $run->processed_visits,
            $run->processed_actions,
            $run->chunks,
        ));

        return self::SUCCESS;
    }

    /**
     * 실행 상태에서 문자열 값을 꺼냅니다.
     *
     * 모델이 열거형으로 캐스팅하므로 `->value` 와 직접 비교하면 언제나 거짓입니다.
     *
     * @param  mixed  $status  실행 상태 (열거형 또는 문자열)
     * @return string 상태 문자열
     */
    private function valueOf(mixed $status): string
    {
        return $status instanceof ArchiveRunStatus ? $status->value : (string) $status;
    }
}
