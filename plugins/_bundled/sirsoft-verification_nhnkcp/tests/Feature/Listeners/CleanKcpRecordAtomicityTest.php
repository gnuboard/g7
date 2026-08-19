<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Listeners;

use App\Extension\HookManager;
use App\Models\User;
use Mockery;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityLogQueryRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;
use RuntimeException;

/**
 * 본인확인 정리 원자성 테스트 (공개이슈 #112)
 *
 * record 파기와 로그 익명화는 함께 성공해야 한다 — 한쪽만 끝나면 파기된 것으로 보이는데
 * 이력에 식별자가 남거나, 그 반대가 된다.
 *
 * @scenario trigger=withdraw, trigger=delete, failure_injection=log_anonymize_fails
 *
 * @effects record_purge_rolled_back, half_done_state_prevented
 */
class CleanKcpRecordAtomicityTest extends PluginTestCase
{
    /**
     * 탈퇴 훅: 로그 익명화가 실패하면 record 파기도 되돌아간다.
     */
    public function test_record_purge_rolls_back_when_log_anonymize_fails_on_withdraw(): void
    {
        $user = $this->seedUserWithRecord();

        $this->failLogAnonymize();

        try {
            HookManager::doAction('core.user.after_withdraw', $user);
            $this->fail('로그 익명화 실패가 전파되어야 합니다.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('익명화 실패', $e->getMessage());
        }

        // 실패 시: record 만 파기되고 이력에는 식별자가 남는 반쪽 상태
        $this->assertNotNull(
            app(KcpIdentityRecordRepositoryInterface::class)->findByUserId($user->id)
        );
    }

    /**
     * 삭제 훅에서도 같은 원자성이 보장된다.
     */
    public function test_record_purge_rolls_back_when_log_anonymize_fails_on_delete(): void
    {
        $user = $this->seedUserWithRecord();

        $this->failLogAnonymize();

        try {
            HookManager::doAction('core.user.before_delete', $user);
            $this->fail('로그 익명화 실패가 전파되어야 합니다.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('익명화 실패', $e->getMessage());
        }

        $this->assertNotNull(
            app(KcpIdentityRecordRepositoryInterface::class)->findByUserId($user->id)
        );
    }

    /**
     * record 를 가진 사용자를 만듭니다.
     *
     * @return User 생성된 사용자
     */
    private function seedUserWithRecord(): User
    {
        $user = User::factory()->create();

        app(KcpIdentityRecordRepositoryInterface::class)->upsertForUser($user->id, [
            'di_hash' => hash_hmac('sha256', 'DI-ATOMIC-'.$user->id, (string) config('app.key')),
            'verified_at' => now(),
        ]);

        $this->assertNotNull(app(KcpIdentityRecordRepositoryInterface::class)->findByUserId($user->id));

        return $user;
    }

    /**
     * 두 번째 단계(로그 익명화)만 실패시킵니다.
     */
    private function failLogAnonymize(): void
    {
        $failing = Mockery::mock(app(KcpIdentityLogQueryRepositoryInterface::class));
        $failing->shouldReceive('anonymizeUserId')->andThrow(new RuntimeException('익명화 실패'));

        $this->app->instance(KcpIdentityLogQueryRepositoryInterface::class, $failing);
    }
}
