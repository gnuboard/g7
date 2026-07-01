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

    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
    }

    /**
     * @scenario case=login_record_upsert_throws
     *
     * @effects storage_failure_rolls_back_token
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
     * @scenario case=guest_cache_stash_throws
     *
     * @effects storage_failure_rolls_back_token
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
     * @scenario case=login_all_present
     *
     * @effects success_with_record_stored
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
     */
    private function providerWithThrowingRecord(string $duplicateField): InicisIdentityProvider
    {
        $throwingRecord = new class implements InicisIdentityRecordRepositoryInterface
        {
            public function findByUserId(int $userId): ?InicisIdentityRecord
            {
                return null;
            }

            public function findByMTxId(string $mTxId): ?InicisIdentityRecord
            {
                return null;
            }

            public function findByDiHash(string $diHash): ?InicisIdentityRecord
            {
                return null;
            }

            public function findByCiHash(string $ciHash): ?InicisIdentityRecord
            {
                return null;
            }

            public function upsertForUser(int $userId, array $attributes): InicisIdentityRecord
            {
                throw new \RuntimeException('record upsert 실패 주입');
            }

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
     */
    private function providerWithThrowingCache(string $duplicateField): InicisIdentityProvider
    {
        $throwingCache = new class(app(CacheInterface::class)) implements CacheInterface
        {
            public function __construct(private readonly CacheInterface $inner) {}

            public function put(string $key, mixed $value, ?int $ttl = null): bool
            {
                throw new \RuntimeException('cache stash 실패 주입');
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->inner->get($key, $default);
            }

            public function has(string $key): bool
            {
                return $this->inner->has($key);
            }

            public function forget(string $key): bool
            {
                return $this->inner->forget($key);
            }

            public function remember(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed
            {
                return $this->inner->remember($key, $callback, $ttl, $tags);
            }

            public function rememberQuery(string $queryHash, callable $callback, ?int $ttl = null, array $tags = []): mixed
            {
                return $this->inner->rememberQuery($queryHash, $callback, $ttl, $tags);
            }

            public function many(array $keys): array
            {
                return $this->inner->many($keys);
            }

            public function putMany(array $values, ?int $ttl = null): bool
            {
                return $this->inner->putMany($values, $ttl);
            }

            public function flush(): bool
            {
                return $this->inner->flush();
            }

            public function flushTags(array $tags): bool
            {
                return $this->inner->flushTags($tags);
            }

            public function refresh(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed
            {
                return $this->inner->refresh($key, $callback, $ttl, $tags);
            }

            public function supportsTags(): bool
            {
                return $this->inner->supportsTags();
            }

            public function getStore(): string
            {
                return $this->inner->getStore();
            }

            public function withStore(string $store): static
            {
                return $this;
            }

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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
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

    private function makeNullGateway(): InicisGatewayInterface
    {
        return new class implements InicisGatewayInterface
        {
            public function validateAuthUrl(string $url): bool
            {
                return false;
            }

            public function verifyResult(string $authRequestUrl, string $txId, string $token): array
            {
                throw new \LogicException('not used in atomicity test');
            }

            public function generateMTxId(): string
            {
                return '';
            }
        };
    }

    private function makeNullMappingRepository(): InicisChallengeMappingRepositoryInterface
    {
        return new class implements InicisChallengeMappingRepositoryInterface
        {
            public function create(string $mtxid, string $challengeId): InicisChallengeMapping
            {
                throw new \LogicException('not used in atomicity test');
            }

            public function findChallengeIdByMtxid(string $mtxid): ?string
            {
                return null;
            }

            public function findByChallengeId(string $challengeId): ?InicisChallengeMapping
            {
                return null;
            }
        };
    }
}
