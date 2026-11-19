<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGatewayInterface;
use Plugins\Sirsoft\VerificationKginicis\Support\InicisIdentityHasher;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

// 본 테스트는 binding 검증 흐름만 검증 — gateway / mappingRepository 는 호출되지 않음.
// PluginTestCase 의 PHPUnit createMock 부팅 결함을 회피하기 위해 anonymous class 로 dependency 만족.

/**
 * InicisIdentityProvider::verify() 의 D′−1 binding 검증 분기 통합 테스트.
 *
 * verify() 가 log.user_id 가 있는 self IDV 흐름에서 verify 결과의 DI/CI hash 가
 * 대상 user 의 inicis_identity_records 에 저장된 hash 와 불일치하면 failure 반환하는지 검증.
 *
 * 검증 매트릭스:
 *  - log.user_id=null (게스트 가입) → binding 검증 skip → success
 *  - log.user_id 있고 record 없음 (IDV 미가입자) → permissive 통과 → success
 *  - log.user_id 있고 record.di_hash 일치 → 통과 → success
 *  - log.user_id 있고 record.di_hash 불일치 → failure (IDENTITY_BINDING_MISMATCH)
 *  - 식별값(DI·CI) 전무 → 신원 가드가 INCOMPLETE_IDENTITY 로 차단
 *  - CI mode: record.ci_hash 비교 + ci2_hash fallback
 *
 * 실제 DB + 실제 record + 실제 provider 객체 — mock 은 외부 gateway 만.
 */
class InicisIdentityProviderBindingTest extends PluginTestCase
{
    private InicisIdentityProvider $provider;

    private IdentityVerificationLogRepositoryInterface $logRepository;

    private InicisIdentityRecordRepositoryInterface $recordRepository;

    /**
     * 로그·record 저장소와, 더미 gateway·매핑 저장소를 끼운 DI 기준 provider 를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->recordRepository = app(InicisIdentityRecordRepositoryInterface::class);

        $this->provider = new InicisIdentityProvider(
            gateway: $this->makeNullGateway(),
            mappingRepository: $this->makeNullMappingRepository(),
            recordRepository: $this->recordRepository,
            cache: app(CacheInterface::class),
            config: ['duplicate_field' => 'di'],
        );
    }

    /**
     * 비로그인 가입 흐름(log.user_id 없음)은 binding 검증을 건너뛰고 통과한다.
     *
     * @scenario flow=self_idv_verify, duplicate_field=di, existing_record_state=none, verify_hash_state=di_set, log_provider_id=inicis, log_user_id=null
     *
     * @effects verify_succeeds_when_log_user_id_is_null_guest_signup
     *
     * @return void 반환값 없음
     */
    public function test_verify_succeeds_for_guest_signup_flow_skipping_binding_check(): void
    {
        // log.user_id = null (비로그인 가입 흐름) — binding 검증 자체가 skip 되어야 함
        $log = $this->createPendingLog(userId: null);

        $result = $this->provider->verify($log->id, $this->validInput(di: 'DI-NEW'));

        $this->assertTrue($result->success);
    }

    /**
     * 로그인 회원이라도 저장된 record 가 없으면(IDV 미가입자) 허용 통과한다.
     *
     * @scenario flow=self_idv_verify, duplicate_field=di, existing_record_state=none, verify_hash_state=di_set, log_provider_id=inicis, log_user_id=present
     *
     * @effects verify_succeeds_when_user_has_no_existing_record_permissive
     *
     * @return void 반환값 없음
     */
    public function test_verify_succeeds_when_user_has_no_existing_record(): void
    {
        // log.user_id 있지만 record 없음 — permissive 통과 (IDV 미가입자)
        $user = User::factory()->create();
        $log = $this->createPendingLog(userId: $user->id);

        $result = $this->provider->verify($log->id, $this->validInput(di: 'DI-LEGACY-USER'));

        $this->assertTrue($result->success);
    }

