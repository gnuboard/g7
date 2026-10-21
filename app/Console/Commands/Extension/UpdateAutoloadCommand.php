<?php

namespace App\Console\Commands\Extension;

use App\Extension\ExtensionManager;
use App\Extension\HookCacheManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Console\Command;

class UpdateAutoloadCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'extension:update-autoload';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '모듈과 플러그인의 오토로드 캐시 파일을 생성합니다 (bootstrap/cache/autoload-extensions.php)';

    /**
     * 확장 오토로드 캐시와 정적 훅 매핑 캐시를 다시 생성합니다.
     *
     * @param  ExtensionManager  $extensionManager  오토로드 파일 생성·런타임 재등록을 맡는 확장 관리자
     * @param  HookCacheManager  $hookCacheManager  정적 훅 매핑 캐시 생성기
     * @param  ModuleManager  $moduleManager  훅 캐시 생성을 위해 모듈을 적재하는 관리자
     * @param  PluginManager  $pluginManager  훅 캐시 생성을 위해 플러그인을 적재하는 관리자
     * @return int 종료 코드
     */
    public function handle(
        ExtensionManager $extensionManager,
        HookCacheManager $hookCacheManager,
        ModuleManager $moduleManager,
        PluginManager $pluginManager,
    ): int {
        $this->info(__('extensions.commands.update_autoload.generating'));

        try {
            $extensionManager->generateAutoloadFile();

            $this->info(__('extensions.commands.update_autoload.generated'));
            $this->line('  → bootstrap/cache/autoload-extensions.php');

            // 방금 생성한 PSR-4 매핑을 현재 프로세스의 ClassLoader 에 즉시 반영.
            // 이 커맨드는 매핑이 깨진 상태를 복구하는 경로이기도 한데, 재등록 없이 아래
            // loadModules/loadPlugins 를 호출하면 부팅 시점의 (깨진) 매핑으로 확장 클래스를
            // 찾지 못해 훅 캐시가 modules/plugins 0건으로 생성된다 — 모든 확장 훅이 조용히 소실.
            $extensionManager->reregisterRuntimeAutoload();

            // 오토로드 캐시와 동일 생명주기의 정적 훅 매핑 캐시도 함께 재생성.
            // 코어 업데이트(clearAllCaches → 이 커맨드) 시 코어 리스너 추가/변경/삭제가
            // 캐시에 반영되도록 보장한다 (미갱신 시 stale 매핑으로 훅 발화 누락/과잉 위험).
            $moduleManager->loadModules();
            $pluginManager->loadPlugins();
            $hookCacheManager->generate($moduleManager, $pluginManager);

            $this->info(__('extensions.commands.update_autoload.hooks_cache_regenerated'));
            $this->line('  → bootstrap/cache/hooks.php');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error(__('extensions.commands.update_autoload.failed', ['error' => $e->getMessage()]));

            return Command::FAILURE;
        }
    }
}
