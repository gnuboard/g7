<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 설치 마무리가 인스톨러 런타임 파일을 지우지 못했을 때 조용히 성공하지 않는지 검증한다.
 *
 * 회귀: 삭제가 실패해도 설치는 성공 처리되고 `.env` 에 완료 표시가 들어가 다시 정리할 길이 없었다. 남은 파일은
 * `.env` 대신 쓰여, 서버를 옮기며 `.env` 를 고쳐도 반영되지 않았다. 지금은 한 번 더 지워 보고, 안 되면 내용을 비워
 * 비밀값을 없애며, 결과에 표시를 남겨 설치 완료 화면이 직접 지우라고 알린다.
 *
 * @scenario-manifest tests/scenarios/installer-residual-runtime-finalize.yaml
 */
class InstallerFinalizeRuntimeCleanupTest extends TestCase
{
    use RunsInstallerSubprocess;
    use ScansPhpSourceFunctions;

    /**
     * 확정 가능한 설치 직후 상태(런타임·state·템플릿 .env)를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $base = $this->makeInstallerTempBase();
        copy(dirname(__DIR__, 3).'/.env.example', $base.'/.env.example');
        copy(dirname(__DIR__, 3).'/.env.example', $base.'/.env');
        $this->writeInstallerState(['installation_status' => 'completed', 'config' => ['app_url' => 'https://site.test', 'db_write_database' => 'g7']]);
        $this->writeInstallerRuntimeFile([
            'db' => ['write' => ['host' => 'h', 'port' => '3306', 'database' => 'g7', 'username' => 'u', 'password' => 'leftover-secret'], 'prefix' => 'g7_'],
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32))],
        ]);
    }

    /**
     * 임시 루트 제거.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeInstallerTempBase();

        parent::tearDown();
    }

    /**
     * 평소에는 런타임 파일을 지우고 결과에 문제 표시가 없다.
     *
     * @scenario case=finalize_delete_ok
     *
     * @effects finalize_removes_runtime_file
     *
     * @return void 반환값 없음
     */
    public function test_deletes_runtime_file_and_reports_no_problem(): void
    {
        $result = $this->finalize('');

        $this->assertTrue($result['success']);
        $this->assertSame('deleted', $result['runtime_cleanup']);
        $this->assertFalse($result['runtime_cleanup_failed']);
        $this->assertNull($this->readInstallerRuntimeFile());
    }

    /**
     * 지우지 못하면 한 번 더 시도하고, 그래도 안 되면 내용을 비워 비밀값을 없애고 결과에 표시한다.
     *
     * @scenario case=finalize_delete_fails
     *
     * @effects finalize_neutralizes_runtime_file_when_delete_fails
     *
     * @return void 반환값 없음
     */
    public function test_retries_then_blanks_the_file_when_delete_fails(): void
    {
        $result = $this->finalize(<<<'PHP'
            function tryDeleteInstallerRuntime(): bool {
                $GLOBALS['delete_attempts'] = ($GLOBALS['delete_attempts'] ?? 0) + 1;
                return false;
            }
            PHP);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['delete_attempts']);
        $this->assertSame('neutralized', $result['runtime_cleanup']);
        $this->assertTrue($result['runtime_cleanup_failed']);

        // 파일은 남았지만 담긴 것이 없다
        $this->assertSame([], $this->readInstallerRuntimeFile());
        $this->assertStringNotContainsString('leftover-secret', (string) file_get_contents($this->installerTempBasePath('storage/installer/runtime.php')));
        // .env 확정 자체는 끝났다
        $this->assertStringContainsString('INSTALLER_COMPLETED=true', (string) file_get_contents($this->installerTempBasePath('.env')));
    }

    /**
     * 지우지도 비우지도 못하면 결과가 실패 정리로 표시되고 설치 로그에 직접 지우라는 안내가 남는다.
     *
     * @scenario case=finalize_delete_and_blank_fail
     *
     * @effects finalize_reports_runtime_cleanup_failure
     *
     * @return void 반환값 없음
     */
    public function test_reports_failure_when_file_can_be_neither_deleted_nor_blanked(): void
    {
        $result = $this->finalize(<<<'PHP'
            function tryDeleteInstallerRuntime(): bool { return false; }
            function neutralizeInstallerRuntime(): bool { return false; }
            PHP);

        $this->assertTrue($result['success']);
        $this->assertSame('failed', $result['runtime_cleanup']);
        $this->assertTrue($result['runtime_cleanup_failed']);

        $log = (string) @file_get_contents($this->installerTempBasePath('storage/logs/installation.log'));
        $this->assertStringContainsString('runtime.php', $log);
        $this->assertStringNotContainsString('leftover-secret', $log);
    }

    /**
     * 설치 완료 화면에 띄울 경고 문구가 한국어·영어에 모두 있다.
     *
     * @scenario case=finalize_warning_text
     *
     * @effects finalize_reports_runtime_cleanup_failure
     *
     * @return void 반환값 없음
     */
    public function test_warning_text_exists_in_both_languages(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['ko', 'en'] as $locale) {
            $lang = include $root.'/public/install/lang/'.$locale.'.php';
            $this->assertArrayHasKey('runtime_cleanup_failed_warning', $lang, $locale);
            $this->assertStringContainsString('storage/installer/runtime.php', $lang['runtime_cleanup_failed_warning']);
        }

        // 터미널 설치의 두 갈래(처음 설치·이어서 확정)가 그 문구를 쓴다
        foreach (['install-flow.php', 'app.php'] as $file) {
            $this->assertStringContainsString("lang('runtime_cleanup_failed_warning')", $this->sourceWithoutComments($root.'/public/install/includes/cli/'.$file), $file);
        }
    }

    /**
     * finalize 를 실행하고 결과를 돌려준다 (설정 캐시 재생성은 기록 함수로 대체).
     *
     * @param  string  $overrides  인스톨러 함수보다 먼저 정의해 바꿔 끼울 함수 코드
     * @return array<string, mixed> finalize 결과와 삭제 시도 횟수
     */
    private function finalize(string $overrides): array
    {
        $result = $this->runInstallerScript($overrides.<<<'PHP'

            function installer_finalize_rebuild_config_cache(): bool { return true; }
            require_once $projectRoot.'/public/install/includes/env-finalize.php';
            $GLOBALS['translations'] = loadTranslations('ko');
            $r = installer_finalize_env();
            echo json_encode([
                'success' => $r['success'],
                'runtime_cleanup' => $r['runtime_cleanup'] ?? null,
                'runtime_cleanup_failed' => $r['runtime_cleanup_failed'] ?? null,
                'delete_attempts' => $GLOBALS['delete_attempts'] ?? null,
            ]);
            PHP, ['session' => []]);

        $this->assertIsArray($result['body'], $result['raw']);

        return $result['body'];
    }

    /**
     * 임시 루트 안의 경로.
     *
     * @param  string  $relative  상대 경로
     * @return string 절대 경로
     */
    private function installerTempBasePath(string $relative): string
    {
        return $this->installerTempBase.'/'.$relative;
    }
}
