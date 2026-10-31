<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Attributes;

use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 칸 잠금(K8 구독) — 활성 연결 IdP 들의 `sync_lock` 코어 칸 합집합. 비활성 연결과 `sync` 칸은 잠그지 않는다. 마이페이지 수정 422.
 *
 * @since 1.0.0
 */
class LockedFieldsListenerTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 두 IdP 의 sync_lock 칸 합집합, 비활성 연결의 칸은 빠진다.
     *
     * @scenario links=two_active+one_inactive
     *
     * @effects union_of_sync_lock
     *
     * @return void 반환값 없음
     */
    public function test_union_of_active_links(): void
    {
        $user = User::factory()->create();
        $a = $this->makeIdp(['attribute_map' => [['field' => 'name', 'source' => 'display_name', 'mode' => 'sync_lock'], ['field' => 'nickname', 'source' => 'n', 'mode' => 'sync']]]);
        $b = $this->makeIdp(['attribute_map' => [['field' => 'mobile', 'source' => 'm', 'mode' => 'sync_lock'], ['field' => 'attr:department', 'source' => 'd', 'mode' => 'sync_lock']]]);
        $c = $this->makeIdp(['attribute_map' => [['field' => 'phone', 'source' => 'p', 'mode' => 'sync_lock']]]);
        $this->link($user, $a, 'lk-a');
        $this->link($user, $b, 'lk-b');
        $this->link($user, $c, 'lk-c', ['active' => false]);

        $fields = HookManager::applyFilters('core.user.locked_fields', [], $user, 'profile');
        sort($fields);

        $this->assertSame(['mobile', 'name'], $fields);
        $this->assertSame([], HookManager::applyFilters('core.user.locked_fields', [], User::factory()->create(), 'admin'));
    }

    /**
     * 마이페이지 수정에서 잠긴 칸을 다른 값으로 보내면 422 `user.field_locked`, 같은 값이나 잠기지 않은 칸은 통과.
     *
     * @scenario surface=profile, value=different|same
     *
     * @effects rejected_422_field_locked, unlocked_field_saved
     *
     * @return void 반환값 없음
     */
    public function test_profile_update_rejects_locked_field(): void
    {
        $idp = $this->makeIdp(['attribute_map' => [['field' => 'name', 'source' => 'display_name', 'mode' => 'sync_lock']]]);
        $user = User::factory()->create(['name' => '회사이름']);
        $this->link($user, $idp, 'lk-profile');
        $token = $user->createToken('lk')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/me', ['name' => '바꾼이름'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
        $this->assertSame('회사이름', $user->fresh()->name);
    }
}
