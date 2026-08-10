<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Identity;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Models\KcpCertTransaction;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpCertTransactionRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpCallbackResolver;
use Plugins\Sirsoft\VerificationNhnkcp\Support\KcpCertCrypto;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 콜백 판정 파이프라인 통합 검증.
 *
 * 실제 훅 체인(코어 IDV Service → provider verify) + 실제 DB 를 사용하고, 외부 통신만 가로챈다.
 * 어떤 분기든 브리지로 전달할 결과 코드로 끝나며 개인정보는 주소에 실리지 않아야 한다.
 *
 * @since 1.0.0
 */
class KcpCallbackResolverTest extends PluginTestCase
{
    private const SITE_CD = 'AO7F3';

    private const ENC_KEY = 'c2a22fa3ebe4698075bcac6b433d52e351c881b02fb83488d4283a43385b1f8e';

    private const REG_CERT_KEY = 'CERTKEY000000001';

    private const ORDR_IDXX = 'G7-ORDER-0001';

    private IdentityVerificationLogRepositoryInterface $logRepository;

    private KcpCertTransactionRepositoryInterface $transactionRepository;

    private KcpCallbackResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->transactionRepository = app(KcpCertTransactionRepositoryInterface::class);
        $this->resolver = app(KcpCallbackResolver::class);

