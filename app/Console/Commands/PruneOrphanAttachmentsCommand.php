<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\ParsesDaysOption;
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
    use ParsesDaysOption;

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
            $this->info(__('attachment.console.prune_orphans.disabled'));

            return Command::SUCCESS;
        }

        $days = $this->resolveRetentionDays();

        if ($days === false) {
            return Command::FAILURE;
        }

        if ($days < 1) {
            $this->info(__('attachment.console.prune_orphans.retention_below_minimum'));

            return Command::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $isDryRun = (bool) $this->option('dry-run');

        $result = $this->attachmentService->pruneOrphans($days, $limit, $isDryRun);

        if ($isDryRun) {
            $this->info('[DRY RUN] '.__('attachment.console.prune_orphans.dry_run', ['days' => $days, 'count' => $result['scanned']]));

            return Command::SUCCESS;
        }

        $this->info(__('attachment.console.prune_orphans.done', [
            'days' => $days,
            'scanned' => $result['scanned'],
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ]));

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
     * @return int|false 보존기간(일), `--days` 가 0 이상의 정수가 아니면 false (오류는 이미 출력함)
     */
    private function resolveRetentionDays(): int|false
    {
        $option = $this->parseDaysOption();

        if ($option !== null) {
            return $option;
        }

        return (int) g7_core_settings('upload.orphan_retention_days', 30);
    }
}
