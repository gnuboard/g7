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

        // root 로 실행 중이면 경고만 하고 **계속 진행**한다 — 명시적 명령은 운영자 책임이고, 중단 가드는
        // 두지 않는다(#651 D3). 게시가 만드는 캐시 락 샤드·병합 번들이 root 소유로 남으면 이후 웹 요청의
        // 캐시 쓰기가 Permission denied 로 죽으므로(전면 500 실사례) 웹 계정 실행 형태를 함께 적는다.
        if (StatusExtensionStaticCacheCommand::isRootProcess()) {
            $this->warn('root 로 실행 중입니다 — 이 계정으로 게시하면 캐시 폴더·병합 번들이 root 소유가 되어 이후 웹 요청이 실패할 수 있습니다.');
            $this->warn('웹 계정으로 실행하세요: '.StatusExtensionStaticCacheCommand::publishCommandHint());
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
