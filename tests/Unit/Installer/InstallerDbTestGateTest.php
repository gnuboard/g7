<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * Step 3 "DB 테스트 통과" 게이트 회귀 테스트.
 *
 * 결함: DB 테스트 실패를 `$_SESSION['db_write_tested'] = false` 로 저장하고, 소비자는 `isset()` 으로만
 * 검사해 실패 기록도 통과로 봤다. 또 검사한 연결설정과 제출한 설정을 대조하지 않았다.
 */
class InstallerDbTestGateTest extends TestCase
{
    use RunsInstallerSubprocess;

    /**
     * 임시 루트 준비.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeInstallerTempBase();
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
     * 테스트 실패 경로가 `false` 를 저장하지 않는다 (소스 계약).
     *
     * @effects db_test_failure_is_not_a_pass
     *
     * @return void 반환값 없음
     */
    public function test_db_test_failure_paths_do_not_store_false(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/check-configuration.php');

        $this->assertStringNotContainsString("\$_SESSION['db_write_tested'] = false", $source);
        $this->assertStringNotContainsString("\$_SESSION['db_read_tested'] = false", $source);
        $this->assertSame(2, substr_count($source, 'installer_forget_db_test($isReadDb)'), 'PDOException·Exception 두 실패 경로 모두 기록을 지운다');
    }

    /**
     * Step 3 소비자가 isset 으로 검사하지 않는다 (소스 계약).
     *
     * @effects db_test_failure_is_not_a_pass
     *
     * @return void 반환값 없음
     */
    public function test_step3_does_not_use_isset_for_db_test_flag(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/request-handler.php');

        $this->assertStringNotContainsString("isset(\$_SESSION['db_write_tested'])", $source);
        $this->assertStringNotContainsString("isset(\$_SESSION['db_read_tested'])", $source);
    }

    /**
     * 실패 기록(false)·미검사는 not_tested, 통과+지문 일치만 ok, 통과+설정 변경은 changed.
     *
     * @effects db_test_failure_is_not_a_pass, step3_rejects_config_changed_after_db_test
     *
     * @return void 반환값 없음
     */
    public function test_db_test_status_is_strict(): void
    {
        $result = $this->runInstallerScript(<<<'PHP'
            $conn = ['host' => 'h', 'port' => '3306', 'database' => 'd', 'username' => 'u', 'password' => 'p', 'prefix' => 'g7_'];
            $out = [];
            $_SESSION = ['db_write_tested' => false];
            $out['false_flag'] = installer_db_test_status('write', $conn);
            $_SESSION = [];
            $out['absent'] = installer_db_test_status('write', $conn);
            $_SESSION = ['db_write_tested' => true];
            $out['true_without_fp'] = installer_db_test_status('write', $conn);
            $_SESSION = ['db_write_tested' => true, 'install_owner' => str_repeat('a', 64)];
            $_SESSION['db_write_fp'] = installer_db_fingerprint($conn, $_SESSION['install_owner']);
            $out['match'] = installer_db_test_status('write', $conn);
            $out['changed'] = installer_db_test_status('write', array_merge($conn, ['password' => 'other']));
            echo json_encode($out);
        PHP);

        $out = json_decode(strtok($result['raw'], "\n"), true);
        $this->assertSame('not_tested', $out['false_flag'], '실패 기록(false)은 통과가 아니다');
        $this->assertSame('not_tested', $out['absent']);
        $this->assertSame('not_tested', $out['true_without_fp']);
        $this->assertSame('ok', $out['match']);
        $this->assertSame('changed', $out['changed']);
    }

    /**
     * 인스톨러 한국어 번역 문구를 돌려준다 (화면에 나가는 문구와 대조용).
     *
     * @param  string  $key  번역 키
     * @return string 한국어 문구
     */
    private function ko(string $key): string
    {
        $translations = require dirname(__DIR__, 3).'/public/install/lang/ko.php';

        return $translations[$key];
    }

