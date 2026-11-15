<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;

/**
 * 미연결 임시 카테고리 이미지 정리 커맨드
 *
 * 새 카테고리 폼에서 이미지를 올리고 저장하지 않고 닫으면 `temp_key` 만 남고 `category_id` 가 빈
 * 행과 그 파일(썸네일 「등록 시 미리 생성」을 켠 사이트는 썸네일까지)이 영구히 남는다.
 * 저장하면 `temp_key` 가 비워지므로 보존기간이 지나도록 `temp_key` 가 남아 있다는 것은
 * "끝내 저장되지 않았다" 는 뜻이고, 그래서 별도 운영자 토글 없이 상시 동작한다.
 * 선례는 상품 임시 이미지 정리(`PruneTempProductImagesCommand`)이며 옵션·판정 구조가 같다.
 *
 * @example php artisan sirsoft-ecommerce:prune-temp-category-images --dry-run
 * @example php artisan sirsoft-ecommerce:prune-temp-category-images --limit=5000
 */
class PruneTempCategoryImagesCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:prune-temp-category-images
                            {--dry-run : 실제 삭제 없이 대상 건수만 확인}
                            {--limit=500 : 한 번에 처리할 최대 건수}
                            {--days=2 : 임시 이미지 보존기간(일)}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '카테고리에 연결되지 않은 채 방치된 임시 카테고리 이미지를 정리합니다.';

    /**
     * @param  CategoryImageService  $categoryImageService  카테고리 이미지 서비스
     */
    public function __construct(
        protected CategoryImageService $categoryImageService
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
        $days = (int) $this->option('days');

        // 보존기간 0 이하 = 지금 폼에서 올리는 중인 임시 이미지까지 지우게 되므로 차단한다.
        if ($days < 1) {
            $this->info(__('sirsoft-ecommerce::messages.console.prune_temp_category_images.retention_disabled'));

            return Command::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $isDryRun = (bool) $this->option('dry-run');

        $result = $this->categoryImageService->pruneTempUploads($days, $limit, $isDryRun);

        if ($isDryRun) {
            $this->info('[DRY RUN] '.__('sirsoft-ecommerce::messages.console.prune_temp_category_images.dry_run', ['days' => $days, 'count' => $result['scanned']]));

            return Command::SUCCESS;
        }

        $this->info(__('sirsoft-ecommerce::messages.console.prune_temp_category_images.done', [
            'days' => $days,
            'scanned' => $result['scanned'],
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ]));

        Log::info('PruneTempCategoryImagesCommand: 임시 카테고리 이미지 정리 완료', [
            'days' => $days,
            'limit' => $limit,
            'scanned' => $result['scanned'],
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ]);

        return Command::SUCCESS;
    }
}
