<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Signup;

use App\Models\Role;
use App\Models\User;
use App\Services\IdentityMessageDispatcher;
use App\Services\UserService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;
use Tests\Support\Concerns\CapturesSentMail;

/**
 * 「이미 가입된 이메일」 — 같은 이메일의 기존 회원이 있으면 기본은 본인확인(기존 계정 비밀번호 또는 기존 계정 이메일
 * 인증번호) 뒤 연결한다. 이메일이 같다는 것만으로는 합치지 않는다. 정책 3가지와 확인 방법 2가지를 잰다.
 *
 * @since 1.0.0
 */
class LinkExistingTest extends PluginTestCase
{
    use CapturesSentMail;

    /**
     * 카카오를 켜고 메일·알림·큐를 막습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        $this->prepareIdentityNotifications();
        Role::firstOrCreate(['identifier' => 'user'], ['name' => ['ko' => '사용자', 'en' => 'User']]);
        $this->enableKakao();
    }

    /**
     * 기존 회원 이메일을 주는 카카오 계정으로 콜백을 마칩니다.
     *
     * @param  User  $existing  기존 회원
     * @param  string  $uid  카카오 사용자 ID
     * @param  bool  $verified  제공자 검증 표시
     * @return array<string, string> 이동 주소 쿼리
     */
    private function arrive(User $existing, string $uid, bool $verified = true): array
    {
        return $this->redirectQuery($this->kakaoRoundTrip($this->kakaoProfile($uid, strtoupper($existing->email), $verified)));
    }

    /**
     * 확인 요청을 보냅니다.
     *
     * @param  string  $token  대기 토큰
     * @param  array<string, mixed>  $input  입력
     * @return TestResponse 응답
     */
    private function linkRequest(string $token, array $input): TestResponse
    {
        return $this->postJson('/api/plugins/sirsoft-social_login/signup/link-existing/request', ['pending_token' => $token] + $input);
    }

    /**
     * 기본 정책은 이메일 대소문자를 무시하고 기존 회원을 찾아 확인 단계로 보내고, 기존 계정 비밀번호가 맞으면 연결 뒤 인계된다.
     *
     * @scenario policy=verify_link, method=password, password=correct
     *
     * @effects existing_email_detected_case_insensitive, existing_view_masks_email, password_confirm_links_and_hands_off
     *
     * @return void 반환값 없음
     */
    public function test_password_confirmation_links_existing_account(): void
    {
        $existing = User::factory()->create(['email' => $this->uniqueEmail('exist'), 'password' => Hash::make('Passw0rd!x')]);

        $query = $this->arrive($existing, 'k-ex1');
        $this->assertSame('existing', $query['social_step']);

        $view = $this->getJson('/api/plugins/sirsoft-social_login/signup/pending?pending_token='.$query['social_pending'])->assertOk();
        $this->assertSame('existing', $view->json('data.step'));
        $this->assertTrue($view->json('data.existing_account.has_password'));
        $this->assertStringContainsString('*', (string) $view->json('data.existing_account.email_masked'));
        $this->assertStringNotContainsString($existing->email, (string) $view->getContent());

        $done = $this->linkRequest($query['social_pending'], ['method' => 'password', 'password' => 'Passw0rd!x'])->assertOk();

        $this->assertStringStartsWith('/login?login_handoff=', (string) $done->json('data.handoff_url'));
        $this->assertSame($existing->id, SocialAccount::query()->where('provider_user_id', 'k-ex1')->value('user_id'));
    }

    /**
     * 틀린 비밀번호는 거절하고, 다섯 번째 실패에서 가입 대기를 지운다.
     *
     * @scenario method=password, password=wrong
     *
     * @effects wrong_password_refused, pending_dropped_after_five_failures
     *
     * @return void 반환값 없음
     */
    public function test_five_failures_drop_pending(): void
    {
        $existing = User::factory()->create(['email' => $this->uniqueEmail('exist5'), 'password' => Hash::make('Passw0rd!x')]);
        $token = $this->arrive($existing, 'k-ex5')['social_pending'];

        for ($i = 1; $i <= 4; $i++) {
            $this->linkRequest($token, ['method' => 'password', 'password' => 'wrong-'.$i])->assertStatus(422)->assertJsonPath('errors.code', 'password_mismatch');
        }
        $this->linkRequest($token, ['method' => 'password', 'password' => 'wrong-5'])->assertStatus(404)->assertJsonPath('errors.code', 'too_many_attempts');
        $this->linkRequest($token, ['method' => 'password', 'password' => 'Passw0rd!x'])->assertStatus(404);
        $this->assertSame(0, SocialAccount::query()->where('provider_user_id', 'k-ex5')->count());
    }

