<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Extension\Cache\PluginCacheDriver;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
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
     * @scenario stage=verify,cache_state=present
     *
     * @effects guest_verify_stashes_pii_instead_of_record
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
     * @scenario stage=after_register,cache_state=present
     *
     * @effects after_register_absorbs_stash_into_record
     */
    public function test_listener_absorbs_stash_backfills_user_and_clears_cache(): void
    {
        $log = $this->createVerifiedSignupLog($token = 'tok-guest-success');
        $this->stashPendingRecord($log->id);
        $user = User::factory()->create();

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
     * @scenario stage=after_register,cache_state=present
     *
     * @effects after_register_clears_stash
     */
    public function test_listener_processes_token_even_after_core_listener_consumed_it(): void
    {
        // 코어 가입 가드(priority 10)가 토큰을 소비한 뒤에도 본 listener 가 동작해야 한다.
        $log = $this->createVerifiedSignupLog($token = 'tok-consumed');
        $this->stashPendingRecord($log->id);
        $this->logRepository->updateById($log->id, ['consumed_at' => now()]);

        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
            'signup_stage' => 'after_create',
        ]);

        $this->assertDatabaseHas('nhnkcp_identity_records', ['user_id' => $user->id]);
        $this->assertNull($this->cache->get(KcpIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$log->id));
    }

    /**
     * @scenario stage=after_register,cache_state=absent
     *
     * @effects missing_stash_logs_warning_without_breaking_registration
     */
    public function test_missing_stash_does_not_break_registration(): void
    {
        $log = $this->createVerifiedSignupLog($token = 'tok-no-stash');
        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
            'signup_stage' => 'after_create',
        ]);

        $this->assertDatabaseMissing('nhnkcp_identity_records', ['user_id' => $user->id]);
    }

    /**
     * @scenario stage=after_register,cache_state=absent
     *
     * @effects missing_stash_logs_warning_without_breaking_registration
     */
    public function test_listener_noops_without_verification_token(): void
    {
        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, ['signup_stage' => 'after_create']);

        $this->assertDatabaseMissing('nhnkcp_identity_records', ['user_id' => $user->id]);
    }

    /**
     * @scenario stage=after_register,cache_state=present
     *
     * @effects after_register_backfills_log_user_id
     */
    public function test_listener_ignores_logs_from_other_providers(): void
    {
        $log = $this->createVerifiedSignupLog($token = 'tok-other-provider', providerId: 'g7:core.mail');
        $this->stashPendingRecord($log->id);
        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
            'signup_stage' => 'after_create',
        ]);

        $this->assertDatabaseMissing('nhnkcp_identity_records', ['user_id' => $user->id]);
    }

    /**
     * 인증 완료 상태의 가입 목적 로그를 만든다.
     *
     * @param  string  $token  verification_token
     * @param  string  $providerId  provider 식별자
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
     * @param  string  $challengeId  challenge UUID
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
}
