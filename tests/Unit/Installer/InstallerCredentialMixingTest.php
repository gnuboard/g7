<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 교차 세션 자격증명 혼합·기존 DB 삭제 회귀 테스트 (엔드포인트 단위, 서브프로세스).
 *
 * 신고 재현 경로: 피해자가 설치를 진행하는 동안 공격자가 자기 브라우저 세션으로
 *  - install-process 에 자기 관리자 비밀번호를 보내 runtime.php 의 admin 을 덮어쓰고(피해자 DB 비번은 보존)
 *  - existing_db_action=drop_tables 를 보내 피해자 DB 테이블 삭제를 예약했다.
 * 이제 비소유 세션의 모든 상태변경은 409 이며 runtime·state 는 한 바이트도 바뀌지 않는다.
 */
class InstallerCredentialMixingTest extends TestCase
{
    use RunsInstallerSubprocess;

    /** 피해자 소유 토큰 */
    private const VICTIM_TOKEN = 'vvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvv';

    /** @var array<string, mixed> 피해자 runtime */
    private array $victimRuntime = [
        'db' => ['write' => ['host' => 'db', 'port' => '3306', 'database' => 'victim', 'username' => 'victim', 'password' => 'VICTIM-DB-PW'], 'prefix' => 'g7_'],
        'app' => ['key' => 'base64:dmljdGltLWFwcC1rZXktdmljdGltLWFwcC1rZXktMDE='],
        'admin' => ['password' => 'VICTIM-ADMIN-PW'],
        'plan_key' => 'victim-plan-key',
    ];

    /** @var array<string, mixed> 피해자 state */
    private array $victimState;

    /**
     * 피해자가 Step 4 까지 진행한 설치를 임시 루트에 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $base = $this->makeInstallerTempBase();
        file_put_contents($base.'/.env', "APP_NAME=G7\n");

        $this->victimState = [
            'current_step' => 5,
            'installation_status' => 'ready',
            'owner_hash' => hash('sha256', self::VICTIM_TOKEN),
            'recovery_hash' => hash('sha256', str_repeat('9', 32)),
            'generation' => 2,
            'existing_db_action' => 'skip',
            'completed_tasks' => [],
            'config' => [
                'db_write_host' => 'db', 'db_write_port' => '3306', 'db_write_database' => 'victim',
                'db_write_username' => 'victim', 'db_prefix' => 'g7_',
                'app_name' => 'G7', 'app_url' => 'http://localhost',
                'admin_name' => 'Victim', 'admin_email' => 'victim@example.com', 'admin_language' => 'ko',
            ],
            'selected_extensions' => ['admin_templates' => ['sirsoft-admin_basic'], 'user_templates' => [], 'modules' => [], 'plugins' => [], 'language_packs' => []],
        ];
        $this->writeInstallerState($this->victimState);
        $this->writeInstallerRuntimeFile($this->victimRuntime);
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
     * 공격자 세션 spec — 소유 토큰 없음, 자기 CSRF 토큰은 정상 보유(같은 사이트에서 페이지를 연 공격자).
     *
     * @param  array<string, mixed>  $extra  추가 spec
     * @return array<string, mixed> 하네스 spec
     */
    private function attacker(array $extra = []): array
    {
        return array_replace_recursive([
            'session_id' => 'attacker',
            'session' => [
                'csrf_token' => 'attacker-csrf',
                'install_config' => ['admin_email' => 'attacker@example.com', 'admin_password' => 'ATTACKER-ADMIN-PW'],
            ],
            'headers' => ['HTTP_X_CSRF_TOKEN' => 'attacker-csrf'],
        ], $extra);
    }

    /**
     * 공격 후 피해자 runtime·state 가 그대로인지 확인한다.
     *
     * @return void 반환값 없음
     */
    private function assertVictimUntouched(): void
    {
        $runtime = $this->readInstallerRuntimeFile();
        $state = $this->readInstallerState();

        $this->assertSame('VICTIM-ADMIN-PW', $runtime['admin']['password'], 'runtime admin 비번이 공격자 값으로 바뀌면 안 된다');
        $this->assertSame('VICTIM-DB-PW', $runtime['db']['write']['password']);
        $this->assertSame(hash('sha256', self::VICTIM_TOKEN), $state['owner_hash']);
        $this->assertSame('skip', $state['existing_db_action']);
        $this->assertSame('ready', $state['installation_status']);
        $this->assertSame(2, $state['generation']);
        $this->assertSame('victim@example.com', $state['config']['admin_email']);
    }

    /**
     * 공격자 install-process(관리자 비번 주입 + drop_tables) → 409, 피해자 상태 불변.
     *
     * @effects attacker_cannot_mix_credentials_or_schedule_drop, non_owner_state_change_rejected_with_409
     *
     * @return void 반환값 없음
     */
    public function test_attacker_install_process_is_rejected(): void
    {
        $result = $this->runInstallerEndpoint('install-process.php', $this->attacker([
            'post' => ['installation_mode' => 'sse', 'existing_db_action' => 'drop_tables'],
        ]));

        $this->assertSame(409, $result['status'], $result['raw']);
        $this->assertSame('ownership_conflict', $result['body']['error_code'] ?? null);
        $this->assertVictimUntouched();
    }

