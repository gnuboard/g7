<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * 오래된 사이트내 알림을 정리하는 커맨드
 */
class NotificationCleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'notification:cleanup';

    /**
     * The console command description.
     */
    protected $description = '보관 기간이 지난 사이트내 알림을 삭제합니다';

    /**
     * Execute the console command.
     *
     * @param  NotificationService  $service  알림 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(NotificationService $service): int
    {
        $this->info(__('notification.console.cleanup.start'));

        $config = config('notification.database_channel', []);
        $this->info('  '.__('notification.console.cleanup.retention', [
            'read_days' => $config['read_retention_days'],
            'unread_days' => $config['unread_retention_days'],
        ]));

        $result = $service->cleanup();

        $this->info('  '.__('notification.console.cleanup.deleted_read', ['count' => $result['deleted_read']]));
        $this->info('  '.__('notification.console.cleanup.deleted_unread', ['count' => $result['deleted_unread']]));
        $this->info(__('notification.console.cleanup.done'));

        return self::SUCCESS;
    }
}
