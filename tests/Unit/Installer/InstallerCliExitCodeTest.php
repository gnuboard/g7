<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerCli;

/**
 * 터미널 설치 종료 코드 0~7 — 자동화 도구가 원인별로 분기하는 계약.
 *
 * 0 완료·이미 설치됨(--skip-if-installed) / 1 설치 작업 실패 / 2 입력·검증 오류 / 3 이미 설치됨 /
 * 4 요구사항 미충족 / 5 충돌(동시 실행·소유) / 6 사용자 중단 / 7 root 실행 거부.
 * 환경에 묶인 단계(요구사항 판정·설치 작업·root 판정)는 같은 이름의 함수를 먼저 정의해 주입한다.
 */
class InstallerCliExitCodeTest extends TestCase
{
    use RunsInstallerCli;

    /** 요구사항 통과 주입 (표 출력은 생략) */
    private const PASSING_REQUIREMENTS = <<<'PHP'
        function installer_collect_requirements(): array { return ['blocking' => false, 'all_required_passed' => true]; }
        function g7_cli_print_requirements($out, $req, $webUser): void {}
        PHP;

    /**
     * 임시 BASE_PATH 를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeInstallerTempBase();
        $this->putBaseFile('.env', "APP_KEY=\n");
    }

    /**
     * 임시 BASE_PATH 를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeInstallerTempBase();
        parent::tearDown();
    }

    /**
     * 0 — 도움말, 이미 설치된 사이트의 --skip-if-installed.
     *
     * @effects cli_exit_0_help_and_skip_if_installed
     *
     * @return void 반환값 없음
     */
    public function test_exit_0(): void
    {
        $this->assertSame(0, $this->runCli(['--help'])['exit']);

        $this->markInstalled();
        $run = $this->runCli(['--output=json', '--skip-if-installed']);
        $this->assertSame(0, $run['exit']);
        $this->assertTrue($this->eventsNamed($run['events'], 'summary')[0]['already_installed'] ?? false);
    }

    /**
     * 1 — 설치 작업이 실패로 끝남 (재개 안내와 함께).
     *
     * @effects cli_exit_1_installation_failed
     *
     * @return void 반환값 없음
     */
    public function test_exit_1_when_tasks_fail(): void
    {
        $this->writeInstallerState(['installation_status' => 'ready', 'generation' => 1, 'current_step' => 5]);

        $run = $this->runCliCode(
            '$code = g7_cli_execute(new G7CliOutput(true), ["ok" => true, "warning" => null], null, null);'
            .'echo "__RESULT__=".json_encode($code);',
            'function runInstallationTasks(): void { $s = getInstallationState(); $s["installation_status"] = "failed"; saveInstallationState($s); }'
        );

        $this->assertSame(1, $run['result']);
    }

    /**
     * 2 — 약관 미동의(비대화형), 알 수 없는 옵션.
     *
     * @effects cli_exit_2_invalid_input
     *
     * @return void 반환값 없음
     */
    public function test_exit_2(): void
    {
        $run = $this->runCli(['--output=json', '--no-interaction'], [], self::PASSING_REQUIREMENTS);
        $this->assertSame(2, $run['exit']);
        $this->assertSame('cli_error_license_not_accepted', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);

        $this->assertSame(2, $this->runCli(['--bogus'])['exit']);
    }

