<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature;

use App\Models\User;
use Mockery;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentHistoryRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;
use RuntimeException;

/**
 * 동의 이력 정리 원자성 테스트 (공개이슈 #112)
 *
 * 탈퇴 시 항목별 철회는 루프이고, 삭제 시 status 삭제와 history 익명화는 2단계다.
 * 중간에 실패하면 일부 항목만 철회되거나, 삭제만 되고 감사 이력에 식별자가 남는다.
 *
 * @scenario trigger=withdraw, trigger=delete, failure_injection=mid_loop, failure_injection=second_step
 *
 * @effects partial_revoke_rolled_back, delete_and_anonymize_are_atomic
 */
class GdprConsentAtomicityTest extends PluginTestCase
{
    /**
     * 탈퇴 철회 루프가 중간에 실패하면 앞서 철회된 항목도 되돌아간다.
     */
    public function test_revoke_all_on_withdraw_rolls_back_partial_loop(): void
    {
        $user = User::factory()->create();
        $service = app(GdprConsentService::class);

        // 동의 2건을 만든 뒤 철회 루프를 중간에서 실패시킨다
        $service->updateConsents($user->id, null, ['marketing' => true, 'analytics' => true], 'signup');

        $activeBefore = app(GdprUserConsentRepositoryInterface::class)
            ->getActiveByUserId($user->id)
            ->count();

        $this->assertGreaterThanOrEqual(2, $activeBefore, '철회 대상 동의가 2건 이상이어야 합니다');

        // history 기록을 실패시켜 루프 중간에서 예외를 유발한다
        $failing = Mockery::mock(app(GdprUserConsentHistoryRepositoryInterface::class));
        $failing->shouldReceive('record')->andThrow(new RuntimeException('이력 기록 실패'));
        $this->app->instance(GdprUserConsentHistoryRepositoryInterface::class, $failing);

        try {
            app(GdprConsentService::class)->revokeAllOnWithdraw($user->id);
            $this->fail('철회 루프 실패가 전파되어야 합니다.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('이력 기록 실패', $e->getMessage());
        }

        // 실패 시: 앞 항목만 철회되고 뒤 항목은 그대로인 반쪽 상태
        $this->app->forgetInstance(GdprUserConsentHistoryRepositoryInterface::class);
        $activeAfter = app(GdprUserConsentRepositoryInterface::class)
            ->getActiveByUserId($user->id)
            ->count();

        $this->assertSame($activeBefore, $activeAfter, '철회가 일부만 반영된 채 남았습니다');
    }

    /**
     * 삭제 시 history 익명화가 실패하면 status 삭제도 되돌아간다.
     */
    public function test_purge_on_user_delete_rolls_back_when_anonymize_fails(): void
    {
        $user = User::factory()->create();
        $service = app(GdprConsentService::class);

        $service->updateConsents($user->id, null, ['marketing' => true], 'signup');

        $before = app(GdprUserConsentRepositoryInterface::class)->getActiveByUserId($user->id)->count();
        $this->assertGreaterThan(0, $before);

        $failing = Mockery::mock(app(GdprUserConsentHistoryRepositoryInterface::class));
        $failing->shouldReceive('anonymizeForUser')->andThrow(new RuntimeException('익명화 실패'));
        $this->app->instance(GdprUserConsentHistoryRepositoryInterface::class, $failing);

        try {
            app(GdprConsentService::class)->purgeOnUserDelete($user->id);
            $this->fail('익명화 실패가 전파되어야 합니다.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('익명화 실패', $e->getMessage());
        }

        // 실패 시: 동의 status 는 지워졌는데 감사 이력에는 회원 식별자가 남는 상태
        $this->app->forgetInstance(GdprUserConsentHistoryRepositoryInterface::class);
        $this->assertSame(
            $before,
            app(GdprUserConsentRepositoryInterface::class)->getActiveByUserId($user->id)->count()
        );
    }
}
