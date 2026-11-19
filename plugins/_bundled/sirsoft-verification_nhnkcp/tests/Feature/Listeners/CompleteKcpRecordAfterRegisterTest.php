<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Extension\Cache\PluginCacheDriver;
use App\Extension\HookManager;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityTokenAcceptanceService;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Models\KcpIdentityRecord;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 비로그인 가입 완료 후 임시 보관 개인정보 확정 listener 통합 검증.
 *
 * 실제 훅 체인(HookManager::doAction → Listener::handle) + 실제 DB + 실제 캐시로 관찰 가능한
 * 상태 변화를 확인한다.
 *
 * @since 1.0.0
 */
class CompleteKcpRecordAfterRegisterTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    private KcpIdentityRecordRepositoryInterface $recordRepository;

    private CacheInterface $cache;

    /**
     * 로그·식별 기록 저장소와 플러그인 도메인 캐시를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->recordRepository = app(KcpIdentityRecordRepositoryInterface::class);

        // listener 는 ServiceProvider 의 contextual binding 으로 본 플러그인 도메인 캐시를
        // 주입받는다 — 테스트의 stash 도 같은 도메인이어야 키가 일치한다.
        $this->cache = new PluginCacheDriver(self::PLUGIN_IDENTIFIER);
    }

    /**
     * 비로그인 가입 인증이 성공하면 회원 기록을 만들지 않고, 식별값 해시와 암호화한 개인정보만 캐시에 임시 보관하는지 확인합니다.
     *
     * @scenario stage=verify,cache_state=present
     *
     * @effects guest_verify_stashes_pii_instead_of_record
     *
     * @return void 반환값 없음
     */
    public function test_guest_verification_stashes_pii_instead_of_creating_a_record(): void
    {
        $log = $this->createPendingSignupLog();

        $provider = app()->makeWith(KcpIdentityProvider::class, ['config' => ['duplicate_field' => 'di']]);
        $result = $provider->verify($log->id, [
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'user_name' => '홍길동',
            'birth_day' => '19900101',
            'phone_no' => '01012345678',
            'comm_id' => 'SKT',
            'sex_code' => '01',
            'local_code' => '01',
            'ci' => 'CI-GUEST-VERIFY',
            'di' => 'DI-GUEST-VERIFY',
        ]);

        $this->assertTrue($result->success);

        // 가입 전이므로 회원 정보로 확정하지 않고 임시 보관만 한다.
        $this->assertSame(0, KcpIdentityRecord::query()->count());

        $stashed = $this->cache->get(KcpIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$log->id);
        $this->assertIsArray($stashed);
        $this->assertSame(
            hash_hmac('sha256', 'DI-GUEST-VERIFY', (string) config('app.key')),
            $stashed['di_hash'],
        );
        // 임시 보관분도 평문으로 두지 않는다.
        $this->assertNotSame('홍길동', $stashed['name_encrypted']);
        $this->assertSame('홍길동', Crypt::decryptString($stashed['name_encrypted']));
    }

    /**
     * 가입 완료 뒤 리스너가 임시 보관분을 회원 식별 기록으로 확정하고, 인증 로그에 회원 ID 를 채우며 캐시를 비우는지 확인합니다.
     *
     * @scenario stage=after_register,cache_state=present
     *
     * @effects after_register_absorbs_stash_into_record, after_register_clears_stash, after_register_backfills_log_user_id
     *
     * @return void 반환값 없음
     */
    public function test_listener_absorbs_stash_backfills_user_and_clears_cache(): void
    {
        $log = $this->createVerifiedSignupLog($token = 'tok-guest-success');
        $this->stashPendingRecord($log->id);
        $user = User::factory()->create();

        $this->acceptInRequest($token);
        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
            'signup_stage' => 'after_create',
        ]);

        $this->assertDatabaseHas('nhnkcp_identity_records', ['user_id' => $user->id]);

        $record = $this->recordRepository->findByUserId($user->id);
        $this->assertNotNull($record);
        $this->assertSame($log->id, $record->latest_log_id);
        $this->assertSame('홍길동', $record->name);
        $this->assertTrue((bool) $record->is_adult);

        $this->assertDatabaseHas('identity_verification_logs', ['id' => $log->id, 'user_id' => $user->id]);
        $this->assertNull($this->cache->get(KcpIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$log->id));
    }

    /**
     * 코어 가입 가드가 토큰을 먼저 소비한 뒤에도 리스너가 기록을 확정하고 임시 보관분을 지우는지 확인합니다.
     *
     * @scenario stage=after_register,cache_state=present
     *
     * @effects after_register_clears_stash
     *
     * @return void 반환값 없음
     */
    public function test_listener_processes_token_even_after_core_listener_consumed_it(): void
    {
        // 코어 가입 가드(priority 10)가 토큰을 소비한 뒤에도 본 listener 가 동작해야 한다.
        $log = $this->createVerifiedSignupLog($token = 'tok-consumed');
        $this->stashPendingRecord($log->id);
        $this->acceptInRequest($token);

        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
            'signup_stage' => 'after_create',
        ]);

        $this->assertDatabaseHas('nhnkcp_identity_records', ['user_id' => $user->id]);
        $this->assertNull($this->cache->get(KcpIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$log->id));
    }

    /**
     * 임시 보관분이 없으면 기록을 만들지 않되 가입 흐름은 예외 없이 이어지는지 확인합니다.
     *
     * @scenario stage=after_register,cache_state=absent
     *
     * @effects missing_stash_logs_warning_without_breaking_registration
     *
     * @return void 반환값 없음
     */
    public function test_missing_stash_does_not_break_registration(): void
    {
        $log = $this->createVerifiedSignupLog($token = 'tok-no-stash');
        $user = User::factory()->create();

        $this->acceptInRequest($token);
        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
            'signup_stage' => 'after_create',
        ]);

        $this->assertDatabaseMissing('nhnkcp_identity_records', ['user_id' => $user->id]);
    }

    /**
     * 가입 요청에 인증 토큰이 없으면 리스너가 아무것도 하지 않는지 확인합니다.
     *
     * @scenario stage=after_register,cache_state=absent
     *
     * @effects missing_stash_logs_warning_without_breaking_registration
     *
     * @return void 반환값 없음
     */
    public function test_listener_noops_without_verification_token(): void
    {
        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, ['signup_stage' => 'after_create']);

        $this->assertDatabaseMissing('nhnkcp_identity_records', ['user_id' => $user->id]);
    }

    /**
     * 다른 수단(메일)의 인증 로그로 가입하면 임시 보관분이 있어도 KCP 식별 기록을 만들지 않는지 확인합니다.
     *
     * @scenario stage=after_register,cache_state=present
     *
     * @effects after_register_ignores_other_provider_logs
     *
     * @return void 반환값 없음
     */
    public function test_listener_ignores_logs_from_other_providers(): void
    {
        $log = $this->createVerifiedSignupLog($token = 'tok-other-provider', providerId: 'g7:core.mail');
        $this->stashPendingRecord($log->id);
        $user = User::factory()->create();

        $this->acceptInRequest($token);
        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
            'signup_stage' => 'after_create',
        ]);

        $this->assertDatabaseMissing('nhnkcp_identity_records', ['user_id' => $user->id]);
    }

    /**
     * 인증 완료 상태의 가입 목적 로그를 만든다.
     *
     * @param  string  $token  가입 요청에 실을 인증 토큰(verification_token)
     * @param  string  $providerId  provider 식별자
     * @return IdentityVerificationLog 만든 인증 로그
     */
    private function createVerifiedSignupLog(string $token, string $providerId = KcpIdentityProvider::PROVIDER_ID): IdentityVerificationLog
    {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => $providerId,
            'purpose' => 'signup',
            'channel' => KcpIdentityProvider::CHANNEL,
            'user_id' => null,
            'target_hash' => hash('sha256', 'guest@example.test'),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => 'api.auth.register',
            'verification_token' => $token,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['ordr_idxx' => 'G7-'.Str::random(8)],
        ]);
    }

    /**
     * 인증 대기 상태의 비로그인 가입 challenge 로그를 만든다.
     *
     * @return IdentityVerificationLog 만든 challenge 로그
     */
    private function createPendingSignupLog(): IdentityVerificationLog
    {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => KcpIdentityProvider::PROVIDER_ID,
            'purpose' => 'signup',
            'channel' => KcpIdentityProvider::CHANNEL,
            'user_id' => null,
            'target_hash' => hash('sha256', 'guest-verify@example.test'),
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
     * verify 시점 캐시 임시 보관을 재현한다.
     *
     * @param  string  $challengeId  임시 보관 키에 쓸 challenge UUID
     * @return void 반환값 없음
     */
    private function stashPendingRecord(string $challengeId): void
    {
        $this->cache->put(KcpIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$challengeId, [
            'comm_id' => 'SKT',
            'name_encrypted' => Crypt::encryptString('홍길동'),
            'phone_encrypted' => Crypt::encryptString('01012345678'),
            'birthday_encrypted' => Crypt::encryptString('19900101'),
            'di_encrypted' => Crypt::encryptString('DI-GUEST-0001'),
            'di_hash' => hash_hmac('sha256', 'DI-GUEST-0001', (string) config('app.key')),
            'ci_encrypted' => Crypt::encryptString('CI-GUEST-0001'),
            'ci_hash' => hash_hmac('sha256', 'CI-GUEST-0001', (string) config('app.key')),
            'gender' => 'M',
            'is_foreigner' => false,
            'is_adult' => true,
        ], 900);
    }

    /**
     * 같은 가입 요청에서 before_register 단계가 결과를 인정한 상태를 만듭니다 (1.1.4).
     *
     * 리스너는 소비 여부와 무관한 토큰 재조회 대신, 이 요청에서 인정(1회 소비)한 결과만 받는다. 가입 요청의 인정은 가입 전
     * 본인인증 정책이 켜진 사이트에서만 일어나므로 그 정책도 켠다 — 정책이 꺼진 사이트는 가입 본인인증 결과를 인정하지도
     * 쓰지도 않는다(코어 가입 기록도 남지 않는다, 2026-11-17 결정).
     *
     * @param  string  $token  확인 토큰
     * @return void 반환값 없음
     */
    private function acceptInRequest(string $token): void
    {
        $this->seed(IdentityPolicySeeder::class);
        IdentityPolicy::query()->update(['enabled' => false]);
        IdentityPolicy::where('key', 'core.auth.signup_before_submit')->update(['enabled' => true]);

        app(IdentityTokenAcceptanceService::class)->accept($token, 'signup');
    }
}