    /**
     * 저장된 record 의 di_hash 와 인증 결과 DI 가 같으면 통과한다.
     *
     * @scenario flow=self_idv_verify, duplicate_field=di, existing_record_state=same_user_match, verify_hash_state=di_set, log_provider_id=inicis, log_user_id=present
     *
     * @effects verify_succeeds_when_record_di_hash_matches
     *
     * @return void 반환값 없음
     */
    public function test_verify_succeeds_when_existing_record_di_hash_matches(): void
    {
        $user = User::factory()->create();
        $di = 'DI-USER-OWN';
        $this->createRecordForUser($user->id, ['di_hash' => InicisIdentityHasher::hash($di)]);
        $log = $this->createPendingLog(userId: $user->id);

        $result = $this->provider->verify($log->id, $this->validInput(di: $di));

        $this->assertTrue($result->success);
    }

    /**
     * 저장된 record 의 di_hash 와 인증 결과 DI 가 다르면 IDENTITY_BINDING_MISMATCH 로 실패하고 로그가 failed 가 된다.
     *
     * @scenario flow=self_idv_verify, duplicate_field=di, existing_record_state=same_user_mismatch, verify_hash_state=di_set, log_provider_id=inicis, log_user_id=present
     *
     * @effects verify_fails_with_binding_mismatch_when_di_hash_differs, verify_failure_updates_log_status_to_failed
     *
     * @return void 반환값 없음
     */
    public function test_verify_fails_with_binding_mismatch_when_di_hash_differs(): void
    {
        $user = User::factory()->create();
        $this->createRecordForUser($user->id, ['di_hash' => InicisIdentityHasher::hash('DI-OWNED-BY-USER')]);
        $log = $this->createPendingLog(userId: $user->id);

        // 다른 사람의 DI 로 verify 시도
        $result = $this->provider->verify($log->id, $this->validInput(di: 'DI-IMPOSTOR'));

        $this->assertFalse($result->success);
        $this->assertSame('IDENTITY_BINDING_MISMATCH', $result->failureCode);

        // log status 가 failed 로 갱신
        $reload = $this->logRepository->findById($log->id);
        $this->assertSame(IdentityVerificationStatus::Failed->value, $reload->status->value);
    }

    /**
     * DI 기준 설정에서 인증 결과 식별값(DI·CI)이 모두 비면 신원 가드가 INCOMPLETE_IDENTITY 로 막는다.
     *
     * @scenario flow=self_idv_verify, duplicate_field=di, verify_hash_state=di_empty, log_provider_id=inicis, log_user_id=present
     *
     * @return void 반환값 없음
     */
    public function test_verify_blocks_when_both_identifiers_null(): void
    {
        $user = User::factory()->create();
        $this->createRecordForUser($user->id, ['di_hash' => InicisIdentityHasher::hash('DI-OWNED')]);
        $log = $this->createPendingLog(userId: $user->id);

        // 설정 기준 식별값(di) + ci 가 모두 빈값 → 신원 핵심값 부재로 차단.
        // 정상 본인확인이면 CI/DI 는 반드시 제공된다(주민/외국인등록번호 기반). 둘 다 없음 = 비정상 응답.
        // 등록 외국인은 외국인등록번호로 CI/DI 를 발급받으므로 정상 인증이면 식별값이 온다.
        $result = $this->provider->verify($log->id, $this->validInput(di: '', ci: ''));

        $this->assertFalse($result->success, '식별값(DI·CI)이 전무하면 본인확인을 인정하면 안 된다');
        $this->assertSame('INCOMPLETE_IDENTITY', $result->failureCode);
    }

    /**
     * CI 기준 설정에서 저장된 ci_hash 와 인증 결과 CI 가 다르면 IDENTITY_BINDING_MISMATCH 로 실패한다.
     *
     * @scenario flow=self_idv_verify, duplicate_field=ci, existing_record_state=same_user_mismatch, verify_hash_state=ci_set, log_provider_id=inicis, log_user_id=present
     *
     * @effects verify_fails_with_binding_mismatch_in_ci_mode_when_ci_hash_differs
     *
     * @return void 반환값 없음
     */
    public function test_verify_fails_with_binding_mismatch_in_ci_mode_when_ci_hash_differs(): void
    {
        $user = User::factory()->create();
        $this->createRecordForUser($user->id, ['ci_hash' => InicisIdentityHasher::hash('CI-OWNED')]);
        $log = $this->createPendingLog(userId: $user->id);

        $ciModeProvider = new InicisIdentityProvider(
            gateway: $this->makeNullGateway(),
            mappingRepository: $this->makeNullMappingRepository(),
            recordRepository: $this->recordRepository,
            cache: app(CacheInterface::class),
            config: ['duplicate_field' => 'ci'],
        );

        $result = $ciModeProvider->verify($log->id, $this->validInput(di: 'DI-WHATEVER', ci: 'CI-IMPOSTOR'));

        $this->assertFalse($result->success);
        $this->assertSame('IDENTITY_BINDING_MISMATCH', $result->failureCode);
    }

