<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 인스톨러 설치 소유권 게이트 회귀 테스트.
 *
 * 인스톨러 상태변경 API 에 "설치 소유권" 이 없어 다른 브라우저 세션이 진행 중 설치의 설정·자격증명을
 * 바꿀 수 있던 결함(교차 세션 상태 혼합)의 핵심 게이트를 고정한다.
 *
 * - 최초 claim 세션만 소유자, 다른 세션은 비소유
 * - state 에는 토큰 해시만 저장(원문 아님), redact 후에도 소유 필드 보존(이슈 #465 회귀 가드)
 * - 미주장/비소유 세션의 상태변경 → 409, CSRF 불일치 → 403
 */
class InstallerOwnershipGuardTest extends TestCase
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
     * 최초 claim 한 세션이 소유자가 된다.
     *
     * @effects ownership_first_claim_owns_installation
     *
     * @return void 반환값 없음
     */
    public function test_first_claim_makes_current_session_owner(): void
    {
        $state = installer_claim_owner(['installation_status' => 'ready']);

        $this->assertTrue(installer_state_is_claimed($state));
        $this->assertTrue(installer_session_is_owner($state));
    }

    /**
     * 다른 세션(소유 토큰이 다른 세션)은 소유자가 아니다.
     *
     * @effects ownership_other_session_is_not_owner
     *
     * @return void 반환값 없음
     */
    public function test_other_session_is_not_owner(): void
    {
        $state = installer_claim_owner([]);

        $_SESSION = [];
        $this->assertFalse(installer_session_is_owner($state), '토큰 없는 세션');

        $_SESSION = ['install_owner' => str_repeat('a', 64)];
        $this->assertFalse(installer_session_is_owner($state), '다른 토큰 세션');
    }

    /**
     * 이미 소유자가 있으면 claim 이 소유자를 바꾸지 않는다 (교체는 reclaim 으로만).
     *
     * @effects ownership_other_session_is_not_owner
     *
     * @return void 반환값 없음
     */
    public function test_claim_does_not_override_existing_owner(): void
    {
        $state = installer_claim_owner([]);
        $ownerHash = $state['owner_hash'];

        $_SESSION = [];
        $again = installer_claim_owner($state);

        $this->assertSame($ownerHash, $again['owner_hash']);
        $this->assertFalse(installer_session_is_owner($again));
    }

    /**
     * state 에는 소유 토큰 원문이 아니라 해시만 남는다.
     *
     * @effects ownership_state_stores_hashes_only
     *
     * @return void 반환값 없음
     */
    public function test_state_stores_hash_not_raw_token(): void
    {
        $state = installer_claim_owner([]);
        $raw = $_SESSION['install_owner'];

        $this->assertSame(hash('sha256', $raw), $state['owner_hash']);
        $this->assertStringNotContainsString($raw, json_encode($state));
        $this->assertStringNotContainsString($_SESSION['install_recovery_token'], json_encode($state), '복구 토큰도 해시만');
    }

    /**
     * 미주장 state 는 누구의 소유도 아니다.
     *
     * @effects ownership_other_session_is_not_owner
     *
     * @return void 반환값 없음
     */
    public function test_unclaimed_state_has_no_owner(): void
    {
        installer_owner_token();

        $this->assertFalse(installer_session_is_owner([]));
        $this->assertFalse(installer_session_is_owner(['owner_hash' => '']));
    }

    /**
     * 비밀 redact 후에도 소유 필드는 보존된다 (소유 필드는 해시/난수라 비밀 4종이 아님).
     *
     * @effects ownership_fields_survive_secret_redaction
     *
     * @return void 반환값 없음
     */
    public function test_redact_preserves_ownership_fields(): void
    {
        $this->makeInstallerTempBase();
        $result = $this->runInstallerScript(<<<'PHP'
            $state = installer_claim_owner(['config' => ['db_write_password' => 'secret', 'admin_password' => 'pw']]);
            $state['install_plan_sig'] = 'sig';
            $state['drop_confirm'] = ['hash' => 'h'];
            $redacted = redactInstallationStateSecrets($state);
            echo json_encode($redacted);
        PHP);

        $redacted = json_decode(strtok($result['raw'], "\n"), true);
        $this->assertArrayNotHasKey('db_write_password', $redacted['config']);
        $this->assertArrayNotHasKey('admin_password', $redacted['config']);
        $this->assertNotEmpty($redacted['owner_hash']);
        $this->assertNotEmpty($redacted['recovery_hash']);
        $this->assertSame('sig', $redacted['install_plan_sig']);
        $this->assertSame(['hash' => 'h'], $redacted['drop_confirm']);
    }

    /**
     * CSRF 토큰은 세션 값과 일치할 때만 유효하다.
     *
     * @effects csrf_mismatch_rejected_with_403
     *
     * @return void 반환값 없음
     */
    public function test_csrf_token_must_match_session(): void
    {
        $token = installer_csrf_token();

        $this->assertTrue(installer_csrf_is_valid($token));
        $this->assertFalse(installer_csrf_is_valid(''));
        $this->assertFalse(installer_csrf_is_valid(str_repeat('0', 64)));

        $_SESSION = [];
        $this->assertFalse(installer_csrf_is_valid($token), '세션 토큰이 없으면 어떤 값도 무효');
    }

    /**
     * 소유자가 없는(미주장) 설치에 save-extensions 를 보내면 409.
     *
     * @effects non_owner_state_change_rejected_with_409
     *
     * @return void 반환값 없음
     */
    public function test_save_extensions_rejects_unclaimed_state_with_409(): void
    {
        $this->makeInstallerTempBase();
        $this->writeInstallerState(['installation_status' => 'ready', 'generation' => 0]);

        $result = $this->runInstallerEndpoint('save-extensions.php', [
            'session' => ['csrf_token' => 'tok'],
            'headers' => ['HTTP_X_CSRF_TOKEN' => 'tok'],
        ]);

        $this->assertSame(409, $result['status'], $result['raw']);
        $this->assertSame('ownership_conflict', $result['body']['error_code'] ?? null);
    }

    /**
     * 소유 세션이라도 CSRF 토큰이 없으면 403 — 상태는 바뀌지 않는다.
     *
     * @effects csrf_mismatch_rejected_with_403
     *
     * @return void 반환값 없음
     */
    public function test_owner_session_without_csrf_gets_403(): void
    {
        $this->makeInstallerTempBase();
        $ownerToken = str_repeat('b', 64);
        $this->writeInstallerState([
            'installation_status' => 'failed',
            'owner_hash' => hash('sha256', $ownerToken),
            'generation' => 3,
        ]);

        $result = $this->runInstallerEndpoint('state-management.php', [
            'get' => ['action' => 'reset'],
            'session' => ['install_owner' => $ownerToken, 'csrf_token' => 'tok'],
        ]);

        $this->assertSame(403, $result['status'], $result['raw']);
        $this->assertSame('csrf_invalid', $result['body']['error_code'] ?? null);
        $this->assertSame('failed', $this->readInstallerState()['installation_status']);
    }

    /**
     * 폴링 action=get 은 소유권·CSRF 게이트가 없다 (KVE-2026-1056 완료 폴링 자가차단 회귀 가드).
     *
     * @effects state_get_polling_is_not_gated
     *
     * @return void 반환값 없음
     */
    public function test_state_get_polling_is_not_gated(): void
    {
        $this->makeInstallerTempBase();
        $this->writeInstallerState([
            'installation_status' => 'running',
            'owner_hash' => hash('sha256', str_repeat('c', 64)),
        ]);

        $result = $this->runInstallerEndpoint('state-management.php', [
            'method' => 'GET',
            'get' => ['action' => 'get'],
            'session' => [],
        ]);

        $this->assertNotContains($result['status'], [403, 409], $result['raw']);
        $this->assertSame('running', $result['body']['status'] ?? null);
    }
}
