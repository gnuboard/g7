<?php

namespace Plugins\Sirsoft\SocialLogin\Support\Jwt;

/**
 * base64url 인코딩 (RFC 4648 §5, 패딩 없음).
 *
 * @since 1.0.0
 */
final class Base64Url
{
    /**
     * base64url 문자열을 디코딩합니다.
     *
     * @param  string  $value  인코딩 문자열
     * @return string 원본 바이트 (형식이 틀리면 빈 문자열)
     */
    public static function decode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * 바이트를 base64url 로 인코딩합니다.
     *
     * @param  string  $bytes  원본 바이트
     * @return string 인코딩 문자열
     */
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
