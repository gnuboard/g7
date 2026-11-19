<?php

namespace Plugins\Sirsoft\Sso\Drivers\Saml;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * RSA 개인키와 자체 서명 인증서 한 쌍을 만듭니다 (SAML SP 키, 모의 IdP 서명 키).
 *
 * openssl 이 기본 설정 파일을 찾지 못하는 서버(OPENSSL_CONF 미설정 — Windows 개발 PC 등)는 플러그인의 최소 설정
 * (`resources/openssl/minimal.cnf`)으로 다시 시도한다.
 *
 * @since 1.0.0
 */
final class X509KeyPair
{
    /**
     * @param  string  $certificate  인증서 PEM
     * @param  string  $privateKey  개인키 PEM
     * @param  CarbonImmutable  $expiresAt  인증서 만료 시각
     */
    public function __construct(
        public readonly string $certificate,
        public readonly string $privateKey,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    /**
     * 새 키 쌍을 만듭니다.
     *
     * @param  string  $commonName  인증서 CN
     * @param  int  $days  유효 일수 (1 이상)
     * @param  int  $bits  RSA 비트 수
     * @return self 키 쌍
     *
     * @throws RuntimeException 생성 실패
     */
    public static function generate(string $commonName, int $days = 3650, int $bits = 2048): self
    {
        $base = ['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $attempts = [$base, $base + ['config' => dirname(__DIR__, 3).'/resources/openssl/minimal.cnf']];
        $dn = ['commonName' => mb_substr($commonName, 0, 64)];

        $pair = null;
        foreach ($attempts as $options) {
            $pair = self::attempt($dn, $options, $days);
            if ($pair !== null) {
                break;
            }
        }

        while (openssl_error_string() !== false) {
            // openssl 오류 큐를 비운다 — 남겨 두면 뒤따르는 openssl 호출의 오류 판정이 섞인다.
        }

        if ($pair === null) {
            throw new RuntimeException('key pair generation failed');
        }

        return $pair;
    }

    /**
     * 인증서 본문만 꺼냅니다 (머리줄, 줄바꿈 없는 base64 — 메타데이터, 설정에 넣는 모양).
     *
     * @param  string  $pem  인증서 PEM
     * @return string 본문
     */
    public static function certificateBody(string $pem): string
    {
        return preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem) ?? '';
    }

    /**
     * 한 가지 설정으로 생성을 시도합니다.
     *
     * @param  array<string, string>  $dn  주체
     * @param  array<string, mixed>  $options  openssl 설정
     * @param  int  $days  유효 일수
     * @return self|null 키 쌍, 실패면 null
     */
    private static function attempt(array $dn, array $options, int $days): ?self
    {
        $key = @openssl_pkey_new($options);
        if ($key === false) {
            return null;
        }

        $csr = @openssl_csr_new($dn, $key, $options);
        if ($csr === false) {
            return null;
        }

        $x509 = @openssl_csr_sign($csr, null, $key, max(1, $days), $options, random_int(1, PHP_INT_MAX));
        if ($x509 === false) {
            return null;
        }

        $certificate = '';
        $privateKey = '';
        if (! @openssl_x509_export($x509, $certificate) || ! @openssl_pkey_export($key, $privateKey, null, $options)) {
            return null;
        }

        $parsed = openssl_x509_parse($certificate);
        $expires = CarbonImmutable::createFromTimestampUTC((int) ($parsed['validTo_time_t'] ?? time()));

        return new self($certificate, $privateKey, $expires);
    }
}
