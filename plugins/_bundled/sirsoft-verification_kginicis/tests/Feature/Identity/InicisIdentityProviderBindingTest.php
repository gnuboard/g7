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

    public function test_verify_succeeds_for_guest_signup_flow_skipping_binding_check(): void
    {
        // log.user_id = null (비로그인 가입 흐름) — binding 검증 자체가 skip 되어야 함
        $log = $this->createPendingLog(userId: null);

        $result = $this->provider->verify($log->id, $this->validInput(di: 'DI-NEW'));

        $this->assertTrue($result->success);
    }

    public function test_verify_succeeds_when_user_has_no_existing_record(): void
    {
        // log.user_id 있지만 record 없음 — permissive 통과 (IDV 미가입자)
        $user = User::factory()->create();
        $log = $this->createPendingLog(userId: $user->id);

        $result = $this->provider->verify($log->id, $this->validInput(di: 'DI-LEGACY-USER'));

        $this->assertTrue($result->success);
    }

    public function test_verify_succeeds_when_existing_record_di_hash_matches(): void
    {
        $user = User::factory()->create();
        $di = 'DI-USER-OWN';
        $this->createRecordForUser($user->id, ['di_hash' => InicisIdentityHasher::hash($di)]);
        $log = $this->createPendingLog(userId: $user->id);

        $result = $this->provider->verify($log->id, $this->validInput(di: $di));

        $this->assertTrue($result->success);
    }

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
     * @return array<string, mixed>
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
     */
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
                throw new \LogicException('not used in binding test');
            }

            public function generateMTxId(): string
            {
                return '';
            }
        };
    }

    /**
     * binding 검증 흐름에서 호출되지 않는 더미 mapping repository.
     */
    private function makeNullMappingRepository(): InicisChallengeMappingRepositoryInterface
    {
        return new class implements InicisChallengeMappingRepositoryInterface
        {
            public function create(string $mtxid, string $challengeId): \Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping
            {
                throw new \LogicException('not used in binding test');
            }

            public function findChallengeIdByMtxid(string $mtxid): ?string
            {
                return null;
            }

            public function findByChallengeId(string $challengeId): ?\Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping
            {
                return null;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $hashes
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
