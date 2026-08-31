<?php

namespace App\Console\Commands;

use App\Scheduling\ScheduleRunner;
use App\Scheduling\ScheduleRunState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * 예약 작업 러너의 CLI 진입점 (A-5 — 진입점 둘, 본체 하나).
 *
 * `schedule:run` 과 다른 점은 **같은 프로세스 안에서** 커맨드를 실행하고, 놓친 시각을
 * 소급 실행한다는 것이다. 서버 크론을 걸 수 있는 환경에서도 이 커맨드를 쓰면 웹 크론과
 * 같은 소급 규칙이 적용된다 — 두 진입점이 다른 규칙으로 돌면 같은 사이트에서 실행
 * 이력이 어긋난다.
 *
 * 웹 크론을 쓸 수 없는(또는 쓰지 않는) 환경에서 crontab 에 이렇게 건다:
 *
 * ```text
 * * * * * * cd /path/to/site && php artisan g7:schedule-run >> /dev/null 2>&1
 * ```
 *
 * @since 7.1.0
 */
class ScheduleRunInProcessCommand extends Command
{
    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'g7:schedule-run';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '밀린 예약 작업을 같은 프로세스에서 실행합니다 (웹 크론과 동일한 러너)';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  ScheduleRunner  $runner  스케줄 러너 본체
     * @param  ScheduleRunState  $state  점검·실행 기록
     * @return int 종료 코드 (실패한 작업이 있으면 1)
     */
    public function handle(ScheduleRunner $runner, ScheduleRunState $state): int
    {
        $now = CarbonImmutable::now();

        // 최소 호출 간격은 적용하지 않는다 — 그 제한은 외부의 과다 호출을 막기 위한
        // 것이고, crontab 의 주기는 운영자가 이미 정한 값이다. 다만 호출 시각은
        // 남긴다: 두 진입점이 같은 기록을 보아야 "마지막 호출" 이 하나로 읽힌다.
        $state->recordInvocation($now);

        $summary = $runner->run($now);

        $this->line(__('schedule.cron.console_summary', [
            'due' => $summary->due,
            'executed' => $summary->executed,
            'failed' => $summary->failed,
            'skipped' => $summary->skipped,
            'duration' => $summary->durationMs(),
        ]));

        foreach ($summary->events as $event) {
            $line = sprintf('  [%s] %s', $event['status'] ?? '?', $event['name'] ?? '');

            if (isset($event['reason'])) {
                $line .= ' — '.$event['reason'];
            }

            ($event['status'] ?? '') === 'failed' ? $this->error($line) : $this->line($line);
        }

        if ($summary->truncated) {
            $this->warn(__('schedule.cron.console_truncated'));
        }

        return $summary->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
