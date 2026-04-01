<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Enums\PermissionType;
use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * Page 모듈 권한 스코프 테스트
 *
 * 미들웨어 scope_type 기반 상세 접근 체크 및 applyPermissionScope 목록 필터링을 검증합니다.
 */
class PermissionScopeTest extends ModuleTestCase
{
    private Permission $permission;

    private Role $testRole;

    private User $scopeUser;

    private User $otherUser;

    private User $sameRoleUser;

    /**
     * DatabaseTransactions 비활성화
     *
     * Page 모듈의 ModuleTestCase가 DatabaseTransactions를 사용하지만,
     * 테스트 데이터가 트랜잭션 롤백으로 정리되지 않는 경우가 있으므로
     * 수동 정리 방식을 사용합니다.
     */
    public function beginDatabaseTransaction(): void
    {
        // DatabaseTransactions 비활성화 — 수동 정리
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearPermissionCache();

        // 이전 실행 잔여 데이터 정리
        Permission::where('identifier', 'like', 'test.page.%')->forceDelete();
        Permission::where('identifier', 'like', 'test.pages.%')->forceDelete();
        Role::where('identifier', 'test_page_scope_role')->forceDelete();

        // 테스트용 권한 생성
        $this->permission = Permission::create([
            'identifier' => 'test.page.scope',
            'name' => ['ko' => '페이지 스코프 테스트', 'en' => 'Page Scope Test'],
            'type' => PermissionType::Admin,
            'resource_route_key' => 'page',
            'owner_key' => 'created_by',
        ]);

        // 사용자 생성
        $this->scopeUser = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->sameRoleUser = User::factory()->create();

        // 역할 생성 및 할당
        $this->testRole = Role::create([
            'identifier' => 'test_page_scope_role',
            'name' => ['ko' => '페이지 스코프 역할', 'en' => 'Page Scope Role'],
            'is_active' => true,
        ]);
        $this->testRole->permissions()->attach($this->permission->id, ['scope_type' => ScopeType::Self]);
        $this->scopeUser->roles()->attach($this->testRole->id);
        $this->sameRoleUser->roles()->attach($this->testRole->id);
    }

    protected function tearDown(): void
    {
        $this->clearPermissionCache();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // 테스트 사용자 관련 정리
        $userIds = array_filter([
            $this->scopeUser->id ?? null,
            $this->otherUser->id ?? null,
            $this->sameRoleUser->id ?? null,
        ]);

        if ($userIds) {
            // 테스트 사용자가 만든 페이지 정리
            Page::whereIn('created_by', $userIds)->forceDelete();

            DB::table('user_roles')->whereIn('user_id', $userIds)->delete();
            User::whereIn('id', $userIds)->forceDelete();
        }

        // 역할/권한 정리
        if (isset($this->testRole)) {
            DB::table('role_permissions')->where('role_id', $this->testRole->id)->delete();
            $this->testRole->forceDelete();
        }

        Permission::where('identifier', 'like', 'test.page.%')->forceDelete();
        Permission::where('identifier', 'like', 'test.pages.%')->forceDelete();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        parent::tearDown();
    }

    // ========================================================================
    // 미들웨어 scope 체크 — Page (owner_key='created_by')
    // ========================================================================

    /**
     * Page — scope=self, 자기가 만든 페이지 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_page(): void
    {
        $this->setupScopePermission('page', 'created_by', ScopeType::Self);
        $page = Page::forceCreate([
            'slug' => 'page-'.uniqid(),
            'title' => ['ko' => '페이지', 'en' => 'Page'],
            'created_by' => $this->scopeUser->id,
        ]);
        $path = $this->registerScopeRoute('page', 'self-own-page', Page::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$page->id}");

        $response->assertStatus(200);
    }

    /**
     * Page — scope=self, 타인이 만든 페이지 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_page(): void
    {
        $this->setupScopePermission('page', 'created_by', ScopeType::Self);
        $page = Page::forceCreate([
            'slug' => 'page-'.uniqid(),
            'title' => ['ko' => '페이지', 'en' => 'Page'],
            'created_by' => $this->otherUser->id,
        ]);
        $path = $this->registerScopeRoute('page', 'self-deny-page', Page::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$page->id}");

        $response->assertStatus(403);
    }

    /**
     * Page — scope=role, 동일 역할 사용자가 만든 페이지 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_role_allows_access_to_same_role_page(): void
    {
        $this->setupScopePermission('page', 'created_by', ScopeType::Role);
        $page = Page::forceCreate([
            'slug' => 'page-'.uniqid(),
            'title' => ['ko' => '페이지', 'en' => 'Page'],
            'created_by' => $this->sameRoleUser->id,
        ]);
        $path = $this->registerScopeRoute('page', 'role-page', Page::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$page->id}");

        $response->assertStatus(200);
    }

    // ========================================================================
    // applyPermissionScope 목록 필터링 — Page
    // ========================================================================

    /**
     * Page — scope=null -> 전체 페이지 조회
     */
    public function test_apply_scope_null_returns_all_pages(): void
    {
        $this->createPermissionWithScope('test.pages.read', 'page', 'created_by', null);

        $initialCount = Page::count();

        Page::forceCreate(['slug' => 'p-'.uniqid(), 'title' => ['ko' => '페이지1', 'en' => 'P1'], 'created_by' => $this->scopeUser->id]);
        Page::forceCreate(['slug' => 'p-'.uniqid(), 'title' => ['ko' => '페이지2', 'en' => 'P2'], 'created_by' => $this->otherUser->id]);

        $query = Page::query();
        PermissionHelper::applyPermissionScope($query, 'test.pages.read', $this->scopeUser);

        $this->assertSame($initialCount + 2, $query->count());
    }