    /**
     * 기존 계정 이메일 인증번호로 확인해도 연결된다 — 비밀번호 없는 기존 회원은 비밀번호 방법을 쓸 수 없다.
     *
     * @scenario method=email, existing=passwordless
     *
     * @effects email_code_confirm_links, passwordless_existing_hides_password_method
     *
     * @return void 반환값 없음
     */
    public function test_email_code_confirmation_and_passwordless_existing(): void
    {
        $existing = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('exist-np')]);
        $token = $this->arrive($existing, 'k-ex-np')['social_pending'];

        $view = $this->getJson('/api/plugins/sirsoft-social_login/signup/pending?pending_token='.$token)->assertOk();
        $this->assertFalse($view->json('data.existing_account.has_password'));
        $this->linkRequest($token, ['method' => 'password', 'password' => 'anything'])->assertStatus(422)->assertJsonPath('errors.code', 'password_not_available');

        $challengeId = (string) $this->linkRequest($token, ['method' => 'email'])->assertOk()->json('data.challenge_id');
        $done = $this->postJson('/api/plugins/sirsoft-social_login/signup/link-existing/verify', [
            'pending_token' => $token, 'challenge_id' => $challengeId, 'code' => $this->plantCode($challengeId),
        ])->assertOk();

        $this->assertStringStartsWith('/login?login_handoff=', (string) $done->json('data.handoff_url'));
        $this->assertSame($existing->id, SocialAccount::query()->where('provider_user_id', 'k-ex-np')->value('user_id'));
    }

    /**
     * 「검증된 이메일이면 자동 연결」은 제공자가 검증했다고 알린 이메일만 바로 연결하고, 검증 표시가 없으면 확인 단계로 보낸다.
     *
     * @scenario policy=auto_link_verified, verified=true|false
     *
     * @effects auto_link_only_when_verified
     *
     * @return void 반환값 없음
     */
    public function test_auto_link_only_for_verified_email(): void
    {
        $this->setPluginSettings(['email_conflict_policy' => 'auto_link_verified']);
        $existing = User::factory()->create(['email' => $this->uniqueEmail('auto')]);

        $unverified = $this->arrive($existing, 'k-auto-u', verified: false);
        $this->assertSame('existing', $unverified['social_step']);

        $verified = $this->arrive($existing, 'k-auto-v');
        $this->assertArrayHasKey('login_handoff', $verified);
        $this->assertSame($existing->id, SocialAccount::query()->where('provider_user_id', 'k-auto-v')->value('user_id'));
    }

    /**
     * 「연결하지 않음」은 확인 단계 없이 새 계정 가입으로 가고, 겹치는 이메일은 쓰지 않는다(다른 이메일 입력).
     *
     * @scenario policy=never_link
     *
     * @effects never_link_goes_to_new_account_without_email
     *
     * @return void 반환값 없음
     */
    public function test_never_link_goes_to_new_account(): void
    {
        $this->setPluginSettings(['email_conflict_policy' => 'never_link']);
        $existing = User::factory()->create(['email' => $this->uniqueEmail('never')]);

        $query = $this->arrive($existing, 'k-never');

        $this->assertSame('complete', $query['social_step']);
        $view = $this->getJson('/api/plugins/sirsoft-social_login/signup/pending?pending_token='.$query['social_pending'])->assertOk();
        $this->assertSame('input', $view->json('data.email.mode'));
        $this->assertNull($view->json('data.email.value'));
    }

    /**
     * [새 계정으로 가입]은 확인 단계를 버리고 이메일을 비운 가입 단계로 바꾼다 — 제공자의 「이메일 미제공」 정책이 대체
     * 이메일이어도 대체 이메일로 넘기지 않고 다른 주소를 받는다.
     *
     * @scenario action=discard, email_missing_policy=ask|placeholder
     *
     * @effects discard_switches_to_new_account, discard_asks_email_even_with_placeholder_policy
     *
     * @return void 반환값 없음
     */
    public function test_discard_switches_to_new_account(): void
    {
        $existing = User::factory()->create(['email' => $this->uniqueEmail('discard')]);
        $token = $this->arrive($existing, 'k-discard')['social_pending'];

        $view = $this->postJson('/api/plugins/sirsoft-social_login/signup/discard-existing', ['pending_token' => $token])->assertOk();

        $this->assertSame('complete', $view->json('data.step'));
        $this->assertSame('input', $view->json('data.email.mode'));
        $this->assertNull($view->json('data.existing_account'));
        $this->linkRequest($token, ['method' => 'password', 'password' => 'x'])->assertStatus(409)->assertJsonPath('errors.code', 'not_existing_step');

        SocialLoginProvider::query()->where('key', 'kakao')->firstOrFail()->forceFill(['email_missing_policy' => 'placeholder'])->save();
        app(ProviderRegistry::class)->flush();
        $other = User::factory()->create(['email' => $this->uniqueEmail('discard-ph')]);
        $phToken = $this->arrive($other, 'k-discard-ph')['social_pending'];

        $phView = $this->postJson('/api/plugins/sirsoft-social_login/signup/discard-existing', ['pending_token' => $phToken])->assertOk();

        $this->assertSame('input', $phView->json('data.email.mode'));
        $this->assertNull($phView->json('data.email.value'));
        $this->assertStringNotContainsString('@social.invalid', (string) json_encode($phView->json('data')));
    }

    /**
     * 탈퇴한 회원의 이메일은 「이미 가입된 이메일」로 잡지 않는다.
     *
     * @scenario existing=withdrawn
     *
     * @effects withdrawn_email_not_detected
     *
     * @return void 반환값 없음
     */
    public function test_withdrawn_member_email_is_not_detected(): void
    {
        $email = $this->uniqueEmail('gone');
        $member = User::factory()->create(['email' => $email]);
        app(UserService::class)->withdrawUser($member);

        $query = $this->redirectQuery($this->kakaoRoundTrip($this->kakaoProfile('k-gone', $email)));

        $this->assertSame('complete', $query['social_step']);
    }

    /**
     * 기존 회원이 관리 권한 회원이면 확인이 맞아도 연결·로그인하지 않는다(관리자 소셜 로그인 차단).
     *
     * @scenario existing=admin, method=password
     *
     * @effects admin_existing_link_blocked
     *
     * @return void 반환값 없음
     */
    public function test_admin_existing_account_is_not_linked(): void
    {
        $admin = $this->makeAdminUser(['email' => $this->uniqueEmail('adm'), 'password' => Hash::make('Passw0rd!x')]);
        $token = $this->arrive($admin, 'k-adm')['social_pending'];

        $this->linkRequest($token, ['method' => 'password', 'password' => 'Passw0rd!x'])->assertStatus(403)->assertJsonPath('errors.code', 'admin_blocked');
        $this->assertSame(0, SocialAccount::query()->where('provider_user_id', 'k-adm')->count());
    }

    /**
     * 기존 계정 메일로 인증번호를 보내지 못하면 503 과 관리자 문의 안내로 답하고 시도 기록에 `mail_failed` 를 남긴다.
     *
     * @scenario method=email, mail=failed
     *
     * @effects existing_mail_failure_surfaced
     *
     * @return void 반환값 없음
     */
    public function test_existing_account_mail_failure_is_reported(): void
    {
        $existing = User::factory()->create(['email' => $this->uniqueEmail('exist-mf')]);
        $token = $this->arrive($existing, 'k-ex-mf')['social_pending'];
        $this->mock(IdentityMessageDispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andReturn(false));

        $response = $this->linkRequest($token, ['method' => 'email'])->assertStatus(503);

        $this->assertSame('mail_failed', $response->json('errors.code'));
        $this->assertTrue(SocialLoginAttempt::query()->where('provider_key', 'kakao')->where('error_code', 'mail_failed')->exists());
    }
}
