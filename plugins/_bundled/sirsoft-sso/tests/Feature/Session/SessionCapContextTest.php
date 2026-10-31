<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Session;

use App\Models\User;
use Illuminate\Support\Carbon;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;
use Plugins\Sirsoft\Sso\Services\SessionService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;
use Plugins\Sirsoft\Sso\Tests\Support\SsoLoginHelpers;

/**
 * 세션 기록과 수명 상한(D7 ④) — 로그인마다 `sso_sessions` 1행 + 토큰 id 연결, 상한은 IdP 값과 사이트 값 중 작은 값.
 *
 * @since 1.0.0
 */
class SessionCapContextTest extends PluginTestCase
{
    use ProvisioningTestHelpers;
    use SsoLoginHelpers;

    /**
     * 로그인 확정 때 세션 행이 생기고 로그인 완료 뒤 토큰 id 가 붙는다. 신원의 세션 참조, NameID 를 남긴다.
     *
     * @scenario identity=session_ref+name_id
     *
     * @effects sso_session_row, token_attached, external_ref_in_context
     *
     * @return void 반환값 없음
     */
    public function test_session_row_and_token_attached(): void
    {
        $idp = $this->makeIdp();
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 'sess-1');

        $login = $this->ssoLogin($user, $account, $idp, new ExternalIdentity(subject: 'sess-1', sessionRef: 'sid-abc', nameId: 'nameid-1'));

        $session = SsoSession::query()->where('user_id', $user->id)->sole();
        $this->assertSame(SessionService::EXTERNAL_REF_PREFIX.$session->id, $login['context']['external_ref']);
        $this->assertSame($login['token_id'], $session->token_id);
        $this->assertSame('sid-abc', $session->external_session);
        $this->assertSame('nameid-1', $session->name_id);
        $this->assertNull($session->ended_at);
    }

    /**
     * 상한 — 둘 다 끄면 없음, IdP 값만, 사이트 값과 IdP 값 중 작은 값. 토큰 절대 만료가 상한을 따른다.
     *
     * @scenario idp_cap=null|480|60, site_cap=0|120
     *
     * @effects max_session_minutes, absolute_expires_at
     *
     * @return void 반환값 없음
     */
    public function test_cap_is_minimum_of_idp_and_site(): void
    {
        $service = app(SessionService::class);

        $this->assertNull($service->sessionCap($this->makeIdp(['max_session_minutes' => null])));
        $this->assertSame(480, $service->sessionCap($this->makeIdp(['max_session_minutes' => 480])));

        $this->partialMock(PolicyService::class, function ($mock): void {
            $mock->shouldReceive('get')->andReturnUsing(fn (string $key, mixed $default = null) => $key === 'session_max_minutes' ? 120 : $default);
        });

        $this->assertSame(120, $service->sessionCap($this->makeIdp(['max_session_minutes' => 480])));
        $this->assertSame(60, $service->sessionCap($this->makeIdp(['max_session_minutes' => 60])));
        $this->assertSame(120, $service->sessionCap($this->makeIdp(['max_session_minutes' => null])));

        Carbon::setTestNow(now());
        $idp = $this->makeIdp(['max_session_minutes' => 60]);
        $user = User::factory()->create();
        $login = $this->ssoLogin($user, $this->link($user, $idp, 'cap-1'), $idp);
        $this->assertSame(60, $login['context']['max_session_minutes']);
        $token = $user->tokens()->whereKey($login['token_id'])->sole();
        $this->assertSame(now()->addMinutes(60)->toDateTimeString(), Carbon::parse($token->absolute_expires_at)->toDateTimeString());
        Carbon::setTestNow();
    }
}
