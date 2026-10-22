<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Unit\Support\Jwt;

use Plugins\Sirsoft\SocialLogin\Support\Jwt\Base64Url;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\JwkToPem;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\JwtClaims;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\Rs256Verifier;
use Plugins\Sirsoft\SocialLogin\Tests\Unit\Drivers\DriverTestCase;

/**
 * JWT 지원 시험 — JWK→PEM 변환, RS256 서명 검증(변조·alg=none·HS256 거부), 클레임 실패 사유.
 *
 * @since 1.0.0
 */
class JwtSupportTest extends DriverTestCase
{
    /**
     * JWK 를 PEM 으로 바꾼 공개키가 원래 키와 같은지 확인한다.
     *
     * @scenario jwt=jwk_to_pem
     * @effects jwk_converts_to_same_public_key
     * @return void 반환값 없음
     */
    public function test_jwk_converts_to_the_same_public_key(): void
    {
        $expected = openssl_pkey_get_details(openssl_pkey_get_private(self::privateKey()))['key'];

        $pem = JwkToPem::convert(self::jwk());

        $this->assertSame(
            openssl_pkey_get_details(openssl_pkey_get_public($expected))['rsa']['n'],
            openssl_pkey_get_details(openssl_pkey_get_public($pem))['rsa']['n'],
        );
    }

    /**
     * RSA 가 아닌 JWK 를 거절하는지 확인한다.
     *
     * @scenario jwt=jwk_to_pem
     * @effects non_rsa_jwk_rejected
     * @return void 반환값 없음
     */
    public function test_non_rsa_jwk_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JwkToPem::convert(['kty' => 'EC', 'crv' => 'P-256', 'x' => 'a', 'y' => 'b']);
    }

    /**
     * 올바른 RS256 서명을 받아들이는지 확인한다.
     *
     * @scenario jwt=signature
     * @effects valid_signature_accepted
     * @return void 반환값 없음
     */
    public function test_valid_signature_is_accepted(): void
    {
        $jwt = self::signJwt(['sub' => 'a']);

        $this->assertTrue(Rs256Verifier::verify($jwt, JwkToPem::convert(self::jwk())));
    }

    /**
     * 변조, alg=none, HS256 으로 바꾼 토큰을 거절하는지 확인한다.
     *
     * @scenario jwt=signature, attack=tampered_payload|alg_none|alg_hs256
     * @effects forged_tokens_rejected
     * @return void 반환값 없음
     */
    public function test_tampered_and_downgraded_tokens_are_rejected(): void
    {
        $pem = JwkToPem::convert(self::jwk());
        [$h, , $s] = explode('.', self::signJwt(['sub' => 'a']));

        $tampered = $h.'.'.Base64Url::encode(json_encode(['sub' => 'admin'])).'.'.$s;
        $this->assertFalse(Rs256Verifier::verify($tampered, $pem), '페이로드 변조');

        $none = Base64Url::encode(json_encode(['alg' => 'none'])).'.'.Base64Url::encode(json_encode(['sub' => 'a'])).'.';
        $this->assertFalse(Rs256Verifier::verify($none, $pem), 'alg=none');

        $hsHeader = Base64Url::encode(json_encode(['alg' => 'HS256']));
        $hsPayload = Base64Url::encode(json_encode(['sub' => 'a']));
        $hs = $hsHeader.'.'.$hsPayload.'.'.Base64Url::encode(hash_hmac('sha256', $hsHeader.'.'.$hsPayload, $pem, true));
        $this->assertFalse(Rs256Verifier::verify($hs, $pem), '공개키를 HMAC 비밀로 쓴 HS256');
    }

    /**
     * 클레임 실패 사유 표.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string|null, 2: string|null}> 사례 이름 => 인자 목록
     */
    public static function claims(): array
    {
        $now = 1_800_000_000;
        $base = ['sub' => 'u1', 'iss' => 'https://idp.example.test', 'aud' => 'client-123', 'exp' => $now + 300, 'iat' => $now, 'nonce' => 'n1'];

        return [
            '통과' => [$base, 'n1', null],
            '배열 aud 통과' => [['aud' => ['other', 'client-123']] + $base, 'n1', null],
            'sub 없음' => [array_diff_key($base, ['sub' => 1]), 'n1', 'id_token_sub_missing'],
            'iss 다름' => [['iss' => 'https://evil.example.test'] + $base, 'n1', 'id_token_iss_mismatch'],
            'aud 다름' => [['aud' => 'other'] + $base, 'n1', 'id_token_aud_mismatch'],
            '만료(여유 60초 넘김)' => [['exp' => $now - 61] + $base, 'n1', 'id_token_expired'],
            '만료 여유 안' => [['exp' => $now - 59] + $base, 'n1', null],
            '미래 발급' => [['iat' => $now + 120] + $base, 'n1', 'id_token_issued_in_future'],
            'nonce 다름' => [$base, 'other', 'id_token_nonce_mismatch'],
        ];
    }

    /**
     * id_token 클레임 검사가 실패 사유를 맞게 돌려주는지 확인한다.
     *
     * @scenario jwt=claims
     * @effects claim_failures_reported
     * @param  array  $claims  데이터 제공자가 준 값
     * @param  string|null  $nonce  데이터 제공자가 준 값
     * @param  string  $expected  데이터 제공자가 준 값
     * @return void 반환값 없음
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('claims')]
    public function test_claim_validation(array $claims, ?string $nonce, ?string $expected): void
    {
        $this->assertSame($expected, JwtClaims::validate($claims, 'https://idp.example.test', 'client-123', $nonce, 1_800_000_000));
    }
}
