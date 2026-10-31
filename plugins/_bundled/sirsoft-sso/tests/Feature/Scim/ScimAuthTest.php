<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Scim;

use Plugins\Sirsoft\Sso\Exceptions\ScimException;
use Plugins\Sirsoft\Sso\Models\SsoScimEvent;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * SCIM Bearer 인증 — 토큰 없음, 틀림 401 / SCIM 꺼짐 403 / 재발급 뒤 이전 토큰 무효 / 모든 수신을 이력에 (본문 없이).
 *
 * @since 1.0.0
 */
class ScimAuthTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 토큰이 없거나 틀리면 401 + SCIM 오류 스키마.
     *
     * @scenario token=missing|wrong
     *
     * @effects scim_401, scim_event_recorded
     *
     * @return void 반환값 없음
     */
    public function test_missing_or_wrong_token_is_401_with_scim_error(): void
    {
        $idp = $this->makeIdp();
        $this->issueScimToken($idp);

        $missing = $this->scim('GET', $idp, '/Users', null);
        $missing->assertStatus(401)->assertJsonPath('schemas.0', ScimException::ERROR_SCHEMA)->assertJsonPath('status', '401');
        $this->assertStringContainsString('application/scim+json', (string) $missing->headers->get('Content-Type'));
        $this->assertStringContainsString('Bearer', (string) $missing->headers->get('WWW-Authenticate'));

        $this->scim('GET', $idp, '/Users', 'wrong-token')->assertStatus(401);

        $this->assertSame(2, SsoScimEvent::query()->where('idp_id', $idp->id)->where('status', 401)->count());
    }

    /**
     * SCIM 을 끈 IdP 는 토큰이 맞아도 403.
     *
     * @scenario scim_enabled=false
     *
     * @effects scim_403
     *
     * @return void 반환값 없음
     */
    public function test_scim_disabled_is_403(): void
    {
        $idp = $this->makeIdp(['deprovision' => ['scim' => false]]);
        $token = $this->issueScimToken($idp);

        $this->scim('GET', $idp, '/Users', $token)->assertStatus(403)->assertJsonPath('schemas.0', ScimException::ERROR_SCHEMA);
    }

    /**
     * 토큰을 다시 발급하면 이전 토큰은 즉시 무효.
     *
     * @scenario token=reissued
     *
     * @effects old_token_401, new_token_200
     *
     * @return void 반환값 없음
     */
    public function test_reissued_token_invalidates_previous(): void
    {
        $idp = $this->makeIdp();
        $old = $this->issueScimToken($idp);
        $this->scim('GET', $idp, '/Users', $old)->assertOk();

        $new = $this->issueScimToken($idp);
        $this->scim('GET', $idp, '/Users', $old)->assertStatus(401);
        $this->scim('GET', $idp, '/Users', $new)->assertOk();
    }

    /**
     * 다른 IdP 의 토큰으로는 이 IdP 에 들어오지 못한다.
     *
     * @scenario token=other_idp
     *
     * @effects scim_401
     *
     * @return void 반환값 없음
     */
    public function test_token_of_other_idp_is_rejected(): void
    {
        $a = $this->makeIdp();
        $b = $this->makeIdp();
        $tokenA = $this->issueScimToken($a);
        $this->issueScimToken($b);

        $this->scim('GET', $b, '/Users', $tokenA)->assertStatus(401);
    }

    /**
     * 수신 이력에는 메서드, 자원, 대상, 상태, 오류 종류만 남는다.
     *
     * @scenario request=users_get_missing
     *
     * @effects scim_event_has_no_body
     *
     * @return void 반환값 없음
     */
    public function test_event_records_resource_and_status_without_body(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);

        $this->scim('GET', $idp, '/Users/999999', $token)->assertStatus(404);

        $event = SsoScimEvent::query()->where('idp_id', $idp->id)->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('GET', $event->method);
        $this->assertSame('Users', $event->resource);
        $this->assertSame('999999', $event->target_id);
        $this->assertSame(404, $event->status);
        $this->assertSame(['id', 'idp_id', 'method', 'resource', 'target_id', 'status', 'error_code', 'created_at'], array_keys($event->getAttributes()));
    }
}
