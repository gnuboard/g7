<?php

namespace App\Support\ExtensionDoc;

use Illuminate\Support\Facades\File;

/**
 * 확장 테스트 경로 수집기
 *
 * 확장이 보유한 PHPUnit · Vitest · Playwright 테스트와 시나리오 매니페스트를 수집하고,
 * 실행 명령을 규정에 맞는 형태로 조립합니다.
 *
 * 실행 명령은 문서의 핵심 산출물입니다 — 확장 테스트는 무필터 전체 실행이 금지되어 있고
 * 프론트/백엔드가 서로 다른 셸 규약을 요구하므로, 그 형태를 문서가 직접 제시하지 않으면
 * 읽는 쪽이 매번 규정을 되짚어야 합니다.
 */
class TestPathCollector
{
    /**
     * 확장의 테스트 표면을 수집합니다.
     *
     * @param  array<string, mixed>  $record  ExtensionInventory 레코드
     * @return array<string, mixed> 테스트 인벤토리
     */
    public function collect(array $record): array
    {
        $phpunit = $this->countFiles($record, 'tests', 'php', ['Playwright']);
        $vitestRoots = $this->vitestRoots($record);
        $playwright = $this->countFiles($record, 'tests/Playwright', 'ts');
        $scenarios = $this->scenarioManifests($record);

        return [
            'phpunit' => $phpunit,
            'vitest' => $vitestRoots,
            'playwright' => $playwright,
            'scenarios' => $scenarios,
            'commands' => $this->buildCommands($record, $phpunit, $vitestRoots, $playwright),
            'testCaseBase' => $this->testCaseBase($record),
        ];
    }

    /**
     * 확장 테스트의 기저 TestCase 클래스를 찾습니다.
     *
     * 모듈/플러그인 테스트는 `Tests\TestCase` 직접 상속이 금지되고 확장 전용 기저 클래스를
     * 상속해야 하므로, 그 클래스명이 문서에 드러나야 합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return string|null 기저 클래스 파일명 (없으면 null)
     */
    private function testCaseBase(array $record): ?string
    {
        foreach (['ModuleTestCase.php', 'PluginTestCase.php', 'TemplateTestCase.php'] as $name) {
            if (is_file($record['path'].DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.$name)) {
                return 'tests/'.$name;
            }
        }

        return null;
    }

    /**
     * Vitest 대상 디렉토리를 찾습니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array{config: string|null, dirs: array<int, string>, files: int}
     */
    private function vitestRoots(array $record): array
    {
        $config = null;
        foreach (['vitest.config.ts', 'vitest.config.js'] as $name) {
            if (is_file($record['path'].DIRECTORY_SEPARATOR.$name)) {
                $config = $name;
                break;
            }
        }

        $dirs = [];
        $files = 0;

        foreach (['resources/js', 'src', '__tests__'] as $sub) {
            $dir = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sub);
            if (! is_dir($dir)) {
                continue;
            }

            $found = 0;
            foreach (File::allFiles($dir) as $file) {
                $rel = str_replace('\\', '/', $file->getPathname());
                if (! preg_match('/\.(test|spec)\.(ts|tsx)$/', $rel)) {
                    continue;
                }
                $found++;
            }

            if ($found > 0) {
                $dirs[] = $sub;
                $files += $found;
            }
        }

