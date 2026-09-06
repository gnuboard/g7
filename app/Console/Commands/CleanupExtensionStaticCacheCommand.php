<?php

namespace App\Console\Commands;

use App\Services\ExtensionStaticCacheService;
use Illuminate\Console\Command;

/**
 * 오래된 부트스트랩 리소스 정적 게시 디렉토리를 정리하는 커맨드
 *
 * 게시 디렉토리(`public/build/ext/{version}/`)는 캐시 스토어 밖 파일시스템이라
 * version bump 가 구버전 디렉토리를 지우지 않는다. 게시 성공 직후 인라인 GC 가
 * 돌지만, 게시가 오래 없거나 실패한 환경의 잔존물을 이 커맨드가 회수한다.
 * (`CleanupExtensionBundlesCommand` 파일 산출물 GC 패턴 미러, #122)
 */
class CleanupExtensionStaticCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ext-static:cleanup';

    /**
     * The console command description.
     */
    protected $description = '오래된 부트스트랩 리소스 정적 게시 디렉토리(구 version)를 삭제합니다';

    /**
     * Execute the console command.
     *
     * @param  ExtensionStaticCacheService  $service  정적 게시 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(ExtensionStaticCacheService $service): int
    {
        $deleted = $service->cleanup();

        $this->info("오래된 정적 게시 디렉토리 {$deleted}건이 삭제되었습니다.");

        return self::SUCCESS;
    }
}
