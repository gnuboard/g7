<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 설치 소유권 복구 토큰 회귀 테스트.
 *
 * 세션이 유실된 소유자는 복구 토큰으로만 소유권을 되찾는다. 같은 세션은 토큰 없이 재개한다
 * (2025-11-13 브라우저 재시작 재개 UX 회귀 가드).
 */
class InstallerRecoveryTokenTest extends TestCase
{
    use RunsInstallerSubprocess;

    /** @var array<string, mixed>|null 테스트 전 세션 전역 백업 */
    private ?array $sessionBackup = null;

    /**
     * 순수 함수 로드 + 세션 전역 초기화.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3).'/public/install/includes/installer-ownership.php';
        $this->sessionBackup = $_SESSION ?? null;
        $_SESSION = [];
    }

    /**
     * 세션 전역 복원 + 임시 루트 제거.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup ?? [];
        $this->removeInstallerTempBase();

        parent::tearDown();
    }

    /**
     * 발급 시 state 에는 해시만, 원문은 소유 세션에만.
     *
     * @effects ownership_state_stores_hashes_only
     *
     * @return void 반환값 없음
     */
    public function test_issue_stores_hash_and_keeps_raw_in_session(): void
    {
        $state = installer_issue_recovery_token([]);
        $raw = $_SESSION['install_recovery_token'];

        $this->assertSame(32, strlen($raw));
        $this->assertSame(hash('sha256', $raw), $state['recovery_hash']);
    }

    /**
     * 표시 형식(하이픈)·대문자·공백이 섞여도 같은 토큰으로 인정된다.
     *
     * @effects recovery_token_rejects_wrong_token
     *
     * @return void 반환값 없음
     */
    public function test_matches_accepts_formatted_input(): void
    {
        $state = installer_issue_recovery_token([]);
        $raw = $_SESSION['install_recovery_token'];

        $this->assertTrue(installer_recovery_token_matches($state, $raw));
        $this->assertTrue(installer_recovery_token_matches($state, installer_format_recovery_token($raw)));
        $this->assertTrue(installer_recovery_token_matches($state, ' '.strtoupper($raw).' '));
        $this->assertFalse(installer_recovery_token_matches($state, str_repeat('0', 32)));
        $this->assertFalse(installer_recovery_token_matches($state, ''));
        $this->assertFalse(installer_recovery_token_matches([], $raw), '복구 해시가 없으면 무효');
    }

    /**
     * reclaim 은 소유자를 현재 세션으로 바꾸고 복구 토큰을 회전한다.
     *
     * @effects recovery_token_reclaims_and_rotates
     *
     * @return void 반환값 없음
     */
    public function test_reclaim_rotates_owner_and_recovery(): void
    {
        $state = installer_claim_owner([]);
        $oldRecovery = $state['recovery_hash'];

        $_SESSION = [];
        $reclaimed = installer_reclaim_owner($state);

        $this->assertTrue(installer_session_is_owner($reclaimed));
        $this->assertNotSame($oldRecovery, $reclaimed['recovery_hash']);
        $this->assertFalse(installer_recovery_token_matches($reclaimed, str_repeat('0', 32)));
    }

    /**
     * 같은 세션은 복구 토큰 없이 소유자로 인정된다.
     *
     * @effects same_session_resumes_without_token
     *
     * @return void 반환값 없음
     */
    public function test_same_session_needs_no_token(): void
    {
        $state = installer_claim_owner([]);
        unset($_SESSION['install_recovery_token']);

        $this->assertTrue(installer_session_is_owner($state));
    }

    /**
     * reclaim 엔드포인트 — 틀린 토큰 403, 올바른 토큰 200 + 소유권 이전 + 토큰 회전.
     *
     * @effects recovery_token_reclaims_and_rotates, recovery_token_rejects_wrong_token
     *
     * @return void 반환값 없음
     */
    public function test_reclaim_endpoint_flow(): void
    {
        $this->makeInstallerTempBase();
        $recovery = str_repeat('ab', 16);
        $this->writeInstallerState([
            'installation_status' => 'aborted',
            'current_step' => 5,
            'owner_hash' => hash('sha256', str_repeat('1', 64)),
            'recovery_hash' => hash('sha256', $recovery),
            'generation' => 6,
        ]);

        $wrong = $this->runInstallerEndpoint('state-management.php', [
            'get' => ['action' => 'reclaim'],
            'session_id' => 'lostsession',
            'session' => ['csrf_token' => 'tok'],
            'headers' => ['HTTP_X_CSRF_TOKEN' => 'tok'],
            'post' => ['recovery_token' => str_repeat('0', 32)],
        ]);
        $this->assertSame(403, $wrong['status'], $wrong['raw']);
        $this->assertSame('recovery_token_invalid', $wrong['body']['error_code'] ?? null);
        $this->assertSame(6, $this->readInstallerState()['generation']);

        $ok = $this->runInstallerEndpoint('state-management.php', [
            'get' => ['action' => 'reclaim'],
            'session_id' => 'lostsession',
            'session' => ['csrf_token' => 'tok'],
            'headers' => ['HTTP_X_CSRF_TOKEN' => 'tok'],
            'post' => ['recovery_token' => installer_format_recovery_token($recovery)],
        ]);
        $this->assertSame(200, $ok['status'], $ok['raw']);
        $this->assertTrue($ok['body']['success'] ?? false);

        $state = $this->readInstallerState();
        $this->assertSame(hash('sha256', $ok['session']['install_owner']), $state['owner_hash']);
        $this->assertNotSame(hash('sha256', $recovery), $state['recovery_hash'], '사용한 토큰은 회전');
        $this->assertSame(hash('sha256', $ok['session']['install_recovery_token']), $state['recovery_hash']);
        $this->assertSame(7, $state['generation']);
        $this->assertSame(5, $ok['session']['installer_current_step']);
    }

    /**
     * 실패가 한도를 넘으면 올바른 토큰도 잠금 해제 전까지 429.
     *
     * @effects recovery_reclaim_is_rate_limited
     *
     * @return void 반환값 없음
     */
    public function test_reclaim_is_rate_limited(): void
    {
        $this->makeInstallerTempBase();
        $recovery = str_repeat('cd', 16);
        $this->writeInstallerState([
            'installation_status' => 'aborted',
            'owner_hash' => hash('sha256', str_repeat('2', 64)),
            'recovery_hash' => hash('sha256', $recovery),
            'generation' => 1,
        ]);

        $spec = [
            'get' => ['action' => 'reclaim'],
            'session' => ['csrf_token' => 'tok'],
            'headers' => ['HTTP_X_CSRF_TOKEN' => 'tok'],
            'post' => ['recovery_token' => str_repeat('0', 32)],
        ];
        for ($i = 0; $i < INSTALLER_RECLAIM_MAX_FAILURES; $i++) {
            $this->assertSame(403, $this->runInstallerEndpoint('state-management.php', $spec)['status']);
        }

        $spec['post']['recovery_token'] = $recovery;
        $locked = $this->runInstallerEndpoint('state-management.php', $spec);

        $this->assertSame(429, $locked['status'], $locked['raw']);
        $this->assertSame(hash('sha256', str_repeat('2', 64)), $this->readInstallerState()['owner_hash']);
    }
}
