<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * 설치 마무리가 설정 캐시를 무효화하는지 실측한다 (PO 결정 13차 D7/E2).
 *
 * 마지막 태스크 `config_cache` 는 `.env` 머지 **이전**에 돈다. 그 시점의 config 는
 * `InstallerRuntimeServiceProvider` 가 `storage/installer/runtime.php` 에서 주입한 값이고,
 * Laravel 의 `config:cache` 는 전체 부팅을 거친 config 를 그대로 `var_export` 하므로
 * **DB 비밀번호가 평문으로 캐시 파일에 복제**된다. 덤으로 그 캐시에는
 * `app.installer_completed=false` 가 박혀 모듈·플러그인 라우트가 등록되지 않는다.
 *
 * 그런데 그 캐시를 갱신하는 지점이 어디에도 없었다 — finalize 는 runtime.php 만 지웠다.
 * 결과는 오류도 경고도 없이 「설치는 성공했는데 확장 API 가 전부 404 이고 비밀번호가
 * 캐시 파일에 남아 있는」 상태다.
 *
 * PO 결정은 「굽지 않는다」가 아니라 「끝날 때 지운다」다 — 태스크 목록은 그대로 두고
 * finalize 가 마지막에 그 파일을 지운다. 그리고 그 삭제는 **설치를 중단시키지 않는다**.
 *
 * 함수 단위로는 확인되지 않는 계약이다 — 삭제가 `.env` 쓰기 **뒤**에 있어야 하고(앞에 두면
 * 그 뒤의 부팅이 다시 캐시를 만든다), 삭제 실패가 흐름을 끊지 않아야 한다. 그래서 이 검사는
 * finalize 흐름을 임시 설치 루트에서 실제로 태운다.
 *
 * 커버 축: config_cache_state present·absent·undeletable / finalize_outcome cache_cleared·cache_kept_with_log
 *
 * @effects installer_finalize_clears_config_cache_after_env_merge, installer_finalize_survives_config_cache_delete_failure
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerFinalizeConfigCacheTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('exec')) {
            $this->markTestSkipped('exec 가 없어 하위 프로세스로 finalize 흐름을 태울 수 없다');
        }

        $this->root = sys_get_temp_dir().'/g7-installer-configcache-'.bin2hex(random_bytes(4));

        foreach (['/storage/logs', '/storage/installer', '/storage/app', '/bootstrap/cache'] as $dir) {
            mkdir($this->root.$dir, 0777, true);
        }

        file_put_contents($this->root.'/.env.example', implode("\n", [
            'APP_NAME=Laravel',
            'APP_URL=http://localhost',
            'DB_WRITE_HOST=127.0.0.1',
            'DB_WRITE_PORT=3306',
            'DB_WRITE_DATABASE=g7',
            'DB_WRITE_USERNAME=root',
            'DB_WRITE_PASSWORD=',
            'DB_PREFIX=g7_',
        ])."\n");

        file_put_contents($this->root.'/storage/installer-state.json', json_encode([
            'installation_status' => 'completed',
            'completed_tasks' => ['complete_flag', 'config_cache'],
            'current_step' => 6,
            'g7_locale' => 'ko',
            'config' => [
                'app_name' => 'T',
                'app_url' => 'http://t.test',
                'db_write_host' => '127.0.0.1',
                'db_write_database' => 'g7',
                'db_write_username' => 'root',
            ],
        ]));

        // finalize 는 runtime.php 가 없으면 「이미 끝났다」로 보고 즉시 돌아간다.
        file_put_contents(
            $this->root.'/storage/installer/runtime.php',
            "<?php\n\nreturn ".var_export(['db' => ['write_password' => 'secret-pw']], true).";\n"
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }

    /**
     * 임시 설치 루트에서 finalize 를 태운다.
     *
     * @return string 표준출력(표준오류 포함)
     */
    private function runFinalize(): string
    {
        $command = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg(__DIR__.'/stubs/finalize_env_probe.php')
            .' '.escapeshellarg($this->root)
            .' 2>&1';

        $output = [];
        exec($command, $output);

        return implode("\n", $output);
    }

    private function cachePath(): string
    {
        return $this->root.'/bootstrap/cache/config.php';
    }

    private function installationLog(): string
    {
        $path = $this->root.'/storage/logs/installation.log';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    public function test_finalize_deletes_the_config_cache_baked_before_the_env_merge(): void
    {
        // `config:cache` 가 남긴 형태 — runtime.php 의 평문 비밀번호와 미완료 표식이 함께 박혀 있다.
        file_put_contents($this->cachePath(), "<?php\n\nreturn ".var_export([
            'app' => ['installer_completed' => false],
            'database' => ['connections' => ['mysql' => ['write' => ['password' => 'secret-pw']]]],
        ], true).";\n");

        $output = $this->runFinalize();

        $this->assertStringNotContainsString('Fatal error', $output, 'finalize 가 치명 종료했다: '.$output);
        $this->assertFileExists($this->root.'/.env', 'finalize 가 .env 를 쓰지 못했다: '.$output);

        $this->assertFileDoesNotExist(
            $this->cachePath(),
            "설정 캐시가 그대로 남았다.\n"
            .'설치 이전 config(installer_completed=false + 평문 DB 비밀번호)가 박힌 캐시가 잔존하면 '
            ."확장 라우트가 등록되지 않고 자격증명이 파일로 복제된 채 운영에 들어간다.\n"
            .'출력: '.$output,
        );
    }

    /**
     * 삭제가 실패해도 설치는 중단되지 않는다 — 기록만 남는다.
     *
     * 같은 이름의 **디렉토리**를 두어 `unlink()` 를 실패시킨다. 파일 권한은 OS·실행 사용자에
     * 따라 root 가 그대로 지워 버려 재현되지 않는다.
     */
    public function test_finalize_completes_even_when_the_config_cache_cannot_be_deleted(): void
    {
        mkdir($this->cachePath(), 0777, true);

        $output = $this->runFinalize();

        $this->assertStringNotContainsString('Fatal error', $output, 'finalize 가 치명 종료했다: '.$output);

        // 삭제 실패가 흐름을 끊지 않았다는 증거 — 뒤따르는 정리가 그대로 수행됐다.
        $this->assertFileExists($this->root.'/.env', '.env 머지가 삭제 실패에 막혔다: '.$output);
        $this->assertFileDoesNotExist(
            $this->root.'/storage/installer/runtime.php',
            'runtime.php 정리가 설정 캐시 삭제 실패에 막혔다 — 삭제 실패는 설치를 중단시키지 않는다',
        );

        $this->assertStringContainsString(
            'bootstrap/cache/config.php 삭제 FAILED',
            $this->installationLog(),
            '삭제 실패가 진행 기록에 남지 않았다 — 운영자가 수동 정리 대상을 알 방법이 사라진다',
        );
    }

    public function test_finalize_is_a_noop_when_no_config_cache_exists(): void
    {
        $this->assertFileDoesNotExist($this->cachePath());

        $output = $this->runFinalize();

        $this->assertStringNotContainsString('Fatal error', $output, 'finalize 가 치명 종료했다: '.$output);
        $this->assertFileExists($this->root.'/.env', 'finalize 가 .env 를 쓰지 못했다: '.$output);
        $this->assertStringNotContainsString(
            'bootstrap/cache/config.php 삭제 FAILED',
            $this->installationLog(),
            '캐시가 없는데 삭제 실패를 기록했다',
        );
    }
}
