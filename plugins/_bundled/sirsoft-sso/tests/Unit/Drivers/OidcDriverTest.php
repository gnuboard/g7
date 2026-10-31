<?php

namespace Plugins\Sirsoft\Sso\Tests\Unit\Drivers;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Sso\Drivers\Oidc\OidcDriver;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcVariants;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Services\StateStore;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * OIDC 드라이버 — 모의 IdP 변종 카탈로그(부록 C-1) 전수. 행 수 = 변종 수(18).
 *
 * 인가 주소 → 모의 승인 → 콜백(code, state) → 토큰 교환 → id_token 검증 → 신원까지 드라이버를 실제로 밟는다.
 *
 * @since 1.0.0
 */
class OidcDriverTest extends PluginTestCase
{
    /**
     * 변종별 기대값 — null 이면 성공(신원 단언은 테스트 본문), 문자열이면 그 오류 코드.
     *
     * @return array<string, array{0: string, 1: string|null}> 변종 → 기대 오류
     */
    public static function variants(): array
    {
        return [
            'full' => ['full', null],
            'mfa' => ['mfa', null],
            'acr_mfa' => ['acr_mfa', null],
            'no_email' => ['no_email', null],
            'email_unverified' => ['email_unverified', null],
            'email_verified_string' => ['email_verified_string', null],
            'groups_string' => ['groups_string', null],
            'nonce_mismatch' => ['nonce_mismatch', 'id_token_invalid'],
            'aud_other' => ['aud_other', 'id_token_invalid'],
            'azp_missing_multi_aud' => ['azp_missing_multi_aud', 'id_token_invalid'],
            'alg_none' => ['alg_none', 'id_token_invalid'],
            'alg_hs256' => ['alg_hs256', 'id_token_invalid'],
            'expired' => ['expired', 'id_token_invalid'],
            'iat_future' => ['iat_future', 'id_token_invalid'],
            'kid_rotated' => ['kid_rotated', null],
            'deny' => ['deny', 'access_denied'],
            'token_error' => ['token_error', 'token_error'],
            'refresh_invalid' => ['refresh_invalid', null],
        ];
    }

    /**
     * 레지스트리의 OIDC 드라이버 — 요청 단위로 하나라 마지막 refresh_token 을 기억한다.
     *
     * @return OidcDriver 드라이버
     */
    private function driver(): OidcDriver
    {
        return app(ProtocolRegistry::class)->get('oidc');
    }

    /**
     * 변종 하나로 드라이버 왕복을 돌립니다.
     *
     * @param  IdentityProvider  $idp  모의 IdP
     * @param  string  $variant  변종
     * @return ExternalIdentity 신원
     */
    private function complete(IdentityProvider $idp, string $variant): ExternalIdentity
    {
        $driver = $this->driver();
        $intent = app(StateStore::class)->create([
            'intent' => LoginIntent::LOGIN, 'guard' => 'user', 'redirect' => '/', 'idp_id' => $idp->id, 'idp_key' => $idp->key,
        ], str_repeat('a', 64));

        $authorize = $driver->beginLogin($idp, $intent);
        $callback = $this->approveAtMock((string) $authorize->headers->get('Location'), $variant, ['email' => 'kim798@corp798.test', 'sub' => 'emp-0001']);

        return $driver->completeLogin($idp, Request::create('/cb', 'GET', $callback), $intent);
    }

    /**
     * 모의 IdP 변종마다 드라이버가 신원 또는 오류 코드를 내는지 확인한다.
     *
     * @scenario protocol=oidc, variant=appendix_c1
     *
     * @effects identity_or_error_code
     *
     * @param  string  $variant  모의 IdP 변종
     * @param  string|null  $expectedError  기대 오류 코드 (null 이면 성공)
     * @return void 반환값 없음
     */
    #[DataProvider('variants')]
    public function test_variant(string $variant, ?string $expectedError): void
    {
        $idp = $this->mockOidcIdp(['config' => ['mfa_acr_values' => [MockOidcVariants::MFA_ACR]]]);

        if ($variant === 'kid_rotated') {
            // 검증 쪽이 주 키만 담긴 JWKS 를 먼저 캐시하게 한 뒤 회전 키로 서명된 토큰을 받는다.
            $this->complete($idp, 'full');
        }

        if ($expectedError !== null) {
            try {
                $this->complete($idp, $variant);
                $this->fail('expected '.$expectedError);
            } catch (SsoException $e) {
                $this->assertSame($expectedError, $e->errorCode);
            }

            return;
        }

        $jwksBefore = $this->mockCalls['/plugins/sirsoft-sso/mock/oidc/jwks'] ?? 0;
        $identity = $this->complete($idp, $variant);
        $this->assertSame('emp-0001', $identity->subject);

        match ($variant) {
            'full' => $this->assertFullIdentity($identity),
            'mfa', 'acr_mfa' => $this->assertTrue($identity->mfa),
            'no_email' => $this->assertNull($identity->email),
            'email_unverified' => $this->assertFalse($identity->emailVerified),
            'email_verified_string' => $this->assertTrue($identity->emailVerified),
            'groups_string' => $this->assertSame(['sales', 'g7-users'], $identity->groups),
            'kid_rotated' => $this->assertSame($jwksBefore + 1, $this->mockCalls['/plugins/sirsoft-sso/mock/oidc/jwks'], 'JWKS 는 kid 불일치로 한 번만 다시 받는다'),
            'refresh_invalid' => $this->assertRefreshRejected($idp),
        };
    }

