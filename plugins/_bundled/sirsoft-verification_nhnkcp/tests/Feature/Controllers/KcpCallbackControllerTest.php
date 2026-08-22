<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Controllers;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpCertTransactionRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Support\KcpCertCrypto;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 콜백 수신 → 브리지 전달 경로 검증 (HTTP 관점).
 *
 * 외부 인증기관이 보내는 form POST 는 CSRF 토큰이 없으므로 면제되어야 하고, 결과는 브리지
 * 페이지로 넘어가되 개인정보는 주소에 실리지 않아야 한다.
 *
 * @since 1.0.0
 */
class KcpCallbackControllerTest extends PluginTestCase
{
    private const CALLBACK_URL = '/plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/callback';

    private const BRIDGE_URL = '/plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/bridge';

    private const SITE_CD = 'AO7F3';

    private const ENC_KEY = 'c2a22fa3ebe4698075bcac6b433d52e351c881b02fb83488d4283a43385b1f8e';

    private const REG_CERT_KEY = 'CERTKEY000000001';

    protected function setUp(): void
    {
        parent::setUp();

        app(PluginSettingsService::class)->save(self::PLUGIN_IDENTIFIER, [
            'is_test_mode' => true,
            'test_site_cd' => self::SITE_CD,
            'test_enc_key' => self::ENC_KEY,
        ]);
    }

    /**
     * @scenario outcome=success,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects success_issues_verification_token_and_marks_log_verified
     */
    public function test_successful_callback_redirects_to_bridge_with_token(): void
    {
        $log = $this->createChallenge();
        $this->fakeCertQuery();

        $response = $this->post(self::CALLBACK_URL, [
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'reg_cert_key' => self::REG_CERT_KEY,
            'param_opt_1' => $log->id,
        ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringContainsString(self::BRIDGE_URL, (string) $location);
        $this->assertStringContainsString('verification_token=', (string) $location);
        $this->assertStringNotContainsString('홍길동', (string) $location);
        $this->assertStringNotContainsString('01012345678', (string) $location);
    }

    /**
     * @scenario outcome=cancelled,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects cancelled_marks_log_cancelled_with_9999_code
     */
    public function test_cancelled_callback_redirects_with_error_code(): void
    {
        $this->createChallenge();
        Http::fake();

        $response = $this->post(self::CALLBACK_URL, [
            'res_cd' => '9999',
            'res_msg' => '사용자취소',
            'reg_cert_key' => self::REG_CERT_KEY,
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('identity_error=9999', (string) $response->headers->get('Location'));
    }

    /**
     * @scenario outcome=not_found,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects unknown_transaction_returns_not_found
     */
    public function test_unknown_callback_redirects_with_not_found(): void
    {
        $response = $this->post(self::CALLBACK_URL, [
            'res_cd' => '0000',
            'reg_cert_key' => 'UNKNOWN',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('identity_error=NOT_FOUND', (string) $response->headers->get('Location'));
    }

    /**
     * @scenario outcome=success,lookup=by_param_opt,missing_field=none
     *
     * @effects bridge_query_carries_no_personal_information
     */
    public function test_bridge_page_relays_result_to_opener_without_personal_information(): void
    {
        $response = $this->get(self::BRIDGE_URL.'?verification_token=tok-123&challenge_id=chal-123');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertStringContainsString('identity_result', (string) $html);
        $this->assertStringContainsString('tok-123', (string) $html);
        // 부모창 전달은 동일 origin 으로 제한되어야 한다.
        $this->assertStringContainsString('window.location.origin', (string) $html);
    }

    /**
     * 배열 주입(`?verification_token[]=x`)은 KcpBridgeRequest 의 string 규칙이 422 로
     * 차단해야 한다 — 종전 `(string)` 캐스팅은 배열을 "Array" 문자열로 바꿔 브리지
     * payload 에 유입시켰다.
     */
    public function test_bridge_rejects_array_verification_token_with_422(): void
    {
        $response = $this->getJson(self::BRIDGE_URL.'?verification_token[]=x');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['verification_token']);
    }

    /**
     * 정상 쿼리의 identity_error / identity_error_message 는 브리지 HTML payload 에
     * 그대로 실려야 한다 (KcpBridgeRequest 주입 후 bridgePayload() 경유 회귀 방지).
     */
    public function test_bridge_renders_identity_error_message_passthrough(): void
    {
        $response = $this->get(self::BRIDGE_URL.'?'.http_build_query([
            'challenge_id' => 'chal-456',
            'identity_error' => '9999',
            'identity_error_message' => '사용자취소',
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = (string) $response->getContent();
        $this->assertStringContainsString('identity_result', $html);
        $this->assertStringContainsString('"identity_error":"9999"', $html);
        $this->assertStringContainsString('사용자취소', $html);
        $this->assertStringContainsString('"challenge_id":"chal-456"', $html);
    }

    /**
     * 결과조회 응답을 가로챈다.
     */
    private function fakeCertQuery(): void
    {
        $encrypted = KcpCertCrypto::encrypt((string) json_encode([
            'user_name' => '홍길동',
            'birth_day' => '19900101',
            'phone_no' => '01012345678',
            'comm_id' => 'SKT',
            'sex_code' => '01',
            'local_code' => '01',
            'CI' => 'CI-CTRL-0001',
            'DI' => 'DI-CTRL-0001',
        ]), self::ENC_KEY, self::SITE_CD);

        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'enc_cert_data' => $encrypted['enc_data'],
                'rv' => $encrypted['rv'],
            ]),
        ]);
    }

    /**
     * 인증 대기 challenge + 거래 행을 만든다.
     *
     * @return IdentityVerificationLog
     */
    private function createChallenge()
    {
        $log = app(IdentityVerificationLogRepositoryInterface::class)->create([
            'id' => (string) Str::uuid(),
            'provider_id' => KcpIdentityProvider::PROVIDER_ID,
            'purpose' => 'self_update',
            'channel' => KcpIdentityProvider::CHANNEL,
            'user_id' => User::factory()->create()->id,
            'target_hash' => hash('sha256', 'controller@example.test'),
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => 'api.identity.verify',
            'verification_token' => null,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['ordr_idxx' => 'G7-CTRL-0001'],
        ]);

        app(KcpCertTransactionRepositoryInterface::class)
            ->create('G7-CTRL-0001', self::REG_CERT_KEY, $log->id);

        return $log;
    }
}
