<?php

namespace App\Console\Commands;

use App\Extension\HookCacheManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Console\Command;

/**
 * 정적 훅 매핑 캐시 생성 Artisan 커맨드.
 *
 * 코어(app/Listeners) + 활성 모듈/플러그인의 정적 훅 리스너를 사전 계산하여
 * bootstrap/cache/hooks.php 에 저장한다. route:cache 동형 — 코어 리스너를
 * 추가/변경하는 코드 배포 시 배포 파이프라인에서 실행한다.
 * (확장 install/update 시에는 ExtensionManager 가 자동 재생성하므로 수동 실행 불필요.)
 */
class HooksCacheCommand extends Command
{
    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'hooks:cache';

    /**
     * @var string 커맨드 설명
     */
    protected $description = '정적 훅 매핑 캐시 생성 (bootstrap/cache/hooks.php)';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  HookCacheManager  $hookCacheManager  훅 캐시 매니저
     * @param  ModuleManager  $moduleManager  모듈 매니저
     * @param  PluginManager  $pluginManager  플러그인 매니저
     * @return int 종료 코드
     */
    public function handle(
        HookCacheManager $hookCacheManager,
        ModuleManager $moduleManager,
        PluginManager $pluginManager,
    ): int {
        $moduleManager->loadModules();
        $pluginManager->loadPlugins();

        $counts = $hookCacheManager->generate($moduleManager, $pluginManager);

        $this->info(__('hooks.cache_generated', [
            'core' => $counts['core'],
            'modules' => $counts['modules'],
            'plugins' => $counts['plugins'],
            'path' => $hookCacheManager->getCacheFilePath(),
        ]));

        return Command::SUCCESS;
    }
}
