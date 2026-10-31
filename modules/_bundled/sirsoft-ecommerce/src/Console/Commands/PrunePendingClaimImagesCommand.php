<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;

/**
 * 신청으로 이어지지 않은 클레임 임시 첨부 정리 커맨드
 *
 * 신청서 작성 중 올린 첨부는 `claimable_type=pending` 으로 주문에 묶여 있다가 접수 시
 * 신청서에 귀속된다 — 접수까지 가지 않은 업로드는 아무도 되찾지 않는 파일·행으로 남으므로
 * 보존 기간이 지난 것만 배치로 정리한다. 귀속된 첨부는 유형 값이 바뀌어 대상에서 빠진다.
 * 선례는 상품 임시 이미지 정리(`PruneTempProductImagesCommand`)이며 옵션·판정 구조가 같다.
 */
class PrunePendingClaimImagesCommand extends Command
{
    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:prune-pending-claim-images
                            {--dry-run : 실제 삭제 없이 대상 건수만 확인}
                            {--limit=500 : 한 번에 처리할 최대 건수}
                            {--days=2 : 임시 첨부 보존기간(일)}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '신청으로 이어지지 않은 클레임 임시 첨부(pending)를 정리합니다.';

    /**
     * @param  ClaimAttachmentService  $attachmentService  클레임 첨부 서비스
     */
    public function __construct(protected ClaimAttachmentService $attachmentService)
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
            $this->info(__('sirsoft-ecommerce::messages.console.prune_pending_claim_images.retention_skipped'));

            return Command::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $isDryRun = (bool) $this->option('dry-run');

        $result = $this->attachmentService->prunePendingUploads($days, $limit, $isDryRun);

        if ($isDryRun) {
            $this->info('[dry-run] '.__('sirsoft-ecommerce::messages.console.prune_pending_claim_images.dry_run', ['count' => $result['scanned'], 'days' => $days]));

            return Command::SUCCESS;
        }

        $this->info(__('sirsoft-ecommerce::messages.console.prune_pending_claim_images.done', [
            'scanned' => $result['scanned'],
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ]));

        Log::info('[claim.image] 임시 첨부 정리 배치', [
            'days' => $days,
            'limit' => $limit,
            'scanned' => $result['scanned'],
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ]);

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
