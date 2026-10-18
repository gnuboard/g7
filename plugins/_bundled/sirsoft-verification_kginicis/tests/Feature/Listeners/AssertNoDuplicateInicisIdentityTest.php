<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Listeners;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\IdentityDuplicateException;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Models\InicisIdentityRecord;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * AssertNoDuplicateInicisIdentity Listener 통합 테스트.
 *
 * 실제 hook 체인 (HookManager::doAction → Listener::handle) + 실제 DB 검증.
 * 도메인 매트릭스 §Hook/Event 규정: mock 금지.
 *
 * 검증 매트릭스:
 *  - duplicate_block_enabled=false → 모든 케이스 통과
 *  - duplicate_block_enabled=true:
 *    - 다른 provider 의 token → 통과
 *    - DI hash 매칭 시 IdentityDuplicateException
 *    - CI hash 매칭 시 IdentityDuplicateException (settings duplicate_field=ci)
 *    - hash NULL (외국인) → 통과
 *    - 매칭 record 없음 → 통과
 */
class AssertNoDuplicateInicisIdentityTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    private InicisIdentityRecordRepositoryInterface $recordRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->recordRepository = app(InicisIdentityRecordRepositoryInterface::class);

        // 기본 settings — duplicate_block_enabled=true, duplicate_field=di
        $this->setPluginSettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'di']);
    }

    public function test_passes_when_duplicate_block_disabled(): void
    {
        $this->setPluginSettings(['duplicate_block_enabled' => false, 'duplicate_field' => 'di']);

        $diHash = hash('sha256', 'di-existing');
        $this->createRecordForUser(User::factory()->create()->id, ['di_hash' => $diHash]);

        $log = $this->createVerifiedSignupLog('tok-disabled', metadata: ['di_hash' => $diHash]);

        // throw 없이 통과되어야 함
        HookManager::doAction('core.auth.before_register', [
            'email' => 'new@example.com',
            'verification_token' => 'tok-disabled',
        ], ['signup_stage' => 'before_submit']);

        $this->assertTrue(true); // 통과 의미
    }

    public function test_blocks_when_di_hash_matches_existing_user(): void
    {
        $diHash = hash('sha256', 'di-shared');
        $this->createRecordForUser(User::factory()->create()->id, ['di_hash' => $diHash]);

        $this->createVerifiedSignupLog('tok-di-match', metadata: ['di_hash' => $diHash]);

        $this->expectException(IdentityDuplicateException::class);

        HookManager::doAction('core.auth.before_register', [
            'email' => 'other@example.com',
            'verification_token' => 'tok-di-match',
        ], ['signup_stage' => 'before_submit']);
    }

    public function test_blocks_when_ci_hash_matches_under_ci_mode(): void
    {
        $this->setPluginSettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'ci']);

        $ciHash = hash('sha256', 'ci-shared');
        $this->createRecordForUser(User::factory()->create()->id, ['ci_hash' => $ciHash]);

        $this->createVerifiedSignupLog('tok-ci-match', metadata: ['ci_hash' => $ciHash]);

        $this->expectException(IdentityDuplicateException::class);

        HookManager::doAction('core.auth.before_register', [
            'email' => 'other@example.com',
            'verification_token' => 'tok-ci-match',
        ], ['signup_stage' => 'before_submit']);
    }

    public function test_block_records_matched_field_di_hash_in_log_metadata_for_admin_audit(): void
    {
        $diHash = hash('sha256', 'di-audit');
        $existing = $this->createRecordForUser(User::factory()->create()->id, ['di_hash' => $diHash]);

        $this->createVerifiedSignupLog('tok-di-audit', metadata: ['di_hash' => $diHash]);

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

    public function test_block_records_matched_field_ci_hash_under_ci_mode(): void
    {
        $this->setPluginSettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'ci']);

        $ciHash = hash('sha256', 'ci-audit');
        $existing = $this->createRecordForUser(User::factory()->create()->id, ['ci_hash' => $ciHash]);

        $this->createVerifiedSignupLog('tok-ci-audit', metadata: ['ci_hash' => $ciHash]);

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

    public function test_passes_when_no_matching_record_exists(): void
    {
        $this->createVerifiedSignupLog('tok-no-match', metadata: ['di_hash' => hash('sha256', 'di-unique')]);

        HookManager::doAction('core.auth.before_register', [
            'email' => 'newcomer@example.com',
            'verification_token' => 'tok-no-match',
        ], ['signup_stage' => 'before_submit']);

        $this->assertTrue(true);
    }

    public function test_passes_when_di_hash_is_null_foreigner_case(): void
    {
        $this->createVerifiedSignupLog('tok-foreigner', metadata: ['di_hash' => null, 'ci_hash' => null]);

        HookManager::doAction('core.auth.before_register', [
            'email' => 'foreigner@example.com',
            'verification_token' => 'tok-foreigner',
        ], ['signup_stage' => 'before_submit']);

        $this->assertTrue(true);
    }

    public function test_passes_when_log_belongs_to_other_provider(): void
    {
        $diHash = hash('sha256', 'di-shared');
        $this->createRecordForUser(User::factory()->create()->id, ['di_hash' => $diHash]);

        // mail provider 의 log — 본 plugin 의 책임 외
        $this->createVerifiedSignupLog('tok-other', providerId: 'mail', metadata: ['di_hash' => $diHash]);

        HookManager::doAction('core.auth.before_register', [
            'email' => 'other@example.com',
            'verification_token' => 'tok-other',
        ], ['signup_stage' => 'before_submit']);

        $this->assertTrue(true);
    }

    public function test_passes_when_verification_token_missing(): void
    {
        // token 없으면 listener 가 자동으로 return
        HookManager::doAction('core.auth.before_register', [
            'email' => 'no-token@example.com',
        ], ['signup_stage' => 'before_submit']);

        $this->assertTrue(true);
    }

    public function test_blocks_even_after_core_listener_consumed_token(): void
    {
        // 코어 priority 10 listener 가 consume 한 직후를 시뮬레이션 — log.consumed_at 가 set 되어 있어도
        // 본 plugin 의 LogQueryRepository 가 consumed_at 무관 조회로 회수 가능해야 함.
        $diHash = hash('sha256', 'di-consumed-but-blocked');
        $this->createRecordForUser(User::factory()->create()->id, ['di_hash' => $diHash]);

        $log = $this->createVerifiedSignupLog('tok-consumed', metadata: ['di_hash' => $diHash]);
        $this->logRepository->updateById($log->id, ['consumed_at' => now()]);

        $this->expectException(IdentityDuplicateException::class);

        HookManager::doAction('core.auth.before_register', [
            'email' => 'other@example.com',
            'verification_token' => 'tok-consumed',
        ], ['signup_stage' => 'before_submit']);
    }

    /**
     * inicis 본인확인 verified 로그를 직접 만든다.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function createVerifiedSignupLog(
        string $token,
        string $providerId = InicisIdentityProvider::PROVIDER_ID,
        array $metadata = [],
    ): IdentityVerificationLog {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => $providerId,
            'purpose' => 'signup',
            'channel' => 'ipin',
            'user_id' => null,
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
            'metadata' => $metadata,
        ]);
    }

    /**
     * inicis_identity_records 에 user 의 hash row 를 직접 만든다.
     *
     * @param  array<string, mixed>  $hashes
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
     * @param  array<string, mixed>  $settings
     */
    private function setPluginSettings(array $settings): void
    {
        Config::set('g7_settings.plugins.sirsoft-verification_kginicis', $settings);
    }
}
