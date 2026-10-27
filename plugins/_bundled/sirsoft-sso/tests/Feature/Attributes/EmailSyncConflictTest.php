<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Attributes;

use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Services\AttributeSyncService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 이메일 동기화(P12) — 소유 도메인일 때만 받고, 다른 회원이 쓰는 주소면 건너뛰고 알림 훅을 한 번만 낸다.
 *
 * @since 1.0.0
 */
class EmailSyncConflictTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 이메일 규칙을 가진 IdP 를 만듭니다.
     *
     * @return IdentityProvider 대상 IdP
     */
    private function emailIdp(): IdentityProvider
    {
        return $this->makeIdp(['attribute_map' => [['field' => 'email', 'source' => 'email', 'mode' => 'sync']]]);
    }

    /**
     * 소유 도메인 주소는 바뀌고, 밖의 도메인은 건너뛴다.
     *
     * @scenario domain=owned|foreign
     *
     * @effects email_synced, foreign_skipped
     *
     * @return void 반환값 없음
     */
    public function test_owned_domain_synced_foreign_skipped(): void
    {
        $idp = $this->emailIdp();
        $user = User::factory()->create(['email' => $this->uniqueEmail('corp798.test')]);
        $account = $this->link($user, $idp, 'em-1');
        $new = $this->uniqueEmail('corp798.test');
        $foreign = $this->uniqueEmail('other.test');

        $this->assertSame('skipped', app(AttributeSyncService::class)->sync($user, $account, $idp, new ExternalIdentity(subject: 'em-1', email: $foreign), false)['email']);
        $this->assertSame('synced', app(AttributeSyncService::class)->sync($user, $account, $idp, new ExternalIdentity(subject: 'em-1', email: mb_strtoupper($new)), false)['email']);
        $this->assertSame($new, $user->fresh()->email);
    }

    /**
     * 다른 회원이 쓰는 주소면 바꾸지 않고 충돌 훅을 한 번만 낸다(같은 주소로 다시 와도 다시 알리지 않는다).
     *
     * @scenario target_email=used_by_other
     *
     * @effects email_unchanged, conflict_hook_once
     *
     * @return void 반환값 없음
     */
    public function test_conflict_skips_and_notifies_once(): void
    {
        $idp = $this->emailIdp();
        $taken = $this->uniqueEmail('corp798.test');
        User::factory()->create(['email' => $taken]);
        $own = $this->uniqueEmail('corp798.test');
        $user = User::factory()->create(['email' => $own]);
        $account = $this->link($user, $idp, 'em-2');
        $hooks = [];
        HookManager::addAction(AttributeSyncService::ACTION_EMAIL_CONFLICT, function ($u, $a, $email) use (&$hooks) {
            $hooks[] = [$u->id, $a->id, $email];
        });

        $identity = new ExternalIdentity(subject: 'em-2', email: $taken);
        $this->assertSame('conflict', app(AttributeSyncService::class)->sync($user, $account, $idp, $identity, false)['email']);
        app(AttributeSyncService::class)->sync($user, $account, $idp, $identity, false);

        $this->assertSame($own, $user->fresh()->email);
        $this->assertSame([[$user->id, $account->id, $taken]], $hooks);
    }
}
