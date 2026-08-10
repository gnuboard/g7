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
 * 관리자 회원 삭제 시 본인확인 정보 파기 검증 (PIPC).
 *
 * record 의 user_id 는 users(id) 외래키를 가지며 CASCADE 를 두지 않으므로, 삭제 직전 훅에서
 * 먼저 파기해야 회원 삭제가 제약 위반 없이 완료된다.
 *
 * @since 1.0.0
 */
class CleanKcpRecordOnUserDeleteTest extends PluginTestCase
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
     * @scenario trigger=admin_delete,record_exists=true
     *
     * @effects admin_delete_deletes_record_before_user_row_removal
     */
    public function test_record_is_removed_before_user_row_so_deletion_succeeds(): void
    {
        $user = User::factory()->create();
        $this->recordRepository->upsertForUser($user->id, [
            'di_hash' => hash_hmac('sha256', 'DI-DELETE', (string) config('app.key')),
            'verified_at' => now(),
        ]);
        $log = $this->createVerifiedLog($user->id);

        HookManager::doAction('core.user.before_delete', $user);

        $this->assertNull($this->recordRepository->findByUserId($user->id));
        $this->assertNull($this->logRepository->findById($log->id)->user_id);

        // 파기 후에는 회원 행 삭제가 외래키 제약 위반 없이 완료되어야 한다.
        $user->delete();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /**
     * @scenario trigger=admin_delete,record_exists=false
     *
     * @effects missing_record_is_a_no_op
     */
    public function test_delete_without_record_is_a_no_op(): void
    {
        $user = User::factory()->create();

        HookManager::doAction('core.user.before_delete', $user);

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
            'target_hash' => hash('sha256', 'delete@example.test'),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => 'api.identity.verify',
            'verification_token' => 'tok-'.Str::random(8),
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['di_hash' => hash_hmac('sha256', 'DI-DELETE', (string) config('app.key'))],
        ]);
    }
}
