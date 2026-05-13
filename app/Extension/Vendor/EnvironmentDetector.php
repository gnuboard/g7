<?php

namespace App\Extension\Vendor;

use Illuminate\Support\Facades\Log;

/**
 * 런타임 환경에서 composer 실행 가능 여부 및 ZipArchive 사용 가능 여부를 감지합니다.
 *
 * 공유 호스팅 환경(proc_open 차단, composer 미설치)에서 vendor 번들 모드로
 * 자동 폴백하기 위한 판단 근거를 제공합니다.
 */
class EnvironmentDetector
{
    /**
     * 캐시된 composer 실행 가능 여부.
     */
    private ?bool $cachedComposerExecutable = null;

    /**
     * 캐시된 composer 바이너리 경로.
     */
    private ?string $cachedComposerBinary = null;

    /**
     * proc_open() 함수 사용 가능 여부.
     *
     * @return bool proc_open 호출 가능 여부 (disable_functions 차단 시 false)
     */
    public function hasProcOpen(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('proc_open', $disabled, true);
    }

    /**
     * shell_exec() 함수 사용 가능 여부.
     *
     * @return bool shell_exec 호출 가능 여부 (disable_functions 차단 시 false)
     */
    public function hasShellExec(): bool
    {
        if (! function_exists('shell_exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('shell_exec', $disabled, true);
    }

    /**
     * ZipArchive 클래스 사용 가능 여부.
     *
     * @return bool ext-zip 활성화 여부
     */
    public function hasZipArchive(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * composer 바이너리 경로 찾기.
     *
     * 우선순위: hint → config(process.composer_binary) → $_ENV['COMPOSER_BINARY']
     *           → PATH 검색 → composer.phar
     *
     * @param  string|null  $hint  바이너리 경로 힌트 (null 이면 config/ENV/PATH 순으로 자동 탐색)
     * @return string|null  발견된 composer 바이너리 경로. 없으면 null
     */
    public function findComposerBinary(?string $hint = null): ?string
    {
        if ($this->cachedComposerBinary !== null) {
            return $this->cachedComposerBinary ?: null;
        }

        $candidates = array_filter([
            $hint,
            config('process.composer_binary'),
            $_ENV['COMPOSER_BINARY'] ?? null,
            getenv('COMPOSER_BINARY') ?: null,
        ]);

        foreach ($candidates as $candidate) {
            if ($this->isExecutableCandidate($candidate)) {
                return $this->cachedComposerBinary = $candidate;
            }
        }

        $found = $this->searchComposerInPath();
        if ($found !== null) {
            return $this->cachedComposerBinary = $found;
        }

        // composer.phar 폴백
        $pharCandidates = [
            base_path('composer.phar'),
            getcwd().DIRECTORY_SEPARATOR.'composer.phar',
        ];
        foreach ($pharCandidates as $phar) {
            if (is_file($phar)) {
                return $this->cachedComposerBinary = $phar;
            }
        }

        $this->cachedComposerBinary = '';

        return null;
    }

    /**
     * composer 실행 가능 여부 종합 판단.
     *
     * proc_open 사용 가능 + composer 바이너리 발견 + `composer --version` 종료 코드 0.
     *
     * @param  string|null  $hint  composer 바이너리 경로 힌트 (캐시 우회용)
     * @return bool  composer 가 현재 환경에서 실행 가능한지 여부
     */
    public function canExecuteComposer(?string $hint = null): bool
    {
        if ($this->cachedComposerExecutable !== null && $hint === null) {
            return $this->cachedComposerExecutable;
        }

        if (! $this->hasProcOpen()) {
            return $this->cachedComposerExecutable = false;
        }

        $binary = $this->findComposerBinary($hint);
        if ($binary === null) {
            return $this->cachedComposerExecutable = false;
        }

        try {
            $command = $this->buildComposerCommand($binary, ['--version', '--no-interaction']);
            $process = @proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                null,
                self::buildComposerEnv(),
                ['bypass_shell' => true]
            );

            if (! is_resource($process)) {
                return $this->cachedComposerExecutable = false;
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            $exit = proc_close($process);

            return $this->cachedComposerExecutable = ($exit === 0);
        } catch (\Throwable $e) {
            Log::warning('Composer 실행 가능 여부 확인 실패', [
                'error' => $e->getMessage(),
                'binary' => $binary,
            ]);

            return $this->cachedComposerExecutable = false;
        }
    }

    /**
     * 지정 경로에 쓰기 가능한지 확인.
     *
     * @param  string  $targetPath  vendor 디렉토리 경로 (존재하지 않아도 부모 디렉토리 쓰기 권한 검사)
     * @return bool  부모 디렉토리가 존재하고 쓰기 가능한지 여부
     */
    public function canWriteVendor(string $targetPath): bool
    {
        $parent = dirname($targetPath);

        return is_dir($parent) && is_writable($parent);
    }

    /**
     * 인스톨러 Step 2 요구사항 체크용 종합 리포트.
     *
     * @param  string|null  $hint  composer 바이너리 경로 힌트 (캐시 우회용)
     * @return array{
     *     proc_open: bool,
     *     shell_exec: bool,
     *     zip_archive: bool,
     *     composer_binary: string|null,
     *     composer_executable: bool,
     *     can_use_composer: bool,
     *     can_use_bundle: bool,
     * }
     */
    public function summarize(?string $hint = null): array
    {
        $composerBinary = $this->findComposerBinary($hint);
        $composerExecutable = $this->canExecuteComposer($hint);
        $zipAvailable = $this->hasZipArchive();

        return [
            'proc_open' => $this->hasProcOpen(),
            'shell_exec' => $this->hasShellExec(),
            'zip_archive' => $zipAvailable,
            'composer_binary' => $composerBinary,
            'composer_executable' => $composerExecutable,
            'can_use_composer' => $composerExecutable,
            'can_use_bundle' => $zipAvailable,
        ];
    }

    /**
     * 캐시 초기화 (테스트용).
     */
    public function resetCache(): void
    {
        $this->cachedComposerExecutable = null;
        $this->cachedComposerBinary = null;
    }

    /**
     * proc_open() 5번째 인자용 composer 환경변수 배열을 구성합니다.
     *
     * root/super user 환경 + 비대화형(웹) 컨텍스트에서 composer 가 interactive
     * 경고를 출력하며 비정상 종료하는 문제를 차단합니다.
     *
     * 현재 프로세스의 환경변수(getenv() + $_ENV)에 두 composer 전용 변수를 마지막에
     * 덮어쓰기 — 외부에서 0 으로 설정되어 있어도 강제로 1.
     *
     * @return array<string, string>
     */
    public static function buildComposerEnv(): array
    {
        $current = getenv();
        if (! is_array($current)) {
            $current = [];
        }

        return array_merge($current, $_ENV, [
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_NO_INTERACTION' => '1',
        ]);
    }

    /**
     * composer 실행 명령 문자열 구성.
     *
     * @param  array<int, string>  $args
     */
    private function buildComposerCommand(string $binary, array $args): string
    {
        $escaped = array_map('escapeshellarg', $args);

        if (str_contains($binary, ' ')) {
            // 전체 명령어로 취급
            return $binary.' '.implode(' ', $escaped);
        }

        if (str_ends_with(strtolower($binary), '.phar')) {
            $phpBinary = config('process.php_binary', 'php');

            return escapeshellarg($phpBinary).' '.escapeshellarg($binary).' '.implode(' ', $escaped);
        }

        return escapeshellarg($binary).' '.implode(' ', $escaped);
    }

    /**
     * PATH 환경변수에서 composer 검색.
     */
    private function searchComposerInPath(): ?string
    {
        $pathEnv = getenv('PATH') ?: ($_SERVER['PATH'] ?? '');
        if (empty($pathEnv)) {
            return null;
        }

        $separator = DIRECTORY_SEPARATOR === '\\' ? ';' : ':';
        $paths = explode($separator, $pathEnv);
        $names = DIRECTORY_SEPARATOR === '\\'
            ? ['composer.bat', 'composer.exe', 'composer.phar', 'composer']
            : ['composer', 'composer.phar'];

        foreach ($paths as $dir) {
            foreach ($names as $name) {
                $candidate = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * 후보 경로가 실행 가능한 파일인지 확인.
     */
    private function isExecutableCandidate(?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }

        // 공백 포함 시 전체 커맨드로 간주 — 파일 존재 검사 스킵
        if (str_contains($candidate, ' ')) {
            return true;
        }

        return is_file($candidate);
    }
}