        return ['config' => $config, 'dirs' => $dirs, 'files' => $files];
    }

    /**
     * 시나리오 매니페스트를 수집합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array<int, string> 매니페스트 상대 경로
     */
    private function scenarioManifests(array $record): array
    {
        $dir = $record['path'].DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'scenarios';
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (File::allFiles($dir) as $file) {
            if (in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                $files[] = 'tests/scenarios/'.$file->getFilename();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * 테스트 실행 명령을 조립합니다.
     *
     * 무필터 전체 실행은 규정상 차단되므로 PHPUnit 명령에는 `--filter` 자리를 남깁니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @param  array{count: int, dirs: array<int, string>}  $phpunit  PHPUnit 집계
     * @param  array{config: string|null, dirs: array<int, string>, files: int}  $vitest  Vitest 집계
     * @param  array{count: int, dirs: array<int, string>}  $playwright  Playwright 집계
     * @return array<int, array{label: string, command: string, shell: string}> 실행 명령 목록
     */
    private function buildCommands(array $record, array $phpunit, array $vitest, array $playwright): array
    {
        $commands = [];
        $rel = $record['relPath'];

        if ($phpunit['count'] > 0) {
            $commands[] = [
                'label' => 'PHPUnit (변경 범위만)',
                'command' => "php vendor/bin/phpunit {$rel}/tests --filter='<대상클래스>'",
                'shell' => 'Bash',
            ];
        }

        if ($vitest['files'] > 0) {
            $commands[] = [
                'label' => 'Vitest (확장 디렉토리에서)',
                'command' => "cd {$rel} && powershell -Command \"npm run test:run -- <대상>\"",
                'shell' => 'PowerShell',
            ];
        }

        if ($playwright['count'] > 0) {
            // 확장은 자기 config 를 `tests/Playwright/` 아래 두므로 저장소 루트에서 부르면
            // 코어 config(testDir: tests/Playwright/specs)가 잡혀 그 spec 이 모집단 밖이 된다.
            // 결과는 실패가 아니라 "No tests found" — 돌렸다고 착각한 채 0건이 지나가고,
            // 코어 globalSetup 이 개발 사이트에 시드 화면을 설치·제거하는 부작용만 남는다.
            $commands[] = [
                'label' => 'Playwright E2E (확장 디렉토리에서)',
                'command' => "cd {$rel} && npm run test:e2e -- specs/<대상>.spec.ts",
                'shell' => 'Bash',
            ];
        }

        return $commands;
    }

    /**
     * 그 확장자에서 "테스트 파일" 로 셀 파일명인지 판정합니다.
     *
     * 계수 모집단은 문서의 "테스트 N건" 과 AGENTS `## 7. 테스트 실행` 표에 그대로 실리므로,
     * 설정 파일·픽스처를 함께 세면 공개 문서가 틀린 수치를 싣는다.
     *
     * @param  string  $extension  파일 확장자
     * @param  string  $filename  파일명
     * @return bool 테스트 파일이면 true
     */
    private static function isTestFilename(string $extension, string $filename): bool
    {
        return match ($extension) {
            'php' => (bool) preg_match('/Test\.php$/', $filename),
            'ts', 'tsx' => (bool) preg_match('/\.(test|spec)\.tsx?$/', $filename),
            default => true,
        };
    }

    /**
     * 하위 디렉토리의 파일 수와 1단계 하위 디렉토리를 집계합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @param  string  $sub  확장 루트 기준 하위 경로
     * @param  string  $extension  대상 확장자
     * @param  array<int, string>  $excludeDirs  제외할 1단계 하위 디렉토리명
     * @return array{count: int, dirs: array<int, string>, root: string|null}
     */
    private function countFiles(array $record, string $sub, string $extension, array $excludeDirs = []): array
    {
        $dir = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sub);
        if (! is_dir($dir)) {
            return ['count' => 0, 'dirs' => [], 'root' => null];
        }

        $count = 0;
        $dirs = [];

        foreach (File::allFiles($dir) as $file) {
            if ($file->getExtension() !== $extension) {
                continue;
            }

            // 테스트 파일이 아닌 것이 같은 디렉토리에 산다 — 세면 "테스트 N건" 이 실제
            // 테스트 수보다 커진다. PHP 는 기저 TestCase·헬퍼(board 실측: 157 중 2건),
            // Playwright 는 `playwright.config.ts` 와 픽스처(gdpr 실측: 5 중 3건)다.
            // 두 축이 같은 규율을 갖도록 확장자별 파일명 규칙을 한자리에서 적용한다.
            if (! self::isTestFilename($extension, $file->getFilename())) {
                continue;
            }

            $relative = str_replace('\\', '/', $file->getRelativePath());
            $first = $relative === '' ? '(root)' : explode('/', $relative)[0];

            if (in_array($first, $excludeDirs, true)) {
                continue;
            }

            $count++;
            if (! in_array($first, $dirs, true)) {
                $dirs[] = $first;
            }
        }

        sort($dirs);

        return ['count' => $count, 'dirs' => $dirs, 'root' => $sub];
    }
}
