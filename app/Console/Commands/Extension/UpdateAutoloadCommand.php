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
     * Execute the console command.
     */
    public function handle(
        ExtensionManager $extensionManager,
        HookCacheManager $hookCacheManager,
        ModuleManager $moduleManager,
        PluginManager $pluginManager,
    ): int {
        $this->info('확장 오토로드 파일을 생성합니다...');

        try {
            $extensionManager->generateAutoloadFile();

            $this->info('오토로드 파일이 성공적으로 생성되었습니다.');
            $this->line('  → bootstrap/cache/autoload-extensions.php');

            // 오토로드 캐시와 동일 생명주기의 정적 훅 매핑 캐시도 함께 재생성.
            // 코어 업데이트(clearAllCaches → 이 커맨드) 시 코어 리스너 추가/변경/삭제가
            // 캐시에 반영되도록 보장한다 (미갱신 시 stale 매핑으로 훅 발화 누락/과잉 위험).
            $moduleManager->loadModules();
            $pluginManager->loadPlugins();
            $hookCacheManager->generate($moduleManager, $pluginManager);

            $this->info('정적 훅 매핑 캐시가 재생성되었습니다.');
            $this->line('  → bootstrap/cache/hooks.php');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('오토로드 파일 생성 중 오류가 발생했습니다: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
