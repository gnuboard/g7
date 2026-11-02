<?php

namespace App\Console\Commands\Extension;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Console\Command;

class UpdateAutoloadCommand extends Command
{
    use LocalizesDescription;
    use ReportsLifecycleWarnings;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'extension:update-autoload';

    /**
     * 확장 오토로드 캐시와 정적 확장 캐시(훅 매핑·예약 커맨드 매핑)를 다시 생성합니다.
     *
     * @param  ExtensionManager  $extensionManager  오토로드 파일 생성·런타임 재등록을 맡는 확장 관리자
     * @param  ModuleManager  $moduleManager  정적 캐시 생성을 위해 모듈을 적재하는 관리자
     * @param  PluginManager  $pluginManager  정적 캐시 생성을 위해 플러그인을 적재하는 관리자
     * @return int 종료 코드
     */
    public function handle(
        ExtensionManager $extensionManager,
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

            // 오토로드 캐시와 동일 생명주기의 정적 캐시도 함께 재생성.
            // 코어 업데이트(clearAllCaches → 이 커맨드) 시 코어 리스너 추가/변경/삭제가
            // 캐시에 반영되도록 보장한다 (미갱신 시 stale 매핑으로 훅 발화 누락/과잉 위험).
            //
            // 어떤 캐시가 대상인지는 여기서 열거하지 않는다 — 목록을 아는 지점은
            // ExtensionManager::regenerateExtensionStaticCaches() 하나여야 한다. 여기에
            // 사본을 두면 새 캐시가 추가될 때 이 경로만 빠진다(예약 커맨드 매핑 도입 시 실제 발생).
            $moduleManager->loadModules();
            $pluginManager->loadPlugins();
            $extensionManager->regenerateExtensionStaticCaches($moduleManager, $pluginManager);

            $this->info(__('extensions.commands.update_autoload.hooks_cache_regenerated'));
            $this->line('  → bootstrap/cache/hooks.php');
            $this->line('  → bootstrap/cache/extension-schedule-commands.php');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error(__('extensions.commands.update_autoload.failed', ['error' => $e->getMessage()]));

            return Command::FAILURE;
        }
    }
}
