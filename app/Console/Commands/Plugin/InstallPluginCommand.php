<?php

namespace App\Console\Commands\Plugin;

use App\Console\Commands\Traits\HasProgressBar;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\PluginManager;
use App\Models\Permission;
use App\Rules\ValidExtensionIdentifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InstallPluginCommand extends Command
{
    use HasProgressBar;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plugin:install {identifier : 설치할 플러그인 식별자}';

    /**
     * The console command description.
     */
    protected $description = '플러그인을 설치합니다';

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
            // 플러그인 디렉토리 스캔 및 로드
            $this->pluginManager->loadPlugins();

            // 이미 설치된 플러그인인지 확인
            $existingPlugin = $this->pluginRepository->findByIdentifier($identifier);
            if ($existingPlugin) {
                $this->warn('⚠️  '.__('plugins.commands.install.already_installed', ['plugin' => $identifier]));

                return Command::FAILURE;
            }

            // 플러그인 설치
            $onProgress = $this->createProgressCallback(PluginManager::INSTALL_STEPS);
            try {
                $result = $this->pluginManager->installPlugin($identifier, $onProgress);
                $this->finishProgress();
            } catch (\Exception $e) {
                $this->finishProgress();
                throw $e;
            }

            if ($result) {
                // 설치된 플러그인 정보 조회
                $plugin = $this->pluginRepository->findByIdentifier($identifier);

                // 플러그인 인스턴스에서 생성된 role 개수 조회
                $pluginInstance = $this->pluginManager->getPlugin($identifier);
                $rolesCount = 0;
                if ($pluginInstance && method_exists($pluginInstance, 'getRoles')) {
                    $rolesCount = count($pluginInstance->getRoles());
                }

                // 권한 개수 조회
                $permissionsCount = Permission::byExtension(ExtensionOwnerType::Plugin, $identifier)->count();

                // 성공 메시지
                $this->info('✅ '.__('plugins.commands.install.success', ['plugin' => $identifier]));
                $this->info('   - '.__('plugins.commands.install.vendor', ['vendor' => $plugin->vendor]));
                $this->info('   - '.__('plugins.commands.install.version', ['version' => $plugin->version]));
                $this->info('   - '.__('plugins.commands.install.roles_created', ['count' => $rolesCount]));
                $this->info('   - '.__('plugins.commands.install.permissions_created', ['count' => $permissionsCount]));

                Log::info(__('plugins.commands.install.success', ['plugin' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('플러그인 설치 실패', [
                'plugin' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
