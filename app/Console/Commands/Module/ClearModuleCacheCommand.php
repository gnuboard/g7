<?php

namespace App\Console\Commands\Module;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Enums\LayoutSourceType;
use App\Extension\ExtensionMiddlewareRegistry;
use App\Extension\ModuleManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Extension\Traits\InvalidatesLayoutCache;
use App\Services\ExtensionBundleService;
use App\Services\LayoutResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ClearModuleCacheCommand extends Command
{
    use ClearsTemplateCaches;
    use InvalidatesLayoutCache;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:cache-clear
        {identifier? : 특정 모듈의 캐시만 삭제 (생략 시 모든 모듈)}';

    /**
     * The console command description.
     */
    protected $description = '모듈 캐시를 삭제합니다';

    /**
     * 모듈 관리자 및 리포지토리
     */
    public function __construct(
        private ModuleManager $moduleManager,
        protected LayoutRepositoryInterface $layoutRepository,
        private ExtensionBundleService $bundleService,
        private LayoutResolverService $layoutResolver
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * 실재하는 서버 캐시(모듈 레이아웃 서빙/해석 캐시 + 상태 키 + 미들웨어 인덱스
     * + 버전 포함 키)를 무효화한다.
     * 종전 forget 대상(module.config.{id} 등)은 writer 가 없는 유령 키였다 (#588).
     */
    public function handle(): int
    {
        $identifier = $this->argument('identifier');

        try {
            // 모듈 디렉토리 스캔 및 로드
            $this->moduleManager->loadModules();

            $clearedCount = 0;

            if ($identifier) {
                // 특정 모듈 캐시 삭제
                $this->info(__('modules.commands.cache_clear.clearing_single', ['module' => $identifier]));

                // 모듈이 존재하는지 확인
                $module = $this->moduleManager->getModule($identifier);
                if (! $module) {
                    $this->error('❌ '.__('modules.not_found', ['module' => $identifier]));

                    return Command::FAILURE;
                }

                $clearedCount = $this->clearModuleCache($identifier);

                // 상태 키(ext.modules.*) + 미들웨어 인덱스 + 버전 포함 키 무효화 (버전 bump)
                ModuleManager::invalidateModuleStatusCache();
                ExtensionMiddlewareRegistry::flush();
                $this->incrementExtensionCacheVersion();

                $this->info('✅ '.__('modules.commands.cache_clear.success_single', [
                    'module' => $identifier,
                    'count' => $clearedCount,
                ]));
            } else {
                // 모든 모듈 캐시 삭제
                $this->info(__('modules.commands.cache_clear.clearing_all'));

                // 각 모듈별 캐시 삭제
                foreach ($this->moduleManager->getAllModules() as $module) {
                    $clearedCount += $this->clearModuleCache($module->getIdentifier());
                }

                // 상태 키 + 미들웨어 인덱스 + 버전 포함 키 무효화는 전체에서 1회면 충분
                ModuleManager::invalidateModuleStatusCache();
                ExtensionMiddlewareRegistry::flush();
                $this->incrementExtensionCacheVersion();

                // 모듈 프론트엔드 병합 번들 파일 삭제 (캐시 키 forget 만으로는 미삭제)
                $clearedCount += $this->bundleService->clearBundles('module');

                $this->info('✅ '.__('modules.commands.cache_clear.success_all', ['count' => $clearedCount]));
            }

            Log::info('모듈 캐시 삭제 완료', [
                'module' => $identifier ?? 'all',
                'count' => $clearedCount,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('모듈 캐시 삭제 실패', [
                'module' => $identifier ?? 'all',
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 특정 모듈의 캐시를 삭제합니다.
     *
     * 모듈이 소유한 실재 서버 캐시는 레이아웃 캐시(서빙/병합 + 해석)다 — 각각
     * 트레이트(InvalidatesLayoutCache)와 LayoutResolverService 단일 지점으로
     * 위임해 키 규약 드리프트를 방지한다. 해석 캐시(layout_resolver.*)는 버전
     * 접미사 없는 고정 키에 레이아웃 row ID 를 저장하므로 능동 삭제하지 않으면
     * TTL 동안 이전 해석 결과가 유지된다 (#588 동종 보강).
     *
     * @param  string  $identifier  모듈 식별자
     * @return int 캐시를 무효화한 레이아웃 수
     */
    private function clearModuleCache(string $identifier): int
    {
        $this->invalidateExtensionLayoutCache($identifier, 'module');
        $this->layoutResolver->clearResolutionCacheByModule($identifier);

        return $this->layoutRepository
            ->getBySourceIdentifier($identifier, LayoutSourceType::Module)
            ->count();
    }
}
