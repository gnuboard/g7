<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sirsoft\Ecommerce\Enums\MileageEarnTriggerEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

/**
 * 지연 마일리지 적립 커맨드
 *
 * 적립 시점(구매확정/배송완료) 도달 + 지연일 경과 + earn ledger 부재인 옵션을 적립합니다.
 * 조회 조건 자체가 멱등(earn ledger 부재)이므로 재실행 안전합니다.
 *
 * @example php artisan sirsoft-ecommerce:earn-mileage
 * @example php artisan sirsoft-ecommerce:earn-mileage --dry-run
 */
class EarnMileageCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:earn-mileage
                            {--dry-run : 실제 적립 없이 대상만 확인}
                            {--limit=500 : 한 번에 처리할 최대 옵션 수}';

    /**
     * @var string
     */
    protected $description = '적립 시점 도달 + 지연 경과한 주문옵션의 마일리지를 적립합니다.';

    /**
     * @param  MileageTransactionRepositoryInterface  $ledger  원장 Repository
     * @param  MileageBalanceRepositoryInterface  $cache  잔액 캐시 Repository
     * @param  OrderRepositoryInterface  $orderRepository  주문 Repository
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문옵션 Repository
     * @param  UserMileageService  $mileageService  마일리지 서비스
     */
    public function __construct(
        protected MileageTransactionRepositoryInterface $ledger,
        protected MileageBalanceRepositoryInterface $cache,
        protected OrderRepositoryInterface $orderRepository,
        protected OrderOptionRepositoryInterface $orderOptionRepository,
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
            $this->info(__('sirsoft-ecommerce::messages.console.mileage.disabled'));

            return Command::SUCCESS;
        }

        $triggerValue = (string) module_setting('sirsoft-ecommerce', 'mileage.earn_trigger', MileageEarnTriggerEnum::CONFIRMED->value);
        $trigger = MileageEarnTriggerEnum::tryFrom($triggerValue) ?? MileageEarnTriggerEnum::CONFIRMED;
        $delayDays = (int) module_setting('sirsoft-ecommerce', 'mileage.earn_delay_days', 0);

        $isDryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $targets = $this->ledger->getEarnableOptions(
            $trigger->timestampColumn(),
            $trigger->eligibleStatuses(),
            $delayDays,
            now(),
            $limit,
        );

        if ($targets->isEmpty()) {
            $this->info(__('sirsoft-ecommerce::messages.console.earn_mileage.no_targets'));

            return Command::SUCCESS;
        }

        $this->info(__('sirsoft-ecommerce::messages.console.earn_mileage.targets', [
            'count' => $targets->count(),
            'trigger' => $trigger->value,
            'delay' => $delayDays,
        ]).($isDryRun ? ' [DRY-RUN]' : ''));

        if ($isDryRun) {
            return Command::SUCCESS;
        }

        $earned = 0;
        $affectedUsers = [];
        $failed = 0;
        foreach ($targets as $row) {
            // 행마다 격리한다(B10) — 한 행의 예외가 배치 전체를 멈추면, id 오름차순이라 같은 행이 매 실행 앞을 막아
            // 뒤의 모든 적립이 영영 밀린다. 실패 행은 보고하고 다음 실행에서 다시 잡힌다(적립은 금액 델타 멱등).
            try {
                $order = $this->orderRepository->find((int) $row->order_id);
                $option = $this->orderOptionRepository->findOrFail((int) $row->option_id);
                if ($order === null) {
                    continue;
                }

                $tx = $this->mileageService->earnForOrderOption($order, $option, MileageTransactionTypeEnum::PURCHASE_EARN);
                if ($tx !== null) {
                    $earned++;
                    $affectedUsers[(int) $row->user_id] = $order->currency ?? 'KRW';
                }
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                $this->warn(__('sirsoft-ecommerce::messages.console.earn_mileage.failed', ['option_id' => (int) $row->option_id, 'error' => $e->getMessage()]));
            }
        }

        // pending → available 이동 반영
        foreach ($affectedUsers as $userId => $currency) {
            $this->cache->recalculatePending($userId, $currency);
        }

        $this->info(__('sirsoft-ecommerce::messages.console.earn_mileage.done', ['count' => $earned]));

        return Command::SUCCESS;
    }
}
