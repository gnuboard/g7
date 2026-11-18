<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * 사용자 경로의 본인 토큰 갱신·조회·로그아웃(`/api/user/auth/*`)이 실제로 시드된 회원 역할로 동작하는지 본다 —.
 *
 * 그 라우트는 역할 권한 `core.auth.refresh`·`core.auth.user`·`core.auth.logout` 을 요구했지만 코어가 그 권한을 선언한 적이 없어
 * 어떤 회원도 가질 수 없었다(갱신이 늘 403). 시험은 권한을 `firstOrCreate` 로 직접 만들어 부여해 통과했다 — 그래서 여기서는
 * 권한을 만들지 않고 시드된 `user` 역할만으로 판정하고, 라우트가 요구하는 코어 권한이 모두 선언돼 있는지도 함께 잠근다.
 *
 * @scenario-manifest tests/scenarios/user-auth-token-route-access.yaml
 */
class UserAuthTokenRouteAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 시드된 역할·권한(RolePermissionSeeder)만으로 판정하도록 시드를 깐다 — 시험이 권한을 직접 만들지 않는다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * 라우트의 `permission:` 미들웨어가 요구하는 코어 권한은 모두 `config/core.php` 권한 목록에 선언돼 있다.
     * 선언되지 않은 권한은 시드되지 않아 어떤 역할에도 줄 수 없다 — 그 라우트는 모두에게 막힌다.
     *
     * @scenario case=route_contract
     *
     * @effects route_required_core_permissions_are_declared
     *
     * @return void 반환값 없음
     */
    public function test_every_core_permission_required_by_routes_is_declared(): void
    {
        $declared = [];
        $permissions = config('core.permissions');
        array_walk_recursive($permissions, function ($value, $key) use (&$declared) {
            if ($key === 'identifier') {
                $declared[$value] = true;
            }
        });

        $missing = [];
        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }
                $groups = explode(',', substr($middleware, strlen('permission:')));
                array_shift($groups); // 권한 유형(user, admin)
                foreach ($groups as $group) {
                    foreach (explode('|', $group) as $identifier) {
                        // 확장 권한과 요청 값으로 바꾸는 자리표시({type})는 여기서 보지 않는다
                        if (! str_starts_with($identifier, 'core.') || str_contains($identifier, '{')) {
                            continue;
                        }
                        if (! isset($declared[$identifier])) {
                            $missing[] = $identifier.' <- '.($route->getName() ?? $route->uri());
                        }
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), '선언되지 않은 코어 권한을 요구하는 라우트');
    }

    /**
     * 시드된 `user` 역할만 가진 회원이 본인 토큰을 갱신·조회하고 로그아웃할 수 있다(권한을 따로 만들지 않는다).
     *
     * @scenario case=seeded_member
     *
     * @effects member_refreshes_token_with_seeded_role, member_reads_self_with_seeded_role, member_logs_out_with_seeded_role
     *
     * @return void 반환값 없음
     */
    public function test_member_with_seeded_user_role_uses_own_token_routes(): void
    {
        $member = $this->seededMember();

        $refreshed = $this->withToken($member->createToken('t1')->plainTextToken)->postJson('/api/user/auth/refresh');
        $refreshed->assertStatus(200);
        $this->assertNotEmpty($refreshed->json('data.token'));

        $this->app['auth']->forgetGuards();
        $this->withToken($member->createToken('t2')->plainTextToken)->getJson('/api/user/auth/user')
            ->assertStatus(200)
            ->assertJsonPath('data.uuid', (string) $member->uuid);

        $this->app['auth']->forgetGuards();
        $this->withToken($member->createToken('t3')->plainTextToken)->postJson('/api/user/auth/logout')
            ->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $this->withToken($member->createToken('t4')->plainTextToken)->postJson('/api/user/auth/logout-all-devices')
            ->assertStatus(200);
    }

    /**
     * 로그인하지 않은 요청은 401 이다(본인 토큰 행위라 손님에게 열지 않는다).
     *
     * @scenario case=guest
     *
     * @effects guest_gets_401_on_own_token_routes
     *
     * @return void 반환값 없음
     */
    public function test_guest_gets_401_on_own_token_routes(): void
    {
        $this->postJson('/api/user/auth/refresh')->assertStatus(401);
        $this->getJson('/api/user/auth/user')->assertStatus(401);
        $this->postJson('/api/user/auth/logout')->assertStatus(401);
        $this->postJson('/api/user/auth/logout-all-devices')->assertStatus(401);
    }

    /**
     * 시드된 `user` 역할만 가진 활성 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function seededMember(): User
    {
        $role = Role::where('identifier', 'user')->firstOrFail();
        $member = User::factory()->create();
        $member->roles()->attach($role->id, ['assigned_at' => now()]);

        return $member->fresh();
    }
}
