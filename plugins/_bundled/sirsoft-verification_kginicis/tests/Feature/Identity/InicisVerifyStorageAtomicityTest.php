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
use Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping;
use Plugins\Sirsoft\VerificationKginicis\Models\InicisIdentityRecord;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGatewayInterface;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * 회귀 테스트 — verify() 인증 성공 처리의 원자성.
 *
 * 배경: verify() 는 신원 가드를 통과하면 (1) log 를 status=verified + 토큰 발급으로 갱신한 뒤
 * (2) PII 를 저장한다 (로그인 → record upsert, 비로그인 → Cache stash). (2) 가 실패하면
 * "인증 성공(토큰 발급)" 도장은 찍혔는데 정작 신원 record/PII 는 없는 모순 상태가 남는다.
 * 로그인 사용자는 record 없는 verified, 비로그인 사용자는 Cache 부재로 가입 backfill 이 누락된다.
 *
 * 보강: log 갱신과 PII 저장을 하나의 단위로 묶어, PII 저장이 실패하면 토큰 발급까지 롤백하고
 * verify() 는 STORAGE_FAILED failure 를 반환한다 → "인증 성공 = PII 저장됨" 무결성 보장.
 *
 * 검증 매트릭스:
 *  - 로그인 + record upsert 실패 → failure, 토큰 미발급, log.status 미변경(verified 아님), record 미생성
 *  - 비로그인 + Cache stash 실패 → failure, 토큰 미발급, log.status 미변경
 *  - 정상 경로(회귀 방지) → success + 토큰 + record 저장
 *
 * 실제 DB — record repository / cache 만 실패 주입 더미로 교체.
 *
 * @since 1.0.0-beta.1
 */
class InicisVerifyStorageAtomicityTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    /**
     * 로그 저장소를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
    }

    /**
     * 로그인 경로에서 record upsert 가 실패하면 토큰 발급을 롤백하고 STORAGE_FAILED 로 실패한다.
     *
     * @scenario case=login_record_upsert_throws
     *
     * @effects storage_failure_rolls_back_token
     *
     * @return void 반환값 없음
     */
    public function test_login_path_rolls_back_token_when_record_upsert_fails(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id);

        $result = $this->providerWithThrowingRecord('di')->verify($log->id, $this->validInput());

        $this->assertFalse($result->success, 'PII 저장이 실패하면 인증을 성공으로 인정하면 안 된다');
        $this->assertSame('STORAGE_FAILED', $result->failureCode);
        $this->assertNull($result->claims['verification_token'] ?? null, '저장 실패 시 토큰을 발급하면 안 된다');

        $reload = $this->logRepository->findById($log->id);
        $this->assertNotSame(
            IdentityVerificationStatus::Verified->value,
            $reload->status->value,
            'PII 저장 실패 시 log 가 verified 로 남으면 안 된다 (롤백)'
        );
        $this->assertNull($reload->verification_token, '롤백되어 토큰이 DB 에 남으면 안 된다');
        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
    }

    /**
     * 비로그인 경로에서 Cache stash 가 실패하면 토큰 발급을 롤백하고 STORAGE_FAILED 로 실패한다.
     *
     * @scenario case=guest_cache_stash_throws
     *
     * @effects storage_failure_rolls_back_token
     *
     * @return void 반환값 없음
     */
    public function test_guest_path_rolls_back_token_when_cache_stash_fails(): void
    {
        $log = $this->createPendingLog(null);

        $result = $this->providerWithThrowingCache('di')->verify($log->id, $this->validInput());

        $this->assertFalse($result->success, 'Cache stash 실패 시 인증을 성공으로 인정하면 안 된다');
        $this->assertSame('STORAGE_FAILED', $result->failureCode);
        $this->assertNull($result->claims['verification_token'] ?? null);

        $reload = $this->logRepository->findById($log->id);
        $this->assertNotSame(
            IdentityVerificationStatus::Verified->value,
            $reload->status->value,
            'Cache stash 실패 시 log 가 verified 로 남으면 안 된다 (롤백)'
        );
        $this->assertNull($reload->verification_token);
    }

    /**
     * 정상 로그인 경로는 통과하고 토큰 발급과 record 저장이 함께 남는다.
     *
     * @scenario case=login_all_present
     *
     * @effects success_with_record_stored
     *
     * @return void 반환값 없음
     */
    public function test_login_path_succeeds_and_stores_record_normally(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id);

        $result = $this->provider('di')->verify($log->id, $this->validInput());

        $this->assertTrue($result->success, '정상 경로는 인증 성공해야 한다 (회귀 방지)');
        $this->assertNotNull($result->claims['verification_token'] ?? null);

        $reload = $this->logRepository->findById($log->id);
        $this->assertSame(IdentityVerificationStatus::Verified->value, $reload->status->value);
        $this->assertNotNull($reload->verification_token);
        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $user->id]);
    }

    /**
     * 정상 provider — 외부 gateway/mapping 은 더미, record/cache 는 실제 바인딩.
     *
     * @param  string  $duplicateField  중복 판정 기준 식별값('di' | 'ci')
     * @return InicisIdentityProvider 실제 저장소를 쓰는 provider
     */
    private function provider(string $duplicateField): InicisIdentityProvider
    {
        return new InicisIdentityProvider(
            gateway: $this->makeNullGateway(),
            mappingRepository: $this->makeNullMappingRepository(),
            recordRepository: app(InicisIdentityRecordRepositoryInterface::class),
            cache: app(CacheInterface::class),
            config: ['duplicate_field' => $duplicateField],
        );
    }

    /**
     * record upsert 시 예외를 던지는 provider (로그인 경로 저장 실패 재현).
     *
     * @param  string  $duplicateField  중복 판정 기준 식별값('di' | 'ci')
     * @return InicisIdentityProvider record 저장이 실패하는 provider
     */
    private function providerWithThrowingRecord(string $duplicateField): InicisIdentityProvider
    {
        $throwingRecord = new class implements InicisIdentityRecordRepositoryInterface
        {
            /**
             * 회원 ID 로 record 를 찾는 더미 — 늘 찾지 못한다.
             *
             * @param  int  $userId  회원 ID
             * @return InicisIdentityRecord|null 늘 null
             */
            public function findByUserId(int $userId): ?InicisIdentityRecord
            {
                return null;
            }

            /**
             * 거래 ID 로 record 를 찾는 더미 — 늘 찾지 못한다.
             *
             * @param  string  $mTxId  이니시스 거래 ID
             * @return InicisIdentityRecord|null 늘 null
             */
            public function findByMTxId(string $mTxId): ?InicisIdentityRecord
            {
                return null;
            }

            /**
             * DI 해시로 record 를 찾는 더미 — 늘 찾지 못한다.
             *
             * @param  string  $diHash  DI 해시
             * @return InicisIdentityRecord|null 늘 null
             */
            public function findByDiHash(string $diHash): ?InicisIdentityRecord
            {
                return null;
            }

            /**
             * CI 해시로 record 를 찾는 더미 — 늘 찾지 못한다.
             *
             * @param  string  $ciHash  CI 해시
             * @return InicisIdentityRecord|null 늘 null
             */
            public function findByCiHash(string $ciHash): ?InicisIdentityRecord
            {
                return null;
            }

            /**
             * record 저장 실패 주입 — 늘 예외를 던진다.
             *
             * @param  int  $userId  회원 ID
             * @param  array<string, mixed>  $attributes  저장할 컬럼 값
             * @return InicisIdentityRecord 반환하지 않음(늘 예외)
             */
            public function upsertForUser(int $userId, array $attributes): InicisIdentityRecord
            {
                throw new \RuntimeException('record upsert 실패 주입');
            }

            /**
             * record 삭제 더미 — 늘 성공으로 답한다.
             *
             * @param  int  $userId  회원 ID
             * @return bool 늘 true
             */
            public function deleteByUserId(int $userId): bool
            {
                return true;
            }
        };

        return new InicisIdentityProvider(
            gateway: $this->makeNullGateway(),
            mappingRepository: $this->makeNullMappingRepository(),
            recordRepository: $throwingRecord,
            cache: app(CacheInterface::class),
            config: ['duplicate_field' => $duplicateField],
        );
    }

    /**
     * Cache put 시 예외를 던지는 provider (비로그인 경로 stash 실패 재현).
     *
     * 실제 CacheInterface 구현을 감싸고 put() 만 예외로 가로채는 데코레이터를 사용한다
     * (인터페이스 메서드가 많아 전체 더미 구현 대신 위임 + 부분 오버라이드).
     *
     * @param  string  $duplicateField  중복 판정 기준 식별값('di' | 'ci')
     * @return InicisIdentityProvider Cache 저장이 실패하는 provider
     */
    private function providerWithThrowingCache(string $duplicateField): InicisIdentityProvider
    {
        $throwingCache = new class(app(CacheInterface::class)) implements CacheInterface
        {
            /**
             * 감쌀 실제 캐시를 받는다.
             *
             * @param  CacheInterface  $inner  위임 대상 실제 캐시
             */
            public function __construct(private readonly CacheInterface $inner) {}

            /**
             * Cache stash 실패 주입 — 늘 예외를 던진다.
             *
             * @param  string  $key  캐시 키
             * @param  mixed  $value  저장할 값
             * @param  int|null  $ttl  유효 시간(초)
             * @return bool 반환하지 않음(늘 예외)
             */
            public function put(string $key, mixed $value, ?int $ttl = null): bool
            {
                throw new \RuntimeException('cache stash 실패 주입');
            }

            /**
             * 실제 캐시에 위임해 값을 읽는다.
             *
             * @param  string  $key  캐시 키
             * @param  mixed  $default  없을 때 돌려줄 값
             * @return mixed 캐시 값 또는 기본값
             */
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->inner->get($key, $default);
            }

            /**
             * 실제 캐시에 위임해 키 존재를 확인한다.
             *
             * @param  string  $key  캐시 키
             * @return bool 존재하면 true
             */
            public function has(string $key): bool
            {
                return $this->inner->has($key);
            }

            /**
             * 실제 캐시에 위임해 키를 지운다.
             *
             * @param  string  $key  캐시 키
             * @return bool 지웠으면 true
             */
            public function forget(string $key): bool
            {
                return $this->inner->forget($key);
            }

            /**
             * 실제 캐시에 위임해 없으면 계산해 저장한다.
             *
             * @param  string  $key  캐시 키
             * @param  callable  $callback  값 계산 콜백
             * @param  int|null  $ttl  유효 시간(초)
             * @param  array<int, string>  $tags  캐시 태그
             * @return mixed 캐시 값
             */
            public function remember(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed
            {
                return $this->inner->remember($key, $callback, $ttl, $tags);
            }

            /**
             * 실제 캐시에 위임해 쿼리 결과를 캐시한다.
             *
             * @param  string  $queryHash  쿼리 해시
             * @param  callable  $callback  값 계산 콜백
             * @param  int|null  $ttl  유효 시간(초)
             * @param  array<int, string>  $tags  캐시 태그
             * @return mixed 캐시 값
             */
            public function rememberQuery(string $queryHash, callable $callback, ?int $ttl = null, array $tags = []): mixed
            {
                return $this->inner->rememberQuery($queryHash, $callback, $ttl, $tags);
            }

            /**
             * 실제 캐시에 위임해 여러 키를 읽는다.
             *
             * @param  array<int, string>  $keys  캐시 키 목록
             * @return array<string, mixed> 키별 값
             */
            public function many(array $keys): array
            {
                return $this->inner->many($keys);
            }

            /**
             * 실제 캐시에 위임해 여러 값을 저장한다.
             *
             * @param  array<string, mixed>  $values  키별 값
             * @param  int|null  $ttl  유효 시간(초)
             * @return bool 저장했으면 true
             */
            public function putMany(array $values, ?int $ttl = null): bool
            {
                return $this->inner->putMany($values, $ttl);
            }

            /**
             * 실제 캐시에 위임해 전부 비운다.
             *
             * @return bool 비웠으면 true
             */
            public function flush(): bool
            {
                return $this->inner->flush();
            }

            /**
             * 실제 캐시에 위임해 태그 단위로 비운다.
             *
             * @param  array<int, string>  $tags  캐시 태그
             * @return bool 비웠으면 true
             */
            public function flushTags(array $tags): bool
            {
                return $this->inner->flushTags($tags);
            }

            /**
             * 실제 캐시에 위임해 값을 다시 계산해 저장한다.
             *
             * @param  string  $key  캐시 키
             * @param  callable  $callback  값 계산 콜백
             * @param  int|null  $ttl  유효 시간(초)
             * @param  array<int, string>  $tags  캐시 태그
             * @return mixed 새 캐시 값
             */
            public function refresh(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed
            {
                return $this->inner->refresh($key, $callback, $ttl, $tags);
            }

            /**
             * 실제 캐시에 위임해 태그 지원 여부를 답한다.
             *
             * @return bool 태그를 지원하면 true
             */
            public function supportsTags(): bool
            {
                return $this->inner->supportsTags();
            }

            /**
             * 실제 캐시에 위임해 저장소 이름을 답한다.
             *
             * @return string 저장소 이름
             */
            public function getStore(): string
            {
                return $this->inner->getStore();
            }

            /**
             * 저장소 전환 더미 — 자기 자신을 돌려준다.
             *
             * @param  string  $store  저장소 이름
             * @return static 자기 자신
             */
            public function withStore(string $store): static
            {
                return $this;
            }

            /**
             * 실제 캐시에 위임해 접두를 붙인 키를 만든다.
             *
             * @param  string  $key  캐시 키
             * @return string 접두를 붙인 키
             */
            public function resolveKey(string $key): string
            {
                return $this->inner->resolveKey($key);
            }
        };

        return new InicisIdentityProvider(
            gateway: $this->makeNullGateway(),
            mappingRepository: $this->makeNullMappingRepository(),
            recordRepository: app(InicisIdentityRecordRepositoryInterface::class),
            cache: $throwingCache,
            config: ['duplicate_field' => $duplicateField],
        );
    }

    /**
     * STEP3 성공 페이로드 mockup — overrides 로 특정 필드만 교체/제거.
     *
     * @param  array<string, mixed>  $overrides  교체·제거할 필드
     * @return array<string, mixed> 이니시스 STEP3 결과 페이로드
     */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'resultCode' => '0000',
            'resultMsg' => 'success',
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
            'purpose' => $userId !== null ? 'self_update' : 'signup',
            'channel' => 'ipin',
            'user_id' => $userId,
            'target_hash' => hash('sha256', 'atomicity-test@example.com'),
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
                throw new \LogicException('not used in atomicity test');
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
             * @return InicisChallengeMapping 반환하지 않음(늘 예외)
             */
            public function create(string $mtxid, string $challengeId): InicisChallengeMapping
            {
                throw new \LogicException('not used in atomicity test');
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
             * @return InicisChallengeMapping|null 늘 null
             */
            public function findByChallengeId(string $challengeId): ?InicisChallengeMapping
            {
                return null;
            }
        };
    }
}
