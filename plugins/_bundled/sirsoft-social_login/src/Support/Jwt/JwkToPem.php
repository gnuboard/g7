<?php

namespace Plugins\Sirsoft\SocialLogin\Support\Jwt;

use InvalidArgumentException;

/**
 * RSA JWK(`kty=RSA`, `n`, `e`)를 PEM 공개키로 바꿉니다.
 *
 * openssl 확장은 JWK 를 직접 읽지 못한다. SubjectPublicKeyInfo DER 을 손으로 조립해 PEM 으로 감싼다.
 *
 * @since 1.0.0
 */
final class JwkToPem
{
    /** rsaEncryption OID(1.2.840.113549.1.1.1) + NULL 매개변수 */
    private const RSA_ALGORITHM_IDENTIFIER = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

    /**
     * JWK 를 PEM 공개키로 바꿉니다.
     *
     * @param  array<string, mixed>  $jwk  JWK
     * @return string PEM 공개키
     *
     * @throws InvalidArgumentException RSA 키가 아니거나 n·e 가 없을 때
     */
    public static function convert(array $jwk): string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || ! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
            throw new InvalidArgumentException('jwk_not_rsa');
        }

        $modulus = Base64Url::decode($jwk['n']);
        $exponent = Base64Url::decode($jwk['e']);

        if ($modulus === '' || $exponent === '') {
            throw new InvalidArgumentException('jwk_empty_component');
        }

        $rsaPublicKey = self::sequence(self::integer($modulus).self::integer($exponent));
        $bitString = "\x03".self::length(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;
        $spki = self::sequence(self::RSA_ALGORITHM_IDENTIFIER.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /**
     * DER INTEGER 를 만듭니다 — 최상위 비트가 1 이면 음수로 읽히지 않게 0x00 을 앞에 붙입니다.
     *
     * @param  string  $bytes  부호 없는 큰 정수 바이트
     * @return string DER INTEGER
     */
    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::length(strlen($bytes)).$bytes;
    }

    /**
     * DER SEQUENCE 로 감쌉니다.
     *
     * @param  string  $content  내용
     * @return string DER SEQUENCE
     */
    private static function sequence(string $content): string
    {
        return "\x30".self::length(strlen($content)).$content;
    }

    /**
     * DER 길이 바이트를 만듭니다.
     *
     * @param  int  $length  길이
     * @return string 길이 인코딩
     */
    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
