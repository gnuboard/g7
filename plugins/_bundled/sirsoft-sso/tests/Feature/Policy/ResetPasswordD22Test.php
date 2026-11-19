<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Plugins\Sirsoft\Sso\Listeners\Policy\PasswordResetBlockedNotifyListener;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 비밀번호 찾기와 D22(W3, W36) — 비밀번호 없는 SSO 회원은 재설정 메일로 비밀번호를 처음 만들 수 없고, 회원 기준 강제는 일반 성공
 * 응답 + 안내 메일 훅, 도메인 기준 강제는 화면에 바로 안내한다.
 *
 * @since 1.0.0
 */
class ResetPasswordD22Test extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 비밀번호 없는 연결 회원의 재설정 요청은 일반 성공처럼 보이되 재설정 토큰이 없고 안내 훅이 한 번 나는지 확인한다.
     *
     * @scenario member=passwordless_linked, allow_local_password=false
     *
     * @effects generic_success, no_reset_token, redirected_hook_once
     *
     * @return void 반환값 없음
     */
    public function test_passwordless_linked_member_gets_guidance_instead(): void
    {
        Mail::fake();
        Notification::fake();
        $idp = $this->makeIdp('corp');
        $member = $this->makeMember(['password' => null, 'email' => 'no.pw@example.test']);
        $this->linkAccount($member, $idp);
        $fired = [];
        HookManager::addAction(PasswordResetBlockedNotifyListener::ACTION_REDIRECTED, function ($user, $guide) use (&$fired) {
            $fired[] = [$user->id, $guide?->key];
        });

        $this->postJson('/api/auth/forgot-password', ['email' => 'no.pw@example.test'])->assertOk();

        $this->assertSame([[$member->id, 'corp']], $fired);
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'no.pw@example.test')->count());
    }

    /**
     * 도메인 강제는 재설정 화면에서 바로 안내(422 + 회사 계정 경로)하는지 확인한다.
     *
     * @scenario enforce=domain, phase=identifier
     *
     * @effects reset_422_with_redirect
     *
     * @return void 반환값 없음
     */
    public function test_domain_enforced_reset_shows_guidance(): void
    {
        $this->makeIdp('corp', ['enforce_owned_domains' => true]);
        $this->makeMember(['email' => 'han@corp798.test']);

        $response = $this->postJson('/api/auth/forgot-password', ['email' => 'han@corp798.test']);

        $response->assertStatus(422);
        $this->assertSame('auth.password_auth_disabled', $response->json('errors.code'));
    }

    /**
     * 비밀번호가 있고 강제 대상이 아닌 연결 회원은 재설정을 그대로 받는지 확인한다 (W36 — 바꾸기는 막지 않는다).
     *
     * @scenario member=with_password_linked, enforced=no
     *
     * @effects reset_token_created
     *
     * @return void 반환값 없음
     */
    public function test_member_with_password_can_reset(): void
    {
        Mail::fake();
        Notification::fake();
        $idp = $this->makeIdp('corp');
        $member = $this->makeMember(['email' => 'has.pw@example.test']);
        $this->linkAccount($member, $idp);

        $this->postJson('/api/auth/forgot-password', ['email' => 'has.pw@example.test'])->assertOk();

        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'has.pw@example.test')->count());
    }
}