    /**
     * CI 기준 설정에서 ci_hash 는 다르지만 ci2_hash 가 같으면(통신사 변경) 대체 비교로 통과한다.
     *
     * @scenario flow=self_idv_verify, duplicate_field=ci, existing_record_state=ci2_fallback_match, verify_hash_state=ci_set, log_provider_id=inicis, log_user_id=present
     *
     * @effects verify_succeeds_in_ci_mode_when_ci2_hash_fallback_matches
     *
     * @return void 반환값 없음
     */
    public function test_verify_succeeds_in_ci_mode_when_ci2_hash_fallback_matches(): void
    {
        $user = User::factory()->create();
        // 사용자가 통신사 변경 후 CI 가 새로 발급된 케이스를 시뮬레이션 —
        // record.ci_hash 는 이전값, record.ci2_hash 가 새 CI 와 일치
        $this->createRecordForUser($user->id, [
            'ci_hash' => InicisIdentityHasher::hash('CI-OLD'),
            'ci2_hash' => InicisIdentityHasher::hash('CI-NEW'),
        ]);
        $log = $this->createPendingLog(userId: $user->id);

        $ciModeProvider = new InicisIdentityProvider(
            gateway: $this->makeNullGateway(),
            mappingRepository: $this->makeNullMappingRepository(),
            recordRepository: $this->recordRepository,
            cache: app(CacheInterface::class),
            config: ['duplicate_field' => 'ci'],
        );

        // 신 CI 로 verify — ci_hash 매칭 실패하지만 ci2_hash fallback 으로 통과해야 함
        $result = $ciModeProvider->verify($log->id, $this->validInput(di: 'DI-X', ci: 'CI-NEW'));

        $this->assertTrue($result->success);
    }

    /**
     * STEP3 성공 페이로드 mockup.
     *
     * @param  string  $di  인증 결과 DI(빈 문자열이면 누락)
     * @param  string  $ci  인증 결과 CI(빈 문자열이면 누락)
     * @return array<string, mixed> 이니시스 STEP3 결과 페이로드
     */
    private function validInput(string $di = 'DI-DEFAULT', string $ci = ''): array
    {
        return [
            'resultCode' => '0000',
            'resultMsg' => 'success',
            'txId' => 'TX-'.Str::random(8),
            'svcCd' => '03',
            'providerDevCd' => 'SKT',
            'userName' => '홍길동',
            'userPhone' => '01012345678',
            'userBirthday' => '19900101',
            'userDi' => $di,
            'userCi' => $ci,
            'userCi2' => '',
            'userGender' => 'M',
            'isForeign' => '0',
        ];
    }

