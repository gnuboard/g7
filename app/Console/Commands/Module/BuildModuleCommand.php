<?php

namespace App\Console\Commands\Module;

use App\Extension\ModuleManager;
use App\Extension\Traits\ClearsTemplateCaches;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class BuildModuleCommand extends Command
{
    use ClearsTemplateCaches;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:build
                            {identifier? : 빌드할 모듈 식별자 (생략 시 --all 필요)}
                            {--all : 모든 모듈 빌드}
                            {--watch : 파일 변경 감시 모드}
                            {--production : 프로덕션 빌드}
                            {--active : 활성 디렉토리에서 빌드}';

    /**
     * The console command description.
     */
    protected $description = '모듈의 프론트엔드 에셋을 빌드합니다 (기본: _bundled 디렉토리)';

    /**
     * 모듈 관리자
     */
    public function __construct(
        private ModuleManager $moduleManager
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $identifier = $this->argument('identifier');
        $buildAll = $this->option('all');
        $watchMode = $this->option('watch');
        $productionMode = $this->option('production');

        if (! $identifier && ! $buildAll) {
            $this->error('❌ 모듈 식별자를 지정하거나 --all 옵션을 사용하세요.');
            $this->line('');
            $this->line('사용법:');
            $this->line('  php artisan module:build sirsoft-ecommerce');
            $this->line('  php artisan module:build --all');
            $this->line('  php artisan module:build sirsoft-ecommerce --watch');
            $this->line('  php artisan module:build --all --production');
            $this->line('  php artisan module:build sirsoft-ecommerce --active');

            return Command::FAILURE;
        }

        try {
            // 모듈 로드
            $this->moduleManager->loadModules();

            if ($buildAll) {
                return $this->buildAllModules($productionMode);
            }

            return $this->buildModule($identifier, $watchMode, $productionMode);
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('모듈 빌드 실패', [
                'module' => $identifier ?? 'all',
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
     * @param  string  $identifier  모듈 식별자
     * @return array|null ['path' => string, 'source' => 'bundled'|'active'] 또는 null
     */
    private function resolveBuildPath(string $identifier): ?array
    {
        $bundledPath = base_path("modules/_bundled/{$identifier}");
        $activePath = base_path("modules/{$identifier}");

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
     * 단일 모듈 빌드
     *
     * @param  string  $identifier  모듈 식별자
     * @param  bool  $watchMode  파일 감시 모드
     * @param  bool  $productionMode  프로덕션 빌드
     * @return int 명령 실행 결과 코드
     */
    private function buildModule(string $identifier, bool $watchMode, bool $productionMode): int
    {
        // 경로 결정
        $resolved = $this->resolveBuildPath($identifier);
        if (! $resolved) {
            $this->error("❌ 모듈을 찾을 수 없습니다: {$identifier}");
            $this->line('   _bundled 및 활성 디렉토리 모두 존재하지 않습니다.');

            return Command::FAILURE;
        }

        $buildPath = $resolved['path'];
        $source = $resolved['source'];

        // 소스 표시
        $sourceLabel = $source === 'bundled' ? '_bundled' : '활성';
        $this->info("📂 빌드 소스: {$sourceLabel} ({$buildPath})");

        // package.json 존재 확인
        $packageJsonPath = $buildPath.'/package.json';
        if (! file_exists($packageJsonPath)) {
            $this->error('❌ package.json 파일이 없습니다: '.$packageJsonPath);
            $this->line('   모듈 프론트엔드 구조를 먼저 생성하세요.');

            return Command::FAILURE;
        }

        // 에셋 빌드 가능 여부 확인 (활성 모듈 인스턴스가 있는 경우)
        $module = $this->moduleManager->getModule($identifier);
        if ($module && ! $module->canBuild() && ! $module->hasAssets()) {
            $this->warn('⚠️  모듈에 빌드할 에셋이 없습니다: '.$identifier);

            return Command::SUCCESS;
        }

        // node_modules 확인 및 설치
        if (! is_dir($buildPath.'/node_modules')) {
            $this->info('📦 의존성 설치 중...');
            $installResult = $this->runNpmCommand(['npm', 'install'], $buildPath);

            if ($installResult !== Command::SUCCESS) {
                $this->error('❌ npm install 실패');

                return Command::FAILURE;
            }
        }

        // 빌드 명령 결정
        $buildCommand = ['npm', 'run'];

        if ($watchMode) {
            $buildCommand[] = 'dev';
            $this->info("👀 파일 감시 모드로 빌드 시작: {$identifier}");
            $this->line('   Ctrl+C로 종료할 수 있습니다.');
        } else {
            $buildCommand[] = 'build';
            $this->info("🔨 빌드 시작: {$identifier}".($productionMode ? ' (프로덕션)' : ''));
        }

        // 빌드 실행
        $result = $this->runNpmCommand($buildCommand, $buildPath, ! $watchMode);

        if ($result === Command::SUCCESS && ! $watchMode) {
            $this->info("✅ 빌드 완료: {$identifier}");

            // 빌드 결과 파일 확인
            $this->displayBuildResults($buildPath, $identifier);

            // 캐시 버전 증가 (브라우저 캐시 무효화)
            $this->incrementExtensionCacheVersion();
            $this->line('   - 캐시 버전 갱신됨');

            // _bundled 빌드 시 활성 반영 안내
            if ($source === 'bundled') {
                $this->line('');
                $this->info("💡 활성 디렉토리에 반영하려면: php artisan module:update {$identifier}");
            }
        }

        return $result;
    }

    /**
     * 빌드 결과 파일 정보를 출력합니다.
     *
     * @param  string  $buildPath  빌드된 경로
     * @param  string  $identifier  모듈 식별자
     */
    private function displayBuildResults(string $buildPath, string $identifier): void
    {
        // 활성 모듈 인스턴스가 있으면 getBuiltAssetPaths 활용
        $module = $this->moduleManager->getModule($identifier);
        if ($module) {
            $builtPaths = $module->getBuiltAssetPaths();

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
        $manifestPath = $buildPath.'/module.json';
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
     * 모든 모듈 빌드
     *
     * @param  bool  $productionMode  프로덕션 빌드
     * @return int 명령 실행 결과 코드
     */
    private function buildAllModules(bool $productionMode): int
    {
        $buildTargets = [];

        if ($this->option('active')) {
            // --active: 활성 디렉토리만 대상
            $activeModules = $this->moduleManager->getAllModules();
            foreach ($activeModules as $identifier => $module) {
                $activePath = base_path("modules/{$identifier}");
                if (file_exists($activePath.'/package.json')
                    && ($module->canBuild() || $module->hasAssets())) {
                    $buildTargets[$identifier] = true;
                }
            }
        } else {
            // 기본값: _bundled 디렉토리 스캔
            $bundledModules = $this->moduleManager->getBundledModules();
            foreach ($bundledModules as $identifier => $metadata) {
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
            $this->warn('⚠️  빌드할 모듈이 없습니다.');

            return Command::SUCCESS;
        }

        $sourceLabel = $this->option('active') ? '활성' : '_bundled';
        $this->info("🔨 모든 모듈 빌드 시작 ({$sourceLabel})".($productionMode ? ' (프로덕션)' : ''));
        $this->line('   대상 모듈: '.implode(', ', array_keys($buildTargets)));
        $this->line('');

        $successCount = 0;
        $failCount = 0;

        foreach ($buildTargets as $identifier => $value) {
            $this->line("   [{$identifier}]");
            $result = $this->buildModule($identifier, false, $productionMode);

            if ($result === Command::SUCCESS) {
                $successCount++;
            } else {
                $failCount++;
            }

            $this->line('');
        }

        $this->info("📊 빌드 결과: 성공 {$successCount}개, 실패 {$failCount}개");

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * npm 명령 실행
     *
     * @param  array  $command  실행할 명령
     * @param  string  $cwd  작업 디렉토리
     * @param  bool  $waitForCompletion  완료 대기 여부
     * @return int 명령 실행 결과 코드
     */
    private function runNpmCommand(array $command, string $cwd, bool $waitForCompletion = true): int
    {
        // Windows 환경에서는 cmd /c 사용
        if (PHP_OS_FAMILY === 'Windows') {
            $command = array_merge(['cmd', '/c'], $command);
        }

        $process = new Process($command);
        $process->setWorkingDirectory($cwd);
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