    /**
     * Page — scope=self -> 자기가 만든 페이지만 조회
     */
    public function test_apply_scope_self_filters_own_pages(): void
    {
        $this->createPermissionWithScope('test.pages.read', 'page', 'created_by', ScopeType::Self);

        Page::forceCreate(['slug' => 'p-'.uniqid(), 'title' => ['ko' => '페이지1', 'en' => 'P1'], 'created_by' => $this->scopeUser->id]);
        Page::forceCreate(['slug' => 'p-'.uniqid(), 'title' => ['ko' => '페이지2', 'en' => 'P2'], 'created_by' => $this->otherUser->id]);

        $query = Page::query();
        PermissionHelper::applyPermissionScope($query, 'test.pages.read', $this->scopeUser);

        $results = $query->get();
        $this->assertTrue($results->count() >= 1);
        $results->each(fn ($page) => $this->assertEquals($this->scopeUser->id, $page->created_by));
    }

    // ========================================================================
    // 헬퍼 메서드
    // ========================================================================

    /**
     * 테스트용 권한에 scope 설정 헬퍼
     *
     * @param  string  $routeKey  resource_route_key 값
     * @param  string  $ownerKey  owner_key 값
     * @param  ScopeType|null  $scopeType  scope_type 값
     * @return void
     */
    private function setupScopePermission(string $routeKey, string $ownerKey, ?ScopeType $scopeType): void
    {
        $this->permission->update([
            'resource_route_key' => $routeKey,
            'owner_key' => $ownerKey,
        ]);

        $this->testRole->permissions()->syncWithoutDetaching([
            $this->permission->id => ['scope_type' => $scopeType],
        ]);

        $this->clearPermissionCache();
    }

    /**
     * 별도 권한 생성 및 역할에 할당하는 헬퍼
     *
     * @param  string  $identifier  권한 식별자
     * @param  string|null  $routeKey  resource_route_key
     * @param  string|null  $ownerKey  owner_key
     * @param  ScopeType|null  $scopeType  scope_type
     * @return Permission 생성된 권한
     */
    private function createPermissionWithScope(string $identifier, ?string $routeKey, ?string $ownerKey, ?ScopeType $scopeType): Permission
    {
        $perm = Permission::create([
            'identifier' => $identifier,
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'type' => PermissionType::Admin,
            'resource_route_key' => $routeKey,
            'owner_key' => $ownerKey,
        ]);

        $this->testRole->permissions()->attach($perm->id, ['scope_type' => $scopeType]);
        $this->clearPermissionCache();

        return $perm;
    }

    /**
     * 모델 바인딩 포함 테스트 라우트 등록 헬퍼
     *
     * @param  string  $routeKey  라우트 파라미터명
     * @param  string  $suffix  라우트 경로 구분용 접미사
     * @param  string  $modelClass  바인딩할 모델 FQCN
     * @return string 등록된 라우트 경로 (파라미터 제외)
     */
    private function registerScopeRoute(string $routeKey, string $suffix, string $modelClass): string
    {
        Route::bind($routeKey, fn ($value) => $modelClass::findOrFail($value));

        $path = "/api/test-page-scope-{$suffix}";
        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.page.scope'])
            ->get("{$path}/{{$routeKey}}", fn (Model $model) => response()->json(['message' => 'OK']));

        return $path;
    }

    /**
     * PermissionHelper static 캐시 초기화
     *
     * @return void
     */
    private function clearPermissionCache(): void
    {
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