    /**
     * 3 — 이미 설치된 사이트.
     *
     * @effects cli_exit_3_already_installed
     *
     * @return void 반환값 없음
     */
    public function test_exit_3_when_installed(): void
    {
        $this->markInstalled();

        $run = $this->runCli(['--output=json', '--no-interaction', '--accept-license']);

        $this->assertSame(3, $run['exit']);
        $this->assertSame('cli_error_already_installed', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
    }

    /**
     * 4 — 요구사항 미충족 (주입).
     *
     * @effects cli_exit_4_requirements_not_met
     *
     * @return void 반환값 없음
     */
    public function test_exit_4_when_requirements_block(): void
    {
        $run = $this->runCli(['--output=json', '--no-interaction', '--accept-license'], [], <<<'PHP'
            function installer_collect_requirements(): array { return ['blocking' => true, 'all_required_passed' => false]; }
            function g7_cli_print_requirements($out, $req, $webUser): void {}
            PHP);

        $this->assertSame(4, $run['exit']);
        $this->assertNull($this->readInstallerState(), '요구사항에서 멈추면 소유권을 주장하지 않는다');
    }

    /**
     * 5 — 다른 터미널 설치가 실행 중(cli.lock).
     *
     * @effects cli_exit_5_another_cli_running
     *
     * @return void 반환값 없음
     */
    public function test_exit_5_when_cli_lock_is_held(): void
    {
        $handle = fopen($this->putBaseFile('storage/installer/cli.lock', ''), 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            $run = $this->runCli(['--output=json', '--no-interaction', '--accept-license'], [], self::PASSING_REQUIREMENTS);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->assertSame(5, $run['exit']);
        $this->assertSame('cli_error_another_cli_running', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
    }

    /**
     * 6 — 중단 신호를 받으면 「중단」으로 남기고 종료 6 (이어 설치 가능).
     *
     * @effects cli_exit_6_aborted_marks_state
     *
     * @return void 반환값 없음
     */
    public function test_exit_6_when_abort_signal_received(): void
    {
        $this->writeInstallerState(['installation_status' => 'ready', 'generation' => 1, 'current_step' => 5]);

        $run = $this->runCliCode(
            '$code = g7_cli_execute(new G7CliOutput(true), ["ok" => true, "warning" => null], null, null);'
            .'echo "__RESULT__=".json_encode(["code" => $code, "status" => getInstallationState()["installation_status"] ?? null]);',
            'function runInstallationTasks(): void { $GLOBALS["g7_cli_abort"] = true; $s = getInstallationState(); $s["installation_status"] = "failed"; saveInstallationState($s); }'
        );

        $this->assertSame(['code' => 6, 'status' => 'aborted'], $run['result']);
    }

    /**
     * 7 — root 로 --web-user 없이 실행 (파일을 하나도 만들기 전에 거부). Windows 는 root 판정이 없다.
     *
     * @effects cli_exit_7_root_refused_without_web_user
     *
     * @return void 반환값 없음
     */
    public function test_exit_7_when_root_without_web_user(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows 에는 root 판정이 없다 — WSL 실측(L1)으로 덮는다');
        }

        $run = $this->runCli(['--output=json', '--no-interaction', '--accept-license'], [], 'function g7_cli_is_root(): bool { return true; }');

        $this->assertSame(7, $run['exit']);
        $this->assertFileDoesNotExist($this->installerTempBase.'/storage/installer/cli.lock');
    }

    /**
     * 2 — 대화형인데 숨김 입력을 쓸 수 없으면 DB 비밀번호도 묻지 않고 거부한다(빈 비밀번호로 진행하지 않는다).
     * 관리자 비밀번호와 같은 규칙이고, 안내는 그 비밀값의 환경변수·파일 옵션을 가리킨다.
     *
     * @effects cli_exit_2_hidden_input_unavailable_for_any_secret
     *
     * @return void 반환값 없음
     */
    public function test_exit_2_when_hidden_input_unavailable_for_db_password(): void
    {
        $prelude = self::PASSING_REQUIREMENTS.<<<'PHP'
            function g7_cli_stdin_is_tty(): bool { return true; }
            function g7_cli_hidden_input_supported(): bool { return false; }
            function g7_cli_ask(string $label, ?string $default = null): string { return ($default ?? '') !== '' ? $default : 'x'; }
            function g7_cli_confirm(string $question, bool $default = false): bool { return true; }
            function g7_cli_choice(string $question, array $options, string $default): string { return $default; }
            function g7_cli_page_text(string $text, int $pageLines = 40): void {}
            PHP;

        $run = $this->runCli(['--locale=en'], [], $prelude);

        $this->assertSame(2, $run['exit'], $run['raw']);
        $this->assertStringContainsString('G7_INSTALL_DB_PASSWORD', $run['raw'], 'DB 비밀번호의 환경변수를 안내한다');
        $this->assertStringContainsString('--db-password-file', $run['raw']);
        $this->assertFileDoesNotExist($this->installerTempBase.'/storage/installer-state.json', '아무 상태도 만들지 않는다');
    }

    /**
     * 2 — 이어서 설치(--resume)도 숨김 입력을 쓸 수 없으면 비밀값을 빈 값으로 채우지 않고 거부한다(A9).
     *
     * @effects cli_exit_2_hidden_input_unavailable_on_resume
     *
     * @return void 반환값 없음
     */
    public function test_exit_2_when_hidden_input_unavailable_on_resume(): void
    {
        // 비밀 파일(runtime.php)이 없는 중단 설치 — DB 비밀번호를 다시 받아야 한다.
        $this->writeInstallerState([
            'owner_hash' => str_repeat('d', 64), 'owner_kind' => 'cli', 'installation_status' => 'failed', 'generation' => 2,
            'current_step' => 5, 'selected_extensions' => ['admin_templates' => ['sirsoft-admin_basic']],
        ]);
        $prelude = <<<'PHP'
            function g7_cli_stdin_is_tty(): bool { return true; }
            function g7_cli_hidden_input_supported(): bool { return false; }
            function installer_begin_installation(array $state, array $config, array $secrets, string $action, ?string $token, array $opts = []): array { echo "__BEGIN_CALLED__\n"; return ['ok' => false, 'error_code' => 'x', 'message' => 'x']; }
            PHP;

        $run = $this->runCli(['--locale=en', '--resume'], [], $prelude);

        $this->assertSame(2, $run['exit'], $run['raw']);
        $this->assertStringContainsString('G7_INSTALL_DB_PASSWORD', $run['raw']);
        $this->assertStringContainsString('--db-password-file', $run['raw']);
        $this->assertStringNotContainsString('__BEGIN_CALLED__', $run['raw'], '빈 비밀번호로 설치를 다시 시작했다');
    }
}
