<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use App\Models\User;
use App\Services\IdentityMessageDispatcher;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Services\LinkVerificationService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 기존 회원 연결 확인 — 비밀번호, 메일 인증번호, 비밀번호 3회 실패 폐기(G7 실패 횟수 반영).
 *
 * @since 1.0.0
 */
class LinkVerificationFlowTest extends PluginTestCase
{
    /**
     * 같은 이메일의 기존 회원을 두고 왕복해 연결 확인 대기 토큰을 받습니다.
     *
     * @param  User  $user  기존 회원
     * @param  string  $subject  IdP 안의 고유 식별자
     * @return array{0: IdentityProvider, 1: string} 왕복한 IdP 와 대기 토큰
     */
    private function pendingLink(User $user, string $subject): array
    {
        $idp = $this->mockOidcIdp();
        $response = $this->roundTrip($idp, 'full', ['sub' => $subject, 'email' => $user->email]);
        $token = $this->redirectQuery($response)['sso_link'] ?? null;
        $this->assertNotNull($token, '같은 이메일 회원이 있으면 연결 확인으로 간다');

        return [$idp, $token];
    }

    /**
     * 기존 회원 비밀번호로 연결을 확인하면 연결하고 인계 코드를 내는지 확인한다.
     *
     * @scenario method=password, result=ok
     *
     * @effects masked_email_shown, linked_via_verify, handoff_code
     *
     * @return void 반환값 없음
     */
    public function test_password_confirmation_links_and_hands_off(): void
    {
        $user = User::factory()->create(['email' => $this->uniqueEmail('corp798.test'), 'password' => 'Secret-798!']);
        [$idp, $token] = $this->pendingLink($user, 'lv-1');

        $info = $this->withBrowserBind()->getJson('/api/plugins/sirsoft-sso/link/'.$token);
        $info->assertOk()->assertJsonPath('data.methods', ['password', 'email']);
        $this->assertStringNotContainsString($user->email, (string) $info->getContent());
        $this->assertMatchesRegularExpression('/^.\*{3,}@corp798\.test$/', (string) $info->json('data.email'));

        $verify = $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/link/'.$token.'/verify', ['method' => 'password', 'password' => 'Secret-798!']);
        $verify->assertOk();

        $this->assertDatabaseHas('sso_accounts', ['idp_id' => $idp->id, 'user_id' => $user->id, 'subject' => 'lv-1', 'linked_via' => 'verify']);
        $this->assertSame($user->id, $this->redeemHandoff($verify)['user']->id);
    }

    /**
     * 비밀번호를 세 번 틀리면 연결 확인 대기를 폐기하고 G7 실패 횟수에 넣는지 확인한다.
     *
     * @scenario method=password, result=wrong_x3
     *
     * @effects pending_discarded, g7_failure_counted
     *
     * @return void 반환값 없음
     */
    public function test_three_wrong_passwords_discard_the_pending_link(): void
    {
        $user = User::factory()->create(['email' => $this->uniqueEmail('corp798.test'), 'password' => 'Secret-798!']);
        [, $token] = $this->pendingLink($user, 'lv-2');
        $before = (int) $user->fresh()->failed_login_attempts;

        for ($i = 1; $i < LinkVerificationService::MAX_PASSWORD_FAILURES; $i++) {
            $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/link/'.$token.'/verify', ['method' => 'password', 'password' => 'wrong'])
                ->assertStatus(422)->assertJsonPath('errors.code', 'password_mismatch');
        }
        $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/link/'.$token.'/verify', ['method' => 'password', 'password' => 'wrong'])
            ->assertStatus(404)->assertJsonPath('errors.code', 'link_expired');

        $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/link/'.$token.'/verify', ['method' => 'password', 'password' => 'Secret-798!'])
            ->assertStatus(404);
        $this->assertGreaterThan($before, (int) $user->fresh()->failed_login_attempts, '틀린 비밀번호는 G7 로그인 실패 횟수에 들어간다');
        $this->assertDatabaseMissing('sso_accounts', ['subject' => 'lv-2']);
    }

    /**
     * 메일 인증번호로 연결을 확인하는 흐름을 확인한다.
     *
     * @scenario method=email_code
     *
     * @effects code_sent, verified_links
     *
     * @return void 반환값 없음
     */
    public function test_email_code_confirmation(): void
    {
        // 메일 발송 자체는 코어 몫이다 — 시험 DB 의 알림 템플릿 유무와 무관하게 「발송됨」으로 두고 연결 흐름만 본다.
        $this->mock(IdentityMessageDispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andReturn(true));
        $user = User::factory()->create(['email' => $this->uniqueEmail('corp798.test'), 'password' => null]);
        [$idp, $token] = $this->pendingLink($user, 'lv-3');

        $this->withBrowserBind()->getJson('/api/plugins/sirsoft-sso/link/'.$token)->assertJsonPath('data.methods', ['email']);

        $sent = $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/link/'.$token.'/email');
        $sent->assertOk();
        $challengeId = (string) $sent->json('data.challenge_id');
        $code = $this->plantCode($challengeId);

        $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/link/'.$token.'/verify', ['method' => 'email', 'challenge_id' => $challengeId, 'code' => '000000'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'invalid_code');

        $sent = $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/link/'.$token.'/email');
        $challengeId = (string) $sent->json('data.challenge_id');
        $code = $this->plantCode($challengeId);

        $verify = $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/link/'.$token.'/verify', ['method' => 'email', 'challenge_id' => $challengeId, 'code' => $code]);
        $verify->assertOk();
        $this->assertDatabaseHas('sso_accounts', ['idp_id' => $idp->id, 'user_id' => $user->id, 'linked_via' => 'verify']);
    }
}
