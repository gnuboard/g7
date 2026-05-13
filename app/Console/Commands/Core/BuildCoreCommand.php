<?php

namespace App\Console\Commands\Core;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class BuildCoreCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'core:build
                            {--watch : 파일 변경 감시 모드}
                            {--production : 프로덕션 빌드}
                            {--full : 전체 빌드 (npm run build)}';

    /**
     * The console command description.
     */
    protected $description = '그누보드7 코어 프론트엔드 에셋을 빌드합니다';

    /**
     * Execute the console command.
     *
     * @return int 명령 실행 결과 코드
     */
    public function handle(): int
    {
        $watchMode = $this->option('watch');
        $productionMode = $this->option('production');
        $full = $this->option('full');

        try {
            $projectPath = base_path();
            $packageJsonPath = $projectPath.'/package.json';

            // package.json 존재 확인
            if (! file_exists($packageJsonPath)) {
                $this->error('❌ package.json 파일이 없습니다.');

                return Command::FAILURE;
            }

            // node_modules 확인 및 설치
            if (! is_dir($projectPath.'/node_modules')) {
                $this->info('📦 의존성 설치 중...');
                $installResult = $this->runNpmCommand(['npm', 'install'], $projectPath);

                if ($installResult !== Command::SUCCESS) {
                    $this->error('❌ npm install 실패');

                    return Command::FAILURE;
                }
            }

            // 빌드 유형 결정: 기본은 템플릿 엔진만 빌드
            if ($full) {
                return $this->buildFull($projectPath, $watchMode, $productionMode);
            }

            return $this->buildEngineOnly($projectPath, $watchMode, $productionMode);

        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('코어 빌드 실패', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 템플릿 엔진만 빌드 (build:core)
     *
     * @param  string  $projectPath  프로젝트 경로
     * @param  bool  $watchMode  파일 감시 모드
     * @param  bool  $productionMode  프로덕션 빌드
     * @return int 명령 실행 결과 코드
     */
    private function buildEngineOnly(string $projectPath, bool $watchMode, bool $productionMode): int
    {
        $buildCommand = ['npm', 'run'];

        if ($watchMode) {
            // build:core는 watch 모드가 없으므로 dev 사용
            $buildCommand[] = 'dev';
            $this->info('👀 파일 감시 모드로 코어 빌드 시작 (템플릿 엔진)');
            $this->line('   Ctrl+C로 종료할 수 있습니다.');
        } else {
            $buildCommand[] = 'build:core';
            $this->info('🔨 코어 빌드 시작 (템플릿 엔진)'.($productionMode ? ' (프로덕션)' : ''));
        }

        $result = $this->runNpmCommand($buildCommand, $projectPath, ! $watchMode);

        if ($result === Command::SUCCESS && ! $watchMode) {
            $this->info('✅ 코어 빌드 완료 (템플릿 엔진)');
            $this->showEngineBuildResults($projectPath);
        }

        return $result;
    }

    /**
     * 전체 빌드
     *
     * @param  string  $projectPath  프로젝트 경로
     * @param  bool  $watchMode  파일 감시 모드
     * @param  bool  $productionMode  프로덕션 빌드
     * @return int 명령 실행 결과 코드
     */
    private function buildFull(string $projectPath, bool $watchMode, bool $productionMode): int
    {
        $buildCommand = ['npm', 'run'];

        if ($watchMode) {
            $buildCommand[] = 'dev';
            $this->info('👀 파일 감시 모드로 코어 빌드 시작 (전체)');
            $this->line('   Ctrl+C로 종료할 수 있습니다.');
        } else {
            $buildCommand[] = 'build';
            $this->info('🔨 코어 빌드 시작 (전체)'.($productionMode ? ' (프로덕션)' : ''));
        }

        $result = $this->runNpmCommand($buildCommand, $projectPath, ! $watchMode);

        if ($result === Command::SUCCESS && ! $watchMode) {
            $this->info('✅ 코어 빌드 완료 (전체)');
            $this->showFullBuildResults($projectPath);
        }

        return $result;
    }

    /**
     * 템플릿 엔진 빌드 결과 출력
     *
     * @param  string  $projectPath  프로젝트 경로
     */
    private function showEngineBuildResults(string $projectPath): void
    {
        $corePath = $projectPath.'/public/build/core';

        if (! is_dir($corePath)) {
            return;
        }

        $this->line('   빌드 결과:');

        // template-engine.min.js 확인
        $engineFile = $corePath.'/template-engine.min.js';
        if (file_exists($engineFile)) {
            $fileSize = number_format(filesize($engineFile) / 1024, 2);
            $this->line("   - template-engine.min.js ({$fileSize} KB)");
        }

        // lang 파일 확인
        $langPath = $corePath.'/lang';
        if (is_dir($langPath)) {
            $langFiles = glob($langPath.'/*.json');
            foreach ($langFiles as $langFile) {
                $fileName = 'lang/'.basename($langFile);
                $fileSize = number_format(filesize($langFile) / 1024, 2);
                $this->line("   - {$fileName} ({$fileSize} KB)");
            }
        }
    }

    /**
     * 전체 빌드 결과 출력
     *
     * @param  string  $projectPath  프로젝트 경로
     */
    private function showFullBuildResults(string $projectPath): void
    {
        $buildPath = $projectPath.'/public/build';

        if (! is_dir($buildPath)) {
            return;
        }

        $this->line('   빌드 결과:');

        // manifest.json 확인
        $manifestPath = $buildPath.'/manifest.json';
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if ($manifest) {
                foreach ($manifest as $source => $info) {
                    if (is_array($info) && isset($info['file'])) {
                        $filePath = $buildPath.'/'.$info['file'];
                        if (file_exists($filePath)) {
                            $fileName = $info['file'];
                            $fileSize = number_format(filesize($filePath) / 1024, 2);
                            $this->line("   - {$fileName} ({$fileSize} KB)");
                        }
                    }
                }
            }
        } else {
            // manifest가 없으면 직접 파일 탐색
            $this->scanBuildFiles($buildPath, 'assets');
        }
    }

    /**
     * 빌드 파일 스캔 및 출력
     *
     * @param  string  $buildPath  빌드 경로
     * @param  string  $subDir  하위 디렉토리
     */
    private function scanBuildFiles(string $buildPath, string $subDir): void
    {
        $assetsPath = $buildPath.'/'.$subDir;

        if (! is_dir($assetsPath)) {
            return;
        }

        $files = scandir($assetsPath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $assetsPath.'/'.$file;
            if (is_file($filePath)) {
                $fileName = $subDir.'/'.$file;
                $fileSize = number_format(filesize($filePath) / 1024, 2);
                $this->line("   - {$fileName} ({$fileSize} KB)");
            }
        }
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
                // 출력 표시
                if ($type === Process::ERR) {
                    // stderr이지만 npm은 정상 출력도 stderr로 보내므로 그냥 표시
                    $this->output->write($buffer);
                } else {
                    $this->output->write($buffer);
                }
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
