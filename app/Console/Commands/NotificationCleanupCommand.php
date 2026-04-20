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
     * @param NotificationService $service 알림 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(NotificationService $service): int
    {
        $this->info('알림 정리 시작...');

        $config = config('notification.database_channel', []);
        $this->info("  읽음 보관: {$config['read_retention_days']}일, 미읽음 보관: {$config['unread_retention_days']}일");

        $result = $service->cleanup();

        $this->info("  읽음 알림 삭제: {$result['deleted_read']}건");
        $this->info("  미읽음 알림 삭제: {$result['deleted_unread']}건");
        $this->info('알림 정리 완료');

        return self::SUCCESS;
    }
}
