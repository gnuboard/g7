<?php

namespace Tests\Feature\Security;

use App\Enums\ScopeType;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Services\RoleService;
use App\Support\ScopedPermissionEnforcement;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 비회원 역할에 권한 범위가 저장되는 요청 밖 경로를 막는지 검증합니다 (7.1.1 — D7).
 *
 * 역할 수정 요청은 422 로 막지만, RoleService::syncPermissions 는 public 이라 확장이 요청을 거치지 않고 부를 수 있고,
 * 확장 선언(와일드카드 '*' 포함)은 동기화로 들어온다. 두 경로 모두 비회원에 범위를 남기지 않아야 한다.
 */
class GuestRoleScopeDefenseTest extends TestCase
{
    use DatabaseTransactions;

    private Role $guest;

    /**
     * 코어 역할·권한을 심습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->guest = Role::where('identifier', Role::GUEST_IDENTIFIER)->firstOrFail();
    }

    /**
     * 비회원 역할의 범위 부여 여부를 식별자별로 돌려줍니다.
     *
     * @param  string  $identifier  권한 식별자
     * @return string|null|false 범위 (부여 없음이면 false)
     */
    private function guestScopeOf(string $identifier): string|null|false
    {
        $row = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $this->guest->id)
            ->where('permissions.identifier', $identifier)
            ->first(['role_permissions.scope_type']);

        return $row === null ? false : ($row->scope_type === null ? null : (string) $row->scope_type);
    }

    /**
     * 서비스를 직접 불러도 비회원에 범위를 주면 ValidationException 이고 아무것도 바뀌지 않는다.
     *
     * @scenario case=service_direct_call
     *
     * @effects guest_scope_rejected_in_service, nothing_saved_on_reject
     *
     * @return void 반환값 없음
     */
    public function test_role_service_rejects_scope_for_guest(): void
    {
        $permission = Permission::where('identifier', 'core.users.read')->firstOrFail();
        $before = $this->guest->permissions()->pluck('permissions.id')->sort()->values()->all();

        try {
            app(RoleService::class)->syncPermissions($this->guest, [['id' => $permission->id, 'scope_type' => 'self']]);
            $this->fail('ValidationException 이 나야 한다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('permissions.0.scope_type', $e->errors());
        }

        $this->assertSame($before, $this->guest->permissions()->pluck('permissions.id')->sort()->values()->all());
    }

    /**
     * 일반 역할은 서비스가 범위를 그대로 저장한다(무회귀).
     *
     * @scenario case=service_regular_role
     *
     * @effects regular_role_scope_saved
     *
     * @return void 반환값 없음
     */
    public function test_role_service_keeps_scope_for_regular_role(): void
    {
        $role = Role::where('identifier', 'manager')->firstOrFail();
        $permission = Permission::where('identifier', 'core.users.read')->firstOrFail();

        app(RoleService::class)->syncPermissions($role, [['id' => $permission->id, 'scope_type' => 'role']]);

        $scope = DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->value('scope_type');
        $this->assertSame(ScopeType::Role->value, $scope);
    }

    /**
     * 확장 선언이 비회원에 범위를 주면(명시·와일드카드) 범위 없이 부여하고 경고를 남긴다. 다른 역할의 범위는 그대로다.
     *
     * @scenario case=extension_sync
     *
     * @effects extension_guest_scope_nulled_with_warning, regular_role_scope_saved
     *
     * @return void 반환값 없음
     */
    public function test_extension_sync_nulls_guest_scope_with_warning(): void
    {
        Log::spy();

        app(ExtensionRoleSyncHelper::class)->syncAllRoleAssignments(
            [
                'core.users.read' => [['role' => 'guest', 'scope_type' => 'self']],
                'core.menus.read' => [['role' => '*', 'scope_type' => 'role']],
            ],
            ['core.users.read', 'core.menus.read'],
        );

        $this->assertNull($this->guestScopeOf('core.users.read'));
        $this->assertNull($this->guestScopeOf('core.menus.read'));
        $this->assertSame([], app(ScopedPermissionEnforcement::class)->findGuestScopedGrants());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, '비회원 역할') && ($context['permission'] ?? null) === 'core.users.read')
            ->once();
    }

    /**
     * DB 에 남은 비회원 범위 부여를 findGuestScopedGrants 가 드러낸다(코어 업데이트 경고의 원천).
     *
     * @scenario case=guest_scoped_grant_reported
     *
     * @effects guest_scoped_grant_reported
     *
     * @return void 반환값 없음
     */
    public function test_find_guest_scoped_grants_reports_leftover_rows(): void
    {
        $this->assertSame([], app(ScopedPermissionEnforcement::class)->findGuestScopedGrants());

        $permission = Permission::where('identifier', 'core.users.read')->firstOrFail();
        $this->guest->permissions()->syncWithoutDetaching([$permission->id => ['scope_type' => 'self']]);

        $this->assertSame(
            [['role' => 'guest', 'permission' => 'core.users.read', 'scope_type' => 'self']],
            app(ScopedPermissionEnforcement::class)->findGuestScopedGrants()
        );
    }
}