        // 레지스트리의 provider 가 테스트 자격증명으로 동작하도록 플러그인 설정을 심는다.
        app(PluginSettingsService::class)->save(self::PLUGIN_IDENTIFIER, [
            'is_test_mode' => true,
            'test_site_cd' => self::SITE_CD,
            'test_enc_key' => self::ENC_KEY,
            'duplicate_field' => 'di',
            'duplicate_block_enabled' => true,
        ]);
    }

    /**
     * @scenario outcome=success,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects success_issues_verification_token_and_marks_log_verified
     */
    public function test_successful_callback_issues_token(): void
    {
        $log = $this->createChallenge(User::factory()->create()->id);
        $this->fakeCertQuery($this->identityPayload());

        $outcome = $this->resolver->resolve([
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'reg_cert_key' => self::REG_CERT_KEY,
            'param_opt_1' => $log->id,
        ]);

        $this->assertTrue($outcome->success);
        $this->assertSame($log->id, $outcome->challengeId);
        $this->assertNotEmpty($outcome->verificationToken);
        $this->assertTrue($this->logRepository->findById($log->id)->isVerified());
    }

    /**
     * @scenario outcome=success,lookup=by_param_opt,missing_field=none
     *
     * @effects lookup_by_param_opt_when_reg_cert_key_absent
     */
    public function test_lookup_falls_back_to_param_opt_challenge_id(): void
    {
        $log = $this->createChallenge(User::factory()->create()->id);
        $this->fakeCertQuery($this->identityPayload());

        // 콜백이 거래키를 돌려주지 않는 경우 — 거래등록 시 실어 보낸 challenge_id 로 역조회한다.
        $outcome = $this->resolver->resolve([
            'res_cd' => '0000',
            'param_opt_1' => $log->id,
        ]);

        $this->assertTrue($outcome->success);
        $this->assertSame($log->id, $outcome->challengeId);
    }

    /**
     * @scenario outcome=not_found,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects unknown_transaction_returns_not_found
     */
    public function test_unknown_transaction_returns_not_found(): void
    {
        $outcome = $this->resolver->resolve([
            'res_cd' => '0000',
            'reg_cert_key' => 'UNKNOWN-KEY',
        ]);

        $this->assertFalse($outcome->success);
        $this->assertSame('NOT_FOUND', $outcome->failureCode);
        // 거래를 못 찾은 경우에는 알려줄 challenge 가 없다 — 실패 코드만 전달한다.
        $this->assertSame(['identity_error' => 'NOT_FOUND'], $outcome->toBridgeQuery());
    }

    /**
     * 실패 결과도 어느 challenge 가 끝났는지 화면에 알려야 한다.
     *
     * 한 번 콜백이 처리된 거래는 KCP 쪽에서 종료되어 재사용할 수 없다. 화면은 이 신호를 받아야
     * 재시도 시 새 거래를 발급받는다. 신호가 없으면 죽은 거래키로 인증창을 다시 열어
     * "이미 종료된 거래" 로 실패하며, 사용자는 몇 번을 눌러도 진행할 수 없다.
     *
     * @scenario outcome=incomplete_identity,lookup=by_reg_cert_key,missing_field=user_name
     *
     * @effects failure_bridge_query_carries_challenge_id_for_retry
     */
    public function test_failure_bridge_query_carries_challenge_id(): void
    {
        $log = $this->createChallenge(User::factory()->create()->id);
        $this->fakeCertQuery(array_merge($this->identityPayload(), ['user_name' => '']));

        $outcome = $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);
        $query = $outcome->toBridgeQuery();

        $this->assertFalse($outcome->success);
        $this->assertArrayHasKey('challenge_id', $query, '실패 결과에도 challenge_id 가 있어야 재시도 시 새 거래를 발급받는다');
        $this->assertSame($log->id, $query['challenge_id']);
    }

    /**
     * @scenario outcome=already_consumed,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects second_callback_returns_already_consumed
     */
    public function test_second_callback_is_rejected_as_already_consumed(): void
    {
        $log = $this->createChallenge(User::factory()->create()->id);
        $this->fakeCertQuery($this->identityPayload());

        $first = $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);
        $this->assertTrue($first->success);

        $second = $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);

        $this->assertFalse($second->success);
        $this->assertSame('ALREADY_CONSUMED', $second->failureCode);
        $this->assertNotNull(KcpCertTransaction::query()->where('challenge_id', $log->id)->first()->consumed_at);
    }

    /**
     * @scenario outcome=cancelled,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects cancelled_marks_log_cancelled_with_9999_code
     */
    public function test_user_cancellation_marks_log_cancelled(): void
    {
        $log = $this->createChallenge(User::factory()->create()->id);
        Http::fake();

        $outcome = $this->resolver->resolve([
            'res_cd' => '9999',
            'res_msg' => '사용자취소',
            'reg_cert_key' => self::REG_CERT_KEY,
        ]);

        $this->assertFalse($outcome->success);
        $this->assertSame('9999', $outcome->failureCode);
        $this->assertSame(
            IdentityVerificationStatus::Cancelled->value,
            $this->logRepository->findById($log->id)->status->value,
        );
        Http::assertNothingSent();
    }

    /**
     * @scenario outcome=provider_error,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects provider_error_marks_log_failed_with_res_cd
     */
    public function test_standard_window_failure_marks_log_failed(): void
    {
        $log = $this->createChallenge(User::factory()->create()->id);
        Http::fake();

        $outcome = $this->resolver->resolve([
            'res_cd' => 'CS12',
            'res_msg' => '%EC%9D%B8%EC%A6%9D%EC%8B%A4%ED%8C%A8',
            'reg_cert_key' => self::REG_CERT_KEY,
        ]);

        $this->assertFalse($outcome->success);
        $this->assertSame('CS12', $outcome->failureCode);
        $this->assertSame(
            IdentityVerificationStatus::Failed->value,
            $this->logRepository->findById($log->id)->status->value,
        );
    }

    /**
     * @scenario outcome=remote_failure,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects remote_failure_returns_remote_call_failed
     */
    public function test_result_query_communication_failure(): void
    {
        $this->createChallenge(User::factory()->create()->id);
        Http::fake(['testcert.kcp.co.kr/*' => Http::response('bad gateway', 502)]);

        $outcome = $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);

        $this->assertFalse($outcome->success);
        $this->assertSame('REMOTE_CALL_FAILED', $outcome->failureCode);
    }

    /**
     * 코어 verify 에 닿지 못하고 끝나는 실패도 인증 이력에 남아야 한다.
     *
     * 남기지 않으면 거래는 소비됐는데 로그는 "보냄" 으로 머물러, 사용자가 인증창을 열어 두고
     * 그냥 떠난 경우와 구분되지 않는다.
     *
     * @scenario outcome=remote_failure,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects resolver_only_failure_is_recorded_on_log
     */
    public function test_remote_failure_marks_log_failed_with_failure_code(): void
    {
        $log = $this->createChallenge(User::factory()->create()->id);
        Http::fake(['testcert.kcp.co.kr/*' => Http::response('bad gateway', 502)]);

        $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);

        $log->refresh();
        $this->assertSame(IdentityVerificationStatus::Failed, $log->status);
        $this->assertSame('REMOTE_CALL_FAILED', $log->metadata['failure_code'] ?? null);
    }

    /**
     * 거래키 복호화 실패도 동일하게 인증 이력에 남아야 한다.
     *
     * @scenario outcome=decrypt_failure,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects resolver_only_failure_is_recorded_on_log
     */
    public function test_decrypt_failure_marks_log_failed_with_failure_code(): void
    {
        $log = $this->createChallenge(User::factory()->create()->id);
        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'enc_cert_data' => 'not-a-valid-ciphertext',
                'rv' => 'not-a-valid-salt',
            ]),
        ]);

        $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);

        $log->refresh();
        $this->assertSame(IdentityVerificationStatus::Failed, $log->status);
        $this->assertSame('DECRYPT_FAILED', $log->metadata['failure_code'] ?? null);
    }

    /**
     * 늦게 도착한 중복 콜백이 이미 확정된 성공 기록을 실패로 덮어써서는 안 된다.
     *
     * @scenario outcome=already_consumed,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects resolver_only_failure_is_recorded_on_log
     */
    public function test_already_consumed_does_not_overwrite_verified_log(): void
    {
        $log = $this->createChallenge(User::factory()->create()->id);
        $this->fakeCertQuery($this->identityPayload());

        $this->assertTrue($this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY])->success);

        $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);

        $log->refresh();
        $this->assertSame(IdentityVerificationStatus::Verified, $log->status);
        $this->assertArrayNotHasKey('failure_code', (array) $log->metadata);
    }

    /**
     * @scenario outcome=decrypt_failure,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects decrypt_failure_returns_decrypt_failed
     */
    public function test_result_query_decrypt_failure(): void
    {
        $this->createChallenge(User::factory()->create()->id);
        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'enc_cert_data' => base64_encode('broken-cipher'),
                'rv' => base64_encode(random_bytes(16)),
            ]),
        ]);

        $outcome = $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);

        $this->assertFalse($outcome->success);
        $this->assertSame('DECRYPT_FAILED', $outcome->failureCode);
    }

    /**
     * @scenario outcome=cert_rejected,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects provider_error_marks_log_failed_with_res_cd, unmapped_provider_code_carries_original_message
     */
    public function test_result_query_rejection_is_reported_with_its_code(): void
    {
        $log = $this->createChallenge(User::factory()->create()->id);
        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => 'CS16',
                'res_msg' => '거래등록이 올바르지 않습니다.',
            ]),
        ]);

        $outcome = $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);

        $this->assertFalse($outcome->success);
        $this->assertSame('CS16', $outcome->failureCode);
        // 우리말 안내가 없는 코드는 KCP 원문을 함께 전달해 화면이 "[코드] 메시지" 로 안내한다.
        $this->assertSame('거래등록이 올바르지 않습니다.', $outcome->failureMessage);
        $this->assertSame(
            '거래등록이 올바르지 않습니다.',
            $outcome->toBridgeQuery()['identity_error_message'] ?? null,
        );
        $this->assertSame(
            IdentityVerificationStatus::Failed->value,
            $this->logRepository->findById($log->id)->status->value,
        );
    }

    /**
     * @scenario outcome=incomplete_identity,lookup=by_reg_cert_key,missing_field=user_name
     *
     * @effects missing_identity_fields_returns_incomplete_identity
     */
    public function test_missing_user_name_returns_incomplete_identity(): void
    {
        $this->assertMissingIdentityFieldIsRejected('user_name');
    }

    /**
     * @scenario outcome=incomplete_identity,lookup=by_reg_cert_key,missing_field=phone_no
     *
     * @effects missing_identity_fields_returns_incomplete_identity
     */
    public function test_missing_phone_no_returns_incomplete_identity(): void
    {
        $this->assertMissingIdentityFieldIsRejected('phone_no');
    }

    /**
     * @scenario outcome=incomplete_identity,lookup=by_reg_cert_key,missing_field=ci
     *
     * @effects missing_identity_fields_returns_incomplete_identity
     */
    public function test_missing_ci_returns_incomplete_identity(): void
    {
        $this->assertMissingIdentityFieldIsRejected('CI');
    }

    /**
     * @scenario outcome=incomplete_identity,lookup=by_reg_cert_key,missing_field=di
     *
     * @effects missing_identity_fields_returns_incomplete_identity
     */
    public function test_missing_di_returns_incomplete_identity(): void
    {
        $this->assertMissingIdentityFieldIsRejected('DI');
    }

    /**
     * 신원 핵심값 하나가 비어 온 응답을 인증으로 인정하지 않는지 확인한다.
     *
     * 결과조회가 성공(res_cd=0000)으로 돌아와도 이름·휴대폰·CI·DI 중 빠진 값이 있으면 비정상
     * 응답이다. 실제로 PASS 앱 인증이 진행되지 않은 채 돌아온 응답이 이 형태였다. 빈 신원값으로
     * 회원이 등록되거나 중복 차단이 무력화되는 것을 막기 위해 항목마다 개별로 잠근다.
     *
     * 생년월일은 모드에 따라 판정이 갈리므로 별도 테스트에서 다룬다.
     *
     * @param  string  $field  비워서 보낼 결과조회 응답 필드명
     */
    private function assertMissingIdentityFieldIsRejected(string $field): void
    {
        $this->createChallenge(User::factory()->create()->id);
        $this->fakeCertQuery(array_merge($this->identityPayload(), [$field => '']));

        $outcome = $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);

        $this->assertFalse($outcome->success, "{$field} 가 비면 인증을 인정하면 안 된다");
        $this->assertSame('INCOMPLETE_IDENTITY', $outcome->failureCode);
        $this->assertDatabaseCount('nhnkcp_identity_records', 0);
    }

    /**
     * 운영 모드에서 생년월일이 비면 인증을 인정하지 않는다.
     *
     * KCP 규격상 생년월일은 조건 없이 항상 내려오므로, 운영에서 비어 오면 비정상 응답이다.
     *
     * @scenario outcome=incomplete_identity,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects live_mode_rejects_response_without_birthday
     */
    public function test_live_mode_rejects_missing_birthday(): void
    {
        app(PluginSettingsService::class)->save(self::PLUGIN_IDENTIFIER, [
            'is_test_mode' => false,
            'live_site_cd' => 'SM'.self::SITE_CD,
            'live_enc_key' => self::ENC_KEY,
            'duplicate_field' => 'di',
            'duplicate_block_enabled' => true,
        ]);

        $this->createChallenge(User::factory()->create()->id);
        $this->fakeCertQuery(
            array_merge($this->identityPayload(), ['birth_day' => '']),
            'SM'.self::SITE_CD,
            'cert.kcp.co.kr/*',
        );

        $outcome = $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);

        $this->assertFalse($outcome->success);
        $this->assertSame('INCOMPLETE_IDENTITY', $outcome->failureCode);
        $this->assertDatabaseCount('nhnkcp_identity_records', 0);
    }

    /**
     * 테스트 모드에서는 생년월일이 비어도 인증을 인정한다.
     *
     * KCP 테스트 서버는 실제 통신사 조회를 하지 않아 생년월일·성별·내외국인을 빈 값으로 응답한다.
     * 운영과 동일하게 거절하면 테스트 환경에서는 인증을 완주할 수 없어 검수가 불가능해진다.
     *
     * @scenario outcome=success,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects test_mode_accepts_response_without_birthday
     */
    public function test_test_mode_accepts_missing_birthday(): void
    {
        $this->createChallenge(User::factory()->create()->id);
        $this->fakeCertQuery(array_merge($this->identityPayload(), ['birth_day' => '']));

        $outcome = $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);

        $this->assertTrue($outcome->success, '테스트 모드에서는 생년월일 없이도 인증이 완주되어야 한다');
        $this->assertNotSame('', $outcome->verificationToken);
        $this->assertDatabaseCount('nhnkcp_identity_records', 1);
    }

    /**
     * @scenario outcome=success,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects bridge_query_carries_no_personal_information
     */
    public function test_bridge_query_contains_no_personal_information(): void
    {
        $log = $this->createChallenge(User::factory()->create()->id);
        $this->fakeCertQuery($this->identityPayload());

        $outcome = $this->resolver->resolve(['res_cd' => '0000', 'reg_cert_key' => self::REG_CERT_KEY]);
        $query = $outcome->toBridgeQuery();

        $this->assertSame(['verification_token', 'challenge_id'], array_keys($query));
        $serialized = json_encode($query, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('홍길동', (string) $serialized);
        $this->assertStringNotContainsString('01012345678', (string) $serialized);
        $this->assertStringNotContainsString('DI-CALLBACK-0001', (string) $serialized);
        $this->assertSame($log->id, $query['challenge_id']);
    }

    /**
     * 결과조회 응답(암호문)을 가로챈다.
     *
     * @param  array<string, mixed>  $identity  복호화 후 노출될 평문 페이로드
     * @param  string|null  $siteCd  암호화에 사용할 사이트코드 (운영 모드 검증 시 라이브 코드)
     * @param  string  $hostPattern  가로챌 호스트 패턴 (운영 모드는 cert.kcp.co.kr)
     */
    private function fakeCertQuery(array $identity, ?string $siteCd = null, string $hostPattern = 'testcert.kcp.co.kr/*'): void
    {
        $encrypted = KcpCertCrypto::encrypt((string) json_encode($identity), self::ENC_KEY, $siteCd ?? self::SITE_CD);

        Http::fake([
            $hostPattern => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'enc_cert_data' => $encrypted['enc_data'],
                'rv' => $encrypted['rv'],
            ]),
        ]);
    }

    /**
     * KCP 결과조회 평문 페이로드.
     *
     * @return array<string, string>
     */
    private function identityPayload(): array
    {
        return [
            'user_name' => '홍길동',
            'birth_day' => '19900101',
            'phone_no' => '01012345678',
            'comm_id' => 'SKT',
            'sex_code' => '01',
            'local_code' => '01',
            'CI' => 'CI-CALLBACK-0001',
            'DI' => 'DI-CALLBACK-0001',
        ];
    }

    /**
     * 인증 대기 challenge + 거래 행을 만든다.
     *
     * @param  int|null  $userId  사용자 ID
     */
    private function createChallenge(?int $userId): IdentityVerificationLog
    {
        $log = $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => KcpIdentityProvider::PROVIDER_ID,
            'purpose' => 'self_update',
            'channel' => KcpIdentityProvider::CHANNEL,
            'user_id' => $userId,
            'target_hash' => hash('sha256', 'callback-test@example.com'),
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => 'api.identity.verify',
            'verification_token' => null,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['ordr_idxx' => self::ORDR_IDXX],
        ]);

        $this->transactionRepository->create(self::ORDR_IDXX, self::REG_CERT_KEY, $log->id);

        return $log;
    }
}
