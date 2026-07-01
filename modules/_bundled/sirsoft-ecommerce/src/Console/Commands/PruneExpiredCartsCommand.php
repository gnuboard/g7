<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;

/**
 * 보관기간 만료 장바구니 자동 정리 커맨드
 *
 * 마지막 활동(updated_at)으로부터 설정한 보관기간(cart_expiry_days)이 지난
 * 장바구니 항목을 자동 삭제합니다. 스케줄러에서 주기적으로 실행합니다.
 *
 * @example php artisan sirsoft-ecommerce:prune-expired-carts
 * @example php artisan sirsoft-ecommerce:prune-expired-carts --dry-run
 */
class PruneExpiredCartsCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:prune-expired-carts
                            {--dry-run : 실제 삭제 없이 대상 항목 수만 확인}
                            {--limit=1000 : 한 번에 처리할 최대 항목 수}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '보관기간이 지난 장바구니 항목을 자동 삭제합니다.';

    public function __construct(
        protected CartRepositoryInterface $cartRepository
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
        $days = (int) module_setting('sirsoft-ecommerce', 'order_settings.cart_expiry_days', 30);

        // 보관기간 미설정/0 이하 → 만료 비활성 정책 (전체 삭제 사고 차단)
        if ($days < 1) {
            $this->info('장바구니 보관기간이 비활성화되어 있습니다. (cart_expiry_days < 1)');

            return Command::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $threshold = Carbon::now()->subDays($days);

        if ($isDryRun) {
            $targetCount = Cart::where('updated_at', '<', $threshold)->count();
            $this->info("[DRY RUN] 보관기간({$days}일) 만료 대상 장바구니 항목: {$targetCount}건");

            return Command::SUCCESS;
        }

        $deleted = $this->cartRepository->pruneExpiredItems($days, $limit > 0 ? $limit : null);

        $this->info("보관기간({$days}일) 만료 장바구니 항목 {$deleted}건을 삭제했습니다.");

        Log::info('PruneExpiredCartsCommand: 장바구니 만료 항목 정리 완료', [
            'days' => $days,
            'deleted' => $deleted,
            'limit' => $limit,
        ]);

        return Command::SUCCESS;
    }
}
