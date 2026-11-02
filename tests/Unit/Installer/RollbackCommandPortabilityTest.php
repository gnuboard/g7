<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 설치 실패 시 자동 롤백 명령이 운영체제와 무관하게 실행 가능한지 검증한다.
 *
 * 회귀: `executeMigrateRollback()` 이 `cd /d <경로> && php artisan migrate:rollback` 을 만들었다.
 * `cd /d` 는 Windows cmd 전용이라 리눅스·맥 셸에서는 명령 전체가 실패해, db_migrate·db_seed 가
 * 실패한 뒤의 자동 롤백이 한 번도 돌지 않고 「수동 정리 필요」로 끝났다. 인스톨러의 다른 artisan
 * 실행 경로처럼 chdir(BASE_PATH) 뒤 실행한다.
 *
 * 롤백은 설치 태스크와 같은 실행기(`installerRunArtisan()`, includes/artisan-executor.php)를 지난다 —
 * 명령 줄 조립은 `installerBuildArtisanCommandLine()`, 하위 프로세스 스폰은
 * `installerRunArtisanSubprocess()` 가 소유한다. 그래서 이 검사는 롤백 함수가 그 실행기를 부르는지와,
 * 실행기의 명령 줄·작업 디렉터리 규칙을 함께 잰다(전용 명령 조립 함수는 실행기에 흡수돼 없다).
 */
class RollbackCommandPortabilityTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 주석을 지운 소스에서 함수 정의 본문을 잘라 돌려준다 (다음 최상위 `}` 줄까지 — 들여쓴 함수는 4칸 `}` 줄까지).
     *
     * @param  string  $relativePath  저장소 루트 기준 파일 경로
     * @param  string  $signature  찾을 정의 머리 (예: `function executeMigrateRollback`)
     * @param  string  $terminator  본문 끝 표식
     * @return string 함수 본문 (정의를 찾지 못하면 빈 문자열)
     */
    private function definitionSlice(string $relativePath, string $signature, string $terminator): string
    {
        $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/'.$relativePath);
        $start = strpos($source, $signature);
        if ($start === false) {
            return '';
        }
        $body = substr($source, $start);
        $end = strpos($body, $terminator);

        return $end === false ? $body : substr($body, 0, $end);
    }

    /**
     * 롤백 명령에 Windows 전용 접두가 없다.
     *
     * @effects rollback_command_has_no_windows_only_prefix
     *
     * @return void 반환값 없음
     */
    public function test_rollback_command_has_no_windows_only_prefix(): void
    {
        $rollback = $this->definitionSlice('public/install/api/rollback-functions.php', 'function executeMigrateRollback', "\n}\n");
        $this->assertNotSame('', $rollback, 'executeMigrateRollback 정의를 찾지 못했다 — 이 검사의 기준점이 사라졌다');
        $this->assertStringContainsString("installerRunArtisan('migrate:rollback', \$parameters)", $rollback, '롤백이 설치 태스크와 같은 실행기를 지나지 않는다');
        $this->assertStringContainsString("'--force' => true", $rollback);
        $this->assertStringNotContainsString('cd /d', $rollback);

        // 실행기의 명령 줄 조립을 실제로 돌려 본다 — 순수 함수라 인스톨러 부팅 없이 부를 수 있다.
        $root = str_replace('\\', '/', dirname(__DIR__, 3));
        $code = "require '{$root}/public/install/includes/artisan-executor.php';"
            ." echo installerBuildArtisanCommandLine('migrate:rollback', ['--force' => true, '--step' => '2']);";
        $commandLine = (string) shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));

        $this->assertStringNotContainsString('cd /d', $commandLine);
        $this->assertStringStartsWith('migrate:rollback --force --step=', $commandLine);

        // 스폰은 설정된 PHP 바이너리로 `artisan <명령 줄>` 을 직접 부른다 — 디렉터리 이동을 셸에 맡기지 않는다.
        $spawn = $this->definitionSlice('public/install/includes/artisan-executor.php', 'function installerRunArtisanSubprocess', "\n    }\n");
        $this->assertNotSame('', $spawn, 'installerRunArtisanSubprocess 정의를 찾지 못했다 — 이 검사의 기준점이 사라졌다');
        $this->assertStringContainsString('$phpBin = escapeshellarg(getPhpBinary());', $spawn);
        $this->assertStringContainsString('"{$phpBin} -d memory_limit=512M artisan {$artisanCommand}', $spawn);
        $this->assertDoesNotMatchRegularExpression('/[\'"]cd\s/', $spawn);
    }

    /**
     * 롤백 실행 전에 작업 디렉터리를 프로젝트 루트로 맞춘다.
     *
     * @effects rollback_runs_from_project_root
     *
     * @return void 반환값 없음
     */
    public function test_rollback_changes_directory_to_base_path_before_exec(): void
    {
        $spawn = $this->definitionSlice('public/install/includes/artisan-executor.php', 'function installerRunArtisanSubprocess', "\n    }\n");
        $this->assertNotSame('', $spawn, 'installerRunArtisanSubprocess 정의를 찾지 못했다 — 이 검사의 기준점이 사라졌다');

        $chdir = strpos($spawn, 'chdir(BASE_PATH);');
        $exec = strpos($spawn, 'exec($fullCommand');
        $this->assertNotFalse($chdir, '하위 프로세스 실행기가 작업 디렉터리를 프로젝트 루트로 맞추지 않는다 — 상대 경로 artisan 이 엉뚱한 곳에서 찾힌다');
        $this->assertNotFalse($exec, '하위 프로세스 실행 지점을 찾지 못했다 — 이 검사의 기준점이 사라졌다');
        $this->assertLessThan($exec, $chdir, 'chdir(BASE_PATH) 가 exec 보다 뒤에 있다');
    }

    /**
     * 인스톨러 전체에 Windows cmd 전용 `cd /d` 가 남아 있지 않다.
     *
     * @effects installer_has_no_windows_only_cd
     *
     * @return void 반환값 없음
     */
    public function test_installer_sources_have_no_cd_slash_d(): void
    {
        $root = dirname(__DIR__, 3).'/public/install';
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // 주석 속 언급은 실행되지 않는다 — 주석을 지운 소스(줄 수 보존)에서 줄 번호를 센다.
            foreach (explode("\n", $this->sourceWithoutComments($file->getPathname())) as $no => $line) {
                if (preg_match("/['\"]cd \\/d /", $line)) {
                    $hits[] = $file->getFilename().':'.($no + 1);
                }
            }
        }

        $this->assertSame([], $hits);
    }
}
