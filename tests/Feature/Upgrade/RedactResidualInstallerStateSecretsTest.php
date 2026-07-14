<?php

namespace Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_0_4\Migrations\RedactResidualInstallerStateSecrets;
use Tests\TestCase;

/**
 * 7.0.4 잔존 installer-state.json 비밀 정리 회귀 가드 (이슈 #465).
 *
 * axis 전수:
 *   1. state.json 부재 → no-op
 *   2. 완료 증거(.env INSTALLER_COMPLETED=true) + state 존재 → 파일 삭제
 *   3. 완료 증거(g7_installed 플래그) + state 존재 → 파일 삭제
 *   4. 완료 증거 있음 + 삭제 불가 → redact 폴백 (파일 보존, 비밀만 제거)
 *   5. 완료 증거 없음 → 파일 보존 + redact 만 (설치 진행 중 파괴 금지)
 *   6. JSON 파싱 실패 → 파일 원본 그대로 보존
 *   7. 멱등 — 2회 호출해도 동일 결과
 */
class RedactResidualInstallerStateSecretsTest extends TestCase
{
    private string $statePath;

    private string $envPath;

    private string $installedFlagPath;

    private bool $originalEnvExisted = false;

    private ?string $originalEnvContent = null;

    private bool $originalStateExisted = false;

    private ?string $originalStateContent = null;

    private bool $originalFlagExisted = false;

    private ?string $originalFlagContent = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statePath = base_path('storage/installer-state.json');
        $this->envPath = base_path('.env');
        $this->installedFlagPath = base_path('storage/app/g7_installed');

        // 실 운영 아티팩트 백업 — 본 테스트가 삭제/변조하므로 tearDown 에서 정확히 복원
        if (is_file($this->envPath)) {
            $this->originalEnvExisted = true;
            $this->originalEnvContent = file_get_contents($this->envPath);
        }
        if (is_file($this->statePath)) {
            $this->originalStateExisted = true;
            $this->originalStateContent = file_get_contents($this->statePath);
        }
        if (is_file($this->installedFlagPath)) {
            $this->originalFlagExisted = true;
            $this->originalFlagContent = file_get_contents($this->installedFlagPath);
        }

        // DataMigration 은 autoload 대상이 아님 — 동적 require
        require_once base_path('upgrades/data/7.0.4/migrations/01_RedactResidualInstallerStateSecrets.php');

