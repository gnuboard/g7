<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceDashboardService;

/**
 * 이커머스 일별 판매 현황 집계 커맨드
 *
 * 최근 7일치(오늘 포함) 판매 수량/순매출을 ecommerce_stats 테이블에 upsert 합니다.
 * 스케줄러(hourly)에서 주기적으로 실행하며, 대시보드 조회 API 는 이 테이블만 읽습니다.
 *
 * @example php artisan sirsoft-ecommerce:aggregate-stats
 * @example php artisan sirsoft-ecommerce:aggregate-stats --dry-run
 */
class AggregateEcommerceStatsCommand extends Command
{
    /**
     * 집계 대상 일수 (오늘 포함 최근 N일)
     */
    private const AGGREGATE_DAYS = 7;

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:aggregate-stats
                            {--dry-run : 실제 저장 없이 대상 날짜와 예상 판매 현황만 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '최근 7일치 판매 수량/순매출 현황을 ecommerce_stats 테이블에 집계합니다.';

    /**
     * @param  EcommerceDashboardService  $dashboardService  대시보드 집계 서비스
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문상품 Repository
     */
    public function __construct(
        private readonly EcommerceDashboardService $dashboardService,
        private readonly OrderOptionRepositoryInterface $orderOptionRepository,
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
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            return $this->runDryRun();
        }

        if (! (bool) g7_module_settings('sirsoft-ecommerce', 'dashboard.scheduler_enabled', true)) {
            $this->warn('대시보드 집계 스케줄러가 비활성화되어 있습니다 (dashboard.scheduler_enabled=false). 건너뜁니다.');

            return Command::SUCCESS;
        }

        $rows = $this->dashboardService->aggregateRecentDays(self::AGGREGATE_DAYS);

        $this->info(sprintf('판매 현황 집계 완료: 최근 %d일치 %d개 행 갱신', self::AGGREGATE_DAYS, count($rows)));
        $this->table(['날짜', '판매수량', '순매출'], array_map(
            fn (array $row) => [$row['date'], $row['sales_quantity'], number_format($row['sales_amount'])],
            $rows,
        ));

        return Command::SUCCESS;
    }

    /**
     * dry-run: 저장 없이 대상 날짜와 예상 판매 현황만 출력합니다.
     *
     * @return int 종료 코드
     */
    private function runDryRun(): int
    {
        $today = CarbonImmutable::today();
        $preview = [];

        for ($i = 0; $i < self::AGGREGATE_DAYS; $i++) {
            $date = $today->subDays($i)->toDateString();
            $preview[] = [
                $date,
                $this->orderOptionRepository->sumNetQuantityOnDate($date),
                number_format($this->orderOptionRepository->sumNetSalesOnDate($date)),
            ];
        }

        $this->info(sprintf('[dry-run] 집계 대상 최근 %d일 (저장하지 않음):', self::AGGREGATE_DAYS));
        $this->table(['날짜', '판매수량', '순매출'], $preview);

        return Command::SUCCESS;
    }
}
