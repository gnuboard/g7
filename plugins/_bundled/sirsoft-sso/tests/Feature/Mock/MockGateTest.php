<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Mock;

use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\Sso\Console\Commands\MockCommand;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 모의 IdP 게이트(W23) — artisan 으로만 켜고(최대 240분), 꺼져 있으면 모의 라우트 404, 운영 환경에서는 명령이 거절.
 *
 * @since 1.0.0
 */
class MockGateTest extends PluginTestCase
{
    /**
     * 모의 IdP 라우트가 게이트를 따르는지 확인한다.
     *
     * @scenario gate=off|on
     *
     * @effects mock_routes_404_when_off
     *
     * @return void 반환값 없음
     */
    public function test_mock_routes_follow_gate(): void
    {
        $gate = app(MockGate::class);
        $gate->disable();
        $this->get('/plugins/sirsoft-sso/mock/oidc/.well-known/openid-configuration')->assertNotFound();
        $this->get('/plugins/sirsoft-sso/mock/oidc/jwks')->assertNotFound();

        $gate->enable(5);
        $this->get('/plugins/sirsoft-sso/mock/oidc/.well-known/openid-configuration')->assertOk()->assertJsonPath('code_challenge_methods_supported', ['S256']);
        $this->get('/plugins/sirsoft-sso/mock/oidc/jwks')->assertOk()->assertJsonCount(1, 'keys');
    }

    /**
     * 모의 게이트 활성 시간이 240분으로 제한되는지 확인한다.
     *
     * @scenario gate=enable, minutes=999
     *
     * @effects capped_at_240
     *
     * @return void 반환값 없음
     */
    public function test_enable_is_capped(): void
    {
        $until = app(MockGate::class)->enable(999);

        $this->assertLessThanOrEqual(time() + MockGate::MAX_MINUTES * 60, $until);
    }

    /**
     * 모의 명령이 게이트를 켜고 끄며 모의 IdP 행을 심는지 확인한다.
     *
     * @scenario command=enable+seed-idp|disable
     *
     * @effects gate_toggled, mock_idp_seeded
     *
     * @return void 반환값 없음
     */
    public function test_command_enables_and_seeds(): void
    {
        $this->artisan('sirsoft-sso:mock', ['--enable' => true, '--minutes' => 10, '--seed-idp' => true, '--domain' => 'corp798.test'])->assertSuccessful();

        $this->assertTrue(app(MockGate::class)->enabled());
        $idp = IdentityProvider::query()->where('key', MockCommand::MOCK_OIDC_KEY)->firstOrFail();
        $this->assertSame('oidc', $idp->protocol);
        $this->assertTrue($idp->isMock());
        $this->assertSame(['corp798.test'], $idp->owned_domains);

        $this->artisan('sirsoft-sso:mock', ['--disable' => true])->assertSuccessful();
        $this->assertFalse(app(MockGate::class)->enabled());
    }

    /**
     * 운영 환경에서 모의 명령을 거절하는지 확인한다.
     *
     * @scenario command=enable, env=production
     *
     * @effects refused
     *
     * @return void 반환값 없음
     */
    public function test_command_refused_in_production(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->artisan('sirsoft-sso:mock', ['--enable' => true])->assertFailed();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertFalse(app(MockGate::class)->enabled());
    }

    /**
     * 모의 back-channel 발신이 TLS 검증을 끄는지 확인한다 — 개발 사이트 자체 서명 인증서에서 cURL error 60 으로 실패했다(F1 M7 T7).
     *
     * 모의 IdP 디스커버리·JWKS(`OidcMetadata::http`)와 같은 범위다 — 명령 자체가 개발 전용이고 운영에서는 거절된다.
     *
     * @scenario command=backchannel, tls=self_signed
     *
     * @effects backchannel_sent_without_tls_verify
     *
     * @return void 반환값 없음
     */
    public function test_backchannel_command_skips_tls_verification(): void
    {
        $idp = $this->mockOidcIdp();
        $sent = [];
        Http::fake(function ($request, array $options) use (&$sent) {
            $sent[] = ['url' => $request->url(), 'verify' => $options['verify'] ?? null, 'token' => $request['logout_token'] ?? null];

            return Http::response('', 200);
        });

        $this->artisan('sirsoft-sso:mock', ['--backchannel' => $idp->key, '--sub' => 'kim-001'])->assertSuccessful();

        $this->assertCount(1, $sent);
        $this->assertStringEndsWith('/plugins/sirsoft-sso/oidc/'.$idp->key.'/backchannel-logout', $sent[0]['url']);
        $this->assertNotEmpty($sent[0]['token']);
        $this->assertFalse($sent[0]['verify'], '모의 back-channel 발신은 TLS 검증을 끈다');
    }

    /**
     * 모의 인가 화면이 남의 콜백 주소를 거절하는지 확인한다.
     *
     * @scenario route=mock_authorize, redirect_uri=foreign
     *
     * @effects bad_request
     *
     * @return void 반환값 없음
     */
    public function test_mock_authorize_rejects_foreign_redirect_uri(): void
    {
        app(MockGate::class)->enable(5);

        $this->get('/plugins/sirsoft-sso/mock/oidc/authorize?redirect_uri='.urlencode('https://evil.test/cb'))->assertStatus(400);
    }
}
