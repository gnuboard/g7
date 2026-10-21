<?php

namespace App\Console\Commands\Module;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ComposerInstallModuleCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:composer-install
                            {identifier? : Composer 의존성을 설치할 모듈 식별자 (생략 시 --all 필요)}
                            {--all : 모든 모듈의 Composer 의존성 설치}
                            {--no-dev : dev 의존성 제외}';

    /**
     * The console command description.
     */
    protected $description = '모듈의 Composer 의존성을 설치합니다';

    /**
     * 모듈 관리자 및 리포지토리
     *
     * @param  ModuleManager  $moduleManager  모듈 관리자 (모듈 적재·조회)
     * @param  ExtensionManager  $extensionManager  Composer 의존성 확인·설치·오토로드 갱신을 맡는 확장 관리자
     * @param  ModuleRepositoryInterface  $moduleRepository  모듈 리포지토리
     */
    public function __construct(
        private ModuleManager $moduleManager,
        private ExtensionManager $extensionManager,
        private ModuleRepositoryInterface $moduleRepository
    ) {
        parent::__construct();
    }

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $identifier = $this->argument('identifier');
        $installAll = $this->option('all');
        $noDev = $this->option('no-dev');

        if (! $identifier && ! $installAll) {
            $this->error('❌ '.__('modules.commands.composer_install.missing_identifier'));
            $this->line('');
            $this->line(__('modules.commands.composer_install.usage'));
            $this->line('  php artisan module:composer-install sirsoft-ecommerce');
            $this->line('  php artisan module:composer-install --all');
            $this->line('  php artisan module:composer-install --all --no-dev');

            return Command::FAILURE;
        }

        try {
            $this->moduleManager->loadModules();

            if ($installAll) {
                return $this->installAllModules($noDev);
            }

            return $this->installModule($identifier, $noDev);
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('모듈 Composer 의존성 설치 실패', [
                'module' => $identifier ?? 'all',
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 단일 모듈의 Composer 의존성 설치
     *
     * @param  string  $identifier  모듈 식별자
     * @param  bool  $noDev  dev 의존성 제외 여부
     * @return int 커맨드 결과 코드
     */
    private function installModule(string $identifier, bool $noDev): int
    {
        $module = $this->moduleManager->getModule($identifier);

        if (! $module) {
            $this->error('❌ '.__('modules.errors.not_found', ['module' => $identifier]));

            return Command::FAILURE;
        }

        if (! $this->extensionManager->hasComposerDependencies('modules', $identifier)) {
            $this->info('ℹ️  '.__('modules.composer_install.no_dependencies', ['module' => $identifier]));

            return Command::SUCCESS;
        }

        $deps = $this->extensionManager->getComposerDependencies('modules', $identifier);
        $this->info('📦 '.__('modules.composer_install.start', ['module' => $identifier]));
        $this->line('   '.implode(', ', array_keys($deps)));

        $result = $this->extensionManager->runComposerInstall('modules', $identifier, $noDev, $this);

        if ($result) {
            $this->info('✅ '.__('modules.composer_install.success', ['module' => $identifier]));

            // 오토로드 갱신
            $this->extensionManager->updateComposerAutoload();
            $this->line('   '.__('modules.commands.composer_install.autoload_refreshed'));

            return Command::SUCCESS;
        }

        $this->error('❌ '.__('modules.composer_install.failed', ['module' => $identifier]));

        return Command::FAILURE;
    }

    /**
     * 모든 모듈의 Composer 의존성 설치
     *
     * @param  bool  $noDev  dev 의존성 제외 여부
     * @return int 커맨드 결과 코드
     */
    private function installAllModules(bool $noDev): int
    {
        $modules = $this->moduleManager->getAllModules();

        if (empty($modules)) {
            $this->warn('⚠️  '.__('modules.commands.composer_install.none_installed'));

            return Command::SUCCESS;
        }

        // 중복 패키지 감지
        $duplicates = $this->extensionManager->detectDuplicatePackages();
        if (! empty($duplicates)) {
            $this->warn('⚠️  '.__('modules.commands.composer_install.duplicate_packages'));
            foreach ($duplicates as $package => $users) {
                $this->warn("   - {$package}: ".implode(', ', $users));
            }
            $this->line('');
        }

        $this->info('📦 '.__('modules.commands.composer_install.start_all'));

        $successCount = 0;
        $skipCount = 0;
        $failCount = 0;

        foreach ($modules as $identifier => $module) {
            if (! $this->extensionManager->hasComposerDependencies('modules', $identifier)) {
                $skipCount++;

                continue;
            }

            $this->line("   [{$identifier}]");
            $result = $this->extensionManager->runComposerInstall('modules', $identifier, $noDev, $this);

            if ($result) {
                $successCount++;
                $this->info('   ✅ '.__('modules.commands.composer_install.item_done', ['identifier' => $identifier]));
            } else {
                $failCount++;
                $this->error('   ❌ '.__('modules.commands.composer_install.item_failed', ['identifier' => $identifier]));
            }

            $this->line('');
        }

        // 오토로드 갱신
        $this->extensionManager->updateComposerAutoload();

        $this->info(__('modules.composer_install.summary', [
            'success' => $successCount,
            'skip' => $skipCount,
            'fail' => $failCount,
        ]));

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
