<?php

namespace App\Console\Commands;

use App\Services\ExtensionStaticCacheService;
use Illuminate\Console\Command;

/**
 * 부트스트랩 리소스 정적 게시(bake)를 수동 수행하는 커맨드
 *
 * 수명주기 이벤트(terminating 트리거)와 blade 자가 치유가 정상 경로지만,
 * 배포 직후 워밍이나 수동 복구가 필요할 때 이 커맨드로 즉시 게시한다.
 * 설치기 완료 단계에서도 호출된다 (#122).
 */
class PublishExtensionStaticCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ext-static:publish {--force : 게시 완료 상태여도 강제 재게시}';

    /**
     * The console command description.
     */
    protected $description = '부트스트랩 리소스(다국어·컴포넌트·라우트·번들·템플릿 에셋)를 정적 파일로 게시합니다';

    /**
     * Execute the console command.
     *
     * @param  ExtensionStaticCacheService  $service  정적 게시 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(ExtensionStaticCacheService $service): int
    {
        if (! $service->isEnabled()) {
            $this->warn('정적 게시가 비활성화되어 있습니다 (G7_STATIC_CACHE=false). 게시를 건너뜁니다.');

            return self::SUCCESS;
        }

        $published = $service->publishCurrent(force: (bool) $this->option('force'));

        if (! $published) {
            $this->error('정적 게시에 실패했습니다. 로그를 확인하세요 — 사이트는 API 폴백으로 정상 동작합니다.');

            return self::FAILURE;
        }

        $this->info('부트스트랩 리소스 정적 게시가 완료되었습니다.');

        return self::SUCCESS;
    }
}
