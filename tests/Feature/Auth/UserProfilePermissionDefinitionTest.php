<?php

namespace Tests\Feature\Auth;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CoreUpdateService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 사용자 프로필 API 권한(`core.profile.read`·`core.profile.update`) 정의 회귀 테스트.
 *
 * `GET|PUT /api/user/profile`·`GET /api/user/profile/activity-log` 라우트는 이 두 권한을
 * 요구하지만 코어 권한 정의(config/core.php)에 없어 일반 회원·관리자 모두 403 이었다
 * (7.1.0 이전부터, 실측 A O-2). 정의는 config 가 SSoT 이고 설치(시더)·업데이트
 * (`CoreUpdateService::syncCoreRolesAndPermissions`) 모두 같은 정의를 읽는다.
 */
class UserProfilePermissionDefinitionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> 프로필 권한 식별자 */
    private const PROFILE_PERMISSIONS = ['core.profile.read', 'core.profile.update'];

    /**
     * 코어 역할·권한 정의를 시딩한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * 기본 역할 정책: 일반 사용자 역할이 받고, 관리자 역할은 모든 리프 권한으로 받는다.
     *
     * @scenario case=definition
     *
     * @effects profile_permissions_defined_as_user_type, profile_permissions_granted_to_default_roles
     *
     * @return void 반환값 없음
     */
    public function test_profile_permissions_are_defined_and_granted_to_default_roles(): void
    {
        foreach (self::PROFILE_PERMISSIONS as $identifier) {
            $permission = Permission::where('identifier', $identifier)
                ->where('extension_type', ExtensionOwnerType::Core)
                ->first();

            $this->assertNotNull($permission, "{$identifier} 권한이 코어 정의로 시딩되어야 합니다.");
            $this->assertSame(PermissionType::User, $permission->type, "{$identifier} 는 사용자 화면 권한이다.");
            $this->assertSame('core.profile', $permission->parent?->identifier, '카테고리 행 아래에 놓인다.');
        }

        foreach (['user', 'admin'] as $roleIdentifier) {
            $role = Role::where('identifier', $roleIdentifier)->firstOrFail();
            $this->assertSame(
                2,
                $role->permissions()->whereIn('identifier', self::PROFILE_PERMISSIONS)->count(),
                "{$roleIdentifier} 역할이 프로필 권한 두 개를 가져야 합니다."
            );
        }
    }

    /**
     * 일반 사용자 역할만 가진 회원이 프로필 조회·수정·활동 기록 조회에 성공한다.
     *
     * @scenario case=default_role_api
     *
     * @effects member_profile_api_allowed
     *
     * @return void 반환값 없음
     */
    public function test_member_with_default_user_role_can_use_profile_api(): void
    {
        $member = $this->memberWithRole('user');
        $token = $member->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/user/profile')->assertOk()
            ->assertJsonPath('data.uuid', $member->uuid);
        $this->withToken($token)->getJson('/api/user/profile/activity-log')->assertOk();
        $this->withToken($token)->putJson('/api/user/profile', ['name' => '프로필 이름 변경', 'email' => $member->email])->assertOk();

        $this->assertSame('프로필 이름 변경', $member->fresh()->name);
    }

    /**
     * 프로필 권한이 없는 역할만 가진 회원은 같은 API 가 403 이다(권한 게이트는 유지).
     *
     * @scenario case=bare_role_api
     *
     * @effects member_without_profile_permission_forbidden
     *
     * @return void 반환값 없음
     */
    public function test_role_without_profile_permissions_is_forbidden(): void
    {
        $bare = Role::create([
            'identifier' => 'qa_profile_bare_role',
            'name' => ['ko' => '권한 없는 역할', 'en' => 'Bare role'],
            'is_active' => true,
        ]);

        $member = User::factory()->create();
        $member->roles()->attach($bare->id, ['assigned_at' => now()]);
        $token = $member->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/user/profile')->assertForbidden();
        $this->withToken($token)->getJson('/api/user/profile/activity-log')->assertForbidden();
        $this->withToken($token)->putJson('/api/user/profile', ['name' => '바뀌면 안 됨', 'email' => $member->email])->assertForbidden();

        $this->assertNotSame('바뀌면 안 됨', $member->fresh()->name);
    }

    /**
     * 이미 설치된 사이트(권한 행이 없는 상태)는 코어 업데이트의 역할·권한 동기화가 채운다.
     *
     * @scenario case=core_update_sync
     *
     * @effects existing_install_backfilled_by_core_update_sync
     *
     * @return void 반환값 없음
     */
    public function test_core_update_sync_backfills_existing_user_role(): void
    {
        // 7.1.0 이전 설치본 재현 — 권한·카테고리 행이 없고 역할에도 연결이 없다.
        $ids = Permission::whereIn('identifier', [...self::PROFILE_PERMISSIONS, 'core.profile'])->pluck('id');
        foreach (Role::all() as $role) {
            $role->permissions()->detach($ids);
        }
        Permission::whereIn('id', $ids)->where('identifier', '!=', 'core.profile')->delete();
        Permission::where('identifier', 'core.profile')->delete();

        $userRole = Role::where('identifier', 'user')->firstOrFail();
        $this->assertSame(0, $userRole->permissions()->whereIn('identifier', self::PROFILE_PERMISSIONS)->count());

        app(CoreUpdateService::class)->syncCoreRolesAndPermissions();

        $this->assertSame(2, $userRole->fresh()->permissions()->whereIn('identifier', self::PROFILE_PERMISSIONS)->count());

        $member = $this->memberWithRole('user');
        $this->withToken($member->createToken('test')->plainTextToken)
            ->getJson('/api/user/profile')
            ->assertOk();
    }

    /**
     * 지정한 역할만 가진 활성 회원을 만든다.
     *
     * @param  string  $roleIdentifier  역할 식별자
     * @return User 회원
     */
    private function memberWithRole(string $roleIdentifier): User
    {
        $member = User::factory()->create();
        $role = Role::where('identifier', $roleIdentifier)->firstOrFail();
        $member->roles()->attach($role->id, ['assigned_at' => now()]);

        return $member->fresh();
    }
}
