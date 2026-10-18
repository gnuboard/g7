<?php

namespace App\Console\Commands;

use App\Services\ExtensionBundleService;
use Illuminate\Console\Command;

/**
 * 오래된 확장 프론트엔드 병합 번들 파일을 정리하는 커맨드
 *
 * 번들 캐시 파일(`storage/app/ext-bundles/{type}.{version}.{js|css}`)은 Laravel
 * 캐시 스토어 밖 파일시스템이라 version bump 나 cache:clear 가 구버전 파일을
 * 지우지 않는다(TTL/GC 없음). 이 커맨드가 현재 version 외의 구파일을 삭제한다.
 * (`CleanupLayoutPreviewsCommand` 파일 산출물 GC 패턴 미러)
 */
class CleanupExtensionBundlesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ext-bundles:cleanup';

    /**
     * The console command description.
     */
    protected $description = '오래된 확장 프론트엔드 병합 번들 파일(구 version)을 삭제합니다';

    /**
     * Execute the console command.
     *
     * @param  ExtensionBundleService  $service  번들 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(ExtensionBundleService $service): int
    {
        $currentVersion = $service->getCurrentVersion();
        $deleted = $service->cleanupStaleBundles($currentVersion);

        $this->info("오래된 확장 번들 {$deleted}건이 삭제되었습니다. (현재 version: {$currentVersion})");

        return self::SUCCESS;
    }
}
