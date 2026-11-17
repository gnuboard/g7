<?php

namespace Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 공개 「인증번호 받기」 — 같은 수신 주소·같은 목적으로 60초 안에 다시 요청하면 새 메일을 보내지 않는다.
 *
 * 로그인하지 않아도 아무 주소로 인증 메일을 요청할 수 있고 제한은 IP 분당 횟수뿐이었다. 한 주소에 인증 메일을
 * 계속 보낼 수 있었다. 재요청은 429 와 남은 시간으로 답하고 challenge 를 만들지 않는다.
 * 로그인 2단계 인증·가입 직후 자동 발송은 이 제한의 대상이 아니다.
 *
 * @scenario-manifest tests/scenarios/auth-account-enumeration.yaml
 */
class IdentityChallengeCooldownTest extends TestCase
{
    use CapturesSentMail;
    use RefreshDatabase;

    /**
     * 메일을 격리하고 본인인증 권한·알림 정의를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->prepareIdentityNotifications();
    }

    /**
     * 공개 인증번호 요청 API 를 호출합니다.
     *
     * @param  string  $email  수신 주소
     * @param  string  $purpose  인증 목적
     * @return TestResponse 응답
     */
    private function requestChallenge(string $email, string $purpose = 'password_reset'): TestResponse
    {
        return $this->postJson('/api/identity/challenges', [
            'purpose' => $purpose,
            'target' => ['email' => $email],
        ]);
    }

    /**
     * 같은 주소·같은 목적의 60초 안 재요청은 429·남은 시간·Retry-After 로 답하고 challenge 를 만들지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=identity_challenge__same_target_within_cooldown
     *
     * @effects identity_challenge_repeat_returns_429_without_new_challenge
     */
    public function test_repeat_request_within_60_seconds_is_refused_without_a_new_challenge(): void
    {
        $this->requestChallenge('same@example.com')->assertStatus(201);
        $this->assertSame(1, IdentityVerificationLog::count());

        $this->travel(20)->seconds();
        $second = $this->requestChallenge('SAME@example.com');

        $second->assertStatus(429);
        $retryAfter = (int) $second->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(39, $retryAfter);
        $this->assertLessThanOrEqual(40, $retryAfter);
        $this->assertStringContainsString((string) $retryAfter, (string) $second->json('message'));
        $this->assertSame(1, IdentityVerificationLog::count());
    }

    /**
     * 다른 목적·다른 주소는 막지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=identity_challenge__other_purpose_or_target
     *
     * @effects identity_challenge_cooldown_scoped_to_target_and_purpose
     */
    public function test_other_purpose_or_other_address_is_not_blocked(): void
    {
        $this->requestChallenge('same@example.com', 'password_reset')->assertStatus(201);

        $this->requestChallenge('same@example.com', 'signup')->assertStatus(201);
        $this->requestChallenge('other@example.com', 'password_reset')->assertStatus(201);

        $this->assertSame(3, IdentityVerificationLog::count());
    }

    /**
     * 60초가 지나면 다시 받을 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=identity_challenge__same_target_after_cooldown
     *
     * @effects identity_challenge_allowed_after_cooldown
     */
    public function test_request_is_allowed_again_after_60_seconds(): void
    {
        $this->requestChallenge('same@example.com')->assertStatus(201);

        $this->travel(61)->seconds();

        $this->requestChallenge('same@example.com')->assertStatus(201);
        $this->assertSame(2, IdentityVerificationLog::count());
    }

    /**
     * 직전 발송이 실패로 끝났으면 바로 다시 요청할 수 있다 — 받지 못한 메일 때문에 기다리게 하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=identity_challenge__previous_dispatch_failed
     *
     * @effects identity_challenge_failed_dispatch_does_not_start_cooldown
     */
    public function test_failed_dispatch_does_not_start_the_cooldown(): void
    {
        $this->requestChallenge('same@example.com')->assertStatus(201);
        IdentityVerificationLog::query()->update(['status' => IdentityVerificationStatus::Failed->value]);

        $this->requestChallenge('same@example.com')->assertStatus(201);
    }

    /**
     * 서비스가 직접 발행하는 경로(로그인 2단계 인증·가입 직후 자동 발송)는 이 제한을 받지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=identity_challenge__internal_issue_path
     *
     * @effects internal_challenge_paths_are_not_cooled_down
     */
    public function test_internal_issue_paths_are_not_affected(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);
        $service = app(IdentityVerificationService::class);

        $service->start('sensitive_action', $user, [], MailIdentityProvider::ID);
        $service->start('sensitive_action', $user, [], MailIdentityProvider::ID);

        $this->assertSame(2, IdentityVerificationLog::where('purpose', 'sensitive_action')->count());
    }
}
