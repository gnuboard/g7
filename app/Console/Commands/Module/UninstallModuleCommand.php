<?php

namespace App\Console\Commands\Module;

use App\Console\Commands\Traits\HasProgressBar;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\ModuleManager;
use App\Models\Menu;
use App\Models\Permission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UninstallModuleCommand extends Command
{
    use HasProgressBar;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:uninstall
        {identifier : 제거할 모듈 식별자}
        {--force : 확인 없이 삭제}
        {--delete-data : 모듈 데이터(테이블, 환경설정) 함께 삭제}';

    /**
     * The console command description.
     */
    protected $description = '모듈을 제거합니다';

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

            // 모듈이 설치되어 있는지 확인
            $moduleRecord = $this->moduleRepository->findByIdentifier($identifier);
            if (! $moduleRecord) {
                $this->error('❌ '.__('modules.commands.uninstall.not_installed', ['module' => $identifier]));

                return Command::FAILURE;
            }

            // 모듈 인스턴스에서 role 개수 조회
            $moduleInstance = $this->moduleManager->getModule($identifier);
            $rolesCount = 0;
            if ($moduleInstance && method_exists($moduleInstance, 'getRoles')) {
                $rolesCount = count($moduleInstance->getRoles());
            }

            // 권한, 메뉴, 레이아웃 개수 조회
            $permissionsCount = Permission::byExtension(ExtensionOwnerType::Module, $identifier)->count();
            $menusCount = Menu::byExtension(ExtensionOwnerType::Module, $identifier)->count();
            $layoutsCount = $this->moduleManager->getModuleLayoutsCount($identifier);

            // 확인 프롬프트 (--force 옵션이 없는 경우)
            if (! $this->option('force')) {
                $this->warn(__('modules.commands.uninstall.confirm_prompt', ['module' => $identifier]));
                $this->line(__('modules.commands.uninstall.confirm_details.roles', ['count' => $rolesCount]));
                $this->line(__('modules.commands.uninstall.confirm_details.permissions', ['count' => $permissionsCount]));
                $this->line(__('modules.commands.uninstall.confirm_details.menus', ['count' => $menusCount]));
                $this->line(__('modules.commands.uninstall.confirm_details.layouts', ['count' => $layoutsCount]));
                $this->line(__('modules.commands.uninstall.confirm_details.data'));
                if ($this->option('delete-data')) {
                    $this->warn('⚠️  --delete-data: 마이그레이션 롤백 및 환경설정 파일이 함께 삭제됩니다.');
                }
                $this->newLine();

                if (! $this->confirm(__('modules.commands.uninstall.confirm_question'), false)) {
                    $this->info(__('modules.commands.uninstall.aborted'));

                    return Command::SUCCESS;
                }
            }

            // 모듈 제거
            $deleteData = $this->option('delete-data');
            $onProgress = $this->createProgressCallback(ModuleManager::UNINSTALL_STEPS);
            try {
                $result = $this->moduleManager->uninstallModule($identifier, $deleteData, $onProgress);
                $this->finishProgress();
            } catch (\Exception $e) {
                $this->finishProgress();
                throw $e;
            }

            if ($result) {
                $this->info('✅ '.__('modules.commands.uninstall.success', ['module' => $identifier]));
                $this->info('   - '.__('modules.commands.uninstall.roles_deleted', ['count' => $rolesCount]));
                $this->info('   - '.__('modules.commands.uninstall.permissions_deleted', ['count' => $permissionsCount]));
                $this->info('   - '.__('modules.commands.uninstall.menus_deleted', ['count' => $menusCount]));
                $this->info('   - '.__('modules.commands.uninstall.layouts_deleted', ['count' => $layoutsCount]));
                Log::info(__('modules.commands.uninstall.success', ['module' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('모듈 제거 실패', [
                'module' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
