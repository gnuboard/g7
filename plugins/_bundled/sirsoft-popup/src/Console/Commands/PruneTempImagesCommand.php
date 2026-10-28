<?php

namespace Plugins\Sirsoft\Popup\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Popup\Services\PopupImageService;

/**
 * 고아 임시 팝업 이미지 정리 커맨드
 *
 * 팝업 등록·수정 화면에서 업로드만 하고 저장 없이 이탈해 남은 임시 이미지(파일 + 기록)를 정리합니다.
 * 저장(연결) 때 temp_key 를 비우므로 남아 있다는 것은 끝내 연결되지 않았다는 뜻이고, 그래서 운영자 토글 없이
 * 상시 동작합니다(이커머스 임시 상품 이미지 정리와 같은 방식).
 *
 * @example php artisan sirsoft-popup:prune-temp-images --dry-run
 * @example php artisan sirsoft-popup:prune-temp-images --days=1
 */
class PruneTempImagesCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-popup:prune-temp-images
                            {--dry-run : 실제 삭제 없이 대상 건수만 확인}
                            {--limit=500 : 한 번에 처리할 최대 건수}
                            {--days=2 : 임시 이미지 보존기간(일)}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '팝업에 연결되지 않은 채 방치된 임시 팝업 이미지를 정리합니다.';

    /**
     * PruneTempImagesCommand 생성자
     *
     * @param  PopupImageService  $images  이미지 서비스
     */
    public function __construct(private PopupImageService $images)
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

        // 보존기간 0 이하 = 방금 올린 임시 이미지까지 지우게 되므로 막는다
        if ($days < 1) {
            $this->info(__('sirsoft-popup::messages.console.prune_temp_images.retention_disabled'));

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $result = $this->images->pruneTempUploads($days, $limit, $dryRun);

        if ($dryRun) {
            $this->info('[DRY RUN] '.__('sirsoft-popup::messages.console.prune_temp_images.dry_run', ['days' => $days, 'count' => $result['scanned']]));

            return self::SUCCESS;
        }

        $this->info(__('sirsoft-popup::messages.console.prune_temp_images.done', [
            'days' => $days,
            'scanned' => $result['scanned'],
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ]));

        Log::info('PruneTempImagesCommand: 임시 팝업 이미지 정리 완료', ['days' => $days, 'limit' => $limit] + $result);

        return self::SUCCESS;
    }
}
