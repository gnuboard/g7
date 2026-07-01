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
use Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGatewayInterface;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * 회귀 테스트 — 인증 결과 처리: 신원 핵심값 가드 + 부가 필드 정합 저장.
 *
 * 배경: verify() 가 이니시스 인증 성공(resultCode=0000) 시 신원 핵심값(이름·생년월일·설정 기준
 * 식별값) 유무와 무관하게 status=verified + 토큰을 발급했다. 식별값이 비면 record 에 빈 식별값이
 * 저장되어 중복가입 차단(di_hash/ci_hash 가 유일 판정 키)이 조용히 무력화된다. 또한 휴대폰 등
 * 부가 필드 누락 시 buildPiiPayload 가 Crypt::encryptString('') 로 "암호화된 빈 문자열" 을 NOT NULL
 * 컬럼에 저장해 복호화 시 빈 칸이 나오는 오염 레코드를 만든다.
 *
 * 수정:
 *  (1) 신원 핵심값 가드 — userName/userBirthday/userPhone/설정 기준 식별값(duplicate_field=di→userDi,
 *      ci→userCi) 중 하나라도 비면 verify() failure(INCOMPLETE_IDENTITY) + status=failed (토큰 미발급).
 *      법규상 정상 본인확인이면 이 값들은 반드시 제공되므로, 누락은 비정상 응답으로 보고 차단.
 *  (2) 부가 필드 정합 저장 — buildPiiPayload 의 빈값 필드를 null 로 저장(암호화 빈문자열 금지,
 *      DI/CI/CI2 와 동일 패턴). 신원 핵심값(name/phone/birthday)은 가드가 누락을 차단하므로 정상
 *      경로에선 항상 채워지며, null 저장은 비핵심 부가 필드(ci2 등)에 한해 발생한다.
 *      마이그레이션으로 해당 컬럼 nullable 화.
 *
 * 검증 매트릭스:
 *  - userName 누락 → failure(INCOMPLETE_IDENTITY), status=failed, 토큰 미발급
 *  - userBirthday 누락 → failure(INCOMPLETE_IDENTITY)
 *  - userPhone 누락 → failure(INCOMPLETE_IDENTITY) (휴대폰도 신원 핵심값 — 누락 시 차단)
 *  - duplicate_field=di + userDi 누락(ci 만) → failure (설정 기준값 부재)
 *  - duplicate_field=ci + userCi 누락(di 만) → failure
 *  - CI·DI 둘 다 누락 → failure
 *  - 신원 핵심값 정상 + 비핵심 부가 필드(userCi2) 누락 → success + ci2_encrypted=null (암호화 빈문자열 아님)
 *  - 전 필드 정상 → success + 토큰 발급 + 암호화 저장 (회귀 방지)
 *  - 설정 기준값(di) 정상 + 비기준 식별값(ci) 도 와 있음 → success + ci 도 저장 (오는 건 다 받음 회귀 방지)
 *
 * 실제 DB + 실제 provider 객체 — 외부 gateway 는 더미 (성공 경로에서 미호출).
 *
 * @since 1.0.0-beta.1
 */
class InicisPartialDataStorageTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    private InicisIdentityRecordRepositoryInterface $recordRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->recordRepository = app(InicisIdentityRecordRepositoryInterface::class);
    }

    /**
     * @scenario case=name_absent
     *
     * @effects incomplete_identity_blocked_no_token
     */
    public function test_blocks_when_user_name_missing(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id);

        $result = $this->provider('di')->verify($log->id, $this->validInput(['userName' => '']));

        $this->assertFalse($result->success, '이름 없이 본인인증을 통과하면 안 된다');
        $this->assertSame('INCOMPLETE_IDENTITY', $result->failureCode);
        $this->assertNull($result->claims['verification_token'] ?? null);

        $reload = $this->logRepository->findById($log->id);
        $this->assertSame(IdentityVerificationStatus::Failed->value, $reload->status->value);
        $this->assertNull($reload->verification_token);
    }

    /**
     * @scenario case=birthday_absent
     *
     * @effects incomplete_identity_blocked_no_token
     */
    public function test_blocks_when_birthday_missing(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id);

        $result = $this->provider('di')->verify($log->id, $this->validInput(['userBirthday' => '']));

        $this->assertFalse($result->success, '생년월일 없이 본인인증을 통과하면 안 된다');
        $this->assertSame('INCOMPLETE_IDENTITY', $result->failureCode);
    }

    /**
     * @scenario case=di_setting_di_absent
     *
     * @effects incomplete_identity_blocked_no_token
     */
    public function test_blocks_when_di_setting_and_di_missing(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id);

        // duplicate_field=di 인데 응답에 di 가 없고 ci 만 있음 → 설정 기준값 부재로 차단
        $result = $this->provider('di')->verify(
            $log->id,
            $this->validInput(['userDi' => '', 'userCi' => 'CI-'.Str::random(8)])
        );

        $this->assertFalse($result->success, 'DI 설정인데 DI 가 없으면 통과하면 안 된다');
        $this->assertSame('INCOMPLETE_IDENTITY', $result->failureCode);
    }

    /**
     * @scenario case=ci_setting_ci_absent
     *
     * @effects incomplete_identity_blocked_no_token
     */
    public function test_blocks_when_ci_setting_and_ci_missing(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id);

        // duplicate_field=ci 인데 응답에 ci 가 없고 di 만 있음 → 설정 기준값 부재로 차단
        $result = $this->provider('ci')->verify(
            $log->id,
            $this->validInput(['userCi' => '', 'userDi' => 'DI-'.Str::random(8)])
        );

        $this->assertFalse($result->success, 'CI 설정인데 CI 가 없으면 통과하면 안 된다');
        $this->assertSame('INCOMPLETE_IDENTITY', $result->failureCode);
    }

    /**
     * @scenario case=both_identifiers_absent
     *
     * @effects incomplete_identity_blocked_no_token
     */
    public function test_blocks_when_both_identifiers_missing(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id);

        $result = $this->provider('di')->verify(
            $log->id,
            $this->validInput(['userDi' => '', 'userCi' => ''])
        );

        $this->assertFalse($result->success, '식별값이 전무하면 통과하면 안 된다');
        $this->assertSame('INCOMPLETE_IDENTITY', $result->failureCode);
    }

    /**
     * @scenario case=phone_absent
     *
     * @effects incomplete_identity_blocked_no_token
     */
    public function test_blocks_when_phone_missing(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id);

        // 휴대폰은 아이핀(휴대폰 본인확인) 채널에서 항상 응답되는 신원 핵심값 — 누락 시 차단.
        // (Chrome MCP 실측 2026-06-23: 이름·휴대폰·생년월일·CI·DI 모두 FILLED 확인)
        $result = $this->provider('di')->verify($log->id, $this->validInput(['userPhone' => '']));

        $this->assertFalse($result->success, '휴대폰은 신원 핵심값이므로 누락 시 차단되어야 한다');
        $this->assertSame('INCOMPLETE_IDENTITY', $result->failureCode);

        $reload = $this->logRepository->findById($log->id);
        $this->assertSame(IdentityVerificationStatus::Failed->value, $reload->status->value);
    }

    /**
     * 부가 필드(CI2 등) 누락은 통과 + null 정합 저장 — 신원 핵심값(이름·휴대폰·생년월일·기준 식별값)
     * 이 모두 정상일 때 비핵심 필드는 빈 암호문 대신 null 로 저장되는지 검증.
     *
     * @scenario case=aux_field_absent
     *
     * @effects success_with_aux_field_stored_as_null
     */
    public function test_passes_and_stores_null_for_optional_field(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id);

        // 신원 핵심값 전부 정상, 비핵심(userCi2)만 누락 → 통과
        $result = $this->provider('di')->verify($log->id, $this->validInput(['userCi2' => '']));

        $this->assertTrue($result->success, '비핵심 필드 누락은 인증을 막지 않는다');
        $this->assertNotEmpty($result->claims['verification_token'] ?? null);

        $record = $this->recordRepository->findByUserId($user->id);
        $this->assertNotNull($record);
        $this->assertNull($record->ci2_encrypted, '누락 비핵심 필드는 null 로 저장 (암호화 빈문자열 금지)');
    }

    /**
     * @scenario case=all_present
     *
     * @effects success_with_encrypted_storage
     */
    public function test_passes_and_encrypts_when_all_present(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id);

        $result = $this->provider('di')->verify($log->id, $this->validInput());

        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->claims['verification_token'] ?? null);

        $record = $this->recordRepository->findByUserId($user->id);
        $this->assertNotNull($record);
        $this->assertNotNull($record->phone_encrypted);
        $this->assertSame('01012345678', Crypt::decryptString($record->phone_encrypted));
    }

    /**
     * @scenario case=both_identifiers_present
     *
     * @effects success_stores_both_identifiers
     */
    public function test_stores_both_identifiers_even_with_di_setting(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id);

        // 설정은 di 지만 응답에 ci 도 와 있으면 둘 다 저장되어야 한다 (오는 건 다 받음).
        $result = $this->provider('di')->verify(
            $log->id,
            $this->validInput(['userDi' => 'DI-XYZ', 'userCi' => 'CI-XYZ'])
        );

        $this->assertTrue($result->success);

        $record = $this->recordRepository->findByUserId($user->id);
        $this->assertNotNull($record->di_hash);
        $this->assertNotNull($record->ci_hash, '비기준 식별값(ci)도 와 있으면 저장되어야 한다');
    }

    /**
     * 설정값을 주입한 provider 인스턴스 — 외부 gateway/mapping 은 더미.
     *
     * @param  string  $duplicateField  'di' | 'ci'
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
     * STEP3 성공 페이로드 mockup — overrides 로 특정 필드만 교체/제거.
     *
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

    private function createPendingLog(int $userId): IdentityVerificationLog
    {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => InicisIdentityProvider::PROVIDER_ID,
            'purpose' => 'self_update',
            'channel' => 'ipin',
            'user_id' => $userId,
            'target_hash' => hash('sha256', 'partial-test@example.com'),
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
                throw new \LogicException('not used in partial data test');
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
                throw new \LogicException('not used in partial data test');
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
