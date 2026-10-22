<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\ExportService;

/**
 * 내보내기 파일 정리 커맨드
 *
 * 보관 기간(이커머스 환경설정 › 기본정보 › 「내보내기 파일 보관 기간」)이 지난 파일을 지우고 작업을
 * 「만료」로 바꾼다. 처리 시간 한도를 넘겨 멈춘 「진행중」 작업은 「실패(timeout)」로 닫는다.
 * 스케줄러가 매시 실행한다.
 *
 * @example php artisan sirsoft-ecommerce:prune-exports
 */
class PruneExportsCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:prune-exports';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '보관 기간이 지난 내보내기 파일을 지우고 멈춘 작업을 실패로 닫습니다.';

    /**
     * @param  ExportService  $exportService  내보내기 서비스
     */
    public function __construct(
        protected ExportService $exportService
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
        $result = $this->exportService->prune();

        $this->info(__('sirsoft-ecommerce::messages.console.prune_exports.done', [
            'expired' => $result['expired'],
            'failed' => $result['failed'],
        ]));

        Log::info('PruneExportsCommand: 내보내기 파일 정리 완료', $result);

        return Command::SUCCESS;
    }
}
