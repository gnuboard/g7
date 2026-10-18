<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

/**
 * 마일리지 자동 소멸 커맨드
 *
 * expires_at 도과 + 잔여 > 0 인 적립건을 소멸 처리(expired 거래 + expired_at)합니다.
 * 잔액 캐시 동기화는 Service 가 소멸 처리한 회원·통화에 대해 수행합니다.
 *
 * @example php artisan sirsoft-ecommerce:expire-mileage
 * @example php artisan sirsoft-ecommerce:expire-mileage --dry-run
 */
class ExpireMileageCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:expire-mileage
                            {--dry-run : 실제 소멸 없이 대상만 확인}
                            {--limit=1000 : 한 번에 처리할 최대 lot 수}';

    /**
     * @var string
     */
    protected $description = '유효기간이 만료된 마일리지를 소멸 처리합니다.';

    /**
     * @param  UserMileageService  $mileageService  마일리지 서비스
     */
    public function __construct(
        protected UserMileageService $mileageService,
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

        if (! module_setting('sirsoft-ecommerce', 'mileage.expiry_enabled', true)) {
            $this->info('유효기간 기능이 비활성화되어 있습니다.');

            return Command::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if ($isDryRun) {
            $this->info('[DRY-RUN] 소멸 대상만 확인합니다.');

            return Command::SUCCESS;
        }

        $expired = $this->mileageService->expireLots(now(), $limit);

        $this->info(sprintf('소멸 처리 완료: %d건', $expired));

        return Command::SUCCESS;
    }
}
