<?php

namespace Plugins\Sirsoft\SocialLogin\Support;

use RuntimeException;

/**
 * RSA 개인키 생성.
 *
 * `openssl_pkey_new()` 는 openssl 설정 파일을 찾지 못하면 실패한다(Windows PHP 묶음에서 `OPENSSL_CONF` 가 비어 있는 경우가 흔하다).
 * 그때는 플러그인에 둔 최소 설정 파일로 한 번 더 시도한다.
 *
 * @since 1.0.0
 */
final class RsaKeyFactory
{
    /**
     * 2048비트 RSA 개인키 PEM 을 만듭니다.
     *
     * @return string 개인키 PEM
     *
     * @throws RuntimeException 두 번 모두 실패할 때
     */
    public static function generatePem(): string
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $fallback = $options + ['config' => dirname(__DIR__, 2).'/resources/openssl/minimal.cnf'];

        foreach ([$options, $fallback] as $attempt) {
            $key = @openssl_pkey_new($attempt);
            if ($key !== false && @openssl_pkey_export($key, $pem, null, $attempt)) {
                return $pem;
            }
        }

        while (openssl_error_string() !== false) {
            // openssl 오류 큐를 비운다 — 남겨 두면 뒤따르는 openssl 호출의 오류 판정이 섞인다.
        }

        throw new RuntimeException('rsa_key_generation_failed');
    }
}
