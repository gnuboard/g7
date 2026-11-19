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
     * 워커 생존 판정은 웹 잠금 획득과 같은 판정(installerActiveWorkerIsAlive)이다 — 워커가 선언한 유효 시간
     * (`worker_stale_after`)을 존중하고, 미래 시각 심장박동은 stale 로 본다.
     *
     * 태스크 실행 중인 웹 워커는 심장박동을 갱신하지 못한다(600초 예산). 15초 고정 판정이면 1분 전 심장박동의
     * 살아 있는 워커를 죽은 것으로 보고 --takeover 가 그 설치를 가로챈다.
     *
     * @effects cli_worker_liveness_follows_shared_judgment
     *
     * @return void 반환값 없음
     */
    public function test_worker_liveness_follows_the_shared_judgment(): void
    {
        $this->writeInstallerState([
            'owner_hash' => str_repeat('b', 64), 'installation_status' => 'running', 'generation' => 5,
            'active_worker_id' => 'w-1', 'last_heartbeat' => time() - 60, 'worker_stale_after' => 600,
        ]);

        $run = $this->runCli(['--output=json', '--no-interaction', '--accept-license', '--takeover']);

        $this->assertSame(5, $run['exit'], '선언한 유효 시간 안의 웹 워커를 넘겨받았다');
        $this->assertSame('cli_error_web_worker_active', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);

        $judged = $this->runCliCode(
            '$now = time();'
            .'echo "__RESULT__=".json_encode(['
            .'installer_worker_recently_active(["active_worker_id" => "w", "last_heartbeat" => $now - 60]),'
            .'installer_worker_recently_active(["active_worker_id" => "w", "last_heartbeat" => $now + 3600]),'
            .'installer_worker_recently_active(["active_worker_id" => null, "last_heartbeat" => $now]),'
            .'installer_worker_recently_active(["active_worker_id" => "w", "last_heartbeat" => $now]),'
            .']);'
        );

        $this->assertSame([false, false, false, true], $judged['result'], '선언 없는 60초 전·미래 시각·점유자 없음은 stale, 방금 심장박동은 활동 중');
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
     * 오래 걸린 단계 뒤에도 터미널 설치는 자기 잠금을 잃지 않는다.
     *
     * 설치 상태를 읽는 단일 지점은 「선언 예산을 넘긴 점유자」를 죽은 워커로 보고 실패로 승격한다. 그 판정은
     * 자기 자신을 제외하는데, 터미널 설치는 잠금을 잡고도 자기 워커 ID 를 알리지 않아 자기 상태 읽기가 자기
     * 잠금을 지웠다 — 심장박동 없이 15초를 넘긴 단계(템플릿 활성화 등)가 끝나는 순간 「다른 설치 작업이 잠금을
     * 넘겨받아 이 설치를 멈춥니다」로 끝나고, 그 단계의 완료 표시가 남지 않았다. 알린 워커 ID 는 하위 명령
     * 실행 직전 점유 시간 연장에도 쓰인다(다른 창의 상태 조회가 살아 있는 터미널 설치를 끊지 않게).
     *
     * @effects cli_worker_lock_survives_long_blocking_task
     *
     * @return void 반환값 없음
     */
    public function test_cli_worker_keeps_its_lock_after_a_long_blocking_task(): void
    {
        $this->writeInstallerState(['installation_status' => 'running', 'generation' => 1, 'current_step' => 5, 'owner_kind' => 'cli', 'owner_hash' => str_repeat('c', 64)]);

        // 단계 하나가 심장박동 없이 오래 막힌 것을 시각으로 재현한다 — 잠금을 잡은 지 2분, 마지막 심장박동 1분 전.
        $prelude = <<<'PHP'
            function runInstallationTasks(): void {
                $s = getInstallationState();
                $GLOBALS['__declared'] = installerCurrentWorkerId();
                $s['worker_started_at'] = time() - 120;
                $s['last_heartbeat'] = time() - 60;
                saveInstallationState($s);
                getProgressEmitter()->emit('log', ['message' => 'after a long blocking task']);
                $after = getInstallationState();
                $GLOBALS['__lock_after'] = $after['active_worker_id'] ?? null;
                $GLOBALS['__status_after'] = $after['installation_status'] ?? null;
                $after['installation_status'] = 'failed';
                saveInstallationState($after);
            }
            PHP;

        $run = $this->runCliCode(
            '$code = g7_cli_execute(new G7CliOutput(true), ["ok" => true, "warning" => null], null, null);'
            .'echo "__RESULT__=".json_encode(["code" => $code, "declared" => $GLOBALS["__declared"] ?? null, "lock_after" => $GLOBALS["__lock_after"] ?? null, "status_after" => $GLOBALS["__status_after"] ?? null]);',
            $prelude
        );

        $this->assertIsArray($run['result'], '잠금을 잃고 종료 5 로 끝났다: '.$run['raw']);
        $this->assertSame(1, $run['result']['code'], $run['raw']);
        $this->assertNotNull($run['result']['declared'], '터미널 설치가 자기 워커 ID 를 알리지 않았다');
        $this->assertSame($run['result']['declared'], $run['result']['lock_after'], '자기 상태 읽기가 자기 잠금을 지웠다');
        $this->assertSame('running', $run['result']['status_after'], '살아 있는 터미널 설치가 죽은 워커로 승격됐다');
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
