<?php

namespace Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 번들 확장의 시험 기반 클래스(번들 모듈마다 둔 `ModuleTestCase`, 번들 플러그인마다 둔 `PluginTestCase` — 예: `modules/_bundled/sirsoft-board/tests/ModuleTestCase.php`, `plugins/_bundled/sirsoft-sso/tests/PluginTestCase.php`)를 확장 시험 오토로드 없이 읽어도
 * 잡을 수 없는 컴파일 오류(Fatal)가 나지 않는지 검증한다 (7.2.0).
 *
 * 개발 환경의 일부 PHP 프로세스(PHPUnit 밖)는 Composer 오토로더만 둔 채 이 파일들을 읽는다. 그 프로세스에는
 * `tests/bootstrap.php` 가 등록하는 확장 `Tests` 네임스페이스가 없어, 기반 클래스가 같은 네임스페이스의 트레이트를
 * `use` 하면 「Trait not found」 Fatal 이 나고 개발 로그에 쌓였다(게시판 `RestoresBoardSettingsStorage`, 하루 160~252건).
 * 클래스가 없다는 Error 는 그 프로세스가 잡을 수 있어 로그에 남지 않으므로, 여기서는 Fatal 만 막는다.
 *
 * 같은 프로세스(Laravel VS Code 확장의 시험 탐색 스크립트 `storage/framework/lsp-*.php`)는 앱을 먼저 부팅한 뒤
 * phpunit.xml 의 시험 파일을 하나씩 읽는다. 부팅이 활성 디렉토리 사본의 심볼(게시판 `FormatsBoardDate` 등)을 이미
 * 선언해 둔 상태라, 시험 파일이 파일 범위에서 `_bundled` 원본 src 를 무조건 `require_once` 하면 경로가 달라 중복 제거가
 * 통하지 않고 「Cannot declare trait … already in use」 Fatal 로 죽어 개발 로그에 쌓였다(하루 81건). 그런 시험 파일은
 * 심볼이 아직 없을 때만 원본을 읽어야 한다.
 */
class BundledTestBaseStandaloneLoadTest extends TestCase
{
    /**
     * 번들 확장의 시험 기반 클래스 파일 목록을 돌려준다.
     *
     * @return array<string, array{0: string}> 파일 경로(저장소 상대) 묶음
     */
    public static function baseFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge(
            glob($root.'/modules/_bundled/*/tests/ModuleTestCase.php') ?: [],
            glob($root.'/plugins/_bundled/*/tests/PluginTestCase.php') ?: [],
        );

