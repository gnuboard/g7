<?php

namespace Plugins\Sirsoft\SocialLogin\Support\Jwt;

/**
 * id_token 클레임 검사.
 *
 * 서명이 맞아도 다른 앱(aud)·다른 발급자(iss)·지난 토큰(exp)·다른 왕복의 토큰(nonce)이면 거절한다.
 * 시각 비교는 60초 여유를 둔다 — 서버 시계가 조금 어긋나도 정상 로그인이 실패하지 않게.
 *
 * @since 1.0.0
 */
final class JwtClaims
{
    /** 시계 오차 허용(초) */
    public const LEEWAY_SECONDS = 60;

    /**
     * 페이로드를 디코딩합니다.
     *
     * @param  string  $jwt  compact JWT
     * @return array<string, mixed> 클레임 (형식이 틀리면 빈 배열)
     */
    public static function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        $claims = json_decode(Base64Url::decode($parts[1]), true);

        return is_array($claims) ? $claims : [];
    }

    /**
     * 클레임을 검사하고 실패 사유 코드를 돌려줍니다.
     *
     * @param  array<string, mixed>  $claims  클레임
     * @param  string|list<string>  $issuer  기대 발급자 (제공자가 여러 형태를 공식으로 쓰면 그 목록 — 하나와 같으면 통과)
     * @param  string  $audience  기대 수신자(client_id)
     * @param  string|null  $nonce  기대 nonce (null 이면 검사하지 않음)
     * @param  int|null  $now  기준 시각(유닉스 초, 테스트용)
     * @return string|null 실패 코드 — null 이면 통과
     */
    public static function validate(array $claims, string|array $issuer, string $audience, ?string $nonce, ?int $now = null): ?string
    {
        $now ??= time();

        if (! isset($claims['sub']) || ! is_scalar($claims['sub']) || (string) $claims['sub'] === '') {
            return 'id_token_sub_missing';
        }

        $iss = rtrim((string) ($claims['iss'] ?? ''), '/');
        $issuerMatched = false;
        foreach ((array) $issuer as $expected) {
            if (hash_equals(rtrim((string) $expected, '/'), $iss)) {
                $issuerMatched = true;
            }
        }
        if (! $issuerMatched) {
            return 'id_token_iss_mismatch';
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array($audience, array_map('strval', array_filter($audiences, 'is_scalar')), true)) {
            return 'id_token_aud_mismatch';
        }

        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] + self::LEEWAY_SECONDS < $now) {
            return 'id_token_expired';
        }

        if (is_numeric($claims['iat'] ?? null) && (int) $claims['iat'] - self::LEEWAY_SECONDS > $now) {
            return 'id_token_issued_in_future';
        }

        if ($nonce !== null && ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            return 'id_token_nonce_mismatch';
        }

        return null;
    }
}