    /**
     * verify 직전 상태(sent)의 이니시스 본인인증 로그를 만든다.
     *
     * @param  int|null  $userId  대상 회원 ID(null 이면 비로그인 가입 흐름)
     * @return IdentityVerificationLog 만든 로그
     */
    private function createPendingLog(?int $userId): IdentityVerificationLog
    {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => InicisIdentityProvider::PROVIDER_ID,
            'purpose' => $userId ? 'self_update' : 'signup',
            'channel' => 'ipin',
            'user_id' => $userId,
            'target_hash' => hash('sha256', 'test@example.com'),
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'external_redirect',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => 'api.identity.verify',
            'verification_token' => null,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['mtxid' => 'MTX-'.Str::random(8)],
        ]);
    }

    /**
     * binding 검증 흐름에서 호출되지 않는 더미 gateway.
     *
     * @return InicisGatewayInterface 호출되지 않는 더미 gateway
     */
    private function makeNullGateway(): InicisGatewayInterface
    {
        return new class implements InicisGatewayInterface
        {
            /**
             * 인증 URL 검증 더미 — 이 시험 경로에서는 쓰이지 않아 늘 거부한다.
             *
             * @param  string  $url  검사할 인증 URL
             * @return bool 늘 false
             */
            public function validateAuthUrl(string $url): bool
            {
                return false;
            }

            /**
             * 결과 조회 더미 — 이 시험 경로에서 호출되면 안 되므로 예외를 던진다.
             *
             * @param  string  $authRequestUrl  결과 조회 URL
             * @param  string  $txId  이니시스 거래 ID
             * @param  string  $token  인증 토큰
             * @return array<string, mixed> 반환하지 않음(늘 예외)
             */
            public function verifyResult(string $authRequestUrl, string $txId, string $token): array
            {
                throw new \LogicException('not used in binding test');
            }

            /**
             * 거래 ID 생성 더미 — 빈 문자열을 돌려준다.
             *
             * @return string 늘 빈 문자열
             */
            public function generateMTxId(): string
            {
                return '';
            }
        };
    }

    /**
     * binding 검증 흐름에서 호출되지 않는 더미 mapping repository.
     *
     * @return InicisChallengeMappingRepositoryInterface 호출되지 않는 더미 매핑 저장소
     */
    private function makeNullMappingRepository(): InicisChallengeMappingRepositoryInterface
    {
        return new class implements InicisChallengeMappingRepositoryInterface
        {
            /**
             * 보관주기 정리 전 매핑 삭제 — 이 시험에서는 호출되지 않는다(7.2.0 이 인터페이스에 더한 메서드).
             *
             * @param  array<int, string>  $challengeIds  코어 IdentityVerificationLog UUID 목록
             * @return int 늘 0
             */
            public function deleteByChallengeIds(array $challengeIds): int
            {
                return 0;
            }

            /**
             * 매핑 생성 더미 — 이 시험 경로에서 호출되면 안 되므로 예외를 던진다.
             *
             * @param  string  $mtxid  이니시스 거래 ID
             * @param  string  $challengeId  코어 challenge UUID
             * @return \Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping 반환하지 않음(늘 예외)
             */
            public function create(string $mtxid, string $challengeId): \Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping
            {
                throw new \LogicException('not used in binding test');
            }

            /**
             * mTxId 로 challenge ID 를 찾는 더미 — 늘 찾지 못한다.
             *
             * @param  string  $mtxid  이니시스 거래 ID
             * @return string|null 늘 null
             */
            public function findChallengeIdByMtxid(string $mtxid): ?string
            {
                return null;
            }

            /**
             * challenge ID 로 매핑을 찾는 더미 — 늘 찾지 못한다.
             *
             * @param  string  $challengeId  코어 challenge UUID
             * @return \Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping|null 늘 null
             */
            public function findByChallengeId(string $challengeId): ?\Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping
            {
                return null;
            }
        };
    }

    /**
     * 회원의 이니시스 본인인증 record 를 저장한다 — 비교 대상 해시만 덮어쓴다.
     *
     * @param  int  $userId  record 를 가질 회원 ID
     * @param  array<string, mixed>  $hashes  덮어쓸 해시 컬럼(di_hash·ci_hash·ci2_hash)
     * @return void 반환값 없음
     */
    private function createRecordForUser(int $userId, array $hashes): void
    {
        $this->recordRepository->upsertForUser($userId, array_merge([
            'name_encrypted' => Crypt::encryptString('홍길동'),
            'phone_encrypted' => Crypt::encryptString('01012345678'),
            'birthday_encrypted' => Crypt::encryptString('19900101'),
            'di_encrypted' => null,
            'di_hash' => null,
            'ci_encrypted' => null,
            'ci_hash' => null,
            'ci2_encrypted' => null,
            'ci2_hash' => null,
            'gender' => 'M',
            'is_foreigner' => false,
            'is_adult' => true,
            'verified_at' => now(),
            're_verified_at' => now(),
        ], $hashes));
    }
}
