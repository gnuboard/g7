<?php

namespace Plugins\Sirsoft\Sso\Tests\Unit\Services;

use Plugins\Sirsoft\Sso\Console\Commands\MockCommand;
use Plugins\Sirsoft\Sso\Drivers\Oidc\IdTokenValidator;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcKeys;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * id_token 검증 — 알고리즘 허용 목록, iss, aud/azp, exp/iat 오차, nonce, at_hash, kid 회전 1회 갱신, back-channel logout_token.
 *
 * @since 1.0.0
 */
class IdTokenValidationTest extends PluginTestCase
{
    /** @var IdentityProvider 모의 IdP */
    private IdentityProvider $idp;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->idp = $this->mockOidcIdp();
    }

    /**
     * 기본 클레임.
     *
     * @param  array<string, mixed>  $override  바꿀 값
     * @return array<string, mixed> 클레임
     */
    private function claims(array $override = []): array
    {
        return array_merge([
            'iss' => MockOidcController::issuer(),
            'aud' => MockCommand::MOCK_CLIENT_ID,
            'sub' => 'emp-1',
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => 'n-1',
        ], $override);
    }

    /**
     * 검증합니다.
     *
     * @param  string  $jwt  검증할 토큰
     * @param  string|null  $accessToken  같은 응답의 접근 토큰
     * @return array<string, mixed> 클레임
     */
    private function validate(string $jwt, ?string $accessToken = null): array
    {
        return app(IdTokenValidator::class)->validate($this->idp, $jwt, 'n-1', $accessToken);
    }

    /**
     * 실패를 단언합니다.
     *
     * @param  string  $jwt  검증할 토큰
     * @param  string|null  $accessToken  같은 응답의 접근 토큰
     * @return void 반환값 없음
     */
    private function assertInvalid(string $jwt, ?string $accessToken = null): void
    {
        try {
            $this->validate($jwt, $accessToken);
            $this->fail('expected id_token_invalid');
        } catch (SsoException $e) {
            $this->assertSame('id_token_invalid', $e->errorCode);
        }
    }

    /**
     * 정상 id_token 의 클레임을 돌려주는지 확인한다.
     *
     * @scenario token=valid
     *
     * @effects claims_returned
     *
     * @return void 반환값 없음
     */
    public function test_valid_token(): void
    {
        $this->assertSame('emp-1', $this->validate(app(MockOidcKeys::class)->sign($this->claims()))['sub']);
    }

    /**
     * none, HS256 알고리즘 토큰을 거절하는지 확인한다.
     *
     * @scenario alg=none|HS256
     *
     * @effects rejected_before_signature
     *
     * @return void 반환값 없음
     */
    public function test_algorithm_allow_list(): void
    {
        $keys = app(MockOidcKeys::class);
        $this->assertInvalid($keys->unsigned($this->claims()));
        $this->assertInvalid($keys->signHs256WithPublicKey($this->claims()));
    }

    /**
     * 발급자, 수신자, azp 불일치를 거절하는지 확인한다.
     *
     * @scenario claim=iss|aud|azp
     *
     * @effects rejected
     *
     * @return void 반환값 없음
     */
    public function test_issuer_audience_and_azp(): void
    {
        $keys = app(MockOidcKeys::class);
        $this->assertInvalid($keys->sign($this->claims(['iss' => 'https://other.test'])));
        $this->assertInvalid($keys->sign($this->claims(['aud' => 'other'])));
        $this->assertInvalid($keys->sign($this->claims(['aud' => [MockCommand::MOCK_CLIENT_ID, 'other']])));
        $this->assertSame('emp-1', $this->validate($keys->sign($this->claims(['aud' => [MockCommand::MOCK_CLIENT_ID, 'other'], 'azp' => MockCommand::MOCK_CLIENT_ID])))['sub']);
    }

    /**
     * exp, iat 를 허용 오차 안팎으로 판정하는지 확인한다.
     *
     * @scenario claim=exp|iat, leeway=120
     *
     * @effects within_leeway_ok, beyond_rejected
     *
     * @return void 반환값 없음
     */
    public function test_time_claims_with_leeway(): void
    {
        $keys = app(MockOidcKeys::class);
        $this->assertSame('emp-1', $this->validate($keys->sign($this->claims(['exp' => time() - 60])))['sub'], '120초 오차 안');
        $this->assertInvalid($keys->sign($this->claims(['exp' => time() - 300])));
        $this->assertSame('emp-1', $this->validate($keys->sign($this->claims(['iat' => time() + 60])))['sub']);
        $this->assertInvalid($keys->sign($this->claims(['iat' => time() + 600])));
        $this->assertInvalid($keys->sign(array_diff_key($this->claims(), ['exp' => 1])));
    }

    /**
     * nonce, at_hash 불일치를 거절하는지 확인한다.
     *
     * @scenario claim=nonce|at_hash
     *
     * @effects rejected_on_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_nonce_and_at_hash(): void
    {
        $keys = app(MockOidcKeys::class);
        $this->assertInvalid($keys->sign($this->claims(['nonce' => 'other'])));
        $this->assertInvalid($keys->sign(array_diff_key($this->claims(), ['nonce' => 1])));

        $atHash = IdTokenValidator::atHash('access-1', 'RS256');
        $this->assertSame('emp-1', $this->validate($keys->sign($this->claims(['at_hash' => $atHash])), 'access-1')['sub']);
        $this->assertInvalid($keys->sign($this->claims(['at_hash' => $atHash])), 'access-2');
    }

    /**
     * kid 회전 때 JWKS 를 한 번만 다시 받는지 확인한다.
     *
     * @scenario jwks=rotated|unknown_kid
     *
     * @effects jwks_refetched_once
     *
     * @return void 반환값 없음
     */
    public function test_kid_rotation_refetches_jwks_once(): void
    {
        $keys = app(MockOidcKeys::class);
        $this->validate($keys->sign($this->claims()));
        $before = $this->mockCalls['/plugins/sirsoft-sso/mock/oidc/jwks'];

        $this->assertSame('emp-1', $this->validate($keys->sign($this->claims(), rotated: true))['sub']);
        $this->assertSame($before + 1, $this->mockCalls['/plugins/sirsoft-sso/mock/oidc/jwks']);

        // 같은 회전 키는 이제 캐시에 있다 — 더 받지 않는다.
        $this->validate($keys->sign($this->claims(), rotated: true));
        $this->assertSame($before + 1, $this->mockCalls['/plugins/sirsoft-sso/mock/oidc/jwks']);
    }

    /**
     * back-channel logout_token 규칙을 확인한다.
     *
     * @scenario token=logout_token
     *
     * @effects backchannel_claims_checked
     *
     * @return void 반환값 없음
     */
    public function test_logout_token_rules(): void
    {
        $keys = app(MockOidcKeys::class);
        $validator = app(IdTokenValidator::class);
        $base = ['iss' => MockOidcController::issuer(), 'aud' => MockCommand::MOCK_CLIENT_ID, 'iat' => time(), 'jti' => 'j-1', 'sid' => 's-1',
            'events' => [IdTokenValidator::BACKCHANNEL_EVENT => new \stdClass]];

        $this->assertSame('s-1', $validator->validateLogoutToken($this->idp, $keys->sign($base))['sid']);

        foreach ([['events' => []], ['nonce' => 'x'], ['sid' => null], ['jti' => null]] as $change) {
            $claims = array_filter(array_merge($base, $change), static fn ($v) => $v !== null);
            try {
                $validator->validateLogoutToken($this->idp, $keys->sign($claims));
                $this->fail('expected rejection: '.json_encode(array_keys($change)));
            } catch (SsoException $e) {
                $this->assertSame('id_token_invalid', $e->errorCode);
            }
        }
    }
}
