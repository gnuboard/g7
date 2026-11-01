<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 설치 실패 시 자동 롤백 명령이 운영체제와 무관하게 실행 가능한지 검증한다.
 *
 * 회귀: `executeMigrateRollback()` 이 `cd /d <경로> && php artisan migrate:rollback` 을 만들었다.
 * `cd /d` 는 Windows cmd 전용이라 리눅스·맥 셸에서는 명령 전체가 실패해, db_migrate·db_seed 가
 * 실패한 뒤의 자동 롤백이 한 번도 돌지 않고 「수동 정리 필요」로 끝났다. 인스톨러의 다른 artisan
 * 실행 경로처럼 chdir(BASE_PATH) 뒤 실행한다.
 */
class RollbackCommandPortabilityTest extends TestCase
{
    /**
     * 롤백 명령에 Windows 전용 접두가 없다.
     *
     * @effects rollback_command_has_no_windows_only_prefix
     *
     * @return void 반환값 없음
     */
    public function test_rollback_command_has_no_windows_only_prefix(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 3));
        $code = "require '{$root}/public/install/api/rollback-functions.php'; echo buildMigrateRollbackCommand('/usr/bin/php8.3');";

        $output = (string) shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));

        $this->assertStringNotContainsString('cd /d', $output);
        $this->assertStringContainsString('artisan migrate:rollback --force', $output);
        $this->assertStringContainsString('php8.3', $output);
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
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/rollback-functions.php');
        $body = substr($source, (int) strpos($source, 'function executeMigrateRollback'));
        $body = substr($body, 0, (int) strpos($body, "\n}\n"));

        $this->assertMatchesRegularExpression('/chdir\(BASE_PATH\);\s*\n\s*exec\(\$command/', $body);
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
            foreach (file($file->getPathname()) ?: [] as $no => $line) {
                if (preg_match("/['\"]cd \\/d /", $line)) {
                    $hits[] = $file->getFilename().':'.($no + 1);
                }
            }
        }

        $this->assertSame([], $hits);
    }
}
