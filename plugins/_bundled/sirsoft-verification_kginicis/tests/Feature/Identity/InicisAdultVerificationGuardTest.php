<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGatewayInterface;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * 회귀 테스트 — 성인인증 purpose 미성년자 차단.
 *
 * 배경: 성인인증(inicis.adult_verification) 목적 정책을 등록·활성화해도, 미성년자가
 * 본인인증을 완료하면 verify() 가 success + verification_token 을 발급해 정책 게이트를
 * 통과시킨다. is_adult 결과가 강제 조건으로 검사되지 않아 비성년자가 19금 작업에 접근 가능.
 *
 * 수정: verify() 가 purpose=inicis.adult_verification 이고 미성년자(is_adult=false)면
 * status=failed + VerificationResult::failure(NOT_ADULT) 반환 → 토큰 미발급 → 게이트 차단 유지.
 *
 * 검증 매트릭스 (purpose × is_adult):
 *  - 성인인증 purpose + 미성년자 → failure(NOT_ADULT), status=failed, 토큰 미발급
 *  - 성인인증 purpose + 성인 → success, 토큰 발급, status=verified
 *  - 그 외 purpose(self_update) + 미성년자 → success 유지 (성인 가드 미적용 회귀 방지)
 *  - 성인인증 purpose + 생년월일 누락 → failure(INCOMPLETE_IDENTITY) (신원 가드가 성인 가드보다 먼저 차단)
 *
 * 실제 DB + 실제 provider 객체 — 외부 gateway/mapping 은 더미 (성공 경로에서 미호출).
 *
 * @since 1.0.0-beta.1
 */
class InicisAdultVerificationGuardTest extends PluginTestCase
{
    private const ADULT_PURPOSE = 'inicis.adult_verification';

    /** 만 19세 이상 생년월일 (가드 통과) */
    private const ADULT_BIRTHDAY = '19900101';

    /** 만 19세 미만 생년월일 (가드 차단) — 동적 산정으로 영구 미성년자 보장 */
    private string $minorBirthday;

    private InicisIdentityProvider $provider;

    private IdentityVerificationLogRepositoryInterface $logRepository;

    /**
     * 로그 저장소와, 더미 gateway·매핑 저장소를 끼운 DI 기준 provider 를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);

        // 오늘 기준 10세 — 테스트가 미래 어느 시점에 돌아도 미성년자임이 보장됨.
        $this->minorBirthday = now()->subYears(10)->format('Ymd');

        $this->provider = new InicisIdentityProvider(
            gateway: $this->makeNullGateway(),
            mappingRepository: $this->makeNullMappingRepository(),
            recordRepository: app(InicisIdentityRecordRepositoryInterface::class),
            cache: app(CacheInterface::class),
            config: ['duplicate_field' => 'di'],
        );
    }

    /**
     * 성인인증 목적에서 미성년자는 NOT_ADULT 로 실패하고 토큰이 발급되지 않는다.
     *
     * @scenario purpose=adult,is_adult=false
     * @effects minor_blocked_with_not_adult_failure_and_no_token
     *
     * @return void 반환값 없음
     */
    public function test_adult_purpose_blocks_minor_with_not_adult_failure(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id, self::ADULT_PURPOSE);

        $result = $this->provider->verify($log->id, $this->validInput($this->minorBirthday));

        $this->assertFalse($result->success, '미성년자는 성인인증을 통과하면 안 된다');
        $this->assertSame('NOT_ADULT', $result->failureCode);
        $this->assertNull($result->claims['verification_token'] ?? null, '차단 시 토큰이 발급되면 안 된다');

