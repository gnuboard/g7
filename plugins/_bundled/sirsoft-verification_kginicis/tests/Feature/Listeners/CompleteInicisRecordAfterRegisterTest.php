<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\PluginCacheDriver;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * CompleteInicisRecordAfterRegister Listener 통합 테스트.
 *
 * 실제 hook 체인 (HookManager::doAction → Listener::handle) + 실제 DB + 실제 Cache 검증.
 * 도메인 매트릭스 §Hook/Event 규정: mock 금지, 관찰 가능한 상태 변화 검증.
 *
 * 시나리오 매니페스트 → tests/scenarios/inicis_complete_record_after_register.yaml
 *
 * 검증 후속 효과 (관찰 가능한 상태 변화):
 *  - inicis_identity_records 테이블에 user 의 PII row upsert
 *  - identity_verification_logs.user_id 가 신규 user.id 로 backfill
 *  - inicis:pending_record:{log.id} cache 가 forget 됨
 */
class CompleteInicisRecordAfterRegisterTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    private InicisIdentityRecordRepositoryInterface $recordRepository;

    private CacheInterface $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->recordRepository = app(InicisIdentityRecordRepositoryInterface::class);

        // CompleteInicisRecordAfterRegister listener 는 BasePluginServiceProvider 의
        // contextual binding 으로 본 플러그인 도메인 캐시 (`g7:plugin.sirsoft-verification_kginicis:*`)
        // 를 주입받는다. 본 테스트의 stash/forget 도 같은 도메인을 사용해야 listener 의
        // 캐시 lookup 과 prefix 가 일치한다 (글로벌 코어 CacheInterface 가 아님).
        $this->cache = new PluginCacheDriver('sirsoft-verification_kginicis');
    }

    public function test_listener_upserts_record_backfills_user_id_and_clears_cache_on_after_register_hook(): void
    {
        // 1. 비로그인 사용자가 이니시스 본인확인 완료한 상태 시뮬레이션 — log + verification_token + Cache PII stash
        $log = $this->createVerifiedSignupLog($token = 'tok-valid-success');
        $this->stashPendingRecord($log->id);

        // 2. 가입이 완료된 시점의 user
        $user = User::factory()->create();

        // 3. 실제 hook 발화 (코어 AuthService::register 가 하는 그대로)
        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
            'signup_stage' => 'after_create',
        ]);

        // 4. 후속 효과 검증 — record upsert
        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $user->id]);
        $record = $this->recordRepository->findByUserId($user->id);
        $this->assertNotNull($record);
        $this->assertSame($log->id, $record->latest_log_id);

        // 5. backfill 확인
        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $log->id,
            'user_id' => $user->id,
        ]);

        // 6. Cache forget 확인
        $this->assertNull(
            $this->cache->get(InicisIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$log->id),
        );
    }

    public function test_listener_processes_token_even_after_core_listener_consumed_it(): void
    {
        // 회귀 차단: 코어 AssertIdentityVerifiedBeforeRegister (priority 10) 가
        // consumed_at 을 set 한 후에도 본 listener (priority 50) 가 정상 처리되어야 함.
        // 본 plugin 의 InicisIdentityLogQueryRepository 가 consumed_at 무관 조회를 보장.
        $log = $this->createVerifiedSignupLog($token = 'tok-consumed-still-processed');
        $this->stashPendingRecord($log->id);
        $this->logRepository->updateById($log->id, ['consumed_at' => now()]);

        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
            'signup_stage' => 'after_create',
        ]);

        // record + backfill + cache forget 모두 정상 수행되어야 함
        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $user->id]);
        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $log->id,
            'user_id' => $user->id,
        ]);
        $this->assertNull(
            $this->cache->get(InicisIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$log->id),
        );
    }

    public function test_listener_noops_when_verification_token_missing_in_context(): void
    {
        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'signup_stage' => 'after_create',
            // verification_token 없음 — 이니시스 인증 없이 가입한 케이스
        ]);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
    }

    public function test_listener_noops_when_token_does_not_match_any_verified_log(): void
    {
        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => 'tok-totally-bogus',
        ]);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
    }

    public function test_listener_noops_when_log_belongs_to_other_provider(): void
    {
        // 다른 provider (mail 등) 의 verified log — token 매칭은 되지만 우리가 처리할 대상 아님
        $log = $this->createVerifiedSignupLog($token = 'tok-other-provider', providerId: 'mail');
        $this->stashPendingRecord($log->id);

        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
        ]);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
        // Cache 도 그대로 — 우리가 건드리지 않음
        $this->assertNotNull(
            $this->cache->get(InicisIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$log->id),
        );
    }

    public function test_listener_logs_warning_and_skips_when_cache_pii_missing(): void
    {
        // log 는 있지만 Cache 가 비어있는 경우 (TTL 만료 등)
        $log = $this->createVerifiedSignupLog($token = 'tok-no-cache');
        // stashPendingRecord 호출 안 함

        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
        ]);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
        // backfill 도 없음
        $reload = $this->logRepository->findById($log->id);
        $this->assertNull($reload->user_id);
    }

    public function test_listener_does_not_overwrite_existing_user_id_via_backfill(): void
    {
        // 코어 backfillUserId 의 안전 가드 (이미 user_id 있으면 false 반환) 가 적용되는지 확인
        $existingOwner = User::factory()->create();
        $log = $this->createVerifiedSignupLog($token = 'tok-already-owned', userId: $existingOwner->id);
        $this->stashPendingRecord($log->id);

        $newUser = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $newUser, [
            'verification_token' => $token,
        ]);

        // record 는 newUser 로 upsert (PII 는 newUser 가 막 가입한 시점이라 그쪽 소유)
        // backfill 은 reject (기존 owner 유지)
        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $log->id,
            'user_id' => $existingOwner->id, // 변경 안 됨
        ]);
    }

    public function test_listener_clears_cache_even_when_backfill_fails(): void
    {
        // 보강 회귀: record upsert 성공 후 backfill 이 실패해도 (1) PII 저장은 유지되고
        // (2) stash 캐시는 forget 되어 PII 잔류가 없어야 한다. 예외가 가입 흐름으로 전파되면 안 된다.
        $log = $this->createVerifiedSignupLog($token = 'tok-backfill-throws');
        $this->stashPendingRecord($log->id);
        $user = User::factory()->create();

        // backfillUserId 만 예외를 던지는 mock 을 컨테이너에 주입.
        // 본 리스너는 이 시점에 log repository 의 backfillUserId 외 메서드를 호출하지 않으므로
        // 다른 메서드 stub 은 불필요하다 (hook 체인·record upsert·cache forget 은 실제로 검증).
        $throwingLogRepo = \Mockery::mock(IdentityVerificationLogRepositoryInterface::class);
        $throwingLogRepo->shouldReceive('backfillUserId')
            ->andThrow(new \RuntimeException('backfill 실패 주입'));
        $this->app->instance(IdentityVerificationLogRepositoryInterface::class, $throwingLogRepo);

        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
            'signup_stage' => 'after_create',
        ]);

        // PII record 는 살아있어야 함 (backfill 실패가 record 를 되돌리면 안 됨)
        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $user->id]);
        // 캐시는 finally 로 정리되어 PII 잔류 없음
        $this->assertNull(
            $this->cache->get(InicisIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$log->id),
        );
    }

    /**
     * inicis 본인확인 verified 로그를 직접 만들어 cache 와 함께 기본 환경 셋업.
     */
    private function createVerifiedSignupLog(
        string $token,
        ?int $userId = null,
        string $providerId = InicisIdentityProvider::PROVIDER_ID,
    ): IdentityVerificationLog {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => $providerId,
            'purpose' => 'signup',
            'channel' => 'ipin',
            'user_id' => $userId,
            'target_hash' => hash('sha256', 'test@example.com'),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'external_redirect',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => 'api.auth.register',
            'origin_policy_key' => 'core.auth.signup_before_submit',
            'verification_token' => $token,
            'expires_at' => now()->addMinutes(15),
            'verified_at' => now(),
            'metadata' => [],
        ]);
    }

    /**
     * provider.verify() 가 비로그인 사용자에 대해 cache 에 PII stash 하는 동작을 시뮬레이션.
     */
    private function stashPendingRecord(string $logId): void
    {
        $this->cache->put(
            InicisIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$logId,
            [
                'name_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('홍길동'),
                'phone_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('01012345678'),
                'birthday_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('19900101'),
                'di_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('DI-VAL'),
                'di_hash' => hash('sha256', 'DI-VAL'),
                'ci_encrypted' => null,
                'ci_hash' => null,
                'ci2_encrypted' => null,
                'ci2_hash' => null,
                'gender' => 'M',
                'is_foreigner' => false,
                'is_adult' => true,
                'provider_dev_cd' => 'SKT',
            ],
            15 * 60,
        );
    }
}