    /**
     * 공격자 save-extensions / reset / abort → 모두 409, 피해자 상태 불변.
     *
     * @param  string  $endpoint  엔드포인트
     * @param  array<string, string>  $get  쿼리
     *
     * @effects non_owner_state_change_rejected_with_409
     *
     * @return void 반환값 없음
     */
    #[DataProvider('attackerEndpointProvider')]
    public function test_attacker_state_changes_are_rejected(string $endpoint, array $get): void
    {
        $result = $this->runInstallerEndpoint($endpoint, $this->attacker(['get' => $get]));

        $this->assertSame(409, $result['status'], $result['raw']);
        $this->assertVictimUntouched();
    }

    /**
     * 공격 대상 상태변경 엔드포인트.
     *
     * @return array<string, array{0: string, 1: array<string, string>}> 엔드포인트·쿼리 쌍
     */
    public static function attackerEndpointProvider(): array
    {
        return [
            'save-extensions' => ['save-extensions.php', []],
            'reset' => ['state-management.php', ['action' => 'reset']],
            'abort' => ['state-management.php', ['action' => 'abort']],
        ];
    }

    /**
     * 설치 완료 전 finalize-env 는 409 — .env 에 INSTALLER_COMPLETED 가 써지지 않는다(설치 봉쇄 차단).
     *
     * @effects finalize_blocked_before_completion
     *
     * @return void 반환값 없음
     */
    public function test_finalize_before_completion_is_rejected(): void
    {
        $result = $this->runInstallerEndpoint('finalize-env.php', $this->attacker());

        $this->assertSame(409, $result['status'], $result['raw']);
        $this->assertStringNotContainsString('INSTALLER_COMPLETED', (string) file_get_contents($this->installerTempBase.'/.env'));
        $this->assertNotNull($this->readInstallerRuntimeFile());
    }

    /**
     * 서명된 계획이 없는 state 로 워커를 부르면 어떤 작업도 하지 않고 aborted.
     *
     * @effects worker_refuses_unsigned_plan
     *
     * @return void 반환값 없음
     */
    public function test_worker_refuses_unsigned_plan(): void
    {
        $state = $this->victimState;
        $state['installation_status'] = 'running';
        $state['existing_db_action'] = 'drop_tables';
        $this->writeInstallerState($state);

        $result = $this->runInstallerEndpoint('install-worker.php', ['method' => 'GET', 'session' => []]);

        $this->assertStringContainsString('event: aborted', $result['raw']);
        $this->assertStringContainsString('plan_unverified', $result['raw']);
        $after = $this->readInstallerState();
        $this->assertArrayNotHasKey('active_worker_id', $after, '워커 락조차 잡지 않는다');
        $this->assertSame([], $after['completed_tasks']);
    }

    /**
     * 소유 세션은 통과 — runtime admin 은 소유자 값, 계획은 서명되고, 확인 없는 drop 은 skip 으로 강등.
     *
     * @effects owner_install_signs_plan_and_keeps_owner_credentials, unconfirmed_drop_downgraded_to_skip
     *
     * @return void 반환값 없음
     */
    public function test_owner_install_process_passes_and_signs_plan(): void
    {
        $result = $this->runInstallerEndpoint('install-process.php', [
            'session_id' => 'victim',
            'session' => [
                'install_owner' => self::VICTIM_TOKEN,
                'csrf_token' => 'victim-csrf',
                'install_config' => array_merge($this->victimState['config'], ['db_write_password' => 'VICTIM-DB-PW', 'admin_password' => 'VICTIM-ADMIN-PW']),
            ],
            'headers' => ['HTTP_X_CSRF_TOKEN' => 'victim-csrf'],
            'post' => ['installation_mode' => 'sse', 'existing_db_action' => 'drop_tables'],
        ]);

        $this->assertSame(200, $result['status'], $result['raw']);
        $this->assertTrue($result['body']['success'] ?? false, $result['raw']);
        $this->assertTrue($result['body']['drop_confirm_rejected'], '확인 토큰 없는 drop 은 거부');
        $this->assertSame('skip', $result['body']['existing_db_action']);

        $state = $this->readInstallerState();
        $runtime = $this->readInstallerRuntimeFile();
        $this->assertSame('running', $state['installation_status']);
        $this->assertSame('skip', $state['existing_db_action']);
        $this->assertSame(3, $state['generation']);
        $this->assertSame('VICTIM-ADMIN-PW', $runtime['admin']['password']);
        $this->assertSame('victim-plan-key', $runtime['plan_key'], '재시도 사이 서명 키 보존');
        $this->assertSame(
            'ok',
            $this->verifyPlanInSubprocess(),
            '워커가 검증할 수 있는 서명이 남아야 한다'
        );
    }

    /**
     * 서브프로세스에서 현재 state·runtime 으로 계획 서명을 검증한다.
     *
     * @return string installer_plan_verify 결과
     */
    private function verifyPlanInSubprocess(): string
    {
        $result = $this->runInstallerScript('echo installer_plan_verify(getInstallationState(), readInstallerRuntime());');

        return trim(strtok($result['raw'], "\n"));
    }
}