        $cases = [];
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
            $cases[$relative] = [$relative];
        }

        return $cases;
    }

    /**
     * Composer 오토로더만 둔 별도 PHP 프로세스에서 기반 클래스 파일을 읽어도 Fatal 이 나지 않는다.
     *
     * @scenario base=bundled_test_base_case
     *
     * @effects standalone_include_has_no_fatal
     *
     * @param  string  $relative  저장소 상대 경로
     * @return void 반환값 없음
     */
    #[DataProvider('baseFiles')]
    public function test_base_file_loads_without_fatal_outside_phpunit_bootstrap(string $relative): void
    {
        $script = 'require "vendor/autoload.php"; try { require $argv[1]; echo "LOADED"; } catch (\Throwable $e) { echo "CAUGHT ".get_class($e); }';
        $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script, $relative], base_path());
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertStringNotContainsString('Fatal error', $output, "{$relative} 를 오토로드 등록 없이 읽으면 Fatal 이 난다: ".trim($output));
    }

    /**
     * 번들 확장의 시험 디렉터리 목록을 돌려준다.
     *
     * @return array<string, array{0: string}> 시험 디렉터리(저장소 상대) 묶음
     */
    public static function bundledTestDirectories(): array
    {
        $root = dirname(__DIR__, 3);
        $cases = [];

        foreach (array_merge(glob($root.'/modules/_bundled/*/tests', GLOB_ONLYDIR) ?: [], glob($root.'/plugins/_bundled/*/tests', GLOB_ONLYDIR) ?: []) as $dir) {
            $relative = str_replace('\\', '/', substr($dir, strlen($root) + 1));
            $cases[$relative] = [$relative];
        }

        return $cases;
    }

    /**
     * Composer 오토로더만 둔 별도 PHP 프로세스에서 번들 확장의 시험 파일을 모두 읽어도 Fatal 이 나지 않는다.
     *
     * 시험 클래스가 확장 `Tests` 네임스페이스의 트레이트(`Concerns\…`)를 쓰면, 그 네임스페이스가 등록되지 않은 프로세스에서는
     * 클래스 선언 자체가 잡을 수 없는 「Trait not found」 Fatal 이 된다. 기반 클래스가 그 네임스페이스를 풀어 줘야 한다.
     *
     * @scenario base=bundled_test_file_set
     *
     * @effects test_files_load_without_fatal_outside_phpunit_bootstrap
     *
     * @param  string  $relative  시험 디렉터리(저장소 상대)
     * @return void 반환값 없음
     */
    #[DataProvider('bundledTestDirectories')]
    public function test_bundled_test_files_load_without_fatal_outside_phpunit_bootstrap(string $relative): void
    {
        $script = 'require "vendor/autoload.php"; $list = []; '
            .'foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1], FilesystemIterator::SKIP_DOTS)) as $f) { if (str_ends_with($f->getFilename(), "Test.php")) { $list[] = $f->getPathname(); } } '
            .'sort($list); foreach ($list as $p) { try { require_once $p; } catch (\Throwable $e) { } } echo "LOADED ".count($list);';
        $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', $script, $relative], base_path());
        $process->setTimeout(300);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertStringNotContainsString('Fatal error', $output, "{$relative} 의 시험 파일을 오토로드 등록 없이 읽으면 Fatal 이 난다: ".trim($output));
        $this->assertStringContainsString('LOADED', $output, "{$relative} 의 시험 파일을 끝까지 읽지 못했다: ".trim($output));
    }

    /**
     * 파일 범위에서 자기 확장의 src 파일을 `require_once` 하는 번들 확장 시험 파일과 그 src 경로 목록을 돌려준다.
     *
     * @return array<string, array{0: string, 1: list<string>}> 시험 파일(저장소 상대)과 그 파일이 읽는 src 파일(저장소 상대) 묶음
     */
    public static function srcRequiringTestFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $cases = [];

        foreach (['modules', 'plugins'] as $kind) {
            foreach (glob($root.'/'.$kind.'/_bundled/*/tests', GLOB_ONLYDIR) ?: [] as $testsDir) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    if (! str_ends_with($file->getFilename(), 'Test.php')) {
                        continue;
                    }

                    $source = (string) file_get_contents($file->getPathname());
                    if (! preg_match_all("/^\s*require_once __DIR__\.'([^']*\/src\/[^']+\.php)';/m", $source, $matches)) {
                        continue;
                    }

                    $dir = dirname($file->getPathname());
                    $srcFiles = [];
                    foreach ($matches[1] as $suffix) {
                        $resolved = realpath($dir.$suffix);
                        if ($resolved !== false) {
                            $srcFiles[] = str_replace('\\', '/', substr($resolved, strlen($root) + 1));
                        }
                    }

                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $cases[$relative] = [$relative, $srcFiles];
                }
            }
        }

        ksort($cases);

        return $cases;
    }

    /**
     * 활성 디렉토리 사본이 같은 src 심볼을 먼저 선언한 프로세스에서 시험 파일을 읽어도 재선언 Fatal 이 나지 않는다.
     *
     * [case:backend-73] 편집기 시험 탐색(앱 부팅 뒤 시험 파일 적재)이 남기던 개발 로그 Fatal 을 잠근다.
     *
     * src 파일을 임시 경로로 복사해 먼저 읽으면, 같은 FQCN 을 다른 경로에서 선언해 둔 앱 부팅 직후 상태가 된다.
     *
     * @scenario base=bundled_test_with_src_preload
     *
     * @effects preloaded_src_symbol_is_not_redeclared
     *
     * @param  string  $relative  시험 파일(저장소 상대)
     * @param  list<string>  $srcFiles  시험 파일이 파일 범위에서 읽는 src 파일(저장소 상대)
     * @return void 반환값 없음
     */
    #[DataProvider('srcRequiringTestFiles')]
    public function test_src_requiring_test_file_loads_after_other_copy_declared_the_symbol(string $relative, array $srcFiles): void
    {
        $this->assertNotSame([], $srcFiles, "{$relative} 가 읽는 src 파일을 찾지 못했다");

        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-src-preload-'.bin2hex(random_bytes(6));
        mkdir($tempDir, 0777, true);

        try {
            $copies = [];
            foreach ($srcFiles as $index => $srcFile) {
                $copy = $tempDir.DIRECTORY_SEPARATOR.$index.'-'.basename($srcFile);
                copy(base_path($srcFile), $copy);
                $copies[] = $copy;
            }

            $script = 'require "vendor/autoload.php"; foreach (json_decode($argv[2], true) as $copy) { require $copy; } '
                .'try { require $argv[1]; echo "LOADED"; } catch (\Throwable $e) { echo "CAUGHT ".get_class($e).": ".$e->getMessage(); }';
            $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', $script, $relative, json_encode($copies)], base_path());
            $process->run();

            $output = $process->getOutput().$process->getErrorOutput();
            $this->assertStringNotContainsString('Fatal error', $output, "{$relative} 를 다른 경로 사본이 심볼을 선언한 뒤 읽으면 Fatal 이 난다: ".trim($output));
            $this->assertStringContainsString('LOADED', $output, "{$relative} 를 끝까지 읽지 못했다: ".trim($output));
        } finally {
            foreach (glob($tempDir.DIRECTORY_SEPARATOR.'*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($tempDir);
        }
    }
}
