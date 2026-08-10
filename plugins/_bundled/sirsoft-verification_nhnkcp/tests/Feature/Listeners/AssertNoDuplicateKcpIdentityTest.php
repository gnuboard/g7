<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Listeners;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Support\KcpIdentityHasher;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 가입 직전 동일인 차단 listener 통합 검증 (권위 게이트).
 *
 * 실제 훅 체인(core.auth.before_register)으로 차단 여부와 감사 흔적을 확인한다.
 *
 * @since 1.0.0
 */
class AssertNoDuplicateKcpIdentityTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    private KcpIdentityRecordRepositoryInterface $recordRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->recordRepository = app(KcpIdentityRecordRepositoryInterface::class);
    }

    /**
     * @scenario gate=register,basis=di,blocking=enabled,relation=duplicate
     *
     * @effects register_listener_blocks_duplicate_with_validation_error
     */
    public function test_duplicate_di_blocks_registration(): void
    {
        $this->applySettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'di']);

        $diHash = KcpIdentityHasher::hash('DI-EXISTING-0001');
        $this->recordRepository->upsertForUser(User::factory()->create()->id, [
            'di_hash' => $diHash,
            'verified_at' => now(),
        ]);

        $log = $this->createVerifiedSignupLog('tok-dup-block', ['di_hash' => $diHash]);

        try {
            HookManager::doAction('core.auth.before_register', ['verification_token' => 'tok-dup-block']);
            $this->fail('동일인 가입은 차단되어야 한다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('identity', $e->errors());
            // 안내 문구에 개인정보(이메일/이름)가 노출되면 안 된다.
            $this->assertStringNotContainsString('@', $e->errors()['identity'][0]);
        }

        $reload = $this->logRepository->findById($log->id);
        $this->assertSame('di_hash', $reload->metadata['matched_field'] ?? null, '감사 추적이 남아야 한다');
    }

    /**
     * @scenario gate=register,basis=ci,blocking=enabled,relation=duplicate
     *
     * @effects ci_basis_matches_by_ci_hash
     */
    public function test_duplicate_ci_blocks_registration_when_ci_is_the_basis(): void
    {
        $this->applySettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'ci']);

        $ciHash = KcpIdentityHasher::hash('CI-EXISTING-0001');
        $this->recordRepository->upsertForUser(User::factory()->create()->id, [
            'ci_hash' => $ciHash,
            'verified_at' => now(),
        ]);

        $this->createVerifiedSignupLog('tok-dup-ci', ['ci_hash' => $ciHash]);

        $this->expectException(ValidationException::class);

        HookManager::doAction('core.auth.before_register', ['verification_token' => 'tok-dup-ci']);
    }

    /**
     * @scenario gate=register,basis=di,blocking=disabled,relation=duplicate
     *
     * @effects disabled_setting_passes_both_gates
     */
    public function test_disabled_setting_allows_registration(): void
    {
        $this->applySettings(['duplicate_block_enabled' => false, 'duplicate_field' => 'di']);

        $diHash = KcpIdentityHasher::hash('DI-EXISTING-0002');
        $this->recordRepository->upsertForUser(User::factory()->create()->id, [
            'di_hash' => $diHash,
            'verified_at' => now(),
        ]);

        $this->createVerifiedSignupLog('tok-dup-off', ['di_hash' => $diHash]);

        HookManager::doAction('core.auth.before_register', ['verification_token' => 'tok-dup-off']);

        $this->assertTrue(true, '차단 비활성 시 예외 없이 통과해야 한다');
    }

    /**
     * @scenario gate=register,basis=di,blocking=enabled,relation=new_person
     *
     * @effects register_listener_blocks_duplicate_with_validation_error
     */
    public function test_new_identity_passes(): void
    {
        $this->applySettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'di']);

        $this->createVerifiedSignupLog('tok-new', ['di_hash' => KcpIdentityHasher::hash('DI-BRAND-NEW')]);

        HookManager::doAction('core.auth.before_register', ['verification_token' => 'tok-new']);

        $this->assertTrue(true, '기존 record 가 없으면 통과해야 한다');
    }

    /**
     * @scenario gate=register,basis=di,blocking=enabled,relation=missing_hash
     *
     * @effects missing_identifier_hash_passes
     */
    public function test_missing_hash_passes(): void
    {
        $this->applySettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'di']);

        $this->createVerifiedSignupLog('tok-no-hash', []);

        HookManager::doAction('core.auth.before_register', ['verification_token' => 'tok-no-hash']);

        $this->assertTrue(true, '식별값 해시가 없으면 통과해야 한다');
    }

    /**
     * @scenario gate=register,basis=di,blocking=enabled,relation=new_person
     *
     * @effects non_signup_purpose_is_not_blocked
     */
    public function test_other_provider_logs_are_ignored(): void
    {
        $this->applySettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'di']);

        $diHash = KcpIdentityHasher::hash('DI-EXISTING-0003');
        $this->recordRepository->upsertForUser(User::factory()->create()->id, [
            'di_hash' => $diHash,
            'verified_at' => now(),
        ]);

        $this->createVerifiedSignupLog('tok-other', ['di_hash' => $diHash], 'g7:core.mail');

        HookManager::doAction('core.auth.before_register', ['verification_token' => 'tok-other']);

        $this->assertTrue(true, '다른 provider 의 인증은 본 listener 책임이 아니다');
    }

    /**
     * 플러그인 설정을 런타임 설정 트리에 반영한다.
     *
     * listener 는 `g7_plugin_settings()` 헬퍼(= `config('g7_settings.plugins.{id}')`)로 설정을
     * 읽으므로, 실 환경의 부팅 시 동기화와 동형이 되도록 config 를 직접 채운다.
     *
     * @param  array<string, mixed>  $settings
     */
    private function applySettings(array $settings): void
    {
        config(['g7_settings.plugins.'.self::PLUGIN_IDENTIFIER => $settings]);
    }

    /**
     * 인증 완료 상태의 가입 목적 로그를 만든다.
     *
     * @param  string  $token  verification_token
     * @param  array<string, mixed>  $metadata  로그 metadata (식별값 해시)
     * @param  string  $providerId  provider 식별자
     */
    private function createVerifiedSignupLog(
        string $token,
        array $metadata,
        string $providerId = KcpIdentityProvider::PROVIDER_ID,
    ): IdentityVerificationLog {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => $providerId,
            'purpose' => 'signup',
            'channel' => KcpIdentityProvider::CHANNEL,
            'user_id' => null,
            'target_hash' => hash('sha256', 'dup-listener@example.test'),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => 'api.auth.register',
            'verification_token' => $token,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'metadata' => $metadata,
        ]);
    }
}
