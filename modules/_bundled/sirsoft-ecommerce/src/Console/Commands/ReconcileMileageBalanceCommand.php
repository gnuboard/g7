<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;

/**
 * 마일리지 잔액 캐시 정합 교정 커맨드
 *
 * 전 회원 캐시를 원장 SUM(available/earned/used·expiring) + order_options 집계(pending)로 재산출합니다.
 * 트랜잭션 누락/수동 DB 개입 등으로 캐시가 어긋난 경우 일배치로 자기 치유합니다.
 *
 * @example php artisan sirsoft-ecommerce:reconcile-mileage-balance
 */
class ReconcileMileageBalanceCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:reconcile-mileage-balance';

    /**
     * @var string
     */
    protected $description = '마일리지 잔액 캐시를 원장 기준으로 전체 재산출합니다 (drift 교정).';

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

        $this->info('마일리지 잔액 캐시 재산출 시작...');

        $this->cache->recalculateAll();

        $this->info('마일리지 잔액 캐시 재산출 완료.');

        return Command::SUCCESS;
    }
}
