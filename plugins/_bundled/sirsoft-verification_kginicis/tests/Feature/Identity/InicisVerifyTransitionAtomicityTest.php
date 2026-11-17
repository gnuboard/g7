<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\AlreadyConsumedException;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityClaimRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGatewayInterface;
use Plugins\Sirsoft\VerificationKginicis\Support\InicisIdentityHasher;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;
use Tests\Support\Identity\StaleReadIdentityLogRepository;

/**
 * verify() 의 verified 전이가 조건부인지 검증한다 (1.1.4, KVE 동형 O7).
 *
 * 같은 콜백이 동시에 두 번 들어오면 둘 다 「아직 verified 아님」 상태 검사를 통과한다. 수정 전에는 두 요청이 모두
 * 조건 없는 `$log->update()` 로 verified 를 덮어써 토큰이 두 번 발급됐다(뒤 토큰이 앞 토큰을 덮음). 단일 프로세스에서
 * 경합을 재현하려고 코어 테스트 대역 `StaleReadIdentityLogRepository` 로 두 번째 호출이 낡은(sent) 상태를 읽게 한다.
 *
 * @since 1.1.4
 */
class InicisVerifyTransitionAtomicityTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
    }

    /**
     * 첫 콜백이 verified 로 바꾼 뒤, 낡은 상태 검사를 통과한 두 번째 콜백은 전이에서 져 이미 처리됨으로 끝나는지 확인합니다.
     *
     * @scenario flow=self_idv_verify, log_user_id=null, read_timing=stale
     *
     * @effects second_callback_with_stale_log_is_already_consumed, single_token_per_inicis_challenge
     *
     * @return void 반환값 없음
     */
    public function test_second_callback_with_stale_log_is_already_consumed(): void
    {
        $log = $this->createPendingLog(null);
        $stale = new StaleReadIdentityLogRepository;
        $stale->freeze($log);

        $input = $this->validInput();
        $first = $this->provider($stale)->verify($log->id, $input);
        $this->assertTrue($first->success, '첫 콜백은 성공해야 한다');

        try {
            $this->provider($stale)->verify($log->id, $input);
            $this->fail('낡은 상태를 읽은 두 번째 콜백은 이미 처리됨으로 끝나야 한다');
        } catch (AlreadyConsumedException $e) {
            $this->assertSame($log->id, $e->challengeId);
        }

        $reload = $this->logRepository->findById($log->id);
        $this->assertSame(IdentityVerificationStatus::Verified->value, $reload->status->value);
        $this->assertSame(
            $first->claims['verification_token'],
            $reload->verification_token,
            '두 번째 콜백이 첫 콜백의 토큰을 덮어쓰면 안 된다',
        );
    }

    /**
     * 로그인 사용자 재인증에서 진 쪽 콜백은 선점·record 를 남기지 않고, 이긴 쪽은 자기 식별값을 선점표에 채우는지 확인합니다.
     *
     * @scenario flow=self_idv_verify, log_user_id=present, read_timing=stale
     *
     * @effects second_callback_with_stale_log_is_already_consumed, login_verify_soft_claims_identity_hashes
     *
     * @return void 반환값 없음
     */
    public function test_login_verify_soft_claims_and_losing_callback_writes_nothing(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog((int) $user->id);
        $stale = new StaleReadIdentityLogRepository;
        $stale->freeze($log);

        $input = $this->validInput(['userCi' => 'CI-'.Str::random(8)]);
        $this->assertTrue($this->provider($stale)->verify($log->id, $input)->success);

        $claims = app(InicisIdentityClaimRepositoryInterface::class);
        $this->assertSame((int) $user->id, $claims->findHolderId('di', InicisIdentityHasher::hash($input['userDi'])));
        $this->assertSame((int) $user->id, $claims->findHolderId('ci', InicisIdentityHasher::hash($input['userCi'])));

        $this->expectException(AlreadyConsumedException::class);

        try {
            $this->provider($stale)->verify($log->id, $input);
        } finally {
            $this->assertSame(2, DB::table('inicis_identity_claims')->count(), '진 쪽 콜백이 선점을 더 남기면 안 된다');
            $this->assertSame(1, DB::table('inicis_identity_records')->where('user_id', $user->id)->count());
        }
    }

    /**
     * 주어진 로그 저장소를 쓰는 provider 를 만듭니다 (외부 게이트웨이·매핑은 더미).
     *
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  로그 저장소 (낡은 읽기 대역)
     * @return InicisIdentityProvider 이니시스 provider
     */
    private function provider(IdentityVerificationLogRepositoryInterface $logRepository): InicisIdentityProvider
    {
        return new InicisIdentityProvider(
            gateway: $this->makeNullGateway(),
            mappingRepository: $this->makeNullMappingRepository(),
            recordRepository: app(InicisIdentityRecordRepositoryInterface::class),
            cache: app(CacheInterface::class),
            config: ['duplicate_field' => 'di'],
            logRepository: $logRepository,
        );
    }

    /**
     * 정상 STEP3 응답 페이로드를 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 콜백 입력
     */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'resultCode' => '0000',
            'resultMsg' => 'success',
            'mTxId' => 'MTX-'.Str::random(8),
            'txId' => 'TX-'.Str::random(8),
            'svcCd' => '03',
            'providerDevCd' => 'SKT',
            'userName' => '홍길동',
            'userPhone' => '01012345678',
            'userBirthday' => '19900101',
            'userDi' => 'DI-'.Str::random(8),
            'userCi' => '',
            'userCi2' => '',
            'userGender' => 'M',
            'isForeign' => '0',
        ], $overrides);
    }

    /**
     * 진행 중(sent) challenge 를 만듭니다.
     *
     * @param  int|null  $userId  로그인 회원 ID (비로그인 가입이면 null)
     * @return IdentityVerificationLog 만든 challenge
     */
    private function createPendingLog(?int $userId): IdentityVerificationLog
    {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => InicisIdentityProvider::PROVIDER_ID,
            'purpose' => $userId !== null ? 'self_update' : 'signup',
            'channel' => 'ipin',
            'user_id' => $userId,
            'target_hash' => hash('sha256', 'transition-test@example.com'),
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => 'api.identity.verify',
            'verification_token' => null,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['mtxid_hash' => hash('sha256', 'MTX')],
        ]);
    }

    /**
     * 호출되지 않는 게이트웨이 더미를 만듭니다.
     *
     * @return InicisGatewayInterface 게이트웨이 더미
     */
    private function makeNullGateway(): InicisGatewayInterface
    {
        return new class implements InicisGatewayInterface
        {
            /**
             * 인증 주소 검사 — 호출되지 않는다.
             *
             * @param  string  $url  인증 주소
             * @return bool 항상 false
             */
            public function validateAuthUrl(string $url): bool
            {
                return false;
            }

            /**
             * STEP3 결과 조회 — 호출되지 않는다.
             *
             * @param  string  $authRequestUrl  인증 요청 주소
             * @param  string  $txId  거래 ID
             * @param  string  $token  결과 토큰
             * @return array<string, mixed> 반환하지 않고 예외를 던진다
             */
            public function verifyResult(string $authRequestUrl, string $txId, string $token): array
            {
                throw new \LogicException('not used in transition atomicity test');
            }

            /**
             * 가맹점 거래 ID 생성 — 호출되지 않는다.
             *
             * @return string 빈 문자열
             */
            public function generateMTxId(): string
            {
                return '';
            }
        };
    }

    /**
     * 호출되지 않는 mTxId 매핑 저장소 더미를 만듭니다.
     *
     * @return InicisChallengeMappingRepositoryInterface 매핑 저장소 더미
     */
    private function makeNullMappingRepository(): InicisChallengeMappingRepositoryInterface
    {
        return new class implements InicisChallengeMappingRepositoryInterface
        {
            /**
             * 매핑 생성 — 호출되지 않는다.
             *
             * @param  string  $mtxid  가맹점 거래 ID
             * @param  string  $challengeId  본인확인 challenge 식별자
             * @return InicisChallengeMapping 반환하지 않고 예외를 던진다
             */
            public function create(string $mtxid, string $challengeId): InicisChallengeMapping
            {
                throw new \LogicException('not used in transition atomicity test');
            }

            /**
             * 거래 ID 로 challenge 조회 — 늘 없음.
             *
             * @param  string  $mtxid  가맹점 거래 ID
             * @return string|null 항상 null
             */
            public function findChallengeIdByMtxid(string $mtxid): ?string
            {
                return null;
            }

            /**
             * challenge 로 매핑 조회 — 늘 없음.
             *
             * @param  string  $challengeId  본인확인 challenge 식별자
             * @return InicisChallengeMapping|null 항상 null
             */
            public function findByChallengeId(string $challengeId): ?InicisChallengeMapping
            {
                return null;
            }
        };
    }
}
