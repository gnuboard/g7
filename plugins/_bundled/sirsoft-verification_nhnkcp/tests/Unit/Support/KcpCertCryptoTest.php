<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Unit\Support;

use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\DecryptException;
use Plugins\Sirsoft\VerificationNhnkcp\Support\KcpCertCrypto;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * KCP 본인확인 V2 암복호화 규격 검증.
 *
 * 암호화 파라미터가 KCP 서버와 1비트라도 어긋나면 거래등록 자체가 실패하므로, 왕복 검증뿐 아니라
 * 규격 그대로를 독립적으로 재현한 참조 구현(PBKDF2-SHA256 10000회 32B 키 / 사이트코드 파생 IV 앞
 * 16B / AES-256-CBC) 과 결과가 일치하는지도 대조한다 — 상수가 조용히 바뀌면 이 대조가 깨진다.
 *
 * @since 1.0.0
 */
class KcpCertCryptoTest extends PluginTestCase
{
    /** KCP 공개 테스트 사이트코드 */
    private const SITE_CD = 'AO7F3';

    /** KCP 공개 테스트 암호화 키 */
    private const ENC_KEY = 'c2a22fa3ebe4698075bcac6b433d52e351c881b02fb83488d4283a43385b1f8e';

    /**
     * @scenario crypto_case=roundtrip
     *
     * @effects ciphertext_round_trips_with_site_credentials
     */
    public function test_encrypt_and_decrypt_roundtrip(): void
    {
        $plain = json_encode(['site_cd' => self::SITE_CD, 'ordr_idxx' => 'G7-0001'], JSON_UNESCAPED_SLASHES);

        $encrypted = KcpCertCrypto::encrypt((string) $plain, self::ENC_KEY, self::SITE_CD);

        $this->assertNotEmpty($encrypted['enc_data']);
        $this->assertNotEmpty($encrypted['rv']);
        $this->assertSame(16, strlen((string) base64_decode($encrypted['rv'], true)), 'salt 는 16바이트여야 한다');

        $decrypted = KcpCertCrypto::decrypt($encrypted['enc_data'], $encrypted['rv'], self::ENC_KEY, self::SITE_CD);

        $this->assertSame($plain, $decrypted);
    }

    /**
     * @scenario crypto_case=spec_reference
     *
     * @effects derivation_matches_kcp_specification_reference
     */
    public function test_cipher_matches_kcp_specification_reference(): void
    {
        $plain = '{"site_cd":"AO7F3","ordr_idxx":"G7-SPEC"}';

        $encrypted = KcpCertCrypto::encrypt($plain, self::ENC_KEY, self::SITE_CD);
        $salt = (string) base64_decode($encrypted['rv'], true);

        // KCP 규격을 그대로 재현한 참조 구현 — 구현체 상수 변경 시 이 대조가 깨진다.
        $key = hash_pbkdf2('sha256', self::ENC_KEY, $salt, 10000, 32, true);
        $iv = substr(hash_pbkdf2('sha256', self::SITE_CD, $salt, 10000, 32, true), 0, 16);
        $expected = base64_encode((string) openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));

        $this->assertSame($expected, $encrypted['enc_data']);
    }

    /**
     * @scenario crypto_case=salt_uniqueness
     *
     * @effects each_encryption_uses_a_fresh_salt
     */
    public function test_each_encryption_uses_a_new_salt(): void
    {
        $first = KcpCertCrypto::encrypt('same-plain-text', self::ENC_KEY, self::SITE_CD);
        $second = KcpCertCrypto::encrypt('same-plain-text', self::ENC_KEY, self::SITE_CD);

        $this->assertNotSame($first['rv'], $second['rv'], '매 요청마다 새 salt 를 사용해야 한다');
        $this->assertNotSame($first['enc_data'], $second['enc_data']);
    }

    /**
     * @scenario crypto_case=wrong_key
     *
     * @effects decrypt_rejects_wrong_encryption_key
     */
    public function test_decrypt_with_wrong_key_throws(): void
    {
        $encrypted = KcpCertCrypto::encrypt('payload', self::ENC_KEY, self::SITE_CD);

        $this->expectException(DecryptException::class);

        KcpCertCrypto::decrypt($encrypted['enc_data'], $encrypted['rv'], 'wrong-enc-key', self::SITE_CD);
    }

    /**
     * @scenario crypto_case=wrong_site_cd
     *
     * @effects decrypt_rejects_wrong_site_code
     */
    public function test_decrypt_with_wrong_site_code_throws(): void
    {
        $encrypted = KcpCertCrypto::encrypt('payload', self::ENC_KEY, self::SITE_CD);

        $this->expectException(DecryptException::class);

        // 사이트코드는 IV 파생 소스이므로 다르면 복호화가 성립하지 않는다.
        KcpCertCrypto::decrypt($encrypted['enc_data'], $encrypted['rv'], self::ENC_KEY, 'OTHER');
    }

    /**
     * @scenario crypto_case=malformed_payload
     *
     * @effects decrypt_rejects_malformed_payload
     */
    public function test_decrypt_with_empty_payload_throws(): void
    {
        $this->expectException(DecryptException::class);

        KcpCertCrypto::decrypt('', '', self::ENC_KEY, self::SITE_CD);
    }
}
