<?php

namespace App\Console\Commands\Module;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Extension\ModuleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ClearModuleCacheCommand extends Command
{
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
        private ModuleRepositoryInterface $moduleRepository
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

                $this->info('✅ '.__('modules.commands.cache_clear.success_single', [
                    'module' => $identifier,
                    'count' => $clearedCount,
                ]));
            } else {
                // 모든 모듈 캐시 삭제
                $this->info(__('modules.commands.cache_clear.clearing_all'));

                // 전체 모듈 캐시 키 삭제
                $cacheKeys = [
                    'modules.all',
                    'modules.active',
                    'modules.installed',
                ];

                foreach ($cacheKeys as $key) {
                    if (Cache::forget($key)) {
                        $clearedCount++;
                    }
                }

                // 각 모듈별 캐시 삭제
                $allModules = $this->moduleManager->getAllModules();
                foreach ($allModules as $moduleName => $module) {
                    $clearedCount += $this->clearModuleCache($module->getIdentifier());
                }

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
     */
    private function clearModuleCache(string $identifier): int
    {
        $clearedCount = 0;

        // 모듈별 캐시 키
        $cacheKeys = [
            "module.config.{$identifier}",
            "module.info.{$identifier}",
            "module.routes.{$identifier}",
            "module.permissions.{$identifier}",
            "module.menus.{$identifier}",
        ];

        foreach ($cacheKeys as $key) {
            if (Cache::forget($key)) {
                $clearedCount++;
            }
        }

        return $clearedCount;
    }
}
