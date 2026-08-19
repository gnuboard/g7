<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sirsoft\Ecommerce\Services\TempOrderService;

/**
 * 만료 임시 주문 자동 정리 커맨드
 *
 * 임시 주문은 체크아웃 진입마다 생성되고 30분 TTL 로 만료되지만, 만료된 행을
 * 실제로 삭제하는 주체가 없어 무한히 누적됩니다. 스케줄러에서 주기적으로 실행합니다.
 *
 * @example php artisan sirsoft-ecommerce:prune-temp-orders
 */
class PruneExpiredTempOrdersCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:prune-temp-orders';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '만료된 임시 주문을 삭제합니다.';

    /**
     * 커맨드 실행
     *
     * @param  TempOrderService  $tempOrderService  임시 주문 서비스
     * @return int 종료 코드
     */
    public function handle(TempOrderService $tempOrderService): int
    {
        $deleted = $tempOrderService->cleanupExpiredTempOrders();

        $this->info("만료된 임시 주문 {$deleted}건이 삭제되었습니다.");

        return Command::SUCCESS;
    }
}
