<?php

namespace Tests\Feature\Auth;

use App\Enums\ExtensionOwnerType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\GuestRoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 꺼진 역할의 권한은 판정에서 제외된다 (S8, 제품 결정 2026-10-09).
 *
 * 역할 「사용 여부」를 끄면 그 역할로 받은 권한이 즉시 회수된다. 회원 판정(hasPermission·
 * isAdmin·Gate·미들웨어·abilities), 비회원 판정(guest 역할), 메뉴 접근이 모두 같은 기준을 쓴다.
 * 최고관리자 역할(admin)은 끄면 운영자 전원이 관리 화면을 잃으므로 사용 여부를 바꿀 수 없다.
 */
class InactiveRolePermissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 권한을 만들거나 찾습니다.
     *
     * @param  string  $identifier  권한 식별자
     * @param  string  $type  권한 타입 (admin|user)
     * @return Permission 권한 모델
     */
    private function permission(string $identifier, string $type = 'admin'): Permission
    {
        return Permission::firstOrCreate(
            ['identifier' => $identifier],
            [
                'name' => ['ko' => $identifier, 'en' => $identifier],
                'description' => ['ko' => $identifier, 'en' => $identifier],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => $type,
            ]
        );
    }

    /**
     * 권한 하나를 가진 역할을 만듭니다.
     *
     * @param  bool  $isActive  역할 사용 여부
     * @param  string  $permission  부여할 권한 식별자
     * @return Role 역할 모델
     */
    private function roleWith(bool $isActive, string $permission): Role
    {
        $role = Role::create([
            'identifier' => 'inactive_role_test_'.uniqid(),
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'is_active' => $isActive,
        ]);
        $role->permissions()->sync([$this->permission($permission)->id]);

        return $role;
    }

    /**
     * 꺼진 역할로만 받은 권한은 회원 판정에서 거짓이다 — 켜진 역할은 그대로 참
     *
     * @scenario subject=member,role_state=inactive|active
     *
     * @effects inactive_role_permissions_are_excluded
     *
     * @return void 반환값 없음
     */
    public function test_permissions_from_inactive_role_are_excluded(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->roleWith(false, 'core.users.delete')->id);
        $user->roles()->attach($this->roleWith(true, 'core.users.read')->id);
        $user = $user->fresh();

        $this->assertFalse($user->hasPermission('core.users.delete'));
        $this->assertTrue($user->hasPermission('core.users.read'));
        $this->assertFalse(PermissionHelper::check('core.users.delete', $user));
        $this->assertTrue(PermissionHelper::check('core.users.read', $user));
    }

    /**
     * 역할을 끄면 같은 요청에서 eager load 한 역할 경로에서도 권한이 빠진다
     *
     * @scenario subject=member,role_state=inactive,loading=eager
     *
     * @effects inactive_role_permissions_are_excluded_on_eager_path
     *
     * @return void 반환값 없음
     */
    public function test_eager_loaded_inactive_role_is_excluded(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->roleWith(false, 'core.users.delete')->id);

        $loaded = User::with('roles.permissions')->find($user->id);

        $this->assertFalse($loaded->hasPermission('core.users.delete'));
        $this->assertFalse($loaded->isAdmin(), '꺼진 역할의 관리자 권한만 가진 사용자는 관리자가 아니다');
    }

    /**
     * 관리자 API 는 꺼진 역할의 권한으로 열리지 않는다 (미들웨어 403)
     *
     * @scenario subject=member,role_state=inactive,endpoint=admin.users.index
     *
     * @effects inactive_role_permissions_rejected_by_middleware
     *
     * @return void 반환값 없음
     */
    public function test_middleware_rejects_permission_from_inactive_role(): void
    {
        $user = User::factory()->create();
        $role = $this->roleWith(true, 'core.users.read');
        $user->roles()->attach($role->id);

        $this->actingAs($user->fresh())->getJson('/api/admin/users')->assertOk();

        $role->update(['is_active' => false]);

        $this->actingAs($user->fresh())->getJson('/api/admin/users')->assertForbidden();
    }

    /**
     * guest 역할을 끄면 비회원 권한도 회수된다
     *
     * @scenario subject=guest,role_state=inactive
     *
     * @effects inactive_guest_role_permissions_are_excluded
     *
     * @return void 반환값 없음
     */
    public function test_inactive_guest_role_grants_nothing(): void
    {
        $guest = Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest'], 'is_active' => true]
        );
        $guest->permissions()->syncWithoutDetaching([$this->permission('core.test.guest_read', 'user')->id]);
        GuestRoleResolver::flush();
        $this->assertTrue(GuestRoleResolver::hasPermission('core.test.guest_read'));

        $guest->update(['is_active' => false]);
        GuestRoleResolver::flush();

        $this->assertFalse(GuestRoleResolver::hasPermission('core.test.guest_read'));
    }

    /**
     * 최고관리자 역할(admin)은 사용 여부를 바꿀 수 없다 — 최고관리자라도 토글·수정 모두 거부, 응답 abilities 도 잠김
     *
     * @scenario subject=super_admin,target_role=admin,action=toggle|update_is_active
     *
     * @effects admin_role_active_state_is_locked
     *
     * @return void 반환값 없음
     */
    public function test_admin_role_active_state_is_locked(): void
    {
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );
        $adminRole->permissions()->syncWithoutDetaching([
            $this->permission('core.permissions.read')->id,
            $this->permission('core.permissions.update')->id,
        ]);

        $super = User::factory()->create(['is_super' => true]);
        $super->roles()->attach($adminRole->id);
        $super = $super->fresh();

        $this->actingAs($super)->patchJson("/api/admin/roles/{$adminRole->id}/toggle-status")
            ->assertStatus(422);
        $this->assertTrue($adminRole->fresh()->is_active);

        $this->actingAs($super)->putJson("/api/admin/roles/{$adminRole->id}", [
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'is_active' => false,
        ])->assertStatus(422);
        $this->assertTrue($adminRole->fresh()->is_active);

        $show = $this->actingAs($super)->getJson("/api/admin/roles/{$adminRole->id}")->assertOk();
        $this->assertFalse($show->json('data.abilities.can_toggle_status'));
        $this->assertTrue($show->json('data.is_active_locked'));
    }
}
