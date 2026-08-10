<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Unit\Services;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\DecryptException;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\RemoteCallException;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpCertClient;
use Plugins\Sirsoft\VerificationNhnkcp\Support\KcpCertCrypto;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * KCP REST 게이트웨이 요청 조립/응답 처리 검증.
 *
 * 요청 가로채기(Http::fake)는 응답만 대신할 뿐 요청 본문이 규격에 맞는지는 검증하지 않으므로,
 * 본 테스트는 전송된 본문을 직접 복호화해 KCP 규격대로 조립되었는지 확인한다.
 * (실제 KCP 테스트 서버 실호출로 규격 자체는 별도 확인함 — 여기서는 회귀 차단이 목적)
 *
 * @since 1.0.0
 */
class KcpCertClientTest extends PluginTestCase
{
    private const SITE_CD = 'AO7F3';

    private const ENC_KEY = 'c2a22fa3ebe4698075bcac6b433d52e351c881b02fb83488d4283a43385b1f8e';

    private const LIVE_SITE_CD = 'SMA1B2C';

    /**
     * @scenario mode=test,kcp_response=success
     *
     * @effects request_body_is_encrypted_and_decryptable_with_site_credentials
     */
    public function test_register_trade_sends_encrypted_body_with_site_headers(): void
    {
        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'call_url' => 'https://testcert.kcp.co.kr/certGateway.do',
                'reg_cert_key' => 'CERTKEY000000001',
            ]),
        ]);

        $result = $this->client()->registerTrade(
            ordrIdxx: 'G7-ORDER-0001',
            retUrl: 'https://example.test/callback',
            webSiteid: '',
            paramOpts: ['challenge-uuid-0001'],
        );

        $this->assertSame('0000', $result['res_cd']);
        $this->assertSame('CERTKEY000000001', $result['reg_cert_key']);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://testcert.kcp.co.kr/api/reg/certDataReg.do', $request->url());
            $this->assertSame(self::SITE_CD, $request->header('site_cd')[0] ?? null);

            $rv = $request->header('rv')[0] ?? '';
            $this->assertNotSame('', $rv, 'salt(rv) 헤더가 있어야 한다');

            // 전송 본문은 평문 JSON 이 아니라 암호문이어야 하며, 사이트 자격증명으로 복호화된다.
            $plain = KcpCertCrypto::decrypt($request->body(), $rv, self::ENC_KEY, self::SITE_CD);
            $payload = json_decode($plain, true);

            $this->assertSame(self::SITE_CD, $payload['site_cd']);
            $this->assertSame('G7-ORDER-0001', $payload['ordr_idxx']);
            $this->assertSame('https://example.test/callback', $payload['Ret_URL']);
            $this->assertSame('', $payload['web_siteid']);
            // 콜백이 거래키를 돌려주지 않는 경우를 대비해 challenge 식별자를 자유 파라미터로 동봉한다.
            $this->assertSame('challenge-uuid-0001', $payload['param_opt_1']);

            return true;
        });
    }

    /**
     * @scenario mode=live,kcp_response=success
     *
     * @effects live_mode_uses_live_host_and_prefixed_site_cd
     */
    public function test_live_mode_uses_live_host(): void
    {
        Http::fake([
            'cert.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'call_url' => 'https://cert.kcp.co.kr/certGateway.do',
                'reg_cert_key' => 'LIVEKEY000000001',
            ]),
        ]);

        $client = (new KcpCertClient)->withCredentials(self::LIVE_SITE_CD, 'live-enc-key', false);
        $result = $client->registerTrade('G7-LIVE-0001', 'https://example.test/callback');

        $this->assertSame('0000', $result['res_cd']);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringStartsWith('https://cert.kcp.co.kr/', $request->url());
            $this->assertSame(self::LIVE_SITE_CD, $request->header('site_cd')[0] ?? null);

            return true;
        });
    }

    /**
     * @scenario mode=test,kcp_response=http_error
     *
     * @effects http_error_raises_remote_call_exception_without_challenge_row
     */
    public function test_http_error_raises_remote_call_exception(): void
    {
        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response('gateway error', 502),
        ]);

        $this->expectException(RemoteCallException::class);

        $this->client()->registerTrade('G7-ORDER-0002', 'https://example.test/callback');
    }

    /**
     * @scenario mode=test,kcp_response=malformed
     *
     * @effects http_error_raises_remote_call_exception_without_challenge_row
     */
    public function test_non_json_response_raises_remote_call_exception(): void
    {
        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response('<html>maintenance</html>', 200),
        ]);

        $this->expectException(RemoteCallException::class);

        $this->client()->registerTrade('G7-ORDER-0003', 'https://example.test/callback');
    }

    /**
     * @scenario cert_query=success
     *
     * @effects success_issues_verification_token_and_marks_log_verified
     */
    public function test_fetch_cert_data_decrypts_identity_payload(): void
    {
        $identity = [
            'user_name' => '홍길동',
            'birth_day' => '19900102',
            'phone_no' => '01012345678',
            'comm_id' => 'SKT',
            'sex_code' => '01',
            'local_code' => '01',
            'CI' => 'CI-VALUE-0001',
            'DI' => 'DI-VALUE-0001',
        ];
        $encrypted = KcpCertCrypto::encrypt((string) json_encode($identity), self::ENC_KEY, self::SITE_CD);

        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'enc_cert_data' => $encrypted['enc_data'],
                'rv' => $encrypted['rv'],
            ]),
        ]);

        $result = $this->client()->fetchCertData('CERTKEY000000001', 'G7-ORDER-0001');

        $this->assertSame('0000', $result['res_cd']);
        $this->assertSame('홍길동', $result['user_name']);
        $this->assertSame('19900102', $result['birth_day']);
        $this->assertSame('01012345678', $result['phone_no']);
        $this->assertSame('CI-VALUE-0001', $result['ci']);
        $this->assertSame('DI-VALUE-0001', $result['di']);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://testcert.kcp.co.kr/api/query/getCertData.do', $request->url());
            // 결과조회 본문은 평문 JSON — 주문번호는 모바일 흐름 필수값이라 항상 전송한다.
            $payload = json_decode($request->body(), true);
            $this->assertSame('CERTKEY000000001', $payload['reg_cert_key']);
            $this->assertSame('G7-ORDER-0001', $payload['ordr_idxx']);

            return true;
        });
    }

    /**
     * @scenario cert_query=success,identifier_case=lowercase
     *
     * @effects success_issues_verification_token_and_marks_log_verified
     */
    public function test_fetch_cert_data_accepts_lowercase_identifier_keys(): void
    {
        $encrypted = KcpCertCrypto::encrypt(
            (string) json_encode(['user_name' => '김철수', 'ci' => 'ci-lower', 'di' => 'di-lower']),
            self::ENC_KEY,
            self::SITE_CD,
        );

        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'enc_cert_data' => $encrypted['enc_data'],
                'rv' => $encrypted['rv'],
            ]),
        ]);

        $result = $this->client()->fetchCertData('CERTKEY000000001', 'G7-ORDER-0001');

        $this->assertSame('ci-lower', $result['ci']);
        $this->assertSame('di-lower', $result['di']);
    }

    /**
     * @scenario cert_query=res_cd_error
     *
     * @effects provider_error_marks_log_failed_with_res_cd
     */
    public function test_fetch_cert_data_returns_error_code_without_identity(): void
    {
        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => 'CS16',
                'res_msg' => '거래등록이 올바르지 않습니다.',
            ]),
        ]);

        $result = $this->client()->fetchCertData('CERTKEY000000001', 'G7-ORDER-0001');

        $this->assertSame('CS16', $result['res_cd']);
        $this->assertArrayNotHasKey('user_name', $result);
    }

    /**
     * @scenario cert_query=decrypt_failure
     *
     * @effects decrypt_failure_returns_decrypt_failed
     */
    public function test_fetch_cert_data_raises_decrypt_exception_on_broken_payload(): void
    {
        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'enc_cert_data' => base64_encode('not-a-valid-cipher'),
                'rv' => base64_encode(random_bytes(16)),
            ]),
        ]);

        $this->expectException(DecryptException::class);

        $this->client()->fetchCertData('CERTKEY000000001', 'G7-ORDER-0001');
    }

    /**
     * 테스트 자격증명이 주입된 게이트웨이.
     */
    /**
     * 식별값이 표준 키에 없고 URL 인코딩 사본(`CI_URL`/`DI_URL`)에만 있어도 회수해야 한다.
     *
     * 식별값이 비면 중복 가입 차단이 조용히 무력화되므로, 규격 변형 응답에서도 잃지 않는다.
     *
     * @scenario cert_query=success,identifier_case=url_encoded
     *
     * @effects identifier_recovered_from_url_encoded_key
     */
    public function test_fetch_cert_data_recovers_identifiers_from_url_encoded_keys(): void
    {
        $encrypted = KcpCertCrypto::encrypt(
            (string) json_encode([
                'user_name' => '김철수',
                'CI' => '',
                'DI' => '',
                'CI_URL' => rawurlencode('CI+VALUE/0001='),
                'DI_URL' => rawurlencode('DI+VALUE/0001='),
            ]),
            self::ENC_KEY,
            self::SITE_CD,
        );

        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'enc_cert_data' => $encrypted['enc_data'],
                'rv' => $encrypted['rv'],
            ]),
        ]);

        $result = $this->client()->fetchCertData('CERTKEY000000001', 'G7-ORDER-0001');

        $this->assertSame('CI+VALUE/0001=', $result['ci']);
        $this->assertSame('DI+VALUE/0001=', $result['di']);
    }

    /**
     * 표준 키가 채워져 있으면 URL 인코딩 사본보다 우선한다.
     *
     * @scenario cert_query=success,identifier_case=uppercase
     *
     * @effects identifier_recovered_from_url_encoded_key
     */
    public function test_fetch_cert_data_prefers_plain_identifier_over_url_encoded(): void
    {
        $encrypted = KcpCertCrypto::encrypt(
            (string) json_encode([
                'user_name' => '김철수',
                'CI' => 'CI-PLAIN',
                'DI' => 'DI-PLAIN',
                'CI_URL' => rawurlencode('CI-ENCODED'),
                'DI_URL' => rawurlencode('DI-ENCODED'),
            ]),
            self::ENC_KEY,
            self::SITE_CD,
        );

        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'enc_cert_data' => $encrypted['enc_data'],
                'rv' => $encrypted['rv'],
            ]),
        ]);

        $result = $this->client()->fetchCertData('CERTKEY000000001', 'G7-ORDER-0001');

        $this->assertSame('CI-PLAIN', $result['ci']);
        $this->assertSame('DI-PLAIN', $result['di']);
    }

    /**
     * 규격 길이를 넘는 필드는 KCP 로 보내기 전에 막아야 한다.
     *
     * 넘긴 채 보내면 KCP 가 어느 필드가 문제인지 알려주지 않는 코드로 거절해 원인 추적이 어렵다.
     *
     * @param  string  $field  초과시킬 필드
     *
     * @scenario mode=test,kcp_response=res_cd_error
     *
     * @effects oversized_field_is_rejected_before_remote_call
     */
    #[DataProvider('oversizedFieldProvider')]
    public function test_register_trade_rejects_oversized_field_before_sending(string $field): void
    {
        Http::fake();

        $values = [
            'ordr_idxx' => 'G7-ORDER-0001',
            'Ret_URL' => 'https://example.test/callback',
            'web_siteid' => '',
        ];
        $values[$field] = str_repeat('X', $field === 'Ret_URL' ? 300 : ($field === 'ordr_idxx' ? 60 : 20));

        $this->expectException(RemoteCallException::class);

        try {
            $this->client()->registerTrade(
                ordrIdxx: $values['ordr_idxx'],
                retUrl: $values['Ret_URL'],
                webSiteid: $values['web_siteid'],
                paramOpts: ['challenge-uuid-0001'],
            );
        } finally {
            // 규격 위반은 요청을 보내기 전에 걸러져야 한다.
            Http::assertNothingSent();
        }
    }

    /**
     * 길이 초과 대상 필드.
     *
     * @return array<string, array{string}> 필드명 케이스
     */
    public static function oversizedFieldProvider(): array
    {
        return [
            'ordr_idxx 50자 초과' => ['ordr_idxx'],
            'Ret_URL 256자 초과' => ['Ret_URL'],
            'web_siteid 12자 초과' => ['web_siteid'],
        ];
    }

    private function client(): KcpCertClient
    {
        return (new KcpCertClient)->withCredentials(self::SITE_CD, self::ENC_KEY, true);
    }
}
