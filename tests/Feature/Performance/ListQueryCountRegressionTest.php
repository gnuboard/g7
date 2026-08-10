<?php

namespace Tests\Feature\Performance;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * 목록·권한 조회의 쿼리 수 회귀 테스트
 *
 * 특정 시점의 쿼리 수를 숫자로 박아 두면 관계 하나만 추가해도 깨져 유지되지 않는다.
 * 대신 **행 수를 늘려도 쿼리 수가 늘지 않는다** 를 단언한다 — 그것이 N+1 의 정의이고,
 * 정상적인 구조 변경에는 반응하지 않는다.
 */
class ListQueryCountRegressionTest extends TestCase
{
    use CountsQueries;
    use RefreshDatabase;

    /**
     * 관리자 회원 목록: 회원 수가 늘어도 쿼리 수가 늘지 않는지 확인
     */
    public function test_admin_user_list_query_count_is_stable(): void
    {
        User::factory()->count(5)->create();

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => User::query()->with('roles')->paginate(50, ['id', 'uuid', 'name', 'email', 'status', 'created_at']),
            grow: fn () => User::factory()->count(10)->create(),
            context: '관리자 회원 목록',
        );
    }

    /**
     * 권한 판정: 판정 횟수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * 레이아웃 노드 필터링은 노드마다 권한을 묻는다. 판정마다 DB 를 보면
     * 노드 수에 비례해 쿼리가 늘어난다.
     */
    public function test_permission_checks_do_not_grow_with_check_count(): void
    {
        $user = $this->userWithPermissions(['a.read', 'b.read', 'c.read']);

        $tenChecks = $this->countQueries(function () use ($user) {
            for ($i = 0; $i < 10; $i++) {
                $user->hasPermission('a.read');
                $user->hasPermission('b.read');
                $user->hasPermission('missing.read');
            }
        });

        $freshUser = User::find($user->id);

        $hundredChecks = $this->countQueries(function () use ($freshUser) {
            for ($i = 0; $i < 100; $i++) {
                $freshUser->hasPermission('a.read');
                $freshUser->hasPermission('b.read');
                $freshUser->hasPermission('missing.read');
            }
        });

        $this->assertLessThanOrEqual(
            $tenChecks,
            $hundredChecks,
            "판정 횟수를 10배로 늘렸더니 쿼리가 {$tenChecks} → {$hundredChecks} 로 늘었다 — 권한 집합이 재사용되지 않는다"
        );
    }

    /**
     * 관리자 판정도 같은 권한 집합을 재사용하는지 확인
     */
    public function test_is_admin_reuses_permission_set(): void
    {
        $user = $this->userWithPermissions(['x.read']);

        $queries = $this->countQueries(function () use ($user) {
            $user->hasPermission('x.read');
            $user->isAdmin();
            $user->hasPermissions(['x.read', 'y.read'], requireAll: false);
            $user->getEffectiveScopeForPermission('x.read');
        });

        // 집합 적재는 역할 조회 + 권한 eager load 2회로 끝난다.
        // 판정 종류가 늘어도 이 2회를 넘지 않아야 한다.
        $this->assertLessThanOrEqual(
            2,
            $queries,
            "권한 판정 4종이 쿼리를 {$queries}회 실행했다 — 한 번 적재한 집합을 공유해야 한다"
        );
    }

    /**
     * 레이아웃 컴포넌트 트리 권한 필터링이 노드 수와 무관한지 확인
     */
    public function test_layout_permission_filter_does_not_grow_with_node_count(): void
    {
        $user = $this->userWithPermissions(['layout.read']);
        $service = app(LayoutService::class);

        $smallTree = $this->componentTree(5);
        $largeTree = $this->componentTree(50);

        $smallQueries = $this->countQueries(fn () => $this->filterTree($service, $smallTree, $user));

        $freshUser = User::find($user->id);
        $largeQueries = $this->countQueries(fn () => $this->filterTree($service, $largeTree, $freshUser));

        $this->assertLessThanOrEqual(
            $smallQueries,
            $largeQueries,
            "노드를 10배로 늘렸더니 쿼리가 {$smallQueries} → {$largeQueries} 로 늘었다 — 노드마다 권한을 다시 묻고 있다"
        );
    }

    /**
     * 통합검색: 매칭 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * 통합검색은 각 모듈 리스너로 팬아웃하는 구조라, 어느 한 리스너가 결과 항목마다
     * 조회를 내면 그 자리에서 쿼리가 매칭 수에 비례한다. 응답을 통째로 재야 드러난다.
     *
     * 활성 검색 모듈이 하나도 없는 환경에서도 이 단언은 유효하다 — 그 경우 코어의
     * 응답 조립만 재게 되며, 리스너가 붙는 순간부터 그 리스너가 판정 대상이 된다.
     */
    public function test_unified_search_query_count_is_stable(): void
    {
        $this->seedSearchableUsers(5, 'search-a');

        $this->assertQueryCountStableAsDataGrows(
            measure: function () {
                $response = $this->getJson('/api/search?q=g7search');
                $response->assertOk();
            },
            grow: fn () => $this->seedSearchableUsers(10, 'search-b'),
            context: '통합검색',
        );
    }

    /**
     * 검색 대상이 될 만한 데이터를 만듭니다.
     *
     * 어떤 모듈이 설치돼 있든 모수가 늘어나도록 코어 테이블(users)을 늘린다.
     *
     * @param  int  $count  생성할 수
     * @param  string  $prefix  이름 접두
     */
    private function seedSearchableUsers(int $count, string $prefix): void
    {
        User::factory()->count($count)->create([
            'name' => $prefix.' g7search',
        ]);
    }

    /**
     * 권한을 가진 사용자를 만듭니다.
     *
     * @param  array<int, string>  $identifiers  권한 식별자 목록
     * @return User 생성된 사용자
     */
    private function userWithPermissions(array $identifiers): User
    {
        $role = Role::factory()->create();

        foreach ($identifiers as $identifier) {
            $permission = Permission::factory()->create([
                'identifier' => $identifier,
                'type' => 'user',
            ]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([$role->id]);

        return User::find($user->id);
    }

    /**
     * 권한 조건이 붙은 컴포넌트 트리를 만듭니다.
     *
     * @param  int  $nodeCount  노드 수
     * @return array<int, array<string, mixed>> 컴포넌트 배열
     */
    private function componentTree(int $nodeCount): array
    {
        $components = [];

        for ($i = 0; $i < $nodeCount; $i++) {
            $components[] = [
                'type' => 'basic',
                'name' => 'Div',
                'permissions' => ['layout.read'],
                'children' => [
                    ['type' => 'basic', 'name' => 'Span', 'permissions' => ['layout.read']],
                ],
            ];
        }

        return $components;
    }

    /**
     * 컴포넌트 트리에 권한 필터를 적용합니다.
     *
     * @param  LayoutService  $service  레이아웃 서비스
     * @param  array<int, array<string, mixed>>  $components  컴포넌트 배열
     * @param  User  $user  판정 대상 사용자
     * @return array<string, mixed> 필터링 결과
     */
    private function filterTree(LayoutService $service, array $components, User $user): array
    {
        $reflection = new \ReflectionMethod($service, 'filterComponentTree');
        $reflection->setAccessible(true);

        return ['components' => $reflection->invoke($service, $components, $user)];
    }
}
