<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\ParsesDaysOption;
use App\Services\NotificationLogService;
use Illuminate\Console\Command;

/**
 * 보존 기간이 지난 알림 발송 이력을 정리하는 커맨드
 */
class PruneNotificationLogsCommand extends Command
{
    use ParsesDaysOption;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'notification-log:prune {--days=90 : 보관 기간(일)}';

    /**
     * The console command description.
     */
    protected $description = '보관 기간이 지난 알림 발송 이력을 삭제합니다';

    /**
     * Execute the console command.
     *
     * 파기 자체는 도메인 서비스가 수행합니다 — 커맨드는 옵션만 전달합니다.
     * 보존 기간 하한과 확장 훅 발행은 그 계층이 소유합니다.
     *
     * @param  NotificationLogService  $service  알림 발송 이력 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(NotificationLogService $service): int
    {
        $days = $this->parseDaysOption();

        if ($days === false) {
            return self::FAILURE;
        }

        $days ??= 90;

        $deleted = $service->prune($days);

        [$shownDays, $floorNote] = $this->retentionForDisplay($days);
        $this->info(__('common.console.prune_done.notification_logs', ['count' => $deleted, 'days' => $shownDays]).$floorNote);

        return self::SUCCESS;
    }
}
