<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGatewayInterface;
use Plugins\Sirsoft\VerificationKginicis\Support\InicisIdentityHasher;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * 로그인 회원 본인인증의 동일인 충돌 검사 (#40 K12 · R11)
 *
 * 가입 경로만 동일인 중복을 막으면, 일단 다른 정보로 가입한 뒤 마이페이지 본인인증으로 **다른 회원에 이미 묶인** 본인인증
 * 결과를 자기 계정에 붙일 수 있었다. 로그인 회원 본인인증(log.user_id 있음)도 다른 회원의 DI/CI 와 겹치면 실패하고
 * 기록을 만들지 않는다. 같은 회원의 재인증·차단 설정 끔(가족 휴대폰 공유)은 종전대로 통과한다.
 */
class InicisLoggedInDuplicateIdentityTest extends PluginTestCase
{
    private InicisIdentityProvider $provider;

    private IdentityVerificationLogRepositoryInterface $logRepository;

    private InicisIdentityRecordRepositoryInterface $recordRepository;

    /**
     * 실제 저장소와 더미 gateway 로 프로바이더를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('g7_settings.plugins.sirsoft-verification_kginicis', ['duplicate_block_enabled' => true, 'duplicate_field' => 'di']);

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->recordRepository = app(InicisIdentityRecordRepositoryInterface::class);
        $this->provider = $this->makeProvider('di');
    }

    /**
     * 로그인 회원이 다른 회원의 DI 로 본인인증하면 실패하고 기록이 생기지 않는다.
     *
     * @scenario flow=self_idv_verify, existing_record_state=same_hash_other_user, duplicate_field=di
     *
     * @effects verify_fails_when_logged_in_identity_belongs_to_other_user, no_record_for_duplicate_logged_in_identity
     *
     * @return void 반환값 없음
     */
    public function test_logged_in_verify_fails_when_di_belongs_to_other_user(): void
    {
        $owner = User::factory()->create();
        $this->createRecordForUser($owner->id, ['di_hash' => InicisIdentityHasher::hash('DI-OWNER')]);

        $other = User::factory()->create();
        $log = $this->createPendingLog(userId: $other->id);

        $result = $this->provider->verify($log->id, $this->validInput(di: 'DI-OWNER'));

        $this->assertFalse($result->success);
        $this->assertSame('IDENTITY_DUPLICATE', $result->failureCode);
        $this->assertSame('sirsoft-verification_kginicis::exceptions.duplicate_register', $result->failureReason);
        $this->assertNull($this->recordRepository->findByUserId($other->id), '충돌한 본인인증으로 기록을 만들면 안 된다');
        $reload = $this->logRepository->findById($log->id);
        $this->assertSame(IdentityVerificationStatus::Failed->value, $reload->status->value);
        $this->assertNull($reload->verification_token);
    }

    /**
     * CI 기준 설정에서도 다른 회원의 CI 와 겹치면 실패한다.
     *
     * @scenario flow=self_idv_verify, existing_record_state=same_hash_other_user, duplicate_field=ci
     *
     * @effects verify_fails_when_logged_in_identity_belongs_to_other_user
     *
     * @return void 반환값 없음
     */
    public function test_logged_in_verify_fails_when_ci_belongs_to_other_user(): void
    {
        Config::set('g7_settings.plugins.sirsoft-verification_kginicis', ['duplicate_block_enabled' => true, 'duplicate_field' => 'ci']);
        $owner = User::factory()->create();
        $this->createRecordForUser($owner->id, ['ci_hash' => InicisIdentityHasher::hash('CI-OWNER')]);

        $other = User::factory()->create();
        $log = $this->createPendingLog(userId: $other->id);

        $result = $this->makeProvider('ci')->verify($log->id, $this->validInput(di: 'DI-X', ci: 'CI-OWNER'));

        $this->assertFalse($result->success);
        $this->assertSame('IDENTITY_DUPLICATE', $result->failureCode);
    }

    /**
     * 같은 회원의 재인증은 통과한다.
     *
     * @scenario flow=self_idv_verify, existing_record_state=same_user_match
     *
     * @effects same_user_reverification_passes
     *
     * @return void 반환값 없음
     */
    public function test_same_user_reverification_passes(): void
    {
        $user = User::factory()->create();
        $this->createRecordForUser($user->id, ['di_hash' => InicisIdentityHasher::hash('DI-SELF')]);
        $log = $this->createPendingLog(userId: $user->id);

        $this->assertTrue($this->provider->verify($log->id, $this->validInput(di: 'DI-SELF'))->success);
    }

    /**
     * 운영자가 동일인 중복 차단을 끄면(가족 휴대폰 공유 등) 겹쳐도 통과한다 — 가입 경로와 같다.
     *
     * @scenario flow=self_idv_verify, duplicate_block_enabled=false, existing_record_state=same_hash_other_user
     *
     * @effects logged_in_duplicate_allowed_when_block_disabled
     *
     * @return void 반환값 없음
     */
    public function test_logged_in_duplicate_allowed_when_block_disabled(): void
    {
        Config::set('g7_settings.plugins.sirsoft-verification_kginicis', ['duplicate_block_enabled' => false, 'duplicate_field' => 'di']);
        $owner = User::factory()->create();
        $this->createRecordForUser($owner->id, ['di_hash' => InicisIdentityHasher::hash('DI-FAMILY')]);

        $other = User::factory()->create();
        $log = $this->createPendingLog(userId: $other->id);

        $this->assertTrue($this->provider->verify($log->id, $this->validInput(di: 'DI-FAMILY'))->success);
        $this->assertNotNull($this->recordRepository->findByUserId($other->id));
    }

    /**
     * 가입(비로그인) 경로는 이 검사를 타지 않는다 — 가입 차단 listener 가 따로 막는다(종전 동작 불변).
     *
     * @scenario flow=self_idv_verify, log_user_id=null
     *
     * @effects guest_flow_unchanged_by_logged_in_duplicate_check
     *
     * @return void 반환값 없음
     */
    public function test_guest_flow_is_unchanged(): void
    {
        $owner = User::factory()->create();
        $this->createRecordForUser($owner->id, ['di_hash' => InicisIdentityHasher::hash('DI-GUEST')]);
        $log = $this->createPendingLog(userId: null);

        $this->assertTrue($this->provider->verify($log->id, $this->validInput(di: 'DI-GUEST'))->success);
    }

    /**
     * 설정한 판정 키로 프로바이더를 만듭니다.
     *
     * @param  string  $field  판정 키 (di 또는 ci)
     * @return InicisIdentityProvider 프로바이더
     */
    private function makeProvider(string $field): InicisIdentityProvider
    {
        return new InicisIdentityProvider(
            gateway: $this->makeNullGateway(),
            mappingRepository: $this->makeNullMappingRepository(),
            recordRepository: $this->recordRepository,
            cache: app(CacheInterface::class),
            config: ['duplicate_field' => $field],
        );
    }

    /**
     * STEP3 성공 페이로드를 만듭니다.
     *
     * @param  string  $di  DI 평문
     * @param  string  $ci  CI 평문
     * @return array<string, mixed> 결과 페이로드
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
     * 인증 대기 로그를 만듭니다.
     *
     * @param  int|null  $userId  회원 ID (비로그인 가입은 null)
     * @return IdentityVerificationLog 로그
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
     * 이 흐름에서 호출되지 않는 더미 gateway 를 만듭니다.
     *
     * @return InicisGatewayInterface 더미 gateway
     */
    private function makeNullGateway(): InicisGatewayInterface
    {
        return new class implements InicisGatewayInterface
        {
            /**
             * 인증 주소 검증 — 이 흐름에서는 쓰지 않습니다.
             *
             * @param  string  $url  인증 주소
             * @return bool 항상 거짓
             */
            public function validateAuthUrl(string $url): bool
            {
                return false;
            }

            /**
             * 결과 조회 — 이 흐름에서는 호출되면 안 됩니다.
             *
             * @param  string  $authRequestUrl  인증 요청 주소
             * @param  string  $txId  거래 번호
             * @param  string  $token  인증 토큰
             * @return array<string, mixed> 호출되지 않음
             */
            public function verifyResult(string $authRequestUrl, string $txId, string $token): array
            {
                throw new \LogicException('not used in binding test');
            }

            /**
             * 거래 번호 생성 — 이 흐름에서는 쓰지 않습니다.
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
     * 이 흐름에서 호출되지 않는 더미 매핑 저장소를 만듭니다.
     *
     * @return InicisChallengeMappingRepositoryInterface 더미 저장소
     */
    private function makeNullMappingRepository(): InicisChallengeMappingRepositoryInterface
    {
        return new class implements InicisChallengeMappingRepositoryInterface
        {
            /**
             * 매핑 생성 — 이 흐름에서는 호출되면 안 됩니다.
             *
             * @param  string  $mtxid  거래 번호
             * @param  string  $challengeId  인증 식별자
             * @return \Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping 호출되지 않음
             */
            public function create(string $mtxid, string $challengeId): \Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping
            {
                throw new \LogicException('not used in binding test');
            }

            /**
             * 거래 번호로 인증 식별자 조회 — 이 흐름에서는 없음으로 답합니다.
             *
             * @param  string  $mtxid  거래 번호
             * @return string|null 항상 없음
             */
            public function findChallengeIdByMtxid(string $mtxid): ?string
            {
                return null;
            }

            /**
             * 인증 식별자로 매핑 조회 — 이 흐름에서는 없음으로 답합니다.
             *
             * @param  string  $challengeId  인증 식별자
             * @return \Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping|null 항상 없음
             */
            public function findByChallengeId(string $challengeId): ?\Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping
            {
                return null;
            }
        };
    }

    /**
     * 회원의 본인확인 기록을 심습니다.
     *
     * @param  int  $userId  회원 ID
     * @param  array<string, mixed>  $hashes  덮어쓸 해시 컬럼
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