    /**
     * Step 3 제출 시나리오 — 실제 handleStep3Post 를 서브프로세스에서 호출한다.
     *
     * @param  array<string, mixed>  $session  세션 값 (fp 는 스크립트가 계산)
     * @param  string  $testedPassword  DB 테스트 때의 비밀번호 (지문 계산용, 빈 값이면 지문 없음)
     * @param  array<string, mixed>  $post  추가/덮어쓸 POST 값
     * @return array<string, mixed> 폼 오류·종료 시 세션·원문 출력
     */
    private function submitStep3(array $session, string $testedPassword, array $post = []): array
    {
        $spec = [
            'session' => $session,
            'post' => array_merge([
                'csrf_token' => 'tok',
                'db_write_host' => 'db.internal', 'db_write_port' => '3306', 'db_write_database' => 'g7',
                'db_write_username' => 'g7_user', 'db_write_password' => 'pw-1', 'db_prefix' => 'g7_',
                'app_name' => 'G7', 'app_url' => 'http://localhost',
                'admin_name' => 'Victim', 'admin_email' => 'victim@example.com', 'admin_language' => 'ko',
                'admin_password' => 'victim-pass-1', 'admin_password_confirm' => 'victim-pass-1',
                'php_binary' => 'php',
            ], $post),
        ];

        $tested = var_export($testedPassword, true);
        $result = $this->runInstallerScript(<<<PHP
            require_once \$projectRoot.'/public/install/includes/request-handler.php';
            if ({$tested} !== '') {
                \$_SESSION['install_owner'] = \$_SESSION['install_owner'] ?? str_repeat('e', 64);
                \$_SESSION['db_write_fp'] = installer_db_fingerprint([
                    'host' => 'db.internal', 'port' => '3306', 'database' => 'g7',
                    'username' => 'g7_user', 'password' => {$tested}, 'prefix' => 'g7_',
                ], \$_SESSION['install_owner']);
            }
            register_shutdown_function(static function () use (&\$errors) { echo '__ERRORS__='.json_encode(\$errors ?? null)."\\n"; });
            \$formData = [];
            \$errors = [];
            handleStep3Post('ko', \$formData, \$errors);
        PHP, $spec);

        preg_match('/__ERRORS__=(.*)$/m', $result['raw'], $m);

        return [
            'errors' => json_decode($m[1] ?? 'null', true) ?? [],
            'session' => $result['session'],
            'raw' => $result['raw'],
        ];
    }

    /**
     * DB 테스트 실패 기록(false)이 남은 세션은 Step 3 를 통과하지 못한다 (교차 세션 탈취의 첫 관문).
     *
     * @effects db_test_failure_is_not_a_pass
     *
     * @return void 반환값 없음
     */
    public function test_step3_rejects_failed_db_test_record(): void
    {
        $result = $this->submitStep3(['csrf_token' => 'tok', 'db_write_tested' => false], '');

        $this->assertSame($this->ko('error_db_not_tested'), $result['errors']['db_test'] ?? null, $result['raw']);
        $this->assertNull($this->readInstallerState(), 'state 가 기록되면 안 된다');
    }

    /**
     * Step 3 서버 검증 오류는 번역된 문구로 화면에 나간다 — 번역 키가 그대로 노출되면 운영자가
     * 무엇을 고쳐야 하는지 알 수 없다 (브라우저 실측에서 `error_env_value_invalid_url` 원문 노출 발견).
     *
     * @effects step3_server_errors_are_translated
     *
     * @return void 반환값 없음
     */
    public function test_step3_errors_are_translated_for_the_screen(): void
    {
        $result = $this->runInstallerScript(<<<'PHP'
            require_once $projectRoot.'/public/install/includes/request-handler.php';
            $_SESSION['g7_locale'] = 'ko';
            $_POST = ['csrf_token' => 'tok', 'db_write_host' => 'h', 'db_write_database' => 'd', 'db_write_username' => 'u',
                'app_name' => 'G7', 'app_url' => 'not-a-url', 'admin_name' => 'A', 'admin_email' => 'a@example.com',
                'admin_language' => 'ko', 'admin_password' => 'pass-12345', 'admin_password_confirm' => 'pass-12345'];
            $formData = [];
            $errors = [];
            handleStep3Post('ko', $formData, $errors);
            echo json_encode($errors, JSON_UNESCAPED_UNICODE);
        PHP, ['session' => ['csrf_token' => 'tok']]);

        $errors = json_decode(strtok($result['raw'], "\n"), true);
        $this->assertArrayHasKey('app_url', $errors, $result['raw']);
        $this->assertStringNotContainsString('error_env_value_invalid_url', $errors['app_url'], '번역 키 원문이 노출되면 안 된다');
        $this->assertStringContainsString('not-a-url', $errors['app_url'], '치환 자리가 채워진 번역 문구');
    }

