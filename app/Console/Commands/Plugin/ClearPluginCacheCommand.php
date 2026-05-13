<?php

namespace App\Console\Commands\Plugin;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ClearPluginCacheCommand extends Command
{
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
        private PluginRepositoryInterface $pluginRepository,
        private CacheInterface $cache
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
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

                $this->info('✅ '.__('plugins.commands.cache_clear.success_single', [
                    'plugin' => $identifier,
                    'count' => $clearedCount,
                ]));
            } else {
                // 모든 플러그인 캐시 삭제
                $this->info(__('plugins.commands.cache_clear.clearing_all'));

                // 전체 플러그인 캐시 키 삭제
                $cacheKeys = [
                    'plugins.all',
                    'plugins.active',
                    'plugins.installed',
                ];

                foreach ($cacheKeys as $key) {
                    if ($this->cache->forget($key)) {
                        $clearedCount++;
                    }
                }

                // 각 플러그인별 캐시 삭제
                $allPlugins = $this->pluginManager->getAllPlugins();
                foreach ($allPlugins as $pluginName => $plugin) {
                    $clearedCount += $this->clearPluginCache($plugin->getIdentifier());
                }

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
     */
    private function clearPluginCache(string $identifier): int
    {
        $clearedCount = 0;

        // 플러그인별 캐시 키 (메뉴 캐시 없음)
        $cacheKeys = [
            "plugin.config.{$identifier}",
            "plugin.info.{$identifier}",
            "plugin.routes.{$identifier}",
            "plugin.permissions.{$identifier}",
        ];

        foreach ($cacheKeys as $key) {
            if ($this->cache->forget($key)) {
                $clearedCount++;
            }
        }

        return $clearedCount;
    }
}
