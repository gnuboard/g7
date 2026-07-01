<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use App\Extension\HookManager;
use Illuminate\Console\Command;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;

/**
 * 소멸 예정 마일리지 알림 커맨드
 *
 * N일 내 소멸 예정 잔액 보유 회원에게 GenericNotification 을 발송합니다 (회원·통화 단위).
 * 발송 전 expiring_soon/expiring_date 캐시를 재계산하고, 당일 중복 발송을 방지합니다.
 *
 * @example php artisan sirsoft-ecommerce:notify-expiring-mileage
 * @example php artisan sirsoft-ecommerce:notify-expiring-mileage --dry-run
 */
class NotifyExpiringMileageCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:notify-expiring-mileage
                            {--dry-run : 실제 발송 없이 대상만 확인}
                            {--limit=1000 : 한 번에 처리할 최대 회원 수}';

    /**
     * @var string
     */
    protected $description = '소멸 예정 마일리지 보유 회원에게 사전 안내를 발송합니다.';

    /**
     * @param  MileageBalanceRepositoryInterface  $cache  잔액 캐시 Repository
     */
    public function __construct(
        protected MileageBalanceRepositoryInterface $cache,
    ) {
        parent::__construct();
    }

    /**
     * 커맨드 실행
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        if (! module_setting('sirsoft-ecommerce', 'mileage.enabled', false)) {
            $this->info('마일리지 기능이 비활성화되어 있습니다.');

            return Command::SUCCESS;
        }

        if (! module_setting('sirsoft-ecommerce', 'mileage.expiry_notification_enabled', true)) {
            $this->info('소멸 예정 알림 기능이 비활성화되어 있습니다.');

            return Command::SUCCESS;
        }

        $daysBefore = (int) module_setting('sirsoft-ecommerce', 'mileage.expiry_notification_days_before', 7);
        $isDryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        // 발송 대상 산정을 위한 expiring 윈도우 캐시 갱신
        $this->cache->recalculateExpiringWindow($daysBefore);

        $targets = $this->cache->getExpiringTargets($limit);

        if ($targets->isEmpty()) {
            $this->info('소멸 예정 알림 대상이 없습니다.');

            return Command::SUCCESS;
        }

        $this->info(sprintf('소멸 예정 알림 대상 %d건%s', $targets->count(), $isDryRun ? ' [DRY-RUN]' : ''));

        if ($isDryRun) {
            return Command::SUCCESS;
        }

        $sent = 0;
        foreach ($targets as $balance) {
            $user = $balance->user;
            if ($user === null) {
                continue;
            }

            HookManager::doAction(
                'sirsoft-ecommerce.mileage.notify_expiring',
                $user,
                (float) $balance->expiring_soon,
                $balance->currency,
                $balance->expiring_date?->toDateString() ?? '',
                (float) $balance->available,
            );
            $sent++;
        }

        $this->info(sprintf('발송 완료: %d건', $sent));

        return Command::SUCCESS;
    }
}
