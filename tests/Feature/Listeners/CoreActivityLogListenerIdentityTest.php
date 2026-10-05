<?php

namespace Tests\Feature\Listeners;

use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Models\ActivityLog;
use App\Models\IdentityVerificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CoreActivityLogListener 의 IDV 훅 매핑 동작 테스트.
 *
 * 계획서 PR#2 요구: `core.identity.after_request` / `.after_verify` / `.challenge_expired`
 * 3 훅이 activity_logs 에 정확히 파생되는지 검증.
 */
class CoreActivityLogListenerIdentityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 본인인증 요청 훅이 활동 로그를 남긴다.
     *
     * @scenario policy_stage=activity_log, verification_progress=issued
     *
     * @effects after_request_hook_creates_activity_log
     *
     * @return void 반환값 없음
     */
    public function test_after_request_hook_creates_activity_log(): void
    {
        $challenge = new VerificationChallenge(
            id: 'test-challenge-1',
            providerId: 'g7:core.mail',
            purpose: 'sensitive_action',
            channel: 'email',
            targetHash: hash('sha256', 'user@example.com'),
            expiresAt: now()->addMinutes(15),
            renderHint: 'text_code',
        );

        HookManager::doAction(
            'core.identity.after_request',
            $challenge,
            'sensitive_action',
            ['email' => 'user@example.com'],
            []
        );

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'identity.request',
        ]);

        $latest = ActivityLog::where('action', 'identity.request')->latest('id')->first();
        $this->assertNotNull($latest);
        $this->assertStringContainsString('g7:core.mail', json_encode($latest->properties));
    }

    /**
     * 검증 성공 훅이 검증 활동 로그를 남긴다.
     *
     * @scenario policy_stage=activity_log, verification_progress=verified
     *
     * @effects after_verify_success_creates_verify_activity_log
     *
     * @return void 반환값 없음
     */
    public function test_after_verify_success_creates_verify_activity_log(): void
    {
        $result = new VerificationResult(
            success: true,
            challengeId: 'test-challenge-2',
            providerId: 'g7:core.mail',
            verifiedAt: now(),
        );
        $log = IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'target_hash' => hash('sha256', 'user@example.com'),
            'status' => IdentityVerificationStatus::Verified->value,
            'expires_at' => now()->addMinutes(15),
        ]);

        HookManager::doAction('core.identity.after_verify', $result, $log, []);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'identity.verify',
        ]);
    }

    /**
     * 검증 실패 훅이 실패 활동 로그를 남긴다.
     *
     * @scenario policy_stage=activity_log, verification_progress=failed
     *
     * @effects after_verify_failure_creates_failed_activity_log
     *
     * @return void 반환값 없음
     */
    public function test_after_verify_failure_creates_failed_activity_log(): void
    {
        $result = new VerificationResult(
            success: false,
            challengeId: 'test-challenge-3',
            providerId: 'g7:core.mail',
            verifiedAt: null,
            failureCode: 'invalid_code',
            failureReason: 'Wrong code',
        );
        $log = IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'target_hash' => hash('sha256', 'user@example.com'),
            'status' => IdentityVerificationStatus::Failed->value,
            'expires_at' => now()->addMinutes(15),
        ]);

        HookManager::doAction('core.identity.after_verify', $result, $log, []);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'identity.verify_failed',
        ]);
    }

    /**
     * 인증 만료 훅이 만료 활동 로그를 남긴다.
     *
     * @scenario policy_stage=activity_log, verification_progress=expired
     *
     * @effects challenge_expired_hook_creates_expired_activity_log
     *
     * @return void 반환값 없음
     */
    public function test_challenge_expired_hook_creates_expired_activity_log(): void
    {
        $log = IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'password_reset',
            'channel' => 'email',
            'target_hash' => hash('sha256', 'user@example.com'),
            'status' => IdentityVerificationStatus::Expired->value,
            'expires_at' => now()->subMinutes(1),
        ]);

        HookManager::doAction('core.identity.challenge_expired', $log);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'identity.expired',
        ]);
    }

    /**
     * IDV 4 액션 키의 라벨이 ko/en 모두 raw key 가 아닌 번역된 문자열로 해석되어야 한다.
     *
     * beta.4 출시 시점에 `identity.verify` / `identity.verify_failed` 의 마지막 세그먼트
     * 매핑이 누락되어 관리자 화면에 raw 액션 키가 노출되던 회귀를 차단한다.
     * `ActivityLog::getActionLabelAttribute` 5단계 fallback 중 마지막 단계(raw 문자열)에
     * 도달하면 안 된다.
     *
     * @return iterable<string, array{string}> 액션 키
     */
    public static function provideIdentityActionKeys(): iterable
    {
        return [
            'identity.request' => ['identity.request'],
            'identity.verify' => ['identity.verify'],
            'identity.verify_failed' => ['identity.verify_failed'],
            'identity.expired' => ['identity.expired'],
        ];
    }

    /**
     * 본인인증 활동 로그의 액션 이름이 한국어로 해석된다.
     *
     * @scenario policy_stage=activity_log, verification_progress=verified
     *
     * @effects identity_action_label_resolves_in_ko
     *
     * @param  string  $action  액션 키
     * @return void 반환값 없음
     */
    #[DataProvider('provideIdentityActionKeys')]
    public function test_identity_action_label_resolves_in_ko(string $action): void
    {
        app()->setLocale('ko');
        $log = new ActivityLog(['action' => $action]);

        $label = $log->action_label;

        $this->assertNotSame($action, $label, "ko 라벨이 raw 키 '{$action}' 로 노출됨 — lang/ko/activity_log.php action 배열 매핑 누락");
        $this->assertNotEmpty($label);
    }

    /**
     * 본인인증 활동 로그의 액션 이름이 영어로 해석된다.
     *
     * @scenario policy_stage=activity_log, verification_progress=failed
     *
     * @effects identity_action_label_resolves_in_en
     *
     * @param  string  $action  액션 키
     * @return void 반환값 없음
     */
    #[DataProvider('provideIdentityActionKeys')]
    public function test_identity_action_label_resolves_in_en(string $action): void
    {
        app()->setLocale('en');
        $log = new ActivityLog(['action' => $action]);

        $label = $log->action_label;

        $this->assertNotSame($action, $label, "en 라벨이 raw 키 '{$action}' 로 노출됨 — lang/en/activity_log.php action 배열 매핑 누락");
        $this->assertNotEmpty($label);
    }
}
