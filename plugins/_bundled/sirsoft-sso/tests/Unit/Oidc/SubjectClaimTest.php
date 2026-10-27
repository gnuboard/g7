<?php

namespace Plugins\Sirsoft\Sso\Tests\Unit\Oidc;

use Plugins\Sirsoft\Sso\Drivers\Oidc\OidcDriver;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * OIDC subject 클레임 선택(W31) — `sub` 기본, Entra ID 처럼 앱마다 `sub` 가 다른 IdP 는 `oid`, 그 밖은 지정 클레임.
 *
 * @since 1.0.0
 */
class SubjectClaimTest extends PluginTestCase
{
    /** @var array<string, mixed> 검증을 마친 클레임 표본 */
    private const CLAIMS = ['sub' => 'pairwise-app-sub', 'oid' => 'object-id-0001', 'employee_id' => 'E-777', 'email' => 'Kim@Corp798.test'];

    /**
     * 설정만 다른 IdP.
     *
     * @param  array<string, mixed>  $config  설정
     * @return IdentityProvider IdP (저장하지 않음)
     */
    private function idp(array $config): IdentityProvider
    {
        return new IdentityProvider(['key' => 'corp_oidc', 'protocol' => 'oidc', 'config' => $config]);
    }

    /**
     * 레지스트리의 OIDC 드라이버.
     *
     * @return OidcDriver 드라이버
     */
    private function driver(): OidcDriver
    {
        return app(ProtocolRegistry::class)->get('oidc');
    }

    /**
     * `sub`(기본), `oid`, 지정 클레임마다 subject 가 그 클레임 값이고 형식에 클레임 이름이 남는다.
     *
     * @scenario subject_claim=sub|oid|custom
     *
     * @effects subject_from_configured_claim
     *
     * @return void 반환값 없음
     */
    public function test_subject_follows_configured_claim(): void
    {
        $this->assertSame('pairwise-app-sub', $this->driver()->identityFromClaims($this->idp([]), self::CLAIMS)->subject);

        $oid = $this->driver()->identityFromClaims($this->idp(['subject_claim' => 'oid']), self::CLAIMS);
        $this->assertSame('object-id-0001', $oid->subject);
        $this->assertSame('oid', $oid->subjectFormat);

        $custom = $this->driver()->identityFromClaims($this->idp(['subject_claim' => 'custom', 'subject_claim_custom' => 'employee_id']), self::CLAIMS);
        $this->assertSame('E-777', $custom->subject);
        $this->assertSame('kim@corp798.test', $custom->email);
    }

    /**
     * 고른 클레임이 없으면 다른 클레임으로 대신하지 않고 거절한다(엉뚱한 계정 연결 방지).
     *
     * @scenario subject_claim=oid, claim=missing
     *
     * @effects rejected_id_token_invalid
     *
     * @return void 반환값 없음
     */
    public function test_missing_selected_claim_is_rejected(): void
    {
        try {
            $this->driver()->identityFromClaims($this->idp(['subject_claim' => 'oid']), ['sub' => 'only-sub']);
            $this->fail('oid 가 없는데 통과했다');
        } catch (SsoException $e) {
            $this->assertSame('id_token_invalid', $e->errorCode);
        }
    }
}
