<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Listeners;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityTokenAcceptanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\IdentityDuplicateException;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Listeners\AssertNoDuplicateInicisIdentity;
use Plugins\Sirsoft\VerificationKginicis\Models\InicisIdentityRecord;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityClaimRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * AssertNoDuplicateInicisIdentity Listener 통합 테스트.
 *
 * 실제 hook 체인 (HookManager::doAction → Listener::handle) + 실제 DB 검증.
 * 도메인 매트릭스 §Hook/Event 규정: mock 금지.
 *
 * 1.1.4 부터 본 listener 는 확인 토큰을 「이니시스 결과·가입 이메일」 결박으로 코어 `IdentityTokenAcceptanceService` 에
 * 인정받는다 — 그래서 결과를 만들 때 대상 해시를 가입 이메일로 맞춘다.
 *
 * 검증 매트릭스:
 *  - duplicate_block_enabled=false → 모든 케이스 통과
 *  - duplicate_block_enabled=true:
 *    - 다른 provider 의 token → 통과 (가입 정책이 이니시스를 지정했으면 거부)
 *    - DI hash 매칭 시 IdentityDuplicateException
 *    - CI hash 매칭 시 IdentityDuplicateException (settings duplicate_field=ci)
 *    - records 는 없지만 선점표에 있으면 IdentityDuplicateException
 *    - hash NULL (외국인) → 통과
 *    - 매칭 record 없음 → 통과
 */
class AssertNoDuplicateInicisIdentityTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    private InicisIdentityRecordRepositoryInterface $recordRepository;

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

        // 기본 settings — duplicate_block_enabled=true, duplicate_field=di
        $this->setPluginSettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'di']);
    }

    /**
     * 운영자가 중복 차단을 끄면 같은 명의라도 통과하는지 확인합니다.
     *
     * @scenario flow=register, duplicate_block_enabled=false, duplicate_field=di, existing_record_state=same_hash_other_user, log_provider_id=inicis, log_user_id=null
     *
     * @effects register_passes_when_duplicate_block_disabled_via_settings, off_mode_allows_multiple_records_with_same_di_hash
     *
     * @return void 반환값 없음
     */
    public function test_passes_when_duplicate_block_disabled(): void
    {
        $this->setPluginSettings(['duplicate_block_enabled' => false, 'duplicate_field' => 'di']);

        $diHash = hash('sha256', 'di-existing');
        $this->createRecordForUser(User::factory()->create()->id, ['di_hash' => $diHash]);

        $this->createVerifiedSignupLog('tok-disabled', 'new@example.com', metadata: ['di_hash' => $diHash]);

        // throw 없이 통과되어야 함
        HookManager::doAction('core.auth.before_register', [
            'email' => 'new@example.com',
            'verification_token' => 'tok-disabled',
        ], ['signup_stage' => 'before_submit']);

        $this->assertTrue(true); // 통과 의미
    }

    /**
     * 다른 회원의 record 와 DI 해시가 같으면 가입을 막는지 확인합니다.
     *
     * @scenario flow=register, duplicate_block_enabled=true, duplicate_field=di, existing_record_state=same_hash_other_user, verify_hash_state=di_set, log_provider_id=inicis, log_user_id=null
     *
     * @effects register_blocks_when_di_hash_matches_other_user
     *
     * @return void 반환값 없음
     */
    public function test_blocks_when_di_hash_matches_existing_user(): void
    {
        $diHash = hash('sha256', 'di-shared');
        $this->createRecordForUser(User::factory()->create()->id, ['di_hash' => $diHash]);

        $this->createVerifiedSignupLog('tok-di-match', 'other@example.com', metadata: ['di_hash' => $diHash]);

        $this->expectException(IdentityDuplicateException::class);

        HookManager::doAction('core.auth.before_register', [
            'email' => 'other@example.com',
            'verification_token' => 'tok-di-match',
        ], ['signup_stage' => 'before_submit']);
    }

    /**
     * CI 판정 모드에서 다른 회원의 record 와 CI 해시가 같으면 가입을 막는지 확인합니다.
     *
     * @scenario flow=register, duplicate_block_enabled=true, duplicate_field=ci, existing_record_state=same_hash_other_user, verify_hash_state=ci_set, log_provider_id=inicis, log_user_id=null
     *
     * @effects register_blocks_when_ci_hash_matches_other_user_under_ci_mode
     *
     * @return void 반환값 없음
     */
    public function test_blocks_when_ci_hash_matches_under_ci_mode(): void
    {
        $this->setPluginSettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'ci']);

        $ciHash = hash('sha256', 'ci-shared');
        $this->createRecordForUser(User::factory()->create()->id, ['ci_hash' => $ciHash]);

        $this->createVerifiedSignupLog('tok-ci-match', 'other@example.com', metadata: ['ci_hash' => $ciHash]);

        $this->expectException(IdentityDuplicateException::class);

        HookManager::doAction('core.auth.before_register', [
            'email' => 'other@example.com',
            'verification_token' => 'tok-ci-match',
        ], ['signup_stage' => 'before_submit']);
    }

    /**
     * DI 차단 시 어느 컬럼·record 로 막혔는지 로그 metadata 에 남기고 기존 키를 보존하는지 확인합니다.
     *
     * @scenario flow=register, duplicate_block_enabled=true, duplicate_field=di, existing_record_state=same_hash_other_user, verify_hash_state=di_set, log_provider_id=inicis, log_user_id=null
     *
     * @effects register_blocks_when_di_hash_matches_other_user, register_block_records_matched_field_in_log_metadata
     *
     * @return void 반환값 없음
     */
    public function test_block_records_matched_field_di_hash_in_log_metadata_for_admin_audit(): void
    {
        $diHash = hash('sha256', 'di-audit');
        $existing = $this->createRecordForUser(User::factory()->create()->id, ['di_hash' => $diHash]);

        $this->createVerifiedSignupLog('tok-di-audit', 'audit-di@example.com', metadata: ['di_hash' => $diHash]);

        try {
            HookManager::doAction('core.auth.before_register', [
                'email' => 'audit-di@example.com',
                'verification_token' => 'tok-di-audit',
            ], ['signup_stage' => 'before_submit']);
            $this->fail('IdentityDuplicateException 가 throw 되지 않았습니다.');
        } catch (IdentityDuplicateException $e) {
            // 예외 자체는 의도된 동작 — metadata 가 정확히 기록됐는지 확인
        }

        $log = IdentityVerificationLog::query()->where('verification_token', 'tok-di-audit')->first();
        $this->assertNotNull($log, 'verified log 가 fetch 되어야 합니다');
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $this->assertSame('di_hash', $metadata['matched_field'] ?? null, 'DI 모드 차단 시 matched_field=di_hash 가 기록되어야 합니다');
        $this->assertSame((int) $existing->id, $metadata['matched_record_id'] ?? null, 'matched_record_id 가 기존 record 의 id 와 일치해야 합니다');
        // 기존 metadata 키는 보존되어야 함
        $this->assertSame($diHash, $metadata['di_hash'] ?? null, '기존 metadata.di_hash 키가 보존되어야 합니다');
    }

    /**
     * CI 차단 시 matched_field=ci_hash 를 로그 metadata 에 남기는지 확인합니다.
     *
     * @scenario flow=register, duplicate_block_enabled=true, duplicate_field=ci, existing_record_state=same_hash_other_user, verify_hash_state=ci_set, log_provider_id=inicis, log_user_id=null
     *
     * @effects register_blocks_when_ci_hash_matches_other_user_under_ci_mode, register_block_records_matched_field_in_log_metadata
     *
     * @return void 반환값 없음
     */
    public function test_block_records_matched_field_ci_hash_under_ci_mode(): void
    {
        $this->setPluginSettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'ci']);

        $ciHash = hash('sha256', 'ci-audit');
        $existing = $this->createRecordForUser(User::factory()->create()->id, ['ci_hash' => $ciHash]);

        $this->createVerifiedSignupLog('tok-ci-audit', 'audit-ci@example.com', metadata: ['ci_hash' => $ciHash]);

        try {
            HookManager::doAction('core.auth.before_register', [
                'email' => 'audit-ci@example.com',
                'verification_token' => 'tok-ci-audit',
            ], ['signup_stage' => 'before_submit']);
            $this->fail('IdentityDuplicateException 가 throw 되지 않았습니다.');
        } catch (IdentityDuplicateException $e) {
            // 예외 자체는 의도된 동작
        }

        $log = IdentityVerificationLog::query()->where('verification_token', 'tok-ci-audit')->first();
        $this->assertNotNull($log);
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $this->assertSame('ci_hash', $metadata['matched_field'] ?? null, 'CI 모드 차단 시 matched_field=ci_hash 가 기록되어야 합니다');
        $this->assertSame((int) $existing->id, $metadata['matched_record_id'] ?? null);
    }

    /**
     * 같은 해시의 record 가 없으면 통과하는지 확인합니다.
     *
     * @scenario flow=register, duplicate_block_enabled=true, duplicate_field=di, existing_record_state=none, verify_hash_state=di_set, log_provider_id=inicis, log_user_id=null
     *
     * @effects register_passes_when_no_matching_record_exists
     *
     * @return void 반환값 없음
     */
    public function test_passes_when_no_matching_record_exists(): void
    {
        $this->createVerifiedSignupLog('tok-no-match', 'newcomer@example.com', metadata: ['di_hash' => hash('sha256', 'di-unique')]);

        HookManager::doAction('core.auth.before_register', [
            'email' => 'newcomer@example.com',
            'verification_token' => 'tok-no-match',
        ], ['signup_stage' => 'before_submit']);

        $this->assertTrue(true);
    }

    /**
     * 식별값 해시가 없는 결과(외국인 등)는 통과하는지 확인합니다.
     *
     * @scenario flow=register, duplicate_block_enabled=true, duplicate_field=di, existing_record_state=null_hash, verify_hash_state=di_empty, log_provider_id=inicis, log_user_id=null
     *
     * @effects register_passes_when_di_hash_is_null_foreigner_case
     *
     * @return void 반환값 없음
     */
    public function test_passes_when_di_hash_is_null_foreigner_case(): void
    {
        $this->createVerifiedSignupLog('tok-foreigner', 'foreigner@example.com', metadata: ['di_hash' => null, 'ci_hash' => null]);

        HookManager::doAction('core.auth.before_register', [
            'email' => 'foreigner@example.com',
            'verification_token' => 'tok-foreigner',
        ], ['signup_stage' => 'before_submit']);

        $this->assertTrue(true);
    }

    /**
     * 가입 정책이 수단을 지정하지 않았으면 다른 수단의 결과는 본 listener 소관이 아니어서 통과하는지 확인합니다.
     *
     * 1.1.4 에서 결과 인정이 이니시스 결박으로 바뀐 뒤에도, 정책 수단이 비어 있는 전제에서는 현행 통과를 유지한다.
     *
     * @scenario flow=register, duplicate_block_enabled=true, duplicate_field=di, existing_record_state=same_hash_other_user, log_provider_id=other, log_user_id=null
     *
     * @effects register_passes_when_log_provider_id_is_other
     *
     * @return void 반환값 없음
     */
    public function test_passes_when_log_belongs_to_other_provider(): void
    {
        $diHash = hash('sha256', 'di-shared');
        $this->createRecordForUser(User::factory()->create()->id, ['di_hash' => $diHash]);

        // mail provider 의 log — 본 plugin 의 책임 외
        $log = $this->createVerifiedSignupLog('tok-other', 'other@example.com', providerId: 'mail', metadata: ['di_hash' => $diHash]);

        HookManager::doAction('core.auth.before_register', [
            'email' => 'other@example.com',
            'verification_token' => 'tok-other',
        ], ['signup_stage' => 'before_submit']);

        $this->assertNull($this->logRepository->findById($log->id)->consumed_at, '본 listener 가 다른 수단의 결과를 소비하면 안 된다');
    }

    /**
     * 가입 정책이 이니시스를 지정했는데 다른 수단(메일)의 결과가 오면 거부하는지 확인합니다 (fail-closed).
     *
     * 코어 가입 가드도 같은 판정을 하므로, 본 listener 의 이중 방어를 보려고 listener 를 직접 호출한다.
     *
     * @scenario flow=register, duplicate_block_enabled=true, duplicate_field=di, existing_record_state=none, log_provider_id=other, log_user_id=null
     *
     * @effects register_rejects_other_provider_token_when_policy_requires_inicis
     *
     * @return void 반환값 없음
     */
    public function test_policy_requiring_inicis_rejects_other_provider_token(): void
    {
        $this->createSignupPolicyRequiring(InicisIdentityProvider::PROVIDER_ID);
        $log = $this->createVerifiedSignupLog('tok-mail-under-inicis-policy', 'policy@example.com', providerId: 'g7:core.mail');

        try {
            app(AssertNoDuplicateInicisIdentity::class)->handle([
                'email' => 'policy@example.com',
                'verification_token' => 'tok-mail-under-inicis-policy',
            ], ['signup_stage' => 'before_submit', 'http_method' => 'POST']);
            $this->fail('이니시스 지정 가입 정책에서 다른 수단의 결과는 거부되어야 한다');
        } catch (AuthorizationException) {
            // 의도된 거부
        }

        $this->assertNull($this->logRepository->findById($log->id)->consumed_at, '거부된 결과를 소비하면 안 된다');
    }

    /**
     * records 가 아직 없어도 선점표에 같은 해시가 있으면 조기 차단하는지 확인합니다 (1.1.4 — 경합 창).
     *
     * @scenario flow=register, duplicate_block_enabled=true, duplicate_field=di, existing_record_state=none, verify_hash_state=di_set, log_provider_id=inicis, log_user_id=null
     *
     * @effects register_blocks_when_identity_claimed_before_record_exists
     *
     * @return void 반환값 없음
     */
    public function test_blocks_when_identity_claimed_before_record_exists(): void
    {
        $diHash = hash('sha256', 'di-claimed-only');
        $holder = User::factory()->create();
        app(InicisIdentityClaimRepositoryInterface::class)->claim('di', $diHash, (int) $holder->id);

        $this->createVerifiedSignupLog('tok-claimed-only', 'claimed@example.com', metadata: ['di_hash' => $diHash]);

        try {
            HookManager::doAction('core.auth.before_register', [
                'email' => 'claimed@example.com',
                'verification_token' => 'tok-claimed-only',
            ], ['signup_stage' => 'before_submit']);
            $this->fail('선점된 식별값의 가입은 차단되어야 한다');
        } catch (IdentityDuplicateException $e) {
            $this->assertSame('di_hash', $e->matchedField);
        }

        $metadata = IdentityVerificationLog::query()->where('verification_token', 'tok-claimed-only')->first()->metadata;
        $this->assertSame((int) $holder->id, $metadata['matched_user_id'] ?? null, '선점한 회원 ID 가 감사 추적에 남아야 한다');
    }

    /**
     * 확인 토큰이 없으면 아무것도 하지 않고 통과하는지 확인합니다.
     *
     * @scenario flow=register, duplicate_block_enabled=true, duplicate_field=di, existing_record_state=none, log_provider_id=inicis, log_user_id=null
     *
     * @effects register_passes_when_verification_token_missing_in_data
     *
     * @return void 반환값 없음
     */
    public function test_passes_when_verification_token_missing(): void
    {
        // token 없으면 listener 가 자동으로 return
        HookManager::doAction('core.auth.before_register', [
            'email' => 'no-token@example.com',
        ], ['signup_stage' => 'before_submit']);

        $this->assertTrue(true);
    }

    /**
     * 코어 가입 가드가 같은 요청에서 결과를 인정(소비)한 뒤에도 같은 결과로 동일인을 차단하는지 확인합니다.
     *
     * @scenario flow=register, duplicate_block_enabled=true, duplicate_field=di, existing_record_state=same_hash_other_user, verify_hash_state=di_set, log_provider_id=inicis, log_user_id=null
     *
     * @effects register_blocks_even_after_core_listener_consumed_token
     *
     * @return void 반환값 없음
     */
    public function test_blocks_even_after_core_listener_consumed_token(): void
    {
        // 코어 priority 10 listener 가 같은 요청에서 결과를 인정(consumed_at 기록)한 직후를 시뮬레이션 —
        // 본 listener 는 코어 인정 서비스가 기억한 같은 요청의 결과를 다시 받아 판정해야 한다(1.1.4).
        $diHash = hash('sha256', 'di-consumed-but-blocked');
        $this->createRecordForUser(User::factory()->create()->id, ['di_hash' => $diHash]);

        $log = $this->createVerifiedSignupLog('tok-consumed', 'other@example.com', metadata: ['di_hash' => $diHash]);
        app(IdentityTokenAcceptanceService::class)->accept('tok-consumed', 'signup', null, hash('sha256', 'other@example.com'));
        $this->assertNotNull($this->logRepository->findById($log->id)->consumed_at);

        $this->expectException(IdentityDuplicateException::class);

        HookManager::doAction('core.auth.before_register', [
            'email' => 'other@example.com',
            'verification_token' => 'tok-consumed',
        ], ['signup_stage' => 'before_submit']);
    }

    /**
     * 본인확인 verified 로그를 직접 만든다 (대상 해시는 가입 이메일로 결박).
     *
     * @param  string  $token  확인 토큰
     * @param  string  $email  가입 이메일 (대상 해시 원본)
     * @param  string  $providerId  인증 수단 ID
     * @param  array<string, mixed>  $metadata  로그 metadata (식별값 해시)
     * @return IdentityVerificationLog 만든 결과
     */
    private function createVerifiedSignupLog(
        string $token,
        string $email,
        string $providerId = InicisIdentityProvider::PROVIDER_ID,
        array $metadata = [],
    ): IdentityVerificationLog {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => $providerId,
            'purpose' => 'signup',
            'channel' => 'ipin',
            'user_id' => null,
            'target_hash' => hash('sha256', mb_strtolower($email)),
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
            'metadata' => $metadata,
        ]);
    }

    /**
     * 인증 수단을 지정한 가입 정책을 만든다.
     *
     * @param  string  $providerId  정책이 지정할 인증 수단 ID
     * @return IdentityPolicy 만든 정책
     */
    private function createSignupPolicyRequiring(string $providerId): IdentityPolicy
    {
        return IdentityPolicy::create([
            'key' => 'kve-test.signup_requires_'.$providerId,
            'scope' => 'route',
            'target' => 'api.auth.register',
            'purpose' => 'signup',
            'provider_id' => $providerId,
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
        ]);
    }

    /**
     * inicis_identity_records 에 user 의 hash row 를 직접 만든다.
     *
     * @param  int  $userId  회원 ID
     * @param  array<string, mixed>  $hashes  덮어쓸 해시 컬럼
     * @return InicisIdentityRecord 만든 record
     */
    private function createRecordForUser(int $userId, array $hashes): InicisIdentityRecord
    {
        return $this->recordRepository->upsertForUser($userId, array_merge([
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

    /**
     * g7_plugin_settings() 헬퍼가 읽는 config 경로에 settings 주입.
     *
     * @param  array<string, mixed>  $settings  플러그인 설정
     * @return void 반환값 없음
     */
    private function setPluginSettings(array $settings): void
    {
        Config::set('g7_settings.plugins.sirsoft-verification_kginicis', $settings);
    }
}
