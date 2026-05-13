<?php

namespace App\Console\Commands\Plugin;

use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\ExtensionManager;
use App\Extension\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ComposerInstallPluginCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plugin:composer-install
                            {identifier? : Composer 의존성을 설치할 플러그인 식별자 (생략 시 --all 필요)}
                            {--all : 모든 플러그인의 Composer 의존성 설치}
                            {--no-dev : dev 의존성 제외}';

    /**
     * The console command description.
     */
    protected $description = '플러그인의 Composer 의존성을 설치합니다';

    /**
     * 플러그인 관리자 및 리포지토리
     */
    public function __construct(
        private PluginManager $pluginManager,
        private ExtensionManager $extensionManager,
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
        $installAll = $this->option('all');
        $noDev = $this->option('no-dev');

        if (! $identifier && ! $installAll) {
            $this->error('❌ 플러그인 식별자를 지정하거나 --all 옵션을 사용하세요.');
            $this->line('');
            $this->line('사용법:');
            $this->line('  php artisan plugin:composer-install sirsoft-payment');
            $this->line('  php artisan plugin:composer-install --all');
            $this->line('  php artisan plugin:composer-install --all --no-dev');

            return Command::FAILURE;
        }

        try {
            $this->pluginManager->loadPlugins();

            if ($installAll) {
                return $this->installAllPlugins($noDev);
            }

            return $this->installPlugin($identifier, $noDev);
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('플러그인 Composer 의존성 설치 실패', [
                'plugin' => $identifier ?? 'all',
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 단일 플러그인의 Composer 의존성 설치
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  bool  $noDev  dev 의존성 제외 여부
     * @return int 커맨드 결과 코드
     */
    private function installPlugin(string $identifier, bool $noDev): int
    {
        $plugin = $this->pluginManager->getPlugin($identifier);

        if (! $plugin) {
            $this->error('❌ '.__('plugins.errors.not_found', ['plugin' => $identifier]));

            return Command::FAILURE;
        }

        if (! $this->extensionManager->hasComposerDependencies('plugins', $identifier)) {
            $this->info('ℹ️  '.__('plugins.composer_install.no_dependencies', ['plugin' => $identifier]));

            return Command::SUCCESS;
        }

        $deps = $this->extensionManager->getComposerDependencies('plugins', $identifier);
        $this->info('📦 '.__('plugins.composer_install.start', ['plugin' => $identifier]));
        $this->line('   '.implode(', ', array_keys($deps)));

        $result = $this->extensionManager->runComposerInstall('plugins', $identifier, $noDev, $this);

        if ($result) {
            $this->info('✅ '.__('plugins.composer_install.success', ['plugin' => $identifier]));

            // 오토로드 갱신
            $this->extensionManager->updateComposerAutoload();
            $this->line('   오토로드 캐시 갱신 완료');

            return Command::SUCCESS;
        }

        $this->error('❌ '.__('plugins.composer_install.failed', ['plugin' => $identifier]));

        return Command::FAILURE;
    }

    /**
     * 모든 플러그인의 Composer 의존성 설치
     *
     * @param  bool  $noDev  dev 의존성 제외 여부
     * @return int 커맨드 결과 코드
     */
    private function installAllPlugins(bool $noDev): int
    {
        $plugins = $this->pluginManager->getAllPlugins();

        if (empty($plugins)) {
            $this->warn('⚠️  설치된 플러그인이 없습니다.');

            return Command::SUCCESS;
        }

        // 중복 패키지 감지
        $duplicates = $this->extensionManager->detectDuplicatePackages();
        if (! empty($duplicates)) {
            $this->warn('⚠️  중복 패키지 감지:');
            foreach ($duplicates as $package => $users) {
                $this->warn("   - {$package}: ".implode(', ', $users));
            }
            $this->line('');
        }

        $this->info('📦 모든 플러그인의 Composer 의존성 설치 시작');

        $successCount = 0;
        $skipCount = 0;
        $failCount = 0;

        foreach ($plugins as $identifier => $plugin) {
            if (! $this->extensionManager->hasComposerDependencies('plugins', $identifier)) {
                $skipCount++;

                continue;
            }

            $this->line("   [{$identifier}]");
            $result = $this->extensionManager->runComposerInstall('plugins', $identifier, $noDev, $this);

            if ($result) {
                $successCount++;
                $this->info("   ✅ 완료: {$identifier}");
            } else {
                $failCount++;
                $this->error("   ❌ 실패: {$identifier}");
            }

            $this->line('');
        }

        // 오토로드 갱신
        $this->extensionManager->updateComposerAutoload();

        $this->info(__('plugins.composer_install.summary', [
            'success' => $successCount,
            'skip' => $skipCount,
            'fail' => $failCount,
        ]));

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
