<?php

namespace Tests\Feature\Validate;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 역할 수신자 조회의 eager load 상한 (#599 validate 코어 minor — RoleRepository eager load)
 *
 * `getUsersByIdentifiers()` 는 알림 수신자의 권한 판정을 위해 `users.roles.permissions` 를 싣는다.
 * 사용자 수는 줄일 수 없지만(전원이 수신자다), 권한 행은 역할마다 수십~수백 건이고 다국어 JSON
 * 을 싣고 있어 전 컬럼을 읽으면 해석 한 번에 그 JSON 이 통째로 올라온다. 판정에 필요한
 * 컬럼(identifier·type·피벗 scope_type)만 싣는다.
 */
class RoleRecipientPermissionColumnsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 권한 하나를 가진 역할과 그 역할의 사용자들을 만듭니다.
     *
     * @param  int  $userCount  사용자 수
     * @return array{0: Role, 1: Permission} [역할, 권한]
     */
    private function seedRoleWithPermission(int $userCount): array
    {
        $role = Role::factory()->create(['identifier' => 'validate_p4_role']);
        $permission = Permission::factory()->create(['identifier' => 'validate_p4.read']);
        $role->permissions()->attach($permission->id, ['granted_at' => now(), 'scope_type' => null]);

        $users = User::factory()->count($userCount)->create();
        $role->users()->attach($users->pluck('id')->all());

        return [$role, $permission];
    }

    /**
     * 권한 행은 판정에 쓰는 컬럼만 싣는다.
     *
     * 수정 전 실패 이유: `with('users.roles.permissions')` 가 권한 행의 전 컬럼(name·description
     * 다국어 JSON 등)을 읽었다.
     *
     * @scenario case=role_eager_load
     *
     * @effects role_recipient_permissions_load_only_judgement_columns
     *
     * @return void 반환값 없음
     */
    public function test_role_recipient_permissions_load_only_judgement_columns(): void
    {
        $this->seedRoleWithPermission(2);

        $users = $this->app->make(RoleRepositoryInterface::class)->getUsersByIdentifiers(['validate_p4_role']);

        $this->assertCount(2, $users);

        $permission = $users->first()->roles
            ->firstWhere('identifier', 'validate_p4_role')
            ->permissions
            ->first();

        $attributes = array_keys($permission->getAttributes());
        sort($attributes);

        $this->assertSame(['id', 'identifier', 'type'], $attributes, '권한 행에 판정에 쓰지 않는 컬럼이 실렸습니다.');
        $this->assertTrue($permission->relationLoaded('pivot'), '피벗(scope_type)이 함께 실려야 합니다.');
    }

    /**
     * 좁힌 뒤에도 권한 판정이 추가 쿼리 없이 맞게 나온다.
     *
     * 컬럼을 과하게 좁히면(피벗·type 누락) 판정이 틀리거나, 판정이 관계를 다시 조회해 쿼리가
     * 수신자 수에 비례해 는다.
     *
     * @scenario case=role_eager_load
     *
     * @effects role_recipient_permission_judgement_needs_no_extra_query
     *
     * @return void 반환값 없음
     */
    public function test_role_recipient_permission_judgement_needs_no_extra_query(): void
    {
        $this->seedRoleWithPermission(3);

        $users = $this->app->make(RoleRepositoryInterface::class)->getUsersByIdentifiers(['validate_p4_role']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($users as $user) {
            $this->assertTrue($user->hasPermission('validate_p4.read'));
            $this->assertFalse($user->hasPermission('validate_p4.absent'));
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries, '권한 판정이 수신자마다 쿼리를 새로 냈습니다.');
    }
}
