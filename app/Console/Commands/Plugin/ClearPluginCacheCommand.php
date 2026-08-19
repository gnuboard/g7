<?php

namespace App\Console\Commands\Plugin;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Enums\LayoutSourceType;
use App\Extension\ExtensionMiddlewareRegistry;
use App\Extension\PluginManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Extension\Traits\InvalidatesLayoutCache;
use App\Services\ExtensionBundleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ClearPluginCacheCommand extends Command
{
    use ClearsTemplateCaches;
    use InvalidatesLayoutCache;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plugin:cache-clear
        {identifier? : 특정 플러그인의 캐시만 삭제 (생략 시 모든 플러그인)}';

    /**
     * The console command description.
     */
    protected $description = '플러그인 캐시를 삭제합니다';

    /**
     * 플러그인 관리자 및 리포지토리
     */
    public function __construct(
        private PluginManager $pluginManager,
        protected LayoutRepositoryInterface $layoutRepository,
        private ExtensionBundleService $bundleService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * 실재하는 서버 캐시(플러그인 레이아웃 캐시 + 상태 키 + 미들웨어 인덱스
     * + 버전 포함 키)를 무효화한다.
     * 종전 forget 대상(plugin.config.{id} 등)은 writer 가 없는 유령 키였다 (#588).
     * 해석 캐시(layout_resolver.*)는 모듈 레이아웃 전용이라 플러그인은 비대상
     * (LayoutResolverService::resolve 의 fromModules 스코프는 plugin row 를 반환하지 않음).
     */
    public function handle(): int
    {
        $identifier = $this->argument('identifier');

        try {
            // 플러그인 디렉토리 스캔 및 로드
            $this->pluginManager->loadPlugins();

            $clearedCount = 0;

            if ($identifier) {
                // 특정 플러그인 캐시 삭제
                $this->info(__('plugins.commands.cache_clear.clearing_single', ['plugin' => $identifier]));

                // 플러그인이 존재하는지 확인
                $plugin = $this->pluginManager->getPlugin($identifier);
                if (! $plugin) {
                    $this->error('❌ '.__('plugins.not_found', ['plugin' => $identifier]));

                    return Command::FAILURE;
                }

                $clearedCount = $this->clearPluginCache($identifier);

                // 상태 키(ext.plugins.*) + 미들웨어 인덱스 + 버전 포함 키 무효화 (버전 bump)
                PluginManager::invalidatePluginStatusCache();
                ExtensionMiddlewareRegistry::flush();
                $this->incrementExtensionCacheVersion();

                $this->info('✅ '.__('plugins.commands.cache_clear.success_single', [
                    'plugin' => $identifier,
                    'count' => $clearedCount,
                ]));
            } else {
                // 모든 플러그인 캐시 삭제
                $this->info(__('plugins.commands.cache_clear.clearing_all'));

                // 각 플러그인별 캐시 삭제
                foreach ($this->pluginManager->getAllPlugins() as $plugin) {
                    $clearedCount += $this->clearPluginCache($plugin->getIdentifier());
                }

                // 상태 키 + 미들웨어 인덱스 + 버전 포함 키 무효화는 전체에서 1회면 충분
                PluginManager::invalidatePluginStatusCache();
                ExtensionMiddlewareRegistry::flush();
                $this->incrementExtensionCacheVersion();

                // 플러그인 프론트엔드 병합 번들 파일 삭제 (캐시 키 forget 만으로는 미삭제)
                $clearedCount += $this->bundleService->clearBundles('plugin');

                $this->info('✅ '.__('plugins.commands.cache_clear.success_all', ['count' => $clearedCount]));
            }

            Log::info('플러그인 캐시 삭제 완료', [
                'plugin' => $identifier ?? 'all',
                'count' => $clearedCount,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('플러그인 캐시 삭제 실패', [
                'plugin' => $identifier ?? 'all',
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 특정 플러그인의 캐시를 삭제합니다.
     *
     * 플러그인이 소유한 실재 서버 캐시는 레이아웃 캐시뿐이다 — 트레이트 단일 지점
     * (InvalidatesLayoutCache)으로 위임해 키 규약 드리프트를 방지한다.
     *
     * @return int 캐시를 무효화한 레이아웃 수
     */
    private function clearPluginCache(string $identifier): int
    {
        $this->invalidateExtensionLayoutCache($identifier, 'plugin');

        return $this->layoutRepository
            ->getBySourceIdentifier($identifier, LayoutSourceType::Plugin)
            ->count();
    }
}
