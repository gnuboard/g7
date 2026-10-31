<?php

namespace Plugins\Sirsoft\SocialLogin\Support\Jwt;

/**
 * JWT 서명 검증 — RS256 만 허용합니다.
 *
 * 헤더의 `alg` 는 믿지 않는다. `none` 이나 `HS256`(공개키를 HMAC 비밀로 쓰게 만드는 공격)을 헤더로 바꿔 보내도
 * 이 검증기는 늘 RSA-SHA256 으로만 대조하고, 헤더가 RS256 이 아니면 바로 거절한다.
 *
 * @since 1.0.0
 */
final class Rs256Verifier
{
    /**
     * 토큰 서명을 검증합니다.
     *
     * @param  string  $jwt  compact JWT (header.payload.signature)
     * @param  string  $publicKeyPem  PEM 공개키
     * @return bool 서명이 맞으면 true
     */
    public static function verify(string $jwt, string $publicKeyPem): bool
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || $parts[2] === '') {
            return false;
        }

        $header = json_decode(Base64Url::decode($parts[0]), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
            return false;
        }

        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            return false;
        }

        return openssl_verify($parts[0].'.'.$parts[1], Base64Url::decode($parts[2]), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 헤더를 디코딩합니다 (서명 검증 전 kid 를 고르는 용도).
     *
     * @param  string  $jwt  compact JWT
     * @return array<string, mixed> 헤더 (형식이 틀리면 빈 배열)
     */
    public static function header(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        $header = json_decode(Base64Url::decode($parts[0]), true);

        return is_array($header) ? $header : [];
    }
}
