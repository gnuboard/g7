<?php

namespace App\Console\Commands\Plugin;

use App\Console\Commands\Traits\HasProgressBar;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\PluginManager;
use App\Models\Permission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UninstallPluginCommand extends Command
{
    use HasProgressBar;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plugin:uninstall
        {identifier : 제거할 플러그인 식별자}
        {--force : 확인 없이 삭제}
        {--delete-data : 플러그인 데이터(테이블, 환경설정) 함께 삭제}';

    /**
     * The console command description.
     */
    protected $description = '플러그인을 제거합니다';

    /**
     * 플러그인 관리자 및 리포지토리
     */
    public function __construct(
        private PluginManager $pluginManager,
        private PluginRepositoryInterface $pluginRepository
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

            // 플러그인이 설치되어 있는지 확인
            $pluginRecord = $this->pluginRepository->findByIdentifier($identifier);
            if (! $pluginRecord) {
                $this->error('❌ '.__('plugins.commands.uninstall.not_installed', ['plugin' => $identifier]));

                return Command::FAILURE;
            }

            // 플러그인 인스턴스에서 role 개수 조회
            $pluginInstance = $this->pluginManager->getPlugin($identifier);
            $rolesCount = 0;
            if ($pluginInstance && method_exists($pluginInstance, 'getRoles')) {
                $rolesCount = count($pluginInstance->getRoles());
            }

            // 권한, 레이아웃 개수 조회
            $permissionsCount = Permission::byExtension(ExtensionOwnerType::Plugin, $identifier)->count();
            $layoutsCount = $this->pluginManager->getPluginLayoutsCount($identifier);

            // 확인 프롬프트 (--force 옵션이 없는 경우)
            if (! $this->option('force')) {
                $this->warn(__('plugins.commands.uninstall.confirm_prompt', ['plugin' => $identifier]));
                $this->line(__('plugins.commands.uninstall.confirm_details.roles', ['count' => $rolesCount]));
                $this->line(__('plugins.commands.uninstall.confirm_details.permissions', ['count' => $permissionsCount]));
                $this->line(__('plugins.commands.uninstall.confirm_details.layouts', ['count' => $layoutsCount]));
                $this->line(__('plugins.commands.uninstall.confirm_details.data'));
                if ($this->option('delete-data')) {
                    $this->warn('⚠️  --delete-data: 마이그레이션 롤백 및 환경설정 파일이 함께 삭제됩니다.');
                }
                $this->newLine();

                if (! $this->confirm(__('plugins.commands.uninstall.confirm_question'), false)) {
                    $this->info(__('plugins.commands.uninstall.aborted'));

                    return Command::SUCCESS;
                }
            }

            // 플러그인 제거
            $deleteData = $this->option('delete-data');
            $onProgress = $this->createProgressCallback(PluginManager::UNINSTALL_STEPS);
            try {
                $result = $this->pluginManager->uninstallPlugin($identifier, $deleteData, $onProgress);
                $this->finishProgress();
            } catch (\Exception $e) {
                $this->finishProgress();
                throw $e;
            }

            if ($result) {
                $this->info('✅ '.__('plugins.commands.uninstall.success', ['plugin' => $identifier]));
                $this->info('   - '.__('plugins.commands.uninstall.roles_deleted', ['count' => $rolesCount]));
                $this->info('   - '.__('plugins.commands.uninstall.permissions_deleted', ['count' => $permissionsCount]));
                $this->info('   - '.__('plugins.commands.uninstall.layouts_deleted', ['count' => $layoutsCount]));
                Log::info(__('plugins.commands.uninstall.success', ['plugin' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('플러그인 제거 실패', [
                'plugin' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
