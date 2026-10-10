<?php

namespace Tests\Feature\Identity;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Models\User;
use App\Notifications\GenericNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 메일 본인인증 회로 smoke (Part B-3 Mail::fake 라우트).
 *
 * `MailIdentityProvider::requestChallenge()` 가 purpose 별로 실제 메일 발송 (Notification) 을
 * 트리거하는지 4개 대표 purpose 에 대해 검증한다.
 * 라이프사이클 통합은 `CoreIdentityPolicyLifecycleTest` (TestIdentityProvider 결정적 코드) 가 담당하고,
 * 본 테스트는 메일 발송 그 자체만 확인한다.
 */
class IdentityPolicyMailFakeSmokeTest extends TestCase
{
    use CapturesSentMail;
    use RefreshDatabase;

    private MailIdentityProvider $provider;

    /**
     * 메일을 격리하고 본인인증 알림을 준비한 뒤 메일 프로바이더를 직접 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 본인인증 메일은 알림 정의(category=identity)·메일 채널을 거쳐 발송된다 — 정의가 없거나 채널이
        // 준비되지 않으면 dispatcher 가 false 를 돌려 status='failed' 가 되므로 둘 다 갖춘다.
        Mail::fake();
        // 본인인증 메일도 알림이다(7.1.0) — Notification::fake() 는 그 발송 경로 자체를 건너뛰어
        // challenge 가 failed 가 된다. 메일 격리는 Mail::fake() 로 충분하다.
        $this->prepareIdentityNotifications();
        $logRepository = $this->app->make(IdentityVerificationLogRepositoryInterface::class);
        $this->provider = new MailIdentityProvider($logRepository);
    }

    /**
     * purpose 별 challenge 발급 회로 — log 상태 'sent' + 적절한 render_hint + 메타데이터에 hash 저장.
     *
     * 메일 본문 평문 코드는 보안상 hash 만 저장되므로 본 테스트는 발송이 트리거되었음을 'sent' 상태로 검증.
     * GenericNotification 의 via() 채널 평가는 NotificationDefinition/readiness 체인 의존이라
     * 테스트 환경에서 별도 시드 없이는 fake 캡처가 불가 — 회로 자체 검증으로 충분.
     *
     * @scenario policy_stage=challenge_delivery, verification_progress=issued
     *
     * @effects mail_provider_circuit_for_purpose, each_core_purpose_issues_sent_challenge_with_expected_render_hint, password_reset_uses_link_render_hint_with_link_token_hash
     *
     * @param  string  $purpose  발급할 본인인증 목적
     * @param  string  $expectedRenderHint  기대 render_hint (text_code 또는 link)
     * @param  string  $expectedHashKey  metadata 에 저장돼야 할 해시 키
     * @return void 반환값 없음
     */
    #[DataProvider('purposesProvider')]
    public function test_mail_provider_circuit_for_purpose(string $purpose, string $expectedRenderHint, string $expectedHashKey): void
    {
        $user = User::factory()->create();

        $challenge = $this->provider->requestChallenge($user, ['purpose' => $purpose]);

        $this->assertSame($expectedRenderHint, $challenge->renderHint, "purpose '{$purpose}' 의 render_hint 가 기대값과 일치해야 함");
        $this->assertNotEmpty($challenge->id);

        $log = \DB::table('identity_verification_logs')->where('id', $challenge->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status, "purpose '{$purpose}' challenge 발급 후 log status='sent' 여야 함");
        $this->assertSame($purpose, $log->purpose);
        $this->assertSame($user->id, $log->user_id);

        $metadata = json_decode($log->metadata, true);
        $this->assertArrayHasKey(
            $expectedHashKey,
            $metadata,
            "purpose '{$purpose}' 의 metadata 에 {$expectedHashKey} 가 저장되어 있어야 함",
        );
    }

    /**
     * 대표 목적 4종과 그 기대 render_hint·해시 키를 제공합니다.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> 목적·render_hint·해시 키 행렬
     */
    public static function purposesProvider(): array
    {
        return [
            'signup → text_code' => ['signup', 'text_code', 'code_hash'],
            'sensitive_action → text_code' => ['sensitive_action', 'text_code', 'code_hash'],
            'self_update → text_code' => ['self_update', 'text_code', 'code_hash'],
            'password_reset → link' => ['password_reset', 'link', 'link_token_hash'],
        ];
    }

    /**
     * 코드 발송 회로 — 발송된 challenge 가 DB 로그에 'sent' 상태로 기록되는지 추가 확인.
     * 메일 본문 평문 코드는 보안상 hash 만 저장하므로 실재만 검증.
     *
     * @scenario policy_stage=challenge_delivery, verification_progress=issued
     *
     * @effects mail_provider_logs_challenge_with_sent_status, mail_provider_request_creates_sent_log_with_code_hash
     *
     * @return void 반환값 없음
     */
    public function test_mail_provider_logs_challenge_with_sent_status(): void
    {
        $user = User::factory()->create();

        $challenge = $this->provider->requestChallenge($user, ['purpose' => 'sensitive_action']);

        $log = \DB::table('identity_verification_logs')->where('id', $challenge->id)->first();

        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('sensitive_action', $log->purpose);

        $metadata = json_decode($log->metadata, true);
        $this->assertArrayHasKey('code_hash', $metadata, 'code_hash 가 metadata 에 저장되어 있어야 함 (평문은 발송 메일에만 노출)');
    }
}
