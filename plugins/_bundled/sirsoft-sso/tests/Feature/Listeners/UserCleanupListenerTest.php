<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Listeners;

use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\Listeners\CleanSsoAccountsOnUserLeave;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 회원 탈퇴, 삭제 때 IdP 연결 정리 — CASCADE 가 없으므로 리스너가 지운다(FK 오류 0, 같은 신원 재가입 가능).
 *
 * @since 1.0.0
 */
class UserCleanupListenerTest extends PluginTestCase
{
    /**
     * 회원 삭제 전에 연결을 지워 FK 오류가 없고 같은 신원을 다시 쓸 수 있는지 확인한다.
     *
     * @scenario lifecycle=delete
     *
     * @effects accounts_removed, user_delete_no_fk_error, unlink_hooks
     *
     * @return void 반환값 없음
     */
    public function test_delete_removes_links_before_user_row(): void
    {
        $idp = $this->mockOidcIdp();
        $user = User::factory()->create();
        $this->link($user, $idp, 'cl-1');

        $events = [];
        HookManager::addAction(CleanSsoAccountsOnUserLeave::ACTION_BEFORE_UNLINK, function ($a, $u, $reason) use (&$events) {
            $events[] = 'before:'.$reason;
        });
        HookManager::addAction(CleanSsoAccountsOnUserLeave::ACTION_AFTER_UNLINK, function ($a, $u, $reason) use (&$events) {
            $events[] = 'after:'.$reason;
        });

        HookManager::doAction('core.user.before_delete', $user);
        $user->delete();

        $this->assertDatabaseMissing('sso_accounts', ['subject' => 'cl-1']);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(['before:delete', 'after:delete'], $events);

        // 같은 IdP 신원으로 다시 연결할 수 있다(UNIQUE 해제).
        $this->link(User::factory()->create(), $idp, 'cl-1');
        $this->assertDatabaseHas('sso_accounts', ['subject' => 'cl-1']);
    }

    /**
     * 회원 탈퇴 뒤 그 회원의 연결만 지우는지 확인한다.
     *
     * @scenario lifecycle=withdraw
     *
     * @effects accounts_removed
     *
     * @return void 반환값 없음
     */
    public function test_withdraw_removes_links(): void
    {
        $idp = $this->mockOidcIdp();
        $user = User::factory()->create();
        $this->link($user, $idp, 'cl-2');
        $other = User::factory()->create();
        $this->link($other, $idp, 'cl-3');

        HookManager::doAction('core.user.after_withdraw', $user);

        $this->assertDatabaseMissing('sso_accounts', ['subject' => 'cl-2']);
        // 다른 회원 연결은 그대로.
        $this->assertDatabaseHas('sso_accounts', ['subject' => 'cl-3']);
    }
}
