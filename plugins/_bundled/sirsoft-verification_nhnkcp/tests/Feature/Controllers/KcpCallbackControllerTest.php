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

    /**
     * 테스트 모드 사이트코드·암호화 키로 플러그인 설정을 저장합니다.
     *
     * @return void 반환값 없음
     */
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
     * 성공 콜백은 검증 토큰을 실어 브리지로 이동하고, 이름·전화번호 같은 개인정보는 주소에 싣지 않는다.
     *
     * @scenario outcome=success,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects success_issues_verification_token_and_marks_log_verified
     *
     * @return void 반환값 없음
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
     * 사용자 취소(`res_cd=9999`) 콜백은 오류 코드 9999 를 실어 브리지로 이동한다.
     *
     * @scenario outcome=cancelled,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects cancelled_marks_log_cancelled_with_9999_code
     *
     * @return void 반환값 없음
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
     * 등록되지 않은 거래키로 온 콜백은 NOT_FOUND 오류 코드를 실어 브리지로 이동한다.
     *
     * @scenario outcome=not_found,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects unknown_transaction_returns_not_found
     *
     * @return void 반환값 없음
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
     * 브리지 페이지는 토큰 결과를 사이트 출처의 부모창에만 전달하고 자기 주소 출처를 쓰지 않는다.
     *
     * @scenario outcome=success,lookup=by_param_opt,missing_field=none
     *
     * @effects bridge_query_carries_no_personal_information
     *
     * @return void 반환값 없음
     */
    public function test_bridge_page_relays_result_to_opener_without_personal_information(): void
    {
        $response = $this->get(self::BRIDGE_URL.'?verification_token=tok-123&challenge_id=chal-123');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertStringContainsString('identity_result', (string) $html);
        $this->assertStringContainsString('tok-123', (string) $html);
        // 부모창 전달은 사이트 origin 으로 제한되어야 한다(브리지 자신의 주소가 아니라 사이트 주소).
        $this->assertStringContainsString('}, SITE_ORIGIN);', (string) $html);
        $this->assertStringNotContainsString('window.location.origin', (string) $html);
    }

    /**
     * 배열 주입(`?verification_token[]=x`)은 KcpBridgeRequest 의 string 규칙이 422 로
     * 차단해야 한다 — 종전 `(string)` 캐스팅은 배열을 "Array" 문자열로 바꿔 브리지
     * payload 에 유입시켰다. 브리지가 선언된 문자열 결과 필드만 전달한다는 계약의 일부다.
     *
     * @scenario outcome=success,lookup=by_param_opt,missing_field=none
     *
     * @effects bridge_rejects_non_string_result_field_with_422
     *
     * @return void 반환값 없음
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
     * 실패 결과에 실린 challenge_id 도 재시도를 위해 그대로 부모창에 전달된다.
     *
     * @scenario outcome=cancelled,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects failure_bridge_query_carries_challenge_id_for_retry
     *
     * @return void 반환값 없음
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
     *
     * KCP 테스트 결과조회 호출에 암호화된 신원 정보를 담은 정상 응답을 돌려준다.
     *
     * @return void 반환값 없음
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
     * @return IdentityVerificationLog 거래키와 연결된 인증 대기 challenge 로그
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