    /**
     * 테스트 후 비밀번호를 바꿔 제출하면 거부된다.
     *
     * @effects step3_rejects_config_changed_after_db_test
     *
     * @return void 반환값 없음
     */
    public function test_step3_rejects_config_changed_after_test(): void
    {
        $result = $this->submitStep3(['csrf_token' => 'tok', 'db_write_tested' => true], 'pw-1', ['db_write_password' => 'pw-CHANGED']);

        $this->assertSame($this->ko('error_db_config_changed'), $result['errors']['db_test'] ?? null, $result['raw']);
    }

    /**
     * CSRF 토큰 없이 제출하면 거부된다.
     *
     * @effects csrf_mismatch_rejected_with_403
     *
     * @return void 반환값 없음
     */
    public function test_step3_rejects_missing_csrf(): void
    {
        $result = $this->submitStep3(['csrf_token' => 'tok', 'db_write_tested' => true], 'pw-1', ['csrf_token' => '']);

        $this->assertSame($this->ko('error_csrf_invalid'), $result['errors']['csrf'] ?? null, $result['raw']);
    }

    /**
     * 통과 + 지문 일치면 소유권을 주장하고 비밀 없이 state 에 저장한다.
     *
     * @effects ownership_first_claim_owns_installation, step3_claim_discards_stale_runtime, ownership_state_stores_hashes_only
     *
     * @return void 반환값 없음
     */
    public function test_step3_success_claims_ownership_and_stores_no_secret(): void
    {
        $this->writeInstallerRuntimeFile(['admin' => ['password' => 'stale-previous-attempt']]);

        $result = $this->submitStep3(['csrf_token' => 'tok', 'db_write_tested' => true], 'pw-1');

        $state = $this->readInstallerState();
        $this->assertSame([], $result['errors'], $result['raw']);
        $this->assertSame(hash('sha256', $result['session']['install_owner']), $state['owner_hash']);
        $this->assertNotEmpty($state['recovery_hash']);
        $this->assertSame(1, $state['generation']);
        $this->assertSame('ready', $state['installation_status']);
        $this->assertArrayNotHasKey('db_write_password', $state['config']);
        $this->assertArrayNotHasKey('admin_password', $state['config']);
        $this->assertArrayNotHasKey('csrf_token', $state['config']);
        $this->assertNull($this->readInstallerRuntimeFile(), '소유자 없던 설치의 잔존 runtime.php 는 폐기된다');
        $this->assertSame('pw-1', $result['session']['install_config']['db_write_password']);
    }

    /**
     * 다른 세션이 이미 소유한 설치는 덮어쓰지 않는다.
     *
     * @effects step3_rejects_when_other_session_owns
     *
     * @return void 반환값 없음
     */
    public function test_step3_rejects_when_other_session_owns_installation(): void
    {
        $this->writeInstallerState([
            'installation_status' => 'ready',
            'owner_hash' => hash('sha256', str_repeat('f', 64)),
            'config' => ['admin_email' => 'victim@example.com'],
            'generation' => 4,
        ]);

        $result = $this->submitStep3(['csrf_token' => 'tok', 'db_write_tested' => true], 'pw-1', ['admin_email' => 'attacker@example.com']);

        $this->assertSame($this->ko('error_ownership_conflict'), $result['errors']['ownership'] ?? null, $result['raw']);
        $state = $this->readInstallerState();
        $this->assertSame('victim@example.com', $state['config']['admin_email']);
        $this->assertSame(4, $state['generation']);
    }
}
