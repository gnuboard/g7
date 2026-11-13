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
}
