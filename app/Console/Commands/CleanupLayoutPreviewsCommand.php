<?php

namespace App\Console\Commands;

use App\Services\LayoutPreviewService;
use Illuminate\Console\Command;

/**
 * 만료된 레이아웃 미리보기를 정리하는 커맨드
 */
class CleanupLayoutPreviewsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'layout-previews:cleanup';

    /**
     * The console command description.
     */
    protected $description = '만료된 레이아웃 미리보기 데이터를 삭제합니다';

    /**
     * Execute the console command.
     *
     * @param  LayoutPreviewService  $service  미리보기 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(LayoutPreviewService $service): int
    {
        $deleted = $service->cleanupExpired();

        $this->info(__('layouts.console.previews_cleanup.done', ['count' => $deleted]));

        return self::SUCCESS;
    }
}
