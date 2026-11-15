<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Listeners;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\AssertNoDuplicateKcpIdentity;
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

    /**
     * 로그 저장소와 KCP 식별 기록 저장소를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->recordRepository = app(KcpIdentityRecordRepositoryInterface::class);
    }

    /**
     * DI 기준 차단 설정에서 같은 DI 해시의 기존 회원이 있으면 가입을 검증 오류로 막고, 안내에 개인정보가 없으며 감사 흔적(matched_field)이 남는지 확인합니다.
     *
     * @scenario gate=register,basis=di,blocking=enabled,relation=duplicate
     *
     * @effects register_listener_blocks_duplicate_with_validation_error
     *
     * @return void 반환값 없음
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
     * CI 기준 차단 설정에서는 CI 해시로 기존 회원을 찾아 가입을 막는지 확인합니다.
     *
     * @scenario gate=register,basis=ci,blocking=enabled,relation=duplicate
     *
     * @effects ci_basis_matches_by_ci_hash
     *
     * @return void 반환값 없음
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
     * 동일인 차단이 꺼져 있으면 같은 DI 해시의 기존 회원이 있어도 가입이 예외 없이 통과하는지 확인합니다.
     *
     * @scenario gate=register,basis=di,blocking=disabled,relation=duplicate
     *
     * @effects disabled_setting_passes_both_gates
     *
     * @return void 반환값 없음
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
     * 차단이 켜져 있어도 기존 기록이 없는 새 사람은 가입이 통과하는지 확인합니다.
     *
     * @scenario gate=register,basis=di,blocking=enabled,relation=new_person
     *
     * @effects register_listener_blocks_duplicate_with_validation_error
     *
     * @return void 반환값 없음
     */
    public function test_new_identity_passes(): void
    {
        $this->applySettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'di']);

        $this->createVerifiedSignupLog('tok-new', ['di_hash' => KcpIdentityHasher::hash('DI-BRAND-NEW')]);

        HookManager::doAction('core.auth.before_register', ['verification_token' => 'tok-new']);

        $this->assertTrue(true, '기존 record 가 없으면 통과해야 한다');
    }

    /**
     * 인증 로그에 식별값 해시가 없으면 대조할 수 없으므로 가입이 통과하는지 확인합니다.
     *
     * @scenario gate=register,basis=di,blocking=enabled,relation=missing_hash
     *
     * @effects missing_identifier_hash_passes
     *
     * @return void 반환값 없음
     */
    public function test_missing_hash_passes(): void
    {
        $this->applySettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'di']);

        $this->createVerifiedSignupLog('tok-no-hash', []);

        HookManager::doAction('core.auth.before_register', ['verification_token' => 'tok-no-hash']);

        $this->assertTrue(true, '식별값 해시가 없으면 통과해야 한다');
    }

    /**
     * 다른 수단(메일)의 인증 로그는 이 리스너가 대조하지 않아 가입이 통과하는지 확인합니다.
     *
     * @scenario gate=register,basis=di,blocking=enabled,relation=new_person
     *
     * @effects non_signup_purpose_is_not_blocked
     *
     * @return void 반환값 없음
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
     * 가입 정책이 KCP 를 지정했는데 다른 수단(메일)의 결과가 오면 거부하는지 확인합니다 (fail-closed, 1.1.4).
     *
     * 코어 가입 가드도 같은 판정을 하므로, 본 listener 의 이중 방어를 보려고 listener 를 직접 호출한다.
     *
     * @scenario gate=register,basis=di,blocking=enabled,relation=new_person
     *
     * @effects policy_requiring_kcp_rejects_other_provider_token
     *
     * @return void 반환값 없음
     */
    public function test_policy_requiring_kcp_rejects_other_provider_token(): void
    {
        $this->applySettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'di']);

        IdentityPolicy::create([
            'key' => 'kve-test.signup_requires_kcp',
            'scope' => 'route',
            'target' => 'api.auth.register',
            'purpose' => 'signup',
            'provider_id' => KcpIdentityProvider::PROVIDER_ID,
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
        ]);
        $log = $this->createVerifiedSignupLog('tok-mail-under-kcp-policy', [], 'g7:core.mail');

        try {
            app(AssertNoDuplicateKcpIdentity::class)->handle([
                'email' => 'dup-listener@example.test',
                'verification_token' => 'tok-mail-under-kcp-policy',
            ], ['signup_stage' => 'before_submit', 'http_method' => 'POST']);
            $this->fail('KCP 지정 가입 정책에서 다른 수단의 결과는 거부되어야 한다');
        } catch (AuthorizationException) {
            // 의도된 거부
        }

        $this->assertNull($this->logRepository->findById($log->id)->consumed_at, '거부된 결과를 소비하면 안 된다');
    }

    /**
     * 플러그인 설정을 런타임 설정 트리에 반영한다.
     *
     * listener 는 `g7_plugin_settings()` 헬퍼(= `config('g7_settings.plugins.{id}')`)로 설정을
     * 읽으므로, 실 환경의 부팅 시 동기화와 동형이 되도록 config 를 직접 채운다.
     *
     * @param  array<string, mixed>  $settings  반영할 플러그인 설정값
     * @return void 반환값 없음
     */
    private function applySettings(array $settings): void
    {
        config(['g7_settings.plugins.'.self::PLUGIN_IDENTIFIER => $settings]);
    }

    /**
     * 인증 완료 상태의 가입 목적 로그를 만든다.
     *
     * @param  string  $token  가입 요청에 실을 인증 토큰(verification_token)
     * @param  array<string, mixed>  $metadata  로그 metadata (식별값 해시)
     * @param  string  $providerId  provider 식별자
     * @return IdentityVerificationLog 만든 인증 로그
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
