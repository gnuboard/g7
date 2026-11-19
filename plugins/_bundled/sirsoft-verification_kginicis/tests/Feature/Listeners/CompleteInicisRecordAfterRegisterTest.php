<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Listeners;

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

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 같은 요청에서 인정한 결과로 record 를 저장하고 log.user_id 를 역참조하며 임시 PII 캐시를 지우는지 확인합니다.
     *
     * @scenario case=all_present
     *
     * @effects success_with_record_stored, listener_upserts_inicis_identity_records_for_new_user_on_after_register_hook, listener_backfills_log_user_id_with_new_user_id, listener_clears_pending_record_cache_after_successful_upsert
     *
     * @return void 반환값 없음
     */
    public function test_listener_upserts_record_backfills_user_id_and_clears_cache_on_after_register_hook(): void
    {
        // 1. 비로그인 사용자가 이니시스 본인확인 완료한 상태 시뮬레이션 — log + verification_token + Cache PII stash
        $log = $this->createVerifiedSignupLog($token = 'tok-valid-success');
        $this->stashPendingRecord($log->id);

        // 2. 가입이 완료된 시점의 user
        $user = User::factory()->create();

        // 3. 같은 가입 요청에서 before_register 가 결과를 인정한 상태 (1.1.4) + 실제 hook 발화 (코어 AuthService::register 가 하는 그대로)
        $this->acceptInRequest($token);
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

    /**
     * 코어 가입 가드가 같은 요청에서 결과를 인정(소비)한 뒤에도 본 listener 가 그 결과로 record 를 저장하는지 확인합니다.
     *
     * @scenario registration_context=token_consumed_by_core
     *
     * @effects listener_processes_token_accepted_earlier_in_same_request, listener_processes_token_after_core_listener_marks_consumed, listener_upserts_inicis_identity_records_for_new_user_on_after_register_hook, listener_backfills_log_user_id_with_new_user_id, listener_clears_pending_record_cache_after_successful_upsert
     *
     * @return void 반환값 없음
     */
    public function test_listener_processes_token_even_after_core_listener_consumed_it(): void
    {
        // 회귀 차단: 코어 AssertIdentityVerifiedBeforeRegister (priority 10) 가 같은 요청에서 결과를
        // 인정(consumed_at 기록)한 후에도 본 listener (priority 50) 가 정상 처리되어야 함 (1.1.4 — 같은 요청 인정 결과를 받는다).
        $log = $this->createVerifiedSignupLog($token = 'tok-consumed-still-processed');
        $this->stashPendingRecord($log->id);
        $this->acceptInRequest($token);
        $this->assertNotNull($this->logRepository->findById($log->id)->consumed_at, '인정하면 결과가 소비로 표시되어야 한다');

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

    /**
     * 가입 문맥에 확인 토큰이 없으면 아무것도 하지 않는지 확인합니다.
     *
     * @scenario registration_context=missing_token
     *
     * @effects listener_noops_when_verification_token_missing_in_context
     *
     * @return void 반환값 없음
     */
    public function test_listener_noops_when_verification_token_missing_in_context(): void
    {
        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'signup_stage' => 'after_create',
            // verification_token 없음 — 이니시스 인증 없이 가입한 케이스
        ]);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
    }

    /**
     * 어떤 확인 결과와도 맞지 않는 토큰이면 아무것도 하지 않는지 확인합니다.
     *
     * @scenario registration_context=bogus_token
     *
     * @effects listener_noops_when_token_does_not_match_any_verified_log
     *
     * @return void 반환값 없음
     */
    public function test_listener_noops_when_token_does_not_match_any_verified_log(): void
    {
        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => 'tok-totally-bogus',
        ]);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
    }

    /**
     * 다른 인증 수단의 결과면 record 도 캐시도 건드리지 않는지 확인합니다.
     *
     * @scenario registration_context=other_provider_token
     *
     * @effects listener_noops_when_log_provider_id_is_not_inicis, listener_does_not_clear_cache_for_other_provider_log
     *
     * @return void 반환값 없음
     */
    public function test_listener_noops_when_log_belongs_to_other_provider(): void
    {
        // 다른 provider (mail 등) 의 verified log — token 매칭은 되지만 우리가 처리할 대상 아님
        $log = $this->createVerifiedSignupLog($token = 'tok-other-provider', providerId: 'mail');
        $this->stashPendingRecord($log->id);

        $user = User::factory()->create();

        $this->acceptInRequest($token);
        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
        ]);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
        // Cache 도 그대로 — 우리가 건드리지 않음
        $this->assertNotNull(
            $this->cache->get(InicisIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$log->id),
        );
    }

    /**
     * 대기 캐시에 PII 가 없으면 기록을 만들지 않고, 코어가 로그를 회원에 묶은 뒤에도 그 로그에서 인증 값이 나오지 않는지 검증합니다.
     *
     * @scenario registration_context=cache_pii_missing
     *
     * @effects listener_skips_when_cache_pii_payload_missing_or_empty, verified_claims_null_when_listener_skipped_record_for_bound_log
     *
     * @return void 반환값 없음
     */
    public function test_listener_logs_warning_and_skips_when_cache_pii_missing(): void
    {
        // log 는 있지만 Cache 가 비어있는 경우 (TTL 만료 등)
        $log = $this->createVerifiedSignupLog($token = 'tok-no-cache');
        // stashPendingRecord 호출 안 함

        $user = User::factory()->create();

        $this->acceptInRequest($token);
        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
        ]);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);

        // 요청 행의 회원 연결은 이 리스너가 아니라 코어 가입 기록(SyncVerifiedClaimsToUser, 7.2.0)이 맡는다 —
        // 가입 토큰으로 확인을 마친 행이므로 가입 회원에 묶인다. 이니시스 리스너는 PII 가 없어 기록을 만들지 않았으므로
        // 묶인 뒤에도 이 행에서 꺼낼 인증 값이 없어야 한다(빈 기록으로 잠금 항목이 생기지 않는다).
        $reload = $this->logRepository->findById($log->id);
        $this->assertSame($user->id, (int) $reload->user_id);
        $this->assertNull(app(InicisIdentityProvider::class)->verifiedClaims($reload, $user->fresh()));
    }

    /**
     * 이미 다른 회원이 역참조된 결과의 log.user_id 를 덮어쓰지 않는지 확인합니다.
     *
     * @scenario registration_context=existing_user_id_on_log
     *
     * @effects listener_does_not_overwrite_existing_user_id_on_log_via_backfill_safe_guard
     *
     * @return void 반환값 없음
     */
    public function test_listener_does_not_overwrite_existing_user_id_via_backfill(): void
    {
        // 코어 backfillUserId 의 안전 가드 (이미 user_id 있으면 false 반환) 가 적용되는지 확인
        $existingOwner = User::factory()->create();
        $log = $this->createVerifiedSignupLog($token = 'tok-already-owned', userId: $existingOwner->id);
        $this->stashPendingRecord($log->id);

        $newUser = User::factory()->create();

        $this->acceptInRequest($token);
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

    /**
     * 역참조가 실패해도 record 는 남기고 임시 PII 캐시는 지우는지 확인합니다.
     *
     * @scenario registration_context=backfill_throws
     *
     * @effects listener_clears_pending_record_cache_after_successful_upsert, listener_keeps_record_and_clears_cache_when_backfill_throws
     *
     * @return void 반환값 없음
     */
    public function test_listener_clears_cache_even_when_backfill_fails(): void
    {
        // 보강 회귀: record upsert 성공 후 backfill 이 실패해도 (1) PII 저장은 유지되고
        // (2) stash 캐시는 forget 되어 PII 잔류가 없어야 한다. 예외가 가입 흐름으로 전파되면 안 된다.
        $log = $this->createVerifiedSignupLog($token = 'tok-backfill-throws');
        $this->stashPendingRecord($log->id);
        $user = User::factory()->create();

        // 같은 요청 인정은 실제 Repository 로 먼저 심는다 — 아래 mock 은 backfillUserId 만 흉내 낸다.
        $this->acceptInRequest($token);

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
     * 같은 가입 요청에서 인정하지 않은 결과는 토큰이 맞아도 이 회원에게 붙이지 않는지 확인합니다 (1.1.4).
     *
     * 수정 전에는 소비 여부와 무관하게 토큰만으로 결과를 다시 찾아, 다른 요청이 이미 쓴 결과의 PII 를 붙일 수 있었다.
     *
     * @scenario registration_context=consumed_by_other_request
     *
     * @effects unaccepted_token_not_attached_to_new_user
     *
     * @return void 반환값 없음
     */
    public function test_listener_ignores_token_not_accepted_in_this_request(): void
    {
        $log = $this->createVerifiedSignupLog($token = 'tok-used-by-other-request');
        $this->stashPendingRecord($log->id);
        // 다른 요청이 이미 쓴 결과 — 이 요청의 인정 기록에는 없다
        $this->logRepository->updateById($log->id, ['consumed_at' => now()]);

        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'verification_token' => $token,
            'signup_stage' => 'after_create',
        ]);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
        $this->assertNull($this->logRepository->findById($log->id)->user_id, '인정하지 않은 결과에 회원을 역참조하면 안 된다');
    }

    /**
     * inicis 본인확인 verified 로그를 직접 만들어 cache 와 함께 기본 환경 셋업.
     *
     * @param  string  $token  확인 토큰
     * @param  int|null  $userId  이미 역참조된 회원 ID (없으면 null)
     * @param  string  $providerId  인증 수단 ID
     * @return IdentityVerificationLog 만든 결과
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

    /**
     * provider.verify() 가 비로그인 사용자에 대해 cache 에 PII stash 하는 동작을 시뮬레이션.
     *
     * @param  string  $logId  challenge ID (캐시 키 접미사)
     * @return void 반환값 없음
     */
    private function stashPendingRecord(string $logId): void
    {
        $this->cache->put(
            InicisIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$logId,
            [
                'name_encrypted' => Crypt::encryptString('홍길동'),
                'phone_encrypted' => Crypt::encryptString('01012345678'),
                'birthday_encrypted' => Crypt::encryptString('19900101'),
                'di_encrypted' => Crypt::encryptString('DI-VAL'),
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
