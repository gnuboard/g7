<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\ProvidesVerifiedClaimsInterface;
use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Extension\Cache\PluginCacheDriver;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\DTO\VerifiedClaims;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\FieldDefinition;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityClaimsService;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * NHN KCP 본인확인이 확인한 이름, 휴대폰, 생년월일, 성별을 코어의 중립 계약으로 내준다.
 *
 * 가입 전에는 회원이 없어 인증 직후 넣어 둔 임시 저장 값에서, 회원이 있으면 그 회원의 기록에서 값을 꺼낸다.
 * 값은 코어가 쓰는 모양(휴대폰은 숫자만, 생년월일은 `YYYY-MM-DD`, 성별은 `male`, `female`)으로 바꿔 준다.
 * KCP 테스트 서버는 생년월일과 성별을 빈 값으로 주므로 테스트 모드에서는 그 둘이 없다.
 * 실제 저장소와 실제 임시 저장소를 쓰고, KCP 와의 통신은 일어나지 않는다(확인 단계는 통신하지 않는다).
 */
class KcpVerifiedClaimsTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    private KcpIdentityRecordRepositoryInterface $recordRepository;

    private CacheInterface $cache;

    /**
     * 저장소와, 플러그인이 쓰는 것과 같은 접두의 임시 저장소를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->recordRepository = app(KcpIdentityRecordRepositoryInterface::class);
        $this->cache = new PluginCacheDriver('sirsoft-verification_nhnkcp');
    }

    /**
     * 설정을 주입한 Provider 를 만듭니다 (컨테이너가 플러그인 임시 저장소를 넣어 준다).
     *
     * @param  bool  $isTestMode  테스트 모드 여부
     * @return KcpIdentityProvider 설정을 주입한 본인인증 프로바이더
     */
    private function provider(bool $isTestMode = false): KcpIdentityProvider
    {
        return app()->makeWith(KcpIdentityProvider::class, [
            'config' => ['duplicate_field' => 'di', 'is_test_mode' => $isTestMode],
        ]);
    }

    /**
     * KCP 결과조회 성공 응답을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 응답
     */
    private function certData(array $overrides = []): array
    {
        return array_merge([
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'user_name' => '홍길동',
            'birth_day' => '19900517',
            'phone_no' => '01012345678',
            'comm_id' => 'SKT',
            'sex_code' => '01',
            'local_code' => '01',
            'ci' => 'CI-'.Str::random(12),
            'di' => 'DI-'.Str::random(12),
        ], $overrides);
    }

    /**
     * 확인을 기다리는 본인인증 요청 행을 만듭니다.
     *
     * @param  int|null  $userId  회원 ID (가입 전이면 null)
     * @param  string  $purpose  본인인증 목적
     * @return IdentityVerificationLog 요청 행
     */
    private function pendingLog(?int $userId, string $purpose = 'signup'): IdentityVerificationLog
    {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => KcpIdentityProvider::PROVIDER_ID,
            'purpose' => $purpose,
            'channel' => KcpIdentityProvider::CHANNEL,
            'user_id' => $userId,
            'target_hash' => hash('sha256', 'claims@example.com'),
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => 'api.identity.verify',
            'verification_token' => null,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['ordr_idxx' => 'G7-'.Str::random(8)],
        ]);
    }

    /**
     * 본인인증을 마친 요청 행을 만듭니다 (실제 확인 단계를 거친다).
     *
     * @param  int|null  $userId  회원 ID (가입 전이면 null)
     * @param  array<string, mixed>  $overrides  KCP 응답에서 덮어쓸 값
     * @param  string  $purpose  본인인증 목적
     * @param  bool  $isTestMode  테스트 모드 여부
     * @return IdentityVerificationLog 확인을 마친 요청 행
     */
    private function verifiedLog(?int $userId, array $overrides = [], string $purpose = 'signup', bool $isTestMode = false): IdentityVerificationLog
    {
        $log = $this->pendingLog($userId, $purpose);
        $result = $this->provider($isTestMode)->verify($log->id, $this->certData($overrides));
        $this->assertTrue($result->success, '본인인증 확인이 실패했다: '.($result->failureCode ?? ''));

        return $this->logRepository->findById($log->id);
    }

    /**
     * 가입 전 요청 행은 회원이 없으므로 임시 저장 값에서 인증 값을 돌려준다.
     *
     * @scenario case=pending_cache
     *
     * @effects provider_implements_verified_claims_contract, guest_log_claims_come_from_pending_cache
     *
     * @return void 반환값 없음
     */
    public function test_guest_log_returns_claims_from_pending_cache(): void
    {
        $provider = $this->provider();
        $this->assertInstanceOf(ProvidesVerifiedClaimsInterface::class, $provider);
        $this->assertSame(VerifiedClaims::CLAIMS, $provider->supportedClaims());

        $log = $this->verifiedLog(null);
        $this->assertNull($log->user_id);
        $this->assertIsArray($this->cache->get(KcpIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$log->id));

        $claims = $provider->verifiedClaims($log, null);

        $this->assertNotNull($claims);
        $this->assertSame(
            ['name' => '홍길동', 'mobile' => '01012345678', 'birth_date' => '1990-05-17', 'gender' => 'male'],
            $claims->toArray(),
        );
    }

    /**
     * 가입을 마치면 임시 저장 값은 지워지고, 같은 요청 행의 인증 값은 회원 기록에서 나온다.
     *
     * @scenario case=record_after_signup
     *
     * @effects signed_up_member_claims_come_from_record, pending_cache_cleared_after_signup
     *
     * @return void 반환값 없음
     */
    public function test_after_signup_claims_come_from_member_record(): void
    {
        $log = $this->verifiedLog(null, ['sex_code' => '02']);
        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, ['verification_token' => $log->verification_token]);

        $this->assertNull($this->cache->get(KcpIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$log->id), '가입 뒤에도 임시 저장 값이 남았다');
        $record = $this->recordRepository->findByUserId($user->id);
        $this->assertNotNull($record);
        $this->assertSame((string) $log->id, (string) $record->latest_log_id);

        $fresh = $this->logRepository->findById($log->id);
        $expected = ['name' => '홍길동', 'mobile' => '01012345678', 'birth_date' => '1990-05-17', 'gender' => 'female'];

        $this->assertSame($expected, $this->provider()->verifiedClaims($fresh, $user)?->toArray());
        // 코어가 회원을 넘기지 않아도 요청 행에 적힌 회원으로 찾는다.
        $this->assertSame($expected, $this->provider()->verifiedClaims($fresh, null)?->toArray());
    }

    /**
     * 로그인 회원의 본인인증은 그 회원의 기록에서 값을 돌려주고, 다시 인증하면 옛 요청 행의 값은 더는 주지 않는다.
     *
     * @scenario case=record_logged_in
     *
     * @effects logged_in_member_claims_come_from_record, superseded_log_returns_null, other_member_log_returns_null
     *
     * @return void 반환값 없음
     */
    public function test_logged_in_member_claims_come_from_latest_record(): void
    {
        $user = User::factory()->create();
        $identity = ['di' => 'DI-'.Str::random(12), 'ci' => 'CI-'.Str::random(12)];

        $first = $this->verifiedLog($user->id, [...$identity, 'user_name' => '옛이름'], 'self_update');
        $this->assertSame('옛이름', $this->provider()->verifiedClaims($first, $user)?->name);

        $second = $this->verifiedLog($user->id, [...$identity, 'user_name' => '개명한이름', 'phone_no' => '01077776666'], 'self_update');

        $claims = $this->provider()->verifiedClaims($second, $user);
        $this->assertSame('개명한이름', $claims?->name);
        $this->assertSame('01077776666', $claims?->mobile);

        // 기록은 회원당 한 줄이라 새 인증이 덮어썼다. 옛 요청 행의 값은 더는 알 수 없다.
        $this->assertNull($this->provider()->verifiedClaims($this->logRepository->findById($first->id), $user));

        // 다른 회원에게는 주지 않는다.
        $this->assertNull($this->provider()->verifiedClaims($second, User::factory()->create()));
    }

    /**
     * 휴대폰의 하이픈을 떼고, 생년월일을 `YYYY-MM-DD` 로, 성별 코드를 `male`, `female` 로 바꾼다. 없는 날짜는 주지 않는다.
     *
     * @scenario case=conversion
     *
     * @effects mobile_normalized_to_digits, birthday_converted_to_iso_date, gender_converted_to_core_values, invalid_birthday_dropped
     *
     * @return void 반환값 없음
     */
    public function test_values_are_converted_to_core_shapes(): void
    {
        $female = $this->provider()->verifiedClaims($this->verifiedLog(null, [
            'phone_no' => '010-1234-5678', 'birth_day' => '20001231', 'sex_code' => '02',
        ]), null);
        $this->assertSame('01012345678', $female?->mobile);
        $this->assertSame('2000-12-31', $female?->birthDate);
        $this->assertSame(VerifiedClaims::GENDER_FEMALE, $female?->gender);

        $male = $this->provider()->verifiedClaims($this->verifiedLog(null, ['sex_code' => '01']), null);
        $this->assertSame(VerifiedClaims::GENDER_MALE, $male?->gender);

        $badDate = $this->provider()->verifiedClaims($this->verifiedLog(null, ['birth_day' => '19901340']), null);
        $this->assertNotNull($badDate);
        $this->assertNull($badDate->birthDate, '없는 날짜가 생년월일로 나갔다');
        $this->assertSame('홍길동', $badDate->name);
    }

    /**
     * 테스트 모드의 KCP 서버는 생년월일과 성별을 빈 값으로 준다. 그 둘은 null 이고, 줄 수 있는 값 목록에서도 빠진다.
     *
     * @scenario case=test_mode_empty
     *
     * @effects test_mode_empty_birthday_and_gender_return_null, test_mode_supported_claims_exclude_birthday_and_gender
     *
     * @return void 반환값 없음
     */
    public function test_test_mode_empty_values_are_null(): void
    {
        $provider = $this->provider(true);
        $this->assertSame([VerifiedClaims::CLAIM_NAME, VerifiedClaims::CLAIM_MOBILE], $provider->supportedClaims());

        $log = $this->verifiedLog(null, ['birth_day' => '', 'sex_code' => '', 'local_code' => ''], 'signup', true);
        $claims = $provider->verifiedClaims($log, null);

        $this->assertNotNull($claims);
        $this->assertNull($claims->birthDate);
        $this->assertNull($claims->gender);
        $this->assertSame(['name' => '홍길동', 'mobile' => '01012345678'], $claims->toArray());

        // 가입 뒤 기록에서 꺼내도 같다.
        $user = User::factory()->create();
        HookManager::doAction('core.auth.after_register', $user, ['verification_token' => $log->verification_token]);
        $this->assertSame(
            ['name' => '홍길동', 'mobile' => '01012345678'],
            $provider->verifiedClaims($this->logRepository->findById($log->id), $user)?->toArray(),
        );
    }

    /**
     * 다른 요청의 임시 저장 값이나 다른 수단의 요청 행으로는 값을 주지 않는다.
     *
     * @scenario case=other_challenge
     *
     * @effects other_challenge_cache_not_used, unverified_or_foreign_provider_log_returns_null
     *
     * @return void 반환값 없음
     */
    public function test_other_challenge_cache_is_not_used(): void
    {
        $stashed = $this->verifiedLog(null);
        $this->assertNotNull($this->provider()->verifiedClaims($stashed, null));

        // 임시 저장 값 없이 확인만 끝난 다른 요청 행
        $other = $this->pendingLog(null);
        $this->logRepository->updateById((string) $other->id, [
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => Str::random(48),
            'verified_at' => now(),
        ]);
        $this->assertNull($this->provider()->verifiedClaims($this->logRepository->findById($other->id), null));

        // 확인을 마치지 않은 요청 행
        $this->assertNull($this->provider()->verifiedClaims($this->pendingLog(null), null));

        // 다른 수단의 요청 행
        $this->logRepository->updateById((string) $stashed->id, ['provider_id' => 'g7:core.mail']);
        $this->assertNull($this->provider()->verifiedClaims($this->logRepository->findById($stashed->id), null));
    }

    /**
     * 코어가 본인인증 토큰으로 이 수단의 인증 값을 받아, 가입 항목 API 가 채움값과 잠금을 준다.
     *
     * @scenario case=core_signup_fields
     *
     * @effects core_claims_service_reads_provider_claims_by_token, signup_fields_api_prefills_and_locks_from_kcp_claims
     *
     * @return void 반환값 없음
     */
    public function test_core_signup_fields_are_filled_and_locked_from_kcp_claims(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        // 이름은 인증 값으로 채우고 잠근다. 휴대폰은 채우기만 한다. 생년월일은 운영자 추가 항목에 연결해 잠근다.
        FieldDefinition::where('field_set', 'core.user')->where('key', 'name')->update(['idv_lock' => true]);
        FieldDefinition::create([
            'field_set' => 'core.user', 'key' => 'vc_birth', 'type' => 'date', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => '생년월일'], 'sort_order' => 500,
            'enabled' => true, 'show_on_signup' => true, 'idv_claim' => 'birth_date', 'idv_lock' => true,
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $this->assertInstanceOf(
            ProvidesVerifiedClaimsInterface::class,
            app(IdentityVerificationManager::class)->get(KcpIdentityProvider::PROVIDER_ID),
        );

        $log = $this->verifiedLog(null, ['phone_no' => '010-1234-5678']);
        $token = (string) $log->verification_token;

        $claims = app(IdentityClaimsService::class)->forToken($token);
        $this->assertSame(
            ['name' => '홍길동', 'mobile' => '01012345678', 'birth_date' => '1990-05-17', 'gender' => 'male'],
            $claims?->toArray(),
        );

        $signup = $this->getJson('/api/auth/register/fields?verification_token='.$token)->assertOk()->json('data');
        $fields = array_column($signup['fields'], null, 'key');

        $this->assertSame('applied', $signup['verified_claims']['status']);
        $this->assertSame(['name', 'vc_birth'], $signup['verified_claims']['locked']);
        $this->assertSame('홍길동', $fields['name']['default']);
        $this->assertTrue($fields['name']['readonly']);
        $this->assertSame('identity', $fields['name']['locked_reason']);
        $this->assertSame('1990-05-17', $fields['vc_birth']['default']);
        $this->assertSame('identity', $fields['vc_birth']['locked_reason']);
        $this->assertSame('01012345678', $fields['mobile']['default']);
        $this->assertFalse($fields['mobile']['readonly']);
    }
}
