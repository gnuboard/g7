<?php

namespace App\Console\Commands\Plugin;

use App\Console\Commands\Concerns\PrunesBuildOutput;
use App\Extension\PluginManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Extension\Traits\GeneratesComponentManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class BuildPluginCommand extends Command
{
    use ClearsTemplateCaches;
    use GeneratesComponentManifest;
    use PrunesBuildOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plugin:build
                            {identifier? : 빌드할 플러그인 식별자 (생략 시 --all 필요)}
                            {--all : 모든 플러그인 빌드}
                            {--watch : 파일 변경 감시 모드}
                            {--production : 프로덕션 빌드}
                            {--active : 활성 디렉토리에서 빌드}';

    /**
     * The console command description.
     */
    protected $description = '플러그인의 프론트엔드 에셋을 빌드합니다 (기본: _bundled 디렉토리)';

    /**
     * 커맨드를 생성합니다.
     *
     * @param  PluginManager  $pluginManager  플러그인 관리자
     */
    public function __construct(
        private PluginManager $pluginManager
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
        $buildAll = $this->option('all');
        $watchMode = $this->option('watch');
        $productionMode = $this->option('production');

        if (! $identifier && ! $buildAll) {
            $this->error('❌ '.__('plugins.commands.build.missing_identifier'));
            $this->line('');
            $this->line(__('plugins.commands.build.usage'));
            $this->line('  php artisan plugin:build sirsoft-payment');
            $this->line('  php artisan plugin:build --all');
            $this->line('  php artisan plugin:build sirsoft-payment --watch');
            $this->line('  php artisan plugin:build --all --production');
            $this->line('  php artisan plugin:build sirsoft-payment --active');

            return Command::FAILURE;
        }

        try {
            // 플러그인 로드
            $this->pluginManager->loadPlugins();

            if ($buildAll) {
                return $this->buildAllPlugins($productionMode);
            }

            return $this->buildPlugin($identifier, $watchMode, $productionMode);
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('플러그인 빌드 실패', [
                'plugin' => $identifier ?? 'all',
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 빌드 대상 경로를 결정합니다.
     *
     * 기본값: _bundled 디렉토리. --active 옵션 시 활성 디렉토리.
     * --watch 모드에서는 활성 디렉토리를 사용합니다 (실시간 개발용).
     *
     * @param  string  $identifier  플러그인 식별자
     * @return array|null ['path' => string, 'source' => 'bundled'|'active'] 또는 null
     */
    private function resolveBuildPath(string $identifier): ?array
    {
        $bundledPath = base_path("plugins/_bundled/{$identifier}");
        $activePath = base_path("plugins/{$identifier}");

        // --active 명시 → 활성 디렉토리
        if ($this->option('active')) {
            return is_dir($activePath)
                ? ['path' => $activePath, 'source' => 'active']
                : null;
        }

        // --watch 모드 → 활성 디렉토리 (실시간 개발용)
        if ($this->option('watch')) {
            if (is_dir($activePath)) {
                return ['path' => $activePath, 'source' => 'active'];
            }
        }

        // 기본값: _bundled 우선
        if (is_dir($bundledPath)) {
            return ['path' => $bundledPath, 'source' => 'bundled'];
        }

        // _bundled에 없으면 활성 디렉토리 폴백
        if (is_dir($activePath)) {
            return ['path' => $activePath, 'source' => 'active'];
        }

        return null;
    }

    /**
     * 단일 플러그인 빌드
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  bool  $watchMode  파일 감시 모드
     * @param  bool  $productionMode  프로덕션 빌드
     * @return int 명령 실행 결과 코드
     */
    private function buildPlugin(string $identifier, bool $watchMode, bool $productionMode): int
    {
        // 경로 결정
        $resolved = $this->resolveBuildPath($identifier);
        if (! $resolved) {
            $this->error('❌ '.__('plugins.commands.build.not_found', ['identifier' => $identifier]));
            $this->line('   '.__('plugins.commands.build.not_found_detail'));

            return Command::FAILURE;
        }

        $buildPath = $resolved['path'];
        $source = $resolved['source'];

        // 소스 표시
        $sourceLabel = $source === 'bundled' ? '_bundled' : __('plugins.commands.build.source_active');
        $this->info('📂 '.__('plugins.commands.build.source', ['source' => $sourceLabel, 'path' => $buildPath]));

        // package.json 존재 확인
        $packageJsonPath = $buildPath.'/package.json';
        if (! file_exists($packageJsonPath)) {
            $this->error('❌ '.__('plugins.commands.build.package_json_missing', ['path' => $packageJsonPath]));
            $this->line('   '.__('plugins.commands.build.package_json_hint'));

            return Command::FAILURE;
        }

        // 에셋 빌드 가능 여부 확인 (활성 플러그인 인스턴스가 있는 경우)
        $plugin = $this->pluginManager->getPlugin($identifier);
        if ($plugin && ! $plugin->canBuild() && ! $plugin->hasAssets()) {
            $this->warn('⚠️  '.__('plugins.commands.build.no_assets', ['identifier' => $identifier]));

            return Command::SUCCESS;
        }

        // node_modules 확인 및 설치
        if (! is_dir($buildPath.'/node_modules')) {
            $this->info('📦 '.__('plugins.commands.build.installing_dependencies'));
            $installResult = $this->runNpmCommand(['npm', 'install'], $buildPath);

            if ($installResult !== Command::SUCCESS) {
                $this->error('❌ '.__('plugins.commands.build.npm_install_failed'));

                return Command::FAILURE;
            }
        }

        // 빌드 명령 결정
        $buildCommand = ['npm', 'run'];

        if ($watchMode) {
            $buildCommand[] = 'dev';
            $this->info('👀 '.__('plugins.commands.build.watch_start', ['identifier' => $identifier]));
            $this->line('   '.__('plugins.commands.build.watch_hint'));
        } else {
            $buildCommand[] = 'build';
            $this->info('🔨 '.($productionMode
                ? __('plugins.commands.build.start_production', ['identifier' => $identifier])
                : __('plugins.commands.build.start', ['identifier' => $identifier])));
        }

        // 이전 산출물 정리 (동봉 vendor 는 보존 — vite emptyOutDir 대체)
        if (! $watchMode) {
            $removed = $this->pruneBuildOutput($buildPath);

            if ($removed !== []) {
                $this->line('   🧹 '.__('plugins.commands.build.pruned', ['files' => implode(', ', $removed)]));
            }
        }
        // 빌드 실행 (감시 모드에는 소스맵 억제를 주입하지 않는다 — 개발 중 디버깅 필요)
        $result = $this->runNpmCommand(
            $buildCommand,
            $buildPath,
            ! $watchMode,
            $watchMode ? [] : $this->buildEnv($productionMode)
        );

        if ($result === Command::SUCCESS && ! $watchMode) {
            $this->info('✅ '.__('plugins.commands.build.done', ['identifier' => $identifier]));

            // 빌드 결과 파일 확인
            $this->displayBuildResults($buildPath, $identifier);

            // 편집기 컴포넌트 매니페스트(components.json) 생성
            $manifestResult = $this->generateComponentManifest($buildPath, $identifier);
            if ($manifestResult['written']) {
                $this->line('   - '.__('plugins.commands.build.components_manifest_written', ['count' => $manifestResult['count']]));
            } else {
                $this->warn('   - '.__('plugins.commands.build.components_manifest_failed'));
            }

            // 캐시 버전 증가 (브라우저 캐시 무효화)
            $this->incrementExtensionCacheVersion();
            $this->line('   - '.__('plugins.commands.build.cache_version_bumped'));

            // _bundled 빌드 시 활성 반영 안내
            if ($source === 'bundled') {
                $this->line('');
                $this->info('💡 '.__('plugins.commands.build.apply_hint', ['identifier' => $identifier]));
            }
        }

        return $result;
    }

    /**
     * 빌드 결과 파일 정보를 출력합니다.
     *
     * @param  string  $buildPath  빌드된 경로
     * @param  string  $identifier  플러그인 식별자
     * @return void
     */
    private function displayBuildResults(string $buildPath, string $identifier): void
    {
        // 활성 플러그인 인스턴스가 있으면 getBuiltAssetPaths 활용
        $plugin = $this->pluginManager->getPlugin($identifier);
        if ($plugin) {
            $builtPaths = $plugin->getBuiltAssetPaths();

            if (! empty($builtPaths['js'])) {
                $jsPath = $buildPath.'/'.$builtPaths['js'];
                if (file_exists($jsPath)) {
                    $this->line('   - JS: '.$builtPaths['js'].' ('.number_format(filesize($jsPath) / 1024, 2).' KB)');
                }
            }

            if (! empty($builtPaths['css'])) {
                $cssPath = $buildPath.'/'.$builtPaths['css'];
                if (file_exists($cssPath)) {
                    $this->line('   - CSS: '.$builtPaths['css'].' ('.number_format(filesize($cssPath) / 1024, 2).' KB)');
                }
            }

            return;
        }

        // 활성 인스턴스 없으면 manifest에서 직접 확인
        $manifestPath = $buildPath.'/plugin.json';
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            $assets = $manifest['assets'] ?? [];

            if (! empty($assets['js']['output'])) {
                $jsPath = $buildPath.'/'.$assets['js']['output'];
                if (file_exists($jsPath)) {
                    $this->line('   - JS: '.$assets['js']['output'].' ('.number_format(filesize($jsPath) / 1024, 2).' KB)');
                }
            }

            if (! empty($assets['css']['output'])) {
                $cssPath = $buildPath.'/'.$assets['css']['output'];
                if (file_exists($cssPath)) {
                    $this->line('   - CSS: '.$assets['css']['output'].' ('.number_format(filesize($cssPath) / 1024, 2).' KB)');
                }
            }
        }
    }

    /**
     * 모든 플러그인 빌드
     *
     * @param  bool  $productionMode  프로덕션 빌드
     * @return int 명령 실행 결과 코드
     */
    private function buildAllPlugins(bool $productionMode): int
    {
        $buildTargets = [];

        if ($this->option('active')) {
            // --active: 활성 디렉토리만 대상
            $activePlugins = $this->pluginManager->getAllPlugins();
            foreach ($activePlugins as $identifier => $plugin) {
                $activePath = base_path("plugins/{$identifier}");
                if (file_exists($activePath.'/package.json')
                    && ($plugin->canBuild() || $plugin->hasAssets())) {
                    $buildTargets[$identifier] = true;
                }
            }
        } else {
            // 기본값: _bundled 디렉토리 스캔
            $bundledPlugins = $this->pluginManager->getBundledPlugins();
            foreach ($bundledPlugins as $identifier => $metadata) {
                $bundledPath = $metadata['source_path'];
                if (file_exists($bundledPath.'/package.json')) {
                    $assets = $metadata['assets'] ?? [];
                    if (! empty($assets['js']['entry']) || ! empty($assets['css']['entry']) || ! empty($assets)) {
                        $buildTargets[$identifier] = true;
                    }
                }
            }
        }

        if (empty($buildTargets)) {
            $this->warn('⚠️  '.__('plugins.commands.build.no_targets'));

            return Command::SUCCESS;
        }

        $sourceLabel = $this->option('active') ? __('plugins.commands.build.source_active') : '_bundled';
        $this->info('🔨 '.($productionMode
            ? __('plugins.commands.build.start_all_production', ['source' => $sourceLabel])
            : __('plugins.commands.build.start_all', ['source' => $sourceLabel])));
        $this->line('   '.__('plugins.commands.build.targets', ['list' => implode(', ', array_keys($buildTargets))]));
        $this->line('');

        $successCount = 0;
        $failCount = 0;

        foreach ($buildTargets as $identifier => $value) {
            $this->line("   [{$identifier}]");
            $result = $this->buildPlugin($identifier, false, $productionMode);

            if ($result === Command::SUCCESS) {
                $successCount++;
            } else {
                $failCount++;
            }

            $this->line('');
        }

        $this->info('📊 '.__('plugins.commands.build.summary', ['success' => $successCount, 'failed' => $failCount]));

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * 빌드 프로세스에 주입할 환경변수를 구성합니다.
     *
     * 프로덕션 빌드에서는 소스맵을 생성하지 않습니다. 배포 산출물에 원본 코드가
     * 포함되는 것을 막기 위함이며, 각 vite config 가 이 값을 읽습니다.
     *
     * @param  bool  $productionMode  프로덕션 빌드 여부
     * @return array<string, string> Process 에 주입할 환경변수
     */
    private function buildEnv(bool $productionMode): array
    {
        return $productionMode ? ['G7_BUILD_SOURCEMAP' => '0'] : [];
    }

    /**
     * npm 명령 실행
     *
     * @param  array  $command  실행할 명령
     * @param  string  $cwd  작업 디렉토리
     * @param  bool  $waitForCompletion  완료 대기 여부
     * @param  array<string, string>  $env  추가로 주입할 환경변수 (부모 환경에 병합됨)
     * @return int 명령 실행 결과 코드
     */
    private function runNpmCommand(array $command, string $cwd, bool $waitForCompletion = true, array $env = []): int
    {
        // Windows 환경에서는 cmd /c 사용
        if (PHP_OS_FAMILY === 'Windows') {
            $command = array_merge(['cmd', '/c'], $command);
        }

        $process = new Process($command);
        $process->setWorkingDirectory($cwd);

        // Symfony Process 는 지정한 env 를 부모 환경에 병합하므로(PATH 등 유지) 추가분만 넘긴다.
        if ($env !== []) {
            $process->setEnv($env);
        }
        $process->setTimeout(null); // 타임아웃 없음

        if ($waitForCompletion) {
            $process->run(function ($type, $buffer) {
                $this->output->write($buffer);
            });

            return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
        }

        // 감시 모드: 인터럽트까지 실행
        $process->start(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        // 프로세스가 실행 중인 동안 대기
        while ($process->isRunning()) {
            usleep(100000); // 100ms
        }

        return Command::SUCCESS;
    }
}
