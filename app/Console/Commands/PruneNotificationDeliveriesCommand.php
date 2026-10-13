<?php

namespace App\Console\Commands;

use App\Services\NotificationDeliveryGuard;
use Illuminate\Console\Command;

/**
 * 오래된 알림 수신자별 발송 기록(중복 방지 행)을 정리하는 커맨드 (#139)
 *
 * 발송 기록은 재시도·재예약 창 동안만 쓸모가 있다 — 그보다 오래된 행은 지워도 중복 방지가 풀리지 않는다.
 */
class PruneNotificationDeliveriesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'notification-delivery:prune {--days= : 보관 일수 (기본 notification.delivery.retention_days = 7)}';

    /**
     * The console command description.
     */
    protected $description = '보관 기간이 지난 알림 수신자별 발송 기록(중복 방지)을 삭제합니다';

    /**
     * Execute the console command.
     *
     * @param  NotificationDeliveryGuard  $guard  중복 방지 서비스 (정리 소유)
     * @return int 명령 실행 결과 코드
     */
    public function handle(NotificationDeliveryGuard $guard): int
    {
        $option = $this->option('days');
        $days = $option === null || $option === '' ? (int) config('notification.delivery.retention_days', 7) : max(1, (int) $option);
        $deleted = $guard->prune($days);

        $this->info("알림 수신자별 발송 기록 {$deleted}건이 삭제되었습니다. (보관 {$days}일)");

        return self::SUCCESS;
    }
}
