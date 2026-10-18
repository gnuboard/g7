<?php

namespace Plugins\Sirsoft\Ckeditor5\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Ckeditor5\Services\ImageCleanupService;

/**
 * 미참조 CKEditor5 업로드 이미지 정리 커맨드
 *
 * 에디터로 올렸지만 어떤 콘텐츠에서도 쓰이지 않는 이미지를 파일·기록 함께 정리합니다.
 *
 * 자동 정리는 사용자 파일을 지우므로 기본 꺼짐(운영자 옵트인)입니다. 스케줄러 호출
 * (`--scheduled`)은 커맨드 안에서 설정을 **false 폴백으로** 재확인합니다 — 스케줄 등록부의
 * `enabled_config` 게이트는 설정 조회가 실패하면 true 로 폴백하므로, 그것만으로는
 * "설정을 만지지 않은 사이트에서 자동 삭제 0" 을 보장할 수 없습니다.
 *
 * @example php artisan sirsoft-ckeditor5:prune-unused-images --dry-run
 * @example php artisan sirsoft-ckeditor5:prune-unused-images --days=7 --limit=50
 */
class PruneUnusedImagesCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-ckeditor5:prune-unused-images
                            {--dry-run : 실제 삭제 없이 판정 결과만 확인}
                            {--limit=200 : 한 번에 스캔할 최대 후보 수}
                            {--days= : 보존기간(일) 재정의 — 미지정 시 플러그인 설정값}
                            {--scheduled : 스케줄러 호출 표시 — 자동 정리 토글이 꺼져 있으면 조기 종료}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '어떤 콘텐츠에서도 사용되지 않는 에디터 업로드 이미지를 정리합니다.';

    /**
     * @param  ImageCleanupService  $cleanupService  이미지 정리 서비스
     */
    public function __construct(
        protected ImageCleanupService $cleanupService
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
        if ($this->option('scheduled') && ! $this->isAutoCleanupEnabled()) {
            $this->info('미사용 이미지 자동 정리가 꺼져 있어 실행하지 않았습니다. (unusedImageCleanup = false)');

            return Command::SUCCESS;
        }

        $days = $this->resolveRetentionDays();

        if ($days < 1) {
            $this->info(__('sirsoft-ckeditor5::messages.cleanup.retention_disabled'));

            return Command::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $isDryRun = (bool) $this->option('dry-run');

        $result = $this->cleanupService->pruneUnused($days, $limit, $isDryRun);

        // 판정 불가로 건너뛴 회차 — "정리 완료(0건)" 로 보이면 운영자가 원인을 모른다.
        if (($result['skipped_reason'] ?? null) === 'sources_incomplete') {
            $this->warn(__('sirsoft-ckeditor5::messages.cleanup.sources_incomplete'));

            return Command::SUCCESS;
        }

        if ($isDryRun) {
            $this->renderDryRun($result, $days);

            return Command::SUCCESS;
        }

        $this->info(sprintf(
            '보존기간(%d일) 경과 후보 %d건 중 참조됨 %d건, 삭제 %d건, 실패 %d건.',
            $days,
            $result['scanned'],
            $result['referenced'],
            $result['deleted'],
            $result['failed'],
        ));

        if ($result['failed'] > 0) {
            $this->warn(sprintf('%d건은 파일 삭제에 실패해 기록을 보존했습니다. 다음 회차에 재시도합니다.', $result['failed']));
        }

        Log::info('PruneUnusedImagesCommand: 미참조 에디터 이미지 정리 완료', [
            'days' => $days,
            'limit' => $limit,
            'scanned' => $result['scanned'],
            'referenced' => $result['referenced'],
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ]);

        return Command::SUCCESS;
    }

    /**
     * 자동 정리 토글을 false 폴백으로 조회합니다.
     *
     * @return bool 자동 정리 활성 여부
     */
    private function isAutoCleanupEnabled(): bool
    {
        return (bool) plugin_setting('sirsoft-ckeditor5', 'unusedImageCleanup', false);
    }

    /**
     * 보존기간을 해석합니다 (옵션 > 플러그인 설정 > 기본 30일).
     *
     * @return int 보존기간(일)
     */
    private function resolveRetentionDays(): int
    {
        $option = $this->option('days');

        if ($option !== null && $option !== '') {
            return (int) $option;
        }

        return (int) plugin_setting('sirsoft-ckeditor5', 'unusedImageRetentionDays', 30);
    }

    /**
     * dry-run 결과를 표로 출력합니다.
     *
     * @param  array{scanned: int, referenced: int, deleted: int, failed: int, items: array<int, array<string, mixed>>}  $result  판정 결과
     * @param  int  $days  보존기간(일)
     */
    private function renderDryRun(array $result, int $days): void
    {
        $this->info(sprintf(
            '[DRY RUN] 보존기간(%d일) 경과 후보 %d건 중 참조됨 %d건, 삭제 대상 %d건.',
            $days,
            $result['scanned'],
            $result['referenced'],
            count($result['items']),
        ));

        if ($result['items'] === []) {
            return;
        }

        $this->table(
            ['ID', 'HASH', '원본명', '크기(bytes)', '업로드 시각'],
            array_map(fn (array $item) => [
                $item['id'],
                $item['hash'],
                $item['original_name'],
                $item['file_size'],
                $item['created_at'],
            ], $result['items'])
        );
    }
}
