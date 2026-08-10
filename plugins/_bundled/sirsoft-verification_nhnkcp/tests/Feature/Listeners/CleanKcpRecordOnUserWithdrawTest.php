<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Listeners;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 회원 탈퇴 시 본인확인 정보 파기 검증 (PIPC).
 *
 * record 삭제뿐 아니라 인증 기록의 식별값 보안 지문까지 지워야 탈퇴 후 동일인 추적에 악용될
 * 여지가 남지 않는다. 감사 추적 필드는 보존한다.
 *
 * @since 1.0.0
 */
class CleanKcpRecordOnUserWithdrawTest extends PluginTestCase
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
     * @scenario trigger=withdraw,record_exists=true
     *
     * @effects withdraw_deletes_record_and_anonymizes_logs
     */
    public function test_withdraw_deletes_record_and_anonymizes_log(): void
    {
        $user = User::factory()->create();
        $this->recordRepository->upsertForUser($user->id, [
            'di_hash' => hash_hmac('sha256', 'DI-WITHDRAW', (string) config('app.key')),
            'verified_at' => now(),
        ]);
        $log = $this->createVerifiedLog($user->id);

        HookManager::doAction('core.user.after_withdraw', $user);

        $this->assertNull($this->recordRepository->findByUserId($user->id));

        $reload = $this->logRepository->findById($log->id);
        $this->assertNull($reload->user_id, '탈퇴자 로그는 익명화되어야 한다');
        $this->assertArrayNotHasKey('di_hash', (array) $reload->metadata);
        $this->assertArrayNotHasKey('ci_hash', (array) $reload->metadata);
    }

    /**
     * @scenario trigger=withdraw,record_exists=true
     *
     * @effects anonymized_log_keeps_audit_fields_but_drops_identity_hashes
     */
    public function test_audit_fields_are_preserved_after_anonymization(): void
    {
        $user = User::factory()->create();
        $log = $this->createVerifiedLog($user->id);

        HookManager::doAction('core.user.after_withdraw', $user);

        $metadata = (array) $this->logRepository->findById($log->id)->metadata;
        $this->assertSame('di', $metadata['duplicate_field_used'] ?? null, '감사 추적 필드는 보존해야 한다');
    }

    /**
     * @scenario trigger=withdraw,record_exists=false
     *
     * @effects missing_record_is_a_no_op
     */
    public function test_withdraw_without_record_is_a_no_op(): void
    {
        $user = User::factory()->create();

        HookManager::doAction('core.user.after_withdraw', $user);

        $this->assertNull($this->recordRepository->findByUserId($user->id));
    }

    /**
     * 본 플러그인이 발행한 인증 완료 로그를 만든다.
     *
     * @param  int  $userId  사용자 ID
     * @return IdentityVerificationLog
     */
    private function createVerifiedLog(int $userId)
    {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => KcpIdentityProvider::PROVIDER_ID,
            'purpose' => 'self_update',
            'channel' => KcpIdentityProvider::CHANNEL,
            'user_id' => $userId,
            'target_hash' => hash('sha256', 'withdraw@example.test'),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => 'api.identity.verify',
            'verification_token' => 'tok-'.Str::random(8),
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'metadata' => [
                'di_hash' => hash_hmac('sha256', 'DI-WITHDRAW', (string) config('app.key')),
                'ci_hash' => hash_hmac('sha256', 'CI-WITHDRAW', (string) config('app.key')),
                'duplicate_field_used' => 'di',
            ],
        ]);
    }
}
