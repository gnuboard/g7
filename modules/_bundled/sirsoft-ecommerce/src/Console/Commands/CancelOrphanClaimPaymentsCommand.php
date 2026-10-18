<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;

/**
 * 고아 클레임 결제 대기 건 정리 커맨드
 *
 * 결제창 이탈로 남은 결제 대기 건 중 **신청서가 이미 종결(반려·철회·완료)됐거나 사라진**
 * 것만 취소한다 — 살아 있는 신청서의 대기 건은 사용자가 결제할 유일한 통로라 보존기간과
 * 무관하게 남긴다. 종결 경로는 대기 건을 함께 취소하므로(`cancelPendingPayments()`), 이
 * 배치가 줍는 것은 그 취소가 함께 커밋되지 못한 잔여물이다.
 *
 * 주문 결제의 선례(`CancelPendingPaymentOrdersCommand`)는 주문 자체를 기한 만료로 취소하는
 * 축이라 클레임을 포함하지 않는다 — 클레임 결제 대기 건은 별도 경로가 필요하다.
 */
class CancelOrphanClaimPaymentsCommand extends Command
{
    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:cancel-orphan-claim-payments
                            {--dry-run : 실제 취소 없이 대상 건수만 확인}
                            {--limit=200 : 한 번에 처리할 최대 건수}
                            {--days=1 : 결제 대기 건 보존기간(일)}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '종결된 신청서에 남은 클레임 결제 대기 건을 취소합니다.';

    /**
     * @param  ClaimPaymentService  $paymentService  클레임 추가결제 서비스
     */
    public function __construct(protected ClaimPaymentService $paymentService)
    {
        parent::__construct();
    }

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->info('보존기간(--days)이 1 미만이라 정리를 건너뜁니다.');

            return Command::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $isDryRun = (bool) $this->option('dry-run');

        $result = $this->paymentService->reapOrphanedPendingPayments($days, $limit, $isDryRun);

        if ($isDryRun) {
            $this->info("[dry-run] 취소 대상 {$result['cancelled']}건 / 활성 유지 {$result['skipped_active']}건 (검사 {$result['scanned']}건)");

            return Command::SUCCESS;
        }

        $this->info("고아 클레임 결제 대기 건 정리 완료: 검사 {$result['scanned']}건 / 취소 {$result['cancelled']}건 / 활성 유지 {$result['skipped_active']}건");

        Log::info('[claim.payment] 고아 결제 대기 건 정리 배치', [
            'days' => $days,
            'limit' => $limit,
            'scanned' => $result['scanned'],
            'cancelled' => $result['cancelled'],
            'skipped_active' => $result['skipped_active'],
        ]);

        return Command::SUCCESS;
    }
}