    /**
     * full 변종의 신원을 단언합니다.
     *
     * @param  ExternalIdentity  $identity  검증된 신원
     * @return void 반환값 없음
     */
    private function assertFullIdentity(ExternalIdentity $identity): void
    {
        $this->assertSame('kim798@corp798.test', $identity->email);
        $this->assertTrue($identity->emailVerified);
        $this->assertSame('김직원', $identity->displayName);
        $this->assertSame(MockOidcVariants::GROUPS, $identity->groups);
        $this->assertFalse($identity->mfa, 'amr=pwd 만 있으면 다중 인증 아님');
        $this->assertNotNull($identity->sessionRef);
    }

    /**
     * refresh_invalid 변종의 refresh_token 갱신이 비활성으로 판정되는지 단언합니다 (주기 점검).
     *
     * @param  IdentityProvider  $idp  모의 IdP
     * @return void 반환값 없음
     */
    private function assertRefreshRejected(IdentityProvider $idp): void
    {
        $driver = $this->driver();
        $token = $driver->pullRefreshToken();
        $this->assertNotNull($token);
        $this->assertSame(['active' => false, 'refresh_token' => null], $driver->refresh($idp, $token));
    }

    /**
     * 유효한 refresh_token 갱신이 활성으로 판정되고 새 토큰을 받는지 확인한다.
     *
     * @scenario protocol=oidc, refresh=valid
     *
     * @effects refresh_rotates_token
     *
     * @return void 반환값 없음
     */
    public function test_refresh_valid_token_rotates(): void
    {
        $idp = $this->mockOidcIdp();
        $this->complete($idp, 'full');

        $driver = $this->driver();
        $token = (string) $driver->pullRefreshToken();
        $result = $driver->refresh($idp, $token);

        $this->assertTrue($result['active']);
        $this->assertNotSame($token, $result['refresh_token']);
    }

    /**
     * 지정한 subject 클레임과 그룹 클레임을 읽는지 확인한다.
     *
     * @scenario protocol=oidc, subject_claim=custom
     *
     * @effects subject_from_configured_claim
     *
     * @return void 반환값 없음
     */
    public function test_subject_claim_and_groups_from_claims(): void
    {
        $idp = $this->mockOidcIdp(['config' => ['subject_claim' => 'oid']]);
        $identity = $this->driver()->identityFromClaims($idp, ['sub' => 'app-scoped', 'oid' => 'object-1', 'groups' => 'a b  a']);

        $this->assertSame('object-1', $identity->subject);
        $this->assertSame(['a', 'b'], $identity->groups);
        $this->assertNull($identity->mfa, 'amr, acr 둘 다 없으면 알 수 없음');

        $this->expectException(SsoException::class);
        $this->driver()->identityFromClaims($idp, ['sub' => 'x']);
    }

    /**
     * 인가 주소가 PKCE, nonce, state 를 싣는지 확인한다.
     *
     * @scenario protocol=oidc, endpoint=authorize
     *
     * @effects pkce_s256_nonce_state_sent
     *
     * @return void 반환값 없음
     */
    public function test_begin_login_sends_pkce_nonce_and_state(): void
    {
        $idp = $this->mockOidcIdp();
        $intent = app(StateStore::class)->create([
            'intent' => LoginIntent::LOGIN, 'guard' => 'user', 'redirect' => '/', 'idp_id' => $idp->id, 'idp_key' => $idp->key,
        ], str_repeat('a', 64));

        $location = (string) $this->driver()->beginLogin($idp, $intent)->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        $this->assertSame($intent->stateId, $q['state']);
        $this->assertSame($intent->nonce, $q['nonce']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(OidcDriver::codeChallenge($intent->codeVerifier), $q['code_challenge']);
        $this->assertSame(OidcDriver::callbackUrl($idp), $q['redirect_uri']);
        $this->assertStringContainsString('openid', $q['scope']);
    }
}
