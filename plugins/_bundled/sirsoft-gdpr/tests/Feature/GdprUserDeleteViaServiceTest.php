<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature;

use App\Extension\HookListenerRegistrar;
use App\Models\User;
use App\Services\UserService;
use PHPUnit\Framework\AssertionFailedError;
use Plugins\Sirsoft\Gdpr\Listeners\GdprUserDeleteListener;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsent;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentHistoryRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 회원 완전 삭제 — 같은 트랜잭션에서 동의 이력을 익명화하고, 실패하면 삭제도 취소한다
 *
 * 익명화 리스너가 큐로 가면 회원 삭제가 커밋된 뒤에 돈다. 그때는 외래키 nullOnDelete 가 이력의 회원 ID 를 이미
 * 지워 익명화 대상을 찾지 못하고(0건), IP·브라우저 정보가 그대로 남는다. 삭제와 같은 트랜잭션에서 sync 로 돌려
 * 익명화가 실패하면 회원 삭제도 함께 되돌린다.
 */
class GdprUserDeleteViaServiceTest extends PluginTestCase
{
    /**
     * 큐를 database 로 바꾸고 삭제 리스너를 운영과 같은 등록기로 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        HookListenerRegistrar::register(GdprUserDeleteListener::class, 'sirsoft-gdpr');
    }

    /**
     * 동의 상태·이력이 있는 회원을 만듭니다.
     *
     * @return array{0: User, 1: GdprUserConsentHistory} 회원과 이력 행
     */
    private function makeMemberWithHistory(): array
    {
        $user = User::factory()->create();

        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
        ]);

        $history = GdprUserConsentHistory::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
            'policy_version' => '1.0',
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Queue724-UA',
        ]);

        return [$user, $history];
    }

    /**
     * 삭제 리스너가 sync 로 선언돼 있다. (실패-먼저)
     *
     * @scenario path=service, queue=sync
     *
     * @effects history_anonymized_in_delete_transaction
     *
     * @return void 반환값 없음
     */
    public function test_before_delete_listener_is_synchronous(): void
    {
        $config = GdprUserDeleteListener::getSubscribedHooks()['core.user.before_delete'] ?? [];

        $this->assertTrue(($config['sync'] ?? false) === true, 'core.user.before_delete 가 sync 가 아닙니다 — 커밋 뒤에 돌아 익명화가 0건이 됩니다.');
    }

    /**
     * 회원 삭제 서비스로 지우면 그 회원 동의 이력의 IP·브라우저 정보가 지워지고 행은 남는다. (실패-먼저)
     *
     * @scenario path=service, queue=database
     *
     * @effects history_anonymized_in_delete_transaction
     *
     * @return void 반환값 없음
     */
    public function test_history_is_anonymized_when_user_is_deleted_via_service(): void
    {
        [$user, $history] = $this->makeMemberWithHistory();

        app(UserService::class)->deleteUser($user);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $row = GdprUserConsentHistory::find($history->id);
        $this->assertNotNull($row, '동의 이력 행이 사라졌습니다 — 동의 입증 기록은 남겨야 합니다.');
        $this->assertNull($row->user_id);
        $this->assertNull($row->ip_address, '회원을 지웠는데 동의 이력에 IP 가 남았습니다.');
        $this->assertNull($row->user_agent, '회원을 지웠는데 동의 이력에 브라우저 정보가 남았습니다.');
        $this->assertDatabaseMissing('gdpr_user_consents', ['user_id' => $user->id]);
    }

    /**
     * 익명화가 실패하면 회원 삭제도 취소되어 회원 행이 남는다. (실패-먼저)
     *
     * @scenario path=service, queue=database
     *
     * @effects delete_rolled_back_when_anonymize_fails
     *
     * @return void 반환값 없음
     */
    public function test_user_delete_is_rolled_back_when_anonymization_fails(): void
    {
        [$user] = $this->makeMemberWithHistory();

        $failing = \Mockery::mock(GdprUserConsentHistoryRepositoryInterface::class);
        $failing->shouldReceive('anonymizeForUser')->andThrow(new \RuntimeException('익명화 실패 대역'));
        $this->app->instance(GdprUserConsentHistoryRepositoryInterface::class, $failing);

        try {
            app(UserService::class)->deleteUser($user);
            $this->fail('익명화가 실패했는데 회원 삭제가 성공으로 끝났습니다.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e, $e->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('gdpr_user_consents', ['user_id' => $user->id]);
    }
}
