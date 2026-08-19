<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;

/**
 * 미연결 임시 상품 이미지 정리 커맨드
 *
 * 상품 등록 폼에서 업로드만 하고 저장 없이 이탈해 남은 임시 이미지(파일 + 기록)를 정리합니다.
 * 연결 시점에 본경로로 옮겨지므로 `temp_key` 가 남아 있다는 것은 "끝내 연결되지 않았다" 는
 * 뜻이고, 그래서 별도 운영자 토글 없이 상시 동작합니다 (운영 데이터가 아닌 폼 세션 부산물).
 *
 * @example php artisan sirsoft-ecommerce:prune-temp-product-images --dry-run
 * @example php artisan sirsoft-ecommerce:prune-temp-product-images --days=1
 */
class PruneTempProductImagesCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:prune-temp-product-images
                            {--dry-run : 실제 삭제 없이 대상 건수만 확인}
                            {--limit=500 : 한 번에 처리할 최대 건수}
                            {--days=2 : 임시 이미지 보존기간(일)}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '상품에 연결되지 않은 채 방치된 임시 상품 이미지를 정리합니다.';

    /**
     * @param  ProductImageService  $productImageService  상품 이미지 서비스
     */
    public function __construct(
        protected ProductImageService $productImageService
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

        // 보존기간 0 이하 = 방금 올린 임시 이미지까지 지우게 되므로 차단한다.
        if ($days < 1) {
            $this->info('임시 이미지 보존기간이 1일 미만이어서 정리를 수행하지 않았습니다.');

            return Command::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $isDryRun = (bool) $this->option('dry-run');

        $result = $this->productImageService->pruneTempUploads($days, $limit, $isDryRun);

        if ($isDryRun) {
            $this->info("[DRY RUN] 보존기간({$days}일) 경과 미연결 임시 상품 이미지: {$result['scanned']}건");

            return Command::SUCCESS;
        }

        $this->info(sprintf(
            '보존기간(%d일) 경과 임시 상품 이미지 %d건 중 %d건을 삭제했습니다. (실패 %d건)',
            $days,
            $result['scanned'],
            $result['deleted'],
            $result['failed'],
        ));

        Log::info('PruneTempProductImagesCommand: 임시 상품 이미지 정리 완료', [
            'days' => $days,
            'limit' => $limit,
            'scanned' => $result['scanned'],
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ]);

        return Command::SUCCESS;
    }
}