        $this->clearArtifacts();
    }

    protected function tearDown(): void
    {
        $this->clearArtifacts();

        if ($this->originalEnvExisted && $this->originalEnvContent !== null) {
            file_put_contents($this->envPath, $this->originalEnvContent);
        }
        if ($this->originalStateExisted && $this->originalStateContent !== null) {
            file_put_contents($this->statePath, $this->originalStateContent);
        }
        if ($this->originalFlagExisted && $this->originalFlagContent !== null) {
            file_put_contents($this->installedFlagPath, $this->originalFlagContent);
        }

        parent::tearDown();
    }

    /**
     * 각 axis 가 완료 증거를 명시 제어할 수 있도록 세 아티팩트를 모두 제거한다.
     */
    private function clearArtifacts(): void
    {
        if (is_file($this->statePath)) {
            // axis 4 가 파일을 0444 로 만들 수 있다 — 단언 실패로 조기 종료된 경우에도
            // 정리가 가능하도록 권한을 먼저 되돌린다.
            @chmod($this->statePath, 0644);
            @unlink($this->statePath);
        }
        if (is_file($this->installedFlagPath)) {
            @unlink($this->installedFlagPath);
        }
        if (is_file($this->envPath)) {
            @unlink($this->envPath);
        }
    }

    private function context(): UpgradeContext
    {
        return new UpgradeContext('7.0.3', '7.0.4', '7.0.4');
    }

    private function migration(): RedactResidualInstallerStateSecrets
    {
        return new RedactResidualInstallerStateSecrets;
    }

    /**
     * 평문 비밀이 들어 있는 레거시 state.json (7.0.3 이하 인스톨러가 남긴 형태).
     *
     * @return array<string, mixed>
     */
    private function legacyState(): array
    {
        return [
            'installation_status' => 'completed',
            'current_step' => 5,
            'completed_tasks' => ['db_migrate', 'db_seed', 'complete_flag'],
            'config' => [
                'app_name' => 'G7 Site',
                'db_write_host' => '127.0.0.1',
                'db_write_database' => 'g7',
                'db_write_username' => 'root',
                'db_write_password' => 'db-plain-pw',
                'db_read_password' => 'read-plain-pw',
                'admin_email' => 'admin@example.com',
                'admin_password' => 'admin-plain-pw',
                'admin_password_confirm' => 'admin-plain-pw',
            ],
        ];
    }

    private function writeState(array $state): void
    {
        file_put_contents($this->statePath, json_encode($state, JSON_PRETTY_PRINT));
    }

    private function markInstalledViaEnv(): void
    {
        file_put_contents($this->envPath, "APP_ENV=production\nINSTALLER_COMPLETED=true\n");
    }

    private function markInstalledViaFlag(): void
    {
        $dir = dirname($this->installedFlagPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->installedFlagPath, date('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        return json_decode((string) file_get_contents($this->statePath), true);
    }

    private function assertNoPlaintextSecrets(array $config): void
    {
        foreach (['db_write_password', 'db_read_password', 'admin_password', 'admin_password_confirm'] as $key) {
            $this->assertArrayNotHasKey($key, $config, "{$key} 가 state 에 남으면 안 됨");
        }
    }

    // -----------------------------------------------------------------------
    // axis 1: state.json 부재 → no-op
    // -----------------------------------------------------------------------
    public function test_no_op_when_state_file_absent(): void
    {
        $this->assertFileDoesNotExist($this->statePath);

        $this->migration()->run($this->context());

        $this->assertFileDoesNotExist($this->statePath);
    }

    // -----------------------------------------------------------------------
    // axis 2: 완료 증거(.env) + state 존재 → 파일 삭제
    // -----------------------------------------------------------------------
    public function test_deletes_state_file_when_env_marks_installation_complete(): void
    {
        $this->writeState($this->legacyState());
        $this->markInstalledViaEnv();

        $this->migration()->run($this->context());

        $this->assertFileDoesNotExist($this->statePath, '설치 완료 환경의 잔존 state.json 은 삭제되어야 함');
    }

    // -----------------------------------------------------------------------
    // axis 3: 완료 증거(g7_installed) + state 존재 → 파일 삭제
    // -----------------------------------------------------------------------
    public function test_deletes_state_file_when_installed_flag_present(): void
    {
        $this->writeState($this->legacyState());
        $this->markInstalledViaFlag();
        // .env 는 없음 — g7_installed 단독 신호로도 완료 판정되어야 함

        $this->migration()->run($this->context());

        $this->assertFileDoesNotExist($this->statePath);
    }

    // -----------------------------------------------------------------------
    // axis 4: 완료 증거 있음 + 삭제 불가 → 예외 없이 계속 진행 (업그레이드 중단 금지)
    //
    // 파일을 읽기 전용으로 만들어 unlink 를 실제로 실패시킨다. 이 상태에서는
    // redact 재저장(file_put_contents) 도 함께 실패하므로 평문 제거는 불가능하다 —
    // 파일시스템 레벨 제약이라 코드로 우회할 수 없다. 본 axis 가 고정하는 계약은
    // "업그레이드가 중단되지 않고, 파일이 손상되지 않으며, 운영자가 수동 삭제할 수
    // 있도록 원본이 보존된다" 이다 (경고 로그는 runInternal 이 기록).
    // -----------------------------------------------------------------------
    public function test_does_not_abort_upgrade_when_state_file_cannot_be_deleted(): void
    {
        $original = $this->legacyState();
        $this->writeState($original);
        $this->markInstalledViaEnv();

        $before = file_get_contents($this->statePath);

        // 이 환경에서 읽기 전용 파일의 삭제가 실제로 차단되는지 실측 (POSIX 는 삭제 권한이
        // 부모 디렉토리 소유라 차단되지 않을 수 있다 — 그 경우 본 axis 는 재현 불가).
        if (! $this->unlinkIsBlocked()) {
            $this->markTestSkipped(
                '이 플랫폼에서는 읽기 전용 파일의 unlink 가 차단되지 않아 삭제 실패 경로를 '
                .'결정적으로 재현할 수 없음 (POSIX: 삭제 권한은 부모 디렉토리 소유)'
            );
        }

        @chmod($this->statePath, 0444);

        // 예외를 던지지 않아야 한다 (업그레이드 전체 중단 금지)
        $this->migration()->run($this->context());

        $this->assertFileExists($this->statePath, '삭제 실패 시 파일은 보존되어야 함');
        $this->assertSame($before, file_get_contents($this->statePath), '삭제 실패 시 파일이 손상되면 안 됨');

        @chmod($this->statePath, 0644);
    }

    /**
     * 현재 플랫폼에서 읽기 전용 파일의 unlink 가 차단되는지 실측한다.
     */
    private function unlinkIsBlocked(): bool
    {
        $probe = base_path('storage/installer-state-unlink-probe.tmp');
        file_put_contents($probe, 'x');
        @chmod($probe, 0444);

        $blocked = @unlink($probe) === false;

        @chmod($probe, 0644);
        if (is_file($probe)) {
            @unlink($probe);
        }

        return $blocked;
    }

    // -----------------------------------------------------------------------
    // axis 5: 완료 증거 없음 → 파일 보존 + redact 만
    // -----------------------------------------------------------------------
    public function test_redacts_but_preserves_file_when_installation_not_complete(): void
    {
        $state = $this->legacyState();
        $state['installation_status'] = 'running'; // 설치 진행 중
        $this->writeState($state);
        // 완료 증거 없음 (.env / g7_installed 모두 부재)

        $this->migration()->run($this->context());

        $this->assertFileExists($this->statePath, '진행 중인 설치의 state.json 을 삭제하면 안 됨');

        $after = $this->readState();
        $this->assertNoPlaintextSecrets($after['config']);

        // 비-비밀 필드는 보존되어야 설치를 이어갈 수 있다
        $this->assertSame('G7 Site', $after['config']['app_name']);
        $this->assertSame('127.0.0.1', $after['config']['db_write_host']);
        $this->assertSame('root', $after['config']['db_write_username']);
        $this->assertSame('admin@example.com', $after['config']['admin_email']);
        $this->assertSame(['db_migrate', 'db_seed', 'complete_flag'], $after['completed_tasks']);
        $this->assertSame('running', $after['installation_status']);
    }

    // -----------------------------------------------------------------------
    // axis 6: JSON 파싱 실패 → 파일 원본 보존 (파괴 금지)
    // -----------------------------------------------------------------------
    public function test_preserves_corrupted_state_file(): void
    {
        $corrupted = '{ this is not valid json';
        file_put_contents($this->statePath, $corrupted);
        $this->markInstalledViaEnv();

        $this->migration()->run($this->context());

        $this->assertFileExists($this->statePath, '파싱 실패 파일을 삭제하면 안 됨');
        $this->assertSame($corrupted, file_get_contents($this->statePath));
    }

    // -----------------------------------------------------------------------
    // axis 7: 멱등 — 2회 호출해도 동일 결과
    // -----------------------------------------------------------------------
    public function test_is_idempotent_on_repeated_runs(): void
    {
        $state = $this->legacyState();
        $state['installation_status'] = 'aborted';
        $this->writeState($state);

        $this->migration()->run($this->context());
        $firstPass = file_get_contents($this->statePath);

        $this->migration()->run($this->context());
        $secondPass = file_get_contents($this->statePath);

        $this->assertSame($firstPass, $secondPass, '재실행이 파일을 추가로 변경하면 안 됨');
        $this->assertNoPlaintextSecrets($this->readState()['config']);
    }

    // -----------------------------------------------------------------------
    // 삭제 경로 멱등 — 완료 환경에서 2회 호출
    // -----------------------------------------------------------------------
    public function test_delete_path_is_idempotent(): void
    {
        $this->writeState($this->legacyState());
        $this->markInstalledViaEnv();

        $this->migration()->run($this->context());
        $this->assertFileDoesNotExist($this->statePath);

        // 2회차 — 파일 부재 상태에서 예외 없이 no-op
        $this->migration()->run($this->context());
        $this->assertFileDoesNotExist($this->statePath);
    }

    // -----------------------------------------------------------------------
    // config 섹션 없는 state → 예외 없이 통과
    // -----------------------------------------------------------------------
    public function test_handles_state_without_config_section(): void
    {
        $this->writeState(['installation_status' => 'running', 'completed_tasks' => []]);

        $this->migration()->run($this->context());

        $this->assertFileExists($this->statePath);
        $this->assertSame('running', $this->readState()['installation_status']);
    }
}
