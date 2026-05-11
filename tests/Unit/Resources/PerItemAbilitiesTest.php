<?php

namespace Tests\Unit\Resources;

use App\Enums\ScopeType;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Per-item abilities 테스트
 *
 * 목록 API 응답에서 각 행의 abilities가 스코프(self/role/null) 기반으로
 * 올바르게 결정되는지 검증합니다.
 */
class PerItemAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    private Role $managerRole;

    private Role $userRole;

    private Permission $updatePermission;

    private Permission $deletePermission;

    private User $managerUser;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // 매니저 역할 생성
        $this->managerRole = Role::create([
            'identifier' => 'test-manager',
            'name' => ['ko' => '테스트 매니저', 'en' => 'Test Manager'],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        $this->userRole = Role::create([
            'identifier' => 'test-user',
            'name' => ['ko' => '테스트 사용자', 'en' => 'Test User'],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        // 권한 조회 또는 생성 (시더에서 이미 생성된 경우 조회)
        $this->updatePermission = Permission::firstOrCreate(
            ['identifier' => 'core.users.update'],
            [
                'name' => ['ko' => '사용자 수정', 'en' => 'Update Users'],
                'type' => 'admin',
                'resource_route_key' => 'user',
                'owner_key' => 'id',
            ]
        );

        $this->deletePermission = Permission::firstOrCreate(
            ['identifier' => 'core.users.delete'],
            [
                'name' => ['ko' => '사용자 삭제', 'en' => 'Delete Users'],
                'type' => 'admin',
                'resource_route_key' => 'user',
                'owner_key' => 'id',
            ]
        );

        // 매니저에게 self 스코프로 update 권한 부여
        $this->managerRole->permissions()->attach([
            $this->updatePermission->id => ['scope_type' => ScopeType::Self],
            $this->deletePermission->id => ['scope_type' => ScopeType::Self],
        ]);

        // 사용자 생성
        $this->managerUser = User::factory()->create(['email' => 'manager-peritem@example.com']);
        $this->managerUser->roles()->attach($this->managerRole->id);

        $this->otherUser = User::factory()->create(['email' => 'other-peritem@example.com']);
        $this->otherUser->roles()->attach($this->userRole->id);
    }

    /**
     * self 스코프에서 본인 행은 abilities가 true를 반환한다.
     *
     * UserResource 는 슈퍼관리자 계정만 can_delete 를 강제 false 로 설정한다.
     * 일반 관리자 계정의 삭제 가능 여부는 G7 역할/퍼미션/스코프 시스템이 평가하므로,
     * managerUser(is_super=false, self 스코프 보유) 는 본인 행에 대해 can_delete=true.
     */
    public function test_self_scope_allows_own_row(): void
    {
        $request = Request::create('/api/admin/users');
        $request->setUserResolver(fn () => $this->managerUser);

        $resource = new UserResource($this->managerUser);
        $listArray = $resource->toListArray($request);

        $this->assertArrayHasKey('abilities', $listArray);
        $this->assertTrue($listArray['abilities']['can_update']);
        $this->assertTrue($listArray['abilities']['can_delete']);
        $this->assertTrue($listArray['is_owner']);
    }

    /**
     * self 스코프에서 타인 행은 abilities가 false를 반환한다.
     */
    public function test_self_scope_denies_other_row(): void
    {
        $request = Request::create('/api/admin/users');
        $request->setUserResolver(fn () => $this->managerUser);

        $resource = new UserResource($this->otherUser);
        $listArray = $resource->toListArray($request);

        $this->assertArrayHasKey('abilities', $listArray);
        $this->assertFalse($listArray['abilities']['can_update']);
        $this->assertFalse($listArray['abilities']['can_delete']);
        $this->assertFalse($listArray['is_owner']);
    }

    /**
     * null 스코프(전체)에서는 타인 행도 abilities가 true를 반환한다.
     */
    public function test_null_scope_allows_all_rows(): void
    {
        // 매니저의 스코프를 null(전체)로 변경
        $this->managerRole->permissions()->sync([
            $this->updatePermission->id => ['scope_type' => null],
            $this->deletePermission->id => ['scope_type' => null],
        ]);

        // 캐시 초기화를 위해 사용자 새로 로드
        $managerUser = User::find($this->managerUser->id);

        $request = Request::create('/api/admin/users');
        $request->setUserResolver(fn () => $managerUser);

        $resource = new UserResource($this->otherUser);
        $listArray = $resource->toListArray($request);

        $this->assertArrayHasKey('abilities', $listArray);
        $this->assertTrue($listArray['abilities']['can_update']);
        $this->assertTrue($listArray['abilities']['can_delete']);
    }

    /**
     * toListArray 반환값에 is_owner 필드가 포함된다.
     */
    public function test_to_list_array_includes_is_owner(): void
    {
        $request = Request::create('/api/admin/users');
        $request->setUserResolver(fn () => $this->managerUser);

        $resource = new UserResource($this->managerUser);
        $listArray = $resource->toListArray($request);

        $this->assertArrayHasKey('is_owner', $listArray);
    }

    /**
     * toListArray 반환값에 abilities 필드가 포함된다.
     */
    public function test_to_list_array_includes_abilities(): void
    {
        $request = Request::create('/api/admin/users');
        $request->setUserResolver(fn () => $this->managerUser);

        $resource = new UserResource($this->managerUser);
        $listArray = $resource->toListArray($request);

        $this->assertArrayHasKey('abilities', $listArray);
        $this->assertArrayHasKey('can_update', $listArray['abilities']);
        $this->assertArrayHasKey('can_delete', $listArray['abilities']);
    }

    /**
     * getEffectiveScopeForPermission 캐싱이 동작한다.
     */
    public function test_effective_scope_caching(): void
    {
        $user = User::find($this->managerUser->id);

        // 첫 호출 (DB 쿼리 실행)
        $scope1 = $user->getEffectiveScopeForPermission('core.users.update');
        $this->assertEquals('self', $scope1);

        // 두 번째 호출 (캐시에서 반환 — DB 쿼리 없음)
        $scope2 = $user->getEffectiveScopeForPermission('core.users.update');
        $this->assertEquals('self', $scope2);

        // 캐시된 값이 동일한지 확인
        $this->assertSame($scope1, $scope2);
    }

    /**
     * 권한이 없는 사용자는 abilities가 모두 false다.
     */
    public function test_user_without_permission_gets_all_false(): void
    {
        $request = Request::create('/api/admin/users');
        $request->setUserResolver(fn () => $this->otherUser);

        $resource = new UserResource($this->otherUser);
        $listArray = $resource->toListArray($request);

        $this->assertArrayHasKey('abilities', $listArray);
        $this->assertFalse($listArray['abilities']['can_update']);
        $this->assertFalse($listArray['abilities']['can_delete']);
    }

    /**
     * ResourceCollection 이중 래핑 시에도 per-item abilities가 올바르게 동작한다.
     *
     * ResourceCollection은 아이템을 자동으로 UserResource로 래핑하고,
     * withStatistics 콜백에서 new UserResource($user)로 다시 래핑하면
     * $this->resource가 Model이 아닌 UserResource가 됨.
     * resolveAbilitiesFromMap이 중첩된 JsonResource를 풀어서 Model을 찾아야 한다.
     */
    public function test_collection_double_wrap_resolves_scope_correctly(): void
    {
        request()->setUserResolver(fn () => $this->managerUser);

        $users = User::with('roles')
            ->whereIn('id', [$this->managerUser->id, $this->otherUser->id])
            ->orderByDesc('id')
            ->paginate(15);

        $collection = new UserCollection($users);
        $result = $collection->withStatistics([]);

        // UserResource 의 공개 식별자는 uuid (id 는 toArray 에 포함되지 않음)
        $dataByUuid = collect($result['data'])->keyBy('uuid');

        // 본인(managerUser) — can_update: true
        $ownRow = $dataByUuid[$this->managerUser->uuid];
        $this->assertTrue($ownRow['is_owner']);
        $this->assertTrue($ownRow['abilities']['can_update']);

        // 타인(otherUser) — can_update: false (self 스코프)
        $otherRow = $dataByUuid[$this->otherUser->uuid];
        $this->assertFalse($otherRow['is_owner']);
        $this->assertFalse($otherRow['abilities']['can_update']);
    }

    /**
     * UserResource를 이중으로 래핑해도 abilities가 올바르게 반환된다.
     */
    public function test_double_wrapped_resource_returns_correct_abilities(): void
    {
        $request = Request::create('/api/admin/users');
        $request->setUserResolver(fn () => $this->managerUser);

        // 이중 래핑: UserResource(UserResource(User))
        $innerResource = new UserResource($this->otherUser);
        $outerResource = new UserResource($innerResource);
        $listArray = $outerResource->toListArray($request);

        $this->assertArrayHasKey('abilities', $listArray);
        // self 스코프 + 타인 → false
        $this->assertFalse($listArray['abilities']['can_update']);
        $this->assertFalse($listArray['abilities']['can_delete']);
    }
}
