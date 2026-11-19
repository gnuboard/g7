<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Services\JobQueueService;
use Illuminate\Console\Command;

/**
 * 보관 기간이 지난 큐 처리 기록과 실패 작업을 정리하는 커맨드 (#17)
 *
 * 처리 기록(queue_job_logs)과 실패 작업(failed_jobs)은 보관 기간 설정 1개(drivers.queue_record_retention_days,
 * 기본 7일)를 함께 따른다. 기존 `queue:prune-failed` 예약을 대신한다(코어 카탈로그 `queue-prune-records`).
 */
class PruneQueueRecordsCommand extends Command
{
    use LocalizesDescription;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'queue:prune-records {--days= : 보관 기간(일, 1~90). 생략하면 환경설정 값(기본 7일)}';

    /**
     * Execute the console command.
     *
     * @param  JobQueueService  $service  큐 운영 서비스 (정리 소유)
     * @return int 명령 실행 결과 코드 (보관 기간이 범위 밖이면 INVALID)
     */
    public function handle(JobQueueService $service): int
    {
        $option = $this->option('days');
        $days = null;

        if ($option !== null && $option !== '') {
            if (! ctype_digit((string) $option)
                || (int) $option < JobQueueService::RETENTION_MIN_DAYS
                || (int) $option > JobQueueService::RETENTION_MAX_DAYS) {
                $this->error(__('queue.prune.invalid_days', [
                    'min' => JobQueueService::RETENTION_MIN_DAYS,
                    'max' => JobQueueService::RETENTION_MAX_DAYS,
                ]));

                return self::INVALID;
            }

            $days = (int) $option;
        }

        $result = $service->pruneRecords($days);

        $this->info(__('queue.prune.done', [
            'days' => $result['days'],
            'logs' => $result['logs'],
            'failed' => $result['failed'],
        ]));

        return self::SUCCESS;
    }
}
