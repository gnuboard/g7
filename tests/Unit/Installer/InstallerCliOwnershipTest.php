<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerCli;

/**
 * 터미널 설치의 소유권 — 웹 마법사와 같은 state.json 을 두고 서로의 설치를 덮지 않는다.
 *
 * 신규 각인은 owner_kind=cli, 웹이 소유한 설치는 거부(종료 5)하고 --takeover 로만 넘겨받으며, 웹 워커가
 * 15초 안에 심장박동했으면 넘겨받지 않는다. 넘겨받으면 웹 세션의 비밀(runtime)·삭제 확인을 폐기한다.
 * 터미널 소유 설치는 브라우저 복구(reclaim)로 가져갈 수 없다(403).
 */
class InstallerCliOwnershipTest extends TestCase
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
     * 신규 각인 — 무작위 소유 해시 + owner_kind=cli, 복구 해시 없음.
     *
     * @effects cli_claim_marks_owner_kind_cli
     *
     * @return void 반환값 없음
     */
    public function test_claim_marks_cli_owner(): void
    {
        $run = $this->runCliCode('echo "__RESULT__=".json_encode(installer_claim_cli_owner(["recovery_hash" => "old"]));');

        $this->assertSame('cli', $run['result']['owner_kind']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $run['result']['owner_hash']);
        $this->assertArrayNotHasKey('recovery_hash', $run['result']);
    }

    /**
     * 웹이 소유한 진행 중 설치는 거부한다 — 종료 5 (state 그대로).
     *
     * @effects cli_refuses_web_owned_installation
     *
     * @return void 반환값 없음
     */
    public function test_web_owned_installation_is_refused(): void
    {
        $state = ['owner_hash' => str_repeat('a', 64), 'installation_status' => 'ready', 'generation' => 3, 'current_step' => 4];
        $this->writeInstallerState($state);

        $run = $this->runCli(['--output=json', '--no-interaction', '--accept-license']);

        $this->assertSame(5, $run['exit']);
        $this->assertSame('cli_error_web_owned', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
        $this->assertSame(str_repeat('a', 64), $this->readInstallerState()['owner_hash']);
    }

    /**
     * 웹 워커가 15초 안에 심장박동했으면 --takeover 로도 넘겨받지 않는다.
     *
     * @effects cli_takeover_refused_while_web_worker_active
     *
     * @return void 반환값 없음
     */
    public function test_takeover_refused_while_web_worker_is_active(): void
    {
        $this->writeInstallerState([
            'owner_hash' => str_repeat('b', 64), 'installation_status' => 'running', 'generation' => 5,
            'active_worker_id' => 'w-1', 'last_heartbeat' => time(),
        ]);

        $run = $this->runCli(['--output=json', '--no-interaction', '--accept-license', '--takeover']);

        $this->assertSame(5, $run['exit']);
        $this->assertSame('cli_error_web_worker_active', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
    }

    /**
     * 넘겨받으면 웹 세션의 비밀(runtime.php)·삭제 확인·설치 계획 서명을 폐기하고 터미널 소유로 각인한다.
     *
     * @effects cli_takeover_discards_web_runtime_and_drop_confirm
     *
     * @return void 반환값 없음
     */
    public function test_takeover_discards_runtime_and_drop_confirm(): void
    {
        $this->writeInstallerRuntimeFile(['db' => ['write' => ['password' => 'web-session-secret']]]);

        $run = $this->runCliCode(
            '$s = installer_cli_takeover(["owner_hash" => str_repeat("c", 64), "drop_confirm" => ["x" => 1], "install_plan_sig" => "sig"]);'
            .'echo "__RESULT__=".json_encode(["state" => $s, "runtime" => is_file(INSTALLER_RUNTIME_PATH)]);'
        );

        $this->assertFalse($run['result']['runtime'], 'runtime.php 가 남으면 웹 세션 비밀이 터미널 설치에 섞인다');
        $this->assertSame('cli', $run['result']['state']['owner_kind']);
        $this->assertNotSame(str_repeat('c', 64), $run['result']['state']['owner_hash']);
        $this->assertArrayNotHasKey('drop_confirm', $run['result']['state']);
        $this->assertArrayNotHasKey('install_plan_sig', $run['result']['state']);
    }

    /**
     * 중단된 터미널 설치는 --resume 없이 새로 시작하지 않는다 (종료 5).
     *
     * @effects cli_interrupted_cli_install_requires_resume
     *
     * @return void 반환값 없음
     */
    public function test_interrupted_cli_installation_requires_resume(): void
    {
        $this->writeInstallerState(['owner_hash' => str_repeat('d', 64), 'owner_kind' => 'cli', 'installation_status' => 'failed', 'generation' => 2]);

        $run = $this->runCli(['--output=json', '--no-interaction', '--accept-license']);

        $this->assertSame(5, $run['exit']);
        $this->assertSame('cli_error_cli_interrupted', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
    }

    /**
     * 진행 상태 초기화(--reset-state)도 웹 소유 판정을 먼저 거친다 — 넘겨받기 없이 웹 설치를 지우지 않는다(종료 5, state·runtime 그대로).
     *
     * @effects cli_reset_state_respects_web_ownership
     *
     * @return void 반환값 없음
     */
    public function test_reset_state_does_not_bypass_web_ownership(): void
    {
        $this->writeInstallerState(['owner_hash' => str_repeat('a', 64), 'installation_status' => 'ready', 'generation' => 3, 'current_step' => 4]);
        $this->writeInstallerRuntimeFile(['db' => ['write' => ['password' => 'web-session-secret']]]);

        $run = $this->runCli(['--output=json', '--no-interaction', '--accept-license', '--reset-state']);

        $this->assertSame(5, $run['exit'], $run['raw']);
        $this->assertSame('cli_error_web_owned', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
        $this->assertSame(str_repeat('a', 64), $this->readInstallerState()['owner_hash'] ?? null, '웹 소유 state 가 지워지면 안 된다');
        $this->assertFileExists($this->installerTempBase.'/storage/installer/runtime.php', '웹 세션의 비밀 파일이 지워지면 안 된다');
    }

    /**
     * 웹 워커가 15초 안에 활동했으면 --reset-state --takeover 로도 지우지 않는다(종료 5).
     *
     * @effects cli_reset_state_refused_while_web_worker_active
     *
     * @return void 반환값 없음
     */
    public function test_reset_state_refused_while_web_worker_is_active(): void
    {
        $this->writeInstallerState([
            'owner_hash' => str_repeat('b', 64), 'installation_status' => 'running', 'generation' => 5,
            'active_worker_id' => 'w-1', 'last_heartbeat' => time(),
        ]);

        $run = $this->runCli(['--output=json', '--no-interaction', '--accept-license', '--reset-state', '--takeover']);

        $this->assertSame(5, $run['exit'], $run['raw']);
        $this->assertSame('cli_error_web_worker_active', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
        $this->assertSame(str_repeat('b', 64), $this->readInstallerState()['owner_hash'] ?? null);
    }

    /**
     * 터미널 자신의 중단 설치는 --reset-state 로 지우고 처음부터 시작한다(웹 「설정으로 돌아가기」 대응).
     *
     * @effects cli_reset_state_clears_cli_owned_installation
     *
     * @return void 반환값 없음
     */
    public function test_reset_state_clears_cli_owned_installation(): void
    {
        $this->writeInstallerState(['owner_hash' => str_repeat('d', 64), 'owner_kind' => 'cli', 'installation_status' => 'failed', 'generation' => 2]);

        // 입력이 모자라 [3] 에서 끝나지만(종료 2), 그 전에 이전 터미널 설치 state 는 지워진다.
        $run = $this->runCli(['--output=json', '--no-interaction', '--accept-license', '--reset-state'], [], self::PASSING_REQUIREMENTS);

        $this->assertSame(2, $run['exit'], $run['raw']);
        $this->assertNotSame('cli_error_cli_interrupted', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
        $this->assertNull($this->readInstallerState(), '이전 터미널 설치 state 가 남아 있다');
    }

    /**
     * 넘겨받은 사실은 새 설치 시작이 로그를 새로 만든 뒤에도 installation.log 에 남는다(3.6).
     *
     * @effects cli_takeover_log_line_survives_log_reset
     *
     * @return void 반환값 없음
     */
    public function test_takeover_log_line_survives_installation_log_reset(): void
    {
        $this->writeInstallerState(['owner_hash' => str_repeat('f', 64), 'installation_status' => 'ready', 'generation' => 3, 'current_step' => 4]);

        // [3]·[4]·[5] 실행은 대역 — 설치 시작은 실제처럼 로그를 지우고(install-plan.php 새 설치), 실행은 바로 끝낸다.
        $prelude = self::PASSING_REQUIREMENTS.<<<'PHP'
            function g7_cli_collect_configuration($out, array $input, string $uiLang, bool $interactive) { return ['config' => ['app_name' => 'x'], 'action' => 'skip', 'drop' => []]; }
            function g7_cli_select_extensions($out, array $input, string $uiLang, bool $interactive) { return ['selection' => ['admin_templates' => ['a'], 'user_templates' => [], 'modules' => [], 'plugins' => [], 'language_packs' => []], 'names' => []]; }
            function installer_begin_installation(array $state, array $config, array $secrets, string $action, ?string $token, array $opts = []): array { @unlink(BASE_PATH.'/storage/logs/installation.log'); return ['ok' => true, 'warning' => null]; }
            function g7_cli_execute($out, array $plan, ?string $dropToken, ?array $webUser): int { return 0; }
            PHP;

        $run = $this->runCli(['--output=json', '--no-interaction', '--accept-license', '--takeover', '--locale=en'], [], $prelude);

        $this->assertSame(0, $run['exit'], $run['raw']);
        $log = (string) @file_get_contents($this->installerTempBase.'/storage/logs/installation.log');
        $this->assertStringContainsString('The terminal took over the web installation', $log, '넘겨받기 기록이 로그 초기화로 사라졌다');
    }

    /**
     * 터미널 소유 설치는 브라우저 복구 토큰으로 가져갈 수 없다 — reclaim 403.
     *
     * @effects reclaim_refused_for_cli_owned_installation
     *
     * @return void 반환값 없음
     */
    public function test_browser_reclaim_is_refused_for_cli_owned_installation(): void
    {
        $this->writeInstallerState(['owner_hash' => str_repeat('e', 64), 'owner_kind' => 'cli', 'installation_status' => 'running', 'generation' => 4]);

        $result = $this->runInstallerEndpoint('state-management.php', [
            'get' => ['action' => 'reclaim'],
            'session_id' => 'browser',
            'session' => ['csrf_token' => 'browser-csrf'],
            'headers' => ['HTTP_X_CSRF_TOKEN' => 'browser-csrf'],
            'json_body' => ['recovery_token' => 'guess'],
        ]);

        $this->assertSame(403, $result['status'], $result['raw']);
        $this->assertSame(str_repeat('e', 64), $this->readInstallerState()['owner_hash']);
    }
}
