<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use App\Models\User;
use Plugins\Sirsoft\Sso\Drivers\Oidc\OidcDriver;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 동의·연결 확인 대기를 거친 첫 OIDC 로그인도 refresh 토큰을 보관한다 — 회원 결정이 다음 요청(완료 API)에서 일어나도 콜백 때 받은 토큰이
 * 대기 값을 따라가 주기 점검 저장(`sirsoft-sso.account.after_resolve` 구독)에 닿는지(F1 실측 — 대기를 거치면 `refresh_token IS NULL`).
 *
 * @since 1.0.0
 */
class RefreshTokenAcrossPendingTest extends PluginTestCase
{
    /** 주기 점검을 켠 IdP 설정 */
    private const PERIODIC = ['deprovision' => ['periodic' => true, 'periodic_minutes' => 60]];

    /**
     * 다음 요청이 새 프로세스인 것처럼 드라이버 인스턴스를 버립니다.
     *
     * 시험은 한 프로세스에서 여러 요청을 보내 컨테이너 싱글턴(`OidcDriver`)이 요청 사이에 살아남는다 — 운영에서는 완료 요청이 새 프로세스라
     * 콜백 요청의 드라이버 필드가 비어 있다. 이 차이를 지우지 않으면 결함이 시험에서 재현되지 않는다.
     *
     * @return void 반환값 없음
     */
    private function startNextRequestInFreshProcess(): void
    {
        $this->app->forgetInstance(OidcDriver::class);
        $this->app->forgetScopedInstances();
        app(ProtocolRegistry::class)->flush();
    }

    /**
     * JIT 동의 대기를 완료한 첫 로그인의 연결 행에 refresh 토큰이 남는지 확인한다.
     *
     * @scenario pending=consent, deprovision=periodic
     *
     * @effects refresh_token_stored_after_pending
     *
     * @return void 반환값 없음
     */
    public function test_consent_completion_keeps_refresh_token(): void
    {
        $idp = $this->mockOidcIdp(self::PERIODIC);
        $email = $this->uniqueEmail('corp798.test');

        $response = $this->roundTrip($idp, 'full', ['sub' => 'rt-consent', 'email' => $email]);
        $token = $this->redirectQuery($response)['sso_pending'] ?? null;
        $this->assertNotNull($token, '동의 대기로 간다');

        $this->startNextRequestInFreshProcess();
        $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/pending/'.$token.'/complete', ['agree_terms' => true, 'agree_privacy' => true])->assertOk();

        $account = SsoAccount::query()->where('idp_id', $idp->id)->where('subject', 'rt-consent')->firstOrFail();
        $this->assertIsString($account->refresh_token);
        $this->assertNotSame('', $account->refresh_token);
    }

    /**
     * 기존 회원 연결 확인(비밀번호)을 완료한 첫 로그인의 연결 행에 refresh 토큰이 남는지, 대기 정보 응답에는 실리지 않는지 확인한다.
     *
     * @scenario pending=link, deprovision=periodic
     *
     * @effects refresh_token_stored_after_pending, refresh_token_not_exposed
     *
     * @return void 반환값 없음
     */
    public function test_link_verification_keeps_refresh_token(): void
    {
        $idp = $this->mockOidcIdp(self::PERIODIC);
        $user = User::factory()->create(['email' => $this->uniqueEmail('corp798.test'), 'password' => 'Secret-798!']);

        $response = $this->roundTrip($idp, 'full', ['sub' => 'rt-link', 'email' => $user->email]);
        $token = $this->redirectQuery($response)['sso_link'] ?? null;
        $this->assertNotNull($token, '연결 확인 대기로 간다');

        $info = $this->withBrowserBind()->getJson('/api/plugins/sirsoft-sso/link/'.$token)->assertOk();
        $this->assertStringNotContainsString('refresh', (string) $info->getContent());

        $this->startNextRequestInFreshProcess();
        $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/link/'.$token.'/verify', ['method' => 'password', 'password' => 'Secret-798!'])->assertOk();

        $account = SsoAccount::query()->where('idp_id', $idp->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertIsString($account->refresh_token);
        $this->assertNotSame('', $account->refresh_token);
    }
}
