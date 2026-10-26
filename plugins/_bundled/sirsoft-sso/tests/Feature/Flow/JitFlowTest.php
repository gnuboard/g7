<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use App\Models\User;
use App\Models\UserConsent;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 처음 온 사람의 자동 생성(JIT) — 동의 대기, 동의 생략, 소유 도메인 밖 이메일, 이메일 충돌.
 *
 * @since 1.0.0
 */
class JitFlowTest extends PluginTestCase
{
    /**
     * 동의 대기를 거쳐 비밀번호 없는 회원을 만들고 동의를 기록한 뒤 인계 코드를 내는지 확인한다.
     *
     * @scenario provisioning=jit, require_consent=true
     *
     * @effects pending_token, consent_complete_creates_passwordless_member, consents_recorded, handoff_code
     *
     * @return void 반환값 없음
     */
    public function test_consent_then_create_and_handoff(): void
    {
        $idp = $this->mockOidcIdp();
        $email = $this->uniqueEmail('corp798.test');

        $callback = $this->roundTrip($idp, 'full', ['sub' => 'jit-1', 'email' => $email, 'name' => '새직원'], 'redirect=%2Fboard');
        $query = $this->redirectQuery($callback);
        $this->assertSame('/login', $this->redirectPath($callback));
        $this->assertArrayHasKey('sso_pending', $query);
        $this->assertSame('/board', $query['redirect']);
        $this->assertNull(User::query()->where('email', $email)->first(), '동의 전에는 만들지 않는다');

        $info = $this->withBrowserBind()->getJson('/api/plugins/sirsoft-sso/pending/'.$query['sso_pending']);
        $info->assertOk()->assertJsonPath('data.idp.key', $idp->key)->assertJsonPath('data.name', '새직원');
        $this->assertStringNotContainsString($email, (string) $info->getContent(), '이메일은 가려서만 보인다');

        $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/pending/'.$query['sso_pending'].'/complete', ['agree_terms' => true])
            ->assertStatus(422);

        $complete = $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/pending/'.$query['sso_pending'].'/complete', ['agree_terms' => true, 'agree_privacy' => true]);
        $complete->assertOk()->assertJsonPath('data.guard', 'user')->assertJsonPath('data.redirect', '/board');

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertFalse($user->hasPassword());
        $this->assertSame('새직원', $user->name);
        $this->assertDatabaseHas('sso_accounts', ['idp_id' => $idp->id, 'user_id' => $user->id, 'subject' => 'jit-1', 'linked_via' => 'jit']);
        $this->assertSame(2, UserConsent::query()->where('user_id', $user->id)->count());

        $redeemed = $this->redeemHandoff($complete);
        $this->assertSame($user->id, $redeemed['user']->id);

        // 대기 토큰은 한 번만 쓴다.
        $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/pending/'.$query['sso_pending'].'/complete', ['agree_terms' => true, 'agree_privacy' => true])
            ->assertStatus(404)->assertJsonPath('errors.code', 'pending_expired');
    }

    /**
     * 동의를 받지 않는 IdP 는 동의 기록 없이 회원을 만들고 바로 인계하는지 확인한다.
     *
     * @scenario provisioning=jit, require_consent=false
     *
     * @effects created_without_consents, direct_handoff
     *
     * @return void 반환값 없음
     */
    public function test_consent_skipped_creates_without_consent_records(): void
    {
        $idp = $this->mockOidcIdp(['require_consent' => false]);
        $email = $this->uniqueEmail('corp798.test');

        $response = $this->roundTrip($idp, 'full', ['sub' => 'jit-2', 'email' => $email]);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame(0, UserConsent::query()->where('user_id', $user->id)->count());
        $this->assertSame($user->id, $this->redeemHandoff($response)['user']->id);
    }

    /**
     * 이메일이 없는 신원은 받을 수 없는 대체 주소로 회원을 만드는지 확인한다.
     *
     * @scenario provisioning=jit, email=missing
     *
     * @effects undeliverable_placeholder_email
     *
     * @return void 반환값 없음
     */
    public function test_identity_without_email_gets_invalid_placeholder(): void
    {
        $idp = $this->mockOidcIdp(['require_consent' => false]);

        $response = $this->roundTrip($idp, 'no_email', ['sub' => 'jit-3']);

        $account = SsoAccount::query()->where('idp_id', $idp->id)->where('subject', 'jit-3')->firstOrFail();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}@sso\.invalid$/', $account->user->email);
        $this->assertNotNull($this->redeemHandoff($response));
    }

    /**
     * 연결 확인이 꺼져 있고 이메일이 이미 쓰이면 자동 생성을 거절하는지 확인한다.
     *
     * @scenario provisioning=jit, email=taken, link_verify=off
     *
     * @effects email_in_use_error
     *
     * @return void 반환값 없음
     */
    public function test_email_in_use_is_rejected_when_verify_off(): void
    {
        $idp = $this->mockOidcIdp(['link_methods' => ['domain_auto' => false, 'verify' => false, 'admin' => true]]);
        $email = $this->uniqueEmail('corp798.test');
        User::factory()->create(['email' => $email]);

        $response = $this->roundTrip($idp, 'full', ['sub' => 'jit-4', 'email' => $email]);

        $this->assertSame('email_in_use', $this->redirectQuery($response)['sso_error']);
        $this->assertDatabaseMissing('sso_accounts', ['idp_id' => $idp->id, 'subject' => 'jit-4']);
    }
}
