<?php

namespace Modules\Sirsoft\Board\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Services\AttachmentService;

/**
 * 게시판 첨부파일 정리 커맨드
 *
 * 두 파트를 순차 실행합니다.
 *
 *   1. 임시 첨부 정리 (항상 실행) — 글쓰기 폼에서 올린 뒤 저장 없이 이탈해 남은 첨부.
 *      `temp_key` 가 남아 있으면 끝내 연결되지 않은 폼 세션 부산물이라 오탐 여지가 없다.
 *   2. 소프트 삭제 첨부 영구 정리 (운영자 옵트인) — 삭제된 첨부는 휴지통 복원 대비로
 *      파일이 남는다. 사용자 파일을 실제로 파기하므로 기본 꺼짐이며, 스케줄 호출
 *      (`--scheduled`)에서 설정을 false 폴백으로 확인한 뒤에만 수행한다.
 *
 *      토글이 막는 것은 **자동(스케줄) 실행**이다. `--scheduled` 없이 서버에서 직접 실행하면
 *      토글과 무관하게 파기한다 — 운영자가 의도해서 친 명령이기 때문이다.
 *
 * @example php artisan sirsoft-board:prune-attachments --dry-run
 * @example php artisan sirsoft-board:prune-attachments --temp-days=1
 */
class PruneAttachmentsCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-board:prune-attachments
                            {--dry-run : 실제 삭제 없이 대상 건수만 확인}
                            {--limit=500 : 한 번에 처리할 최대 건수}
                            {--temp-days=2 : 임시 첨부 보존기간(일)}
                            {--purge-days= : 소프트 삭제 첨부 보존기간(일) 재정의 — 미지정 시 모듈 설정값}
                            {--scheduled : 스케줄러 호출 표시 — 영구 정리 토글이 꺼져 있으면 그 파트만 건너뜀 (이 옵션 없이 직접 실행하면 토글과 무관하게 파기)}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '방치된 임시 첨부와 보존기간이 지난 삭제 첨부를 정리합니다.';

    /**
     * @param  AttachmentService  $attachmentService  첨부 서비스
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
        $limit = max(1, (int) $this->option('limit'));
        $isDryRun = (bool) $this->option('dry-run');

        $this->pruneTemp($limit, $isDryRun);
        $this->purgeSoftDeleted($limit, $isDryRun);

        return Command::SUCCESS;
    }

    /**
     * 임시 첨부 정리 파트 (항상 실행).
     *
     * @param  int  $limit  최대 처리 건수
     * @param  bool  $isDryRun  판정만 수행할지 여부
     */
    private function pruneTemp(int $limit, bool $isDryRun): void
    {
        $days = (int) $this->option('temp-days');

        // 보존기간 0 이하 = 방금 올린 임시 첨부까지 지우게 되므로 차단한다.
        if ($days < 1) {
            $this->info('임시 첨부 보존기간이 1일 미만이어서 임시 첨부 정리를 수행하지 않았습니다.');

            return;
        }

        $result = $this->attachmentService->pruneTempUploads($days, $limit, $isDryRun);

        if ($isDryRun) {
            $this->info("[DRY RUN] 보존기간({$days}일) 경과 미연결 임시 첨부: {$result['scanned']}건");

            return;
        }

        $this->info(sprintf(
            '보존기간(%d일) 경과 임시 첨부 %d건 중 %d건을 삭제했습니다. (실패 %d건)',
            $days,
            $result['scanned'],
            $result['deleted'],
            $result['failed'],
        ));

        Log::info('PruneAttachmentsCommand: 임시 첨부 정리 완료', [
            'days' => $days,
            'limit' => $limit,
        ] + $result);
    }

    /**
     * 소프트 삭제 첨부 영구 정리 파트 (운영자 옵트인).
     *
     * @param  int  $limit  최대 처리 건수
     * @param  bool  $isDryRun  판정만 수행할지 여부
     */
    private function purgeSoftDeleted(int $limit, bool $isDryRun): void
    {
        if ($this->option('scheduled') && ! $this->isPurgeEnabled()) {
            $this->info('삭제 첨부 영구 정리가 꺼져 있어 실행하지 않았습니다. (attachment_settings.purge_enabled = false)');

            return;
        }

        $days = $this->resolvePurgeDays();

        if ($days < 1) {
            $this->info('삭제 첨부 보존기간이 1일 미만이어서 영구 정리를 수행하지 않았습니다.');

            return;
        }

        $result = $this->attachmentService->purgeSoftDeleted($days, $limit, $isDryRun);

        if ($isDryRun) {
            $this->info("[DRY RUN] 보존기간({$days}일) 경과 삭제 첨부: {$result['scanned']}건");

            return;
        }

        $this->info(sprintf(
            '보존기간(%d일) 경과 삭제 첨부 %d건 중 %d건을 영구 삭제했습니다. (실패 %d건)',
            $days,
            $result['scanned'],
            $result['deleted'],
            $result['failed'],
        ));

        Log::info('PruneAttachmentsCommand: 삭제 첨부 영구 정리 완료', [
            'days' => $days,
            'limit' => $limit,
        ] + $result);
    }

    /**
     * 영구 정리 토글을 false 폴백으로 조회합니다.
     *
     * @return bool 영구 정리 활성 여부
     */
    private function isPurgeEnabled(): bool
    {
        return (bool) module_setting('sirsoft-board', 'attachment_settings.purge_enabled', false);
    }

    /**
     * 영구 정리 보존기간을 해석합니다 (옵션 > 모듈 설정 > 기본 30일).
     *
     * @return int 보존기간(일)
     */
    private function resolvePurgeDays(): int
    {
        $option = $this->option('purge-days');

        if ($option !== null && $option !== '') {
            return (int) $option;
        }

        return (int) module_setting('sirsoft-board', 'attachment_settings.purge_retention_days', 30);
    }
}