        $reload = $this->logRepository->findById($log->id);
        $this->assertSame(IdentityVerificationStatus::Failed->value, $reload->status->value);
        $this->assertNull($reload->verification_token);
    }

    /**
     * 성인인증 목적에서 성인은 통과하고 로그가 verified 가 된다.
     *
     * @scenario purpose=adult,is_adult=true
     * @effects adult_passes_with_verified_status_and_token
     *
     * @return void 반환값 없음
     */
    public function test_adult_purpose_passes_for_adult(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id, self::ADULT_PURPOSE);

        $result = $this->provider->verify($log->id, $this->validInput(self::ADULT_BIRTHDAY));

        $this->assertTrue($result->success, '성인은 성인인증을 통과해야 한다');
        $this->assertNotEmpty($result->claims['verification_token'] ?? null);

        $reload = $this->logRepository->findById($log->id);
        $this->assertTrue($reload->isVerified());
    }

    /**
     * 성인인증이 아닌 목적은 미성년자여도 성인 가드에 막히지 않는다.
     *
     * @scenario purpose=non_adult,is_adult=true
     * @effects non_adult_purpose_unaffected_by_guard
     *
     * @return void 반환값 없음
     */
    public function test_non_adult_purpose_is_not_affected_by_minor(): void
    {
        $user = User::factory()->create();
        // 성인인증이 아닌 purpose — 성인 가드가 적용되면 안 됨 (회귀 방지)
        $log = $this->createPendingLog($user->id, 'self_update');

        $result = $this->provider->verify($log->id, $this->validInput($this->minorBirthday));

        $this->assertTrue($result->success, '성인인증 외 purpose 는 미성년자여도 통과해야 한다');

        $reload = $this->logRepository->findById($log->id);
        $this->assertTrue($reload->isVerified());
    }

    /**
     * 성인인증이 아닌 목적이라도 생년월일이 없으면 신원 가드가 INCOMPLETE_IDENTITY 로 막는다.
     *
     * @scenario purpose=non_adult,is_adult=missing
     * @effects non_adult_purpose_blocked_by_incomplete_identity_guard
     *
     * @return void 반환값 없음
     */
    public function test_non_adult_purpose_blocked_when_birthday_missing(): void
    {
        $user = User::factory()->create();
        // 성인인증이 아닌 purpose 라도 생년월일은 신원 핵심값이므로 누락 시 신원 가드가 차단한다.
        // (성인 가드보다 신원 가드가 먼저 평가됨 — 더 근본적 실패 사유 우선)
        $log = $this->createPendingLog($user->id, 'self_update');

        $result = $this->provider->verify($log->id, $this->validInput(''));

        $this->assertFalse($result->success, '생년월일은 신원 핵심값이므로 누락 시 차단되어야 한다');
        $this->assertSame('INCOMPLETE_IDENTITY', $result->failureCode);

        $reload = $this->logRepository->findById($log->id);
        $this->assertSame(IdentityVerificationStatus::Failed->value, $reload->status->value);
    }

    /**
     * 성인인증 목적에서 생년월일이 없으면 성인 가드보다 먼저 신원 가드가 INCOMPLETE_IDENTITY 로 막는다.
     *
     * @scenario purpose=adult,is_adult=missing
     * @effects adult_purpose_blocks_when_birthday_absent
     *
     * @return void 반환값 없음
     */
    public function test_adult_purpose_blocks_when_birthday_missing(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id, self::ADULT_PURPOSE);

        // 생년월일 누락 — 신원 핵심값 부재이므로 성인 가드보다 먼저 신원 가드가 차단한다.
        // 정보가 없는데 "미성년자(NOT_ADULT)" 로 단정하는 대신 "신원 불완전(INCOMPLETE_IDENTITY)" 이 정확한 사유.
        $result = $this->provider->verify($log->id, $this->validInput(''));

        $this->assertFalse($result->success, '생년월일 없이 성인인증을 통과하면 안 된다');
        $this->assertSame('INCOMPLETE_IDENTITY', $result->failureCode);

        $reload = $this->logRepository->findById($log->id);
        $this->assertSame(IdentityVerificationStatus::Failed->value, $reload->status->value);
    }

    /**
     * STEP3 성공 페이로드 mockup — birthday 만 파라미터화.
     *
     * @param  string  $birthday  생년월일(Ymd, 빈 문자열이면 누락)
     * @return array<string, mixed> 이니시스 STEP3 결과 페이로드
     */
    private function validInput(string $birthday): array
    {
        return [
            'resultCode' => '0000',
            'resultMsg' => 'success',
            'txId' => 'TX-'.Str::random(8),
            'svcCd' => '03',
            'providerDevCd' => 'SKT',
            'userName' => '홍길동',
            'userPhone' => '01012345678',
            'userBirthday' => $birthday,
            'userDi' => 'DI-'.Str::random(8),
            'userCi' => '',
            'userCi2' => '',
            'userGender' => 'M',
            'isForeign' => '0',
        ];
    }

    /**
     * verify 직전 상태(sent)의 이니시스 본인인증 로그를 만든다.
     *
     * @param  int  $userId  대상 회원 ID
     * @param  string  $purpose  인증 목적(purpose)
     * @return IdentityVerificationLog 만든 로그
     */
    private function createPendingLog(int $userId, string $purpose): IdentityVerificationLog
    {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => InicisIdentityProvider::PROVIDER_ID,
            'purpose' => $purpose,
            'channel' => 'ipin',
            'user_id' => $userId,
            'target_hash' => hash('sha256', 'adult-test@example.com'),
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
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
     * verify 경로에서 쓰이지 않는 더미 gateway 를 만든다.
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
                throw new \LogicException('not used in adult guard test');
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
     * verify 경로에서 쓰이지 않는 더미 challenge 매핑 저장소를 만든다.
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
                throw new \LogicException('not used in adult guard test');
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
}
