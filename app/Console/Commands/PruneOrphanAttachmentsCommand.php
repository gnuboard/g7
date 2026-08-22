<?php

namespace App\Console\Commands;

use App\Services\AttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 소유자 없는 고아 첨부파일 정리 커맨드
 *
 * 폼 저장 전에 즉시 업로드되는 첨부는 소유자 없이 먼저 만들어지므로, 폼을 저장하지 않고
 * 이탈하면 파일과 기록이 그대로 남습니다. 그 잔존물을 회수합니다.
 *
 * 사용자 파일을 실제로 파기하므로 기본 꺼짐(운영자 옵트인)입니다. 스케줄 호출
 * (`--scheduled`)은 설정을 false 폴백으로 확인한 뒤에만 수행합니다.
 *
 * @example php artisan attachments:prune-orphans --dry-run
 * @example php artisan attachments:prune-orphans --days=7 --limit=100
 */
class PruneOrphanAttachmentsCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'attachments:prune-orphans
                            {--dry-run : 실제 삭제 없이 대상 건수만 확인}
                            {--limit=500 : 한 번에 처리할 최대 건수}
                            {--days= : 보존기간(일) 재정의 — 미지정 시 환경설정값}
                            {--scheduled : 스케줄러 호출 표시 — 자동 정리 토글이 꺼져 있으면 조기 종료}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '소유자 없이 방치된 고아 첨부파일을 정리합니다.';

    /**
     * @param  AttachmentService  $attachmentService  첨부파일 서비스
     */
    public function __construct(
        protected AttachmentService $attachmentService
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
        if ($this->option('scheduled') && ! $this->isCleanupEnabled()) {
            $this->info('고아 첨부 자동 정리가 꺼져 있어 실행하지 않았습니다. (upload.orphan_cleanup_enabled = false)');

            return Command::SUCCESS;
        }

        $days = $this->resolveRetentionDays();

        if ($days < 1) {
            $this->info('고아 첨부 보존기간이 1일 미만이어서 정리를 수행하지 않았습니다.');

            return Command::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $isDryRun = (bool) $this->option('dry-run');

        $result = $this->attachmentService->pruneOrphans($days, $limit, $isDryRun);

        if ($isDryRun) {
            $this->info("[DRY RUN] 보존기간({$days}일) 경과 고아 첨부: {$result['scanned']}건");

            return Command::SUCCESS;
        }

        $this->info(sprintf(
            '보존기간(%d일) 경과 고아 첨부 %d건 중 %d건을 삭제했습니다. (실패 %d건)',
            $days,
            $result['scanned'],
            $result['deleted'],
            $result['failed'],
        ));

        Log::info('PruneOrphanAttachmentsCommand: 고아 첨부 정리 완료', [
            'days' => $days,
            'limit' => $limit,
        ] + $result);

        return Command::SUCCESS;
    }

    /**
     * 자동 정리 토글을 false 폴백으로 조회합니다.
     *
     * @return bool 자동 정리 활성 여부
     */
    private function isCleanupEnabled(): bool
    {
        return (bool) g7_core_settings('upload.orphan_cleanup_enabled', false);
    }

    /**
     * 보존기간을 해석합니다 (옵션 > 환경설정 > 기본 30일).
     *
     * @return int 보존기간(일)
     */
    private function resolveRetentionDays(): int
    {
        $option = $this->option('days');

        if ($option !== null && $option !== '') {
            return (int) $option;
        }

        return (int) g7_core_settings('upload.orphan_retention_days', 30);
    }
}
