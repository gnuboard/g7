<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Support;

use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\DecryptException;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\EncryptException;

/**
 * NHN KCP 본인확인 V2 REST 규격의 양방향 암복호화 helper.
 *
 * KCP 규격 (거래등록 요청 / 결과조회 응답 공통):
 *  - 알고리즘: AES-256-CBC (raw binary)
 *  - salt: 16바이트 난수. 요청 시 우리가 생성하여 `rv` 헤더/필드로 base64 전달하고,
 *          응답 복호화 시에는 KCP 가 내려준 `rv` 를 base64 디코드하여 사용한다.
 *  - key: PBKDF2-HMAC-SHA256(enc_key, salt, 10000회, 32바이트)
 *  - iv : PBKDF2-HMAC-SHA256(site_cd, salt, 10000회, 32바이트) 의 앞 16바이트
 *
 * 암호화 파라미터가 KCP 서버와 1비트라도 어긋나면 거래등록 자체가 실패하므로, 상수는
 * 클래스 내부에 고정하고 외부 설정으로 노출하지 않는다.
 *
 * @since 1.0.0
 */
class KcpCertCrypto
{
    /** PBKDF2 해시 알고리즘 (KCP 규격 고정값) */
    private const PBKDF2_ALGO = 'sha256';

    /** PBKDF2 반복 횟수 (KCP 규격 고정값) */
    private const PBKDF2_ITERATIONS = 10000;

    /** PBKDF2 파생 키 길이 — 바이트 (KCP 규격 고정값) */
    private const PBKDF2_LENGTH = 32;

    /** 대칭키 알고리즘 (KCP 규격 고정값) */
    private const CIPHER = 'AES-256-CBC';

    /** salt 길이 — 바이트 (KCP 규격 고정값) */
    private const SALT_LENGTH = 16;

    /** IV 길이 — 바이트 (AES 블록 크기) */
    private const IV_LENGTH = 16;

    /**
     * 평문 JSON 을 KCP 규격으로 암호화한다.
     *
     * @param  string  $plain  암호화할 평문 (거래등록 요청 JSON)
     * @param  string  $encKey  가맹점 암호화 키 (설정값)
     * @param  string  $siteCd  가맹점 사이트 코드 (IV 파생 소스)
     * @return array{enc_data: string, rv: string} base64 인코딩된 암호문과 salt
     *
     * @throws EncryptException 암호화 실패 시
     */
    public static function encrypt(string $plain, string $encKey, string $siteCd): array
    {
        $salt = random_bytes(self::SALT_LENGTH);

        $cipherText = openssl_encrypt(
            $plain,
            self::CIPHER,
            self::deriveKey($encKey, $salt),
            OPENSSL_RAW_DATA,
            self::deriveIv($siteCd, $salt),
        );

        if ($cipherText === false) {
            throw new EncryptException(openssl_error_string() ?: 'openssl_encrypt failed');
        }

        return [
            'enc_data' => base64_encode($cipherText),
            'rv' => base64_encode($salt),
        ];
    }

    /**
     * KCP 응답 암호문을 복호화한다.
     *
     * @param  string  $encData  base64 인코딩된 암호문 (`enc_cert_data`)
     * @param  string  $rv  base64 인코딩된 salt (응답 `rv`)
     * @param  string  $encKey  가맹점 암호화 키 (설정값)
     * @param  string  $siteCd  가맹점 사이트 코드 (IV 파생 소스)
     * @return string 복호화된 평문 JSON
     *
     * @throws DecryptException 복호화 실패 시
     */
    public static function decrypt(string $encData, string $rv, string $encKey, string $siteCd): string
    {
        $salt = base64_decode($rv, true);
        $cipherText = base64_decode($encData, true);

        if ($salt === false || $cipherText === false || $salt === '' || $cipherText === '') {
            throw new DecryptException('enc_cert_data');
        }

        $plain = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            self::deriveKey($encKey, $salt),
            OPENSSL_RAW_DATA,
            self::deriveIv($siteCd, $salt),
        );

        if ($plain === false || $plain === '') {
            throw new DecryptException('enc_cert_data');
        }

        return $plain;
    }

    /**
     * 대칭키를 파생한다 — PBKDF2(enc_key, salt).
     *
     * @param  string  $encKey  가맹점 암호화 키
     * @param  string  $salt  16바이트 salt (raw)
     * @return string 32바이트 raw 키
     */
    private static function deriveKey(string $encKey, string $salt): string
    {
        return hash_pbkdf2(self::PBKDF2_ALGO, $encKey, $salt, self::PBKDF2_ITERATIONS, self::PBKDF2_LENGTH, true);
    }

    /**
     * IV 를 파생한다 — PBKDF2(site_cd, salt) 의 앞 16바이트.
     *
     * @param  string  $siteCd  가맹점 사이트 코드
     * @param  string  $salt  16바이트 salt (raw)
     * @return string 16바이트 raw IV
     */
    private static function deriveIv(string $siteCd, string $salt): string
    {
        $derived = hash_pbkdf2(self::PBKDF2_ALGO, $siteCd, $salt, self::PBKDF2_ITERATIONS, self::PBKDF2_LENGTH, true);

        return substr($derived, 0, self::IV_LENGTH);
    }
}
