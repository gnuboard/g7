<?php

namespace App\Console\Commands\Module;

use App\Console\Commands\Traits\HasProgressBar;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\ModuleManager;
use App\Models\Menu;
use App\Models\Permission;
use App\Rules\ValidExtensionIdentifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InstallModuleCommand extends Command
{
    use HasProgressBar;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:install {identifier : 설치할 모듈 식별자}';

    /**
     * The console command description.
     */
    protected $description = '모듈을 설치합니다';

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

        // 식별자 형식 검증
        $validator = Validator::make(
            ['identifier' => $identifier],
            ['identifier' => [new ValidExtensionIdentifier]]
        );

        if ($validator->fails()) {
            $this->error('❌ '.$validator->errors()->first('identifier'));

            return Command::FAILURE;
        }

        try {
            // 모듈 디렉토리 스캔 및 로드
            $this->moduleManager->loadModules();

            // 이미 설치된 모듈인지 확인
            $existingModule = $this->moduleRepository->findByIdentifier($identifier);
            if ($existingModule) {
                $this->warn('⚠️  '.__('modules.commands.install.already_installed', ['module' => $identifier]));

                return Command::FAILURE;
            }

            // 모듈 설치
            $onProgress = $this->createProgressCallback(ModuleManager::INSTALL_STEPS);
            try {
                $result = $this->moduleManager->installModule($identifier, $onProgress);
                $this->finishProgress();
            } catch (\Exception $e) {
                $this->finishProgress();
                throw $e;
            }

            if ($result) {
                // 설치된 모듈 정보 조회
                $module = $this->moduleRepository->findByIdentifier($identifier);

                // 모듈 인스턴스에서 생성된 role 개수 조회
                $moduleInstance = $this->moduleManager->getModule($identifier);
                $rolesCount = 0;
                if ($moduleInstance && method_exists($moduleInstance, 'getRoles')) {
                    $rolesCount = count($moduleInstance->getRoles());
                }

                // 권한 및 메뉴 개수 조회
                $permissionsCount = Permission::byExtension(ExtensionOwnerType::Module, $identifier)->count();
                $menusCount = Menu::byExtension(ExtensionOwnerType::Module, $identifier)->count();

                // 성공 메시지
                $this->info('✅ '.__('modules.commands.install.success', ['module' => $identifier]));
                $this->info('   - '.__('modules.commands.install.vendor', ['vendor' => $module->vendor]));
                $this->info('   - '.__('modules.commands.install.version', ['version' => $module->version]));
                $this->info('   - '.__('modules.commands.install.roles_created', ['count' => $rolesCount]));
                $this->info('   - '.__('modules.commands.install.permissions_created', ['count' => $permissionsCount]));
                $this->info('   - '.__('modules.commands.install.menus_created', ['count' => $menusCount]));

                Log::info(__('modules.commands.install.success', ['module' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('모듈 설치 실패', [
                'module' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
