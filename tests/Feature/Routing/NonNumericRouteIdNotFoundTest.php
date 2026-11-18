<?php

namespace Tests\Feature\Routing;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * 코어 라우트의 번호 자리에 숫자가 아닌 값이 오면 500(TypeError) 대신 404 로 끝나는지 검증합니다.
 *
 * 컨트롤러가 번호 인자를 `int` 로 받는데 라우트에 숫자 제약(`whereNumber`)이 없으면 숫자 아닌 주소가 권한 판정을 지나
 * 컨트롤러 호출에서 TypeError 로 500 이 되고 오류 로그가 쌓인다. 라우트 제약이 표준(같은 파일 선례 다수)이다 —
 * 제약에 맞지 않는 주소는 라우트가 없는 것과 같아 404 다. 모듈 쪽 같은 검사는 각 모듈의 NonNumericRouteIdNotFoundTest.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/core-non-numeric-route-id.yaml 참조.
 */
class NonNumericRouteIdNotFoundTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 코어 컨트롤러(`App\`)를 부르는 모든 라우트에서, int 로 받는 번호 자리에 숫자 제약이 있다.
     *
     * 새 라우트가 제약 없이 int 인자를 받으면 이 검사가 그 라우트를 이름으로 알린다.
     *
     * @scenario case=route_contract
     *
     * @effects int_route_params_have_numeric_constraint
     *
     * @return void 반환값 없음
     */
    public function test_every_core_int_route_param_has_numeric_constraint(): void
    {
        $offenders = self::unconstrainedIntParams('App\\');

        $this->assertSame([], $offenders, "숫자 제약 없이 int 로 받는 번호 자리:\n".implode("\n", $offenders));
    }

    /**
     * 스케줄 이력 삭제·본인인증 정책 삭제가 숫자 아닌 번호에 404 로 답한다(권한이 있어도).
     *
     * @scenario case=non_numeric_id_404
     *
     * @effects non_numeric_id_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_non_numeric_ids_return_404_not_500(): void
    {
        $admin = $this->createAdminWith(['core.schedules.delete', 'core.admin.identity.policies.update']);

        $this->actingAs($admin)->deleteJson('/api/admin/schedules/history/abc')->assertNotFound();
        $this->actingAs($admin)->deleteJson('/api/admin/identity/policies/abc')->assertNotFound();
        $this->actingAs($admin)->postJson('/api/admin/identity/policies/abc/reset-field', ['field' => 'x'])->assertNotFound();
    }

    /**
     * 주어진 관리자 권한을 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $identifiers  권한 식별자
     * @return User 관리자
     */
    private function createAdminWith(array $identifiers): User
    {
        $user = User::factory()->create();
        $permissionIds = [];
        foreach ($identifiers as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => 'core',
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }
        $role = Role::create([
            'identifier' => 'non_numeric_route_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );
        $user->roles()->attach([$role->id, $adminRole->id]);

        return $user->fresh();
    }

    /**
     * 컨트롤러 클래스 이름이 주어진 접두로 시작하는 라우트 중, int 로 받는 라우트 인자에 숫자 제약이 없는 것을 모읍니다.
     *
     * 라라벨은 라우트 인자를 클래스가 아닌 메서드 인자에 **순서대로** 채운다 — 그 순서로 짝짓는다.
     *
     * @param  string  $classPrefix  컨트롤러 클래스 이름 접두 (예: App\)
     * @return array<int, string> 「메서드 URI {인자} -> 컨트롤러@메서드」 목록
     */
    private static function unconstrainedIntParams(string $classPrefix): array
    {
        $patterns = Route::getPatterns();
        $offenders = [];

        /** @var RoutingRoute $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_starts_with($action, $classPrefix) || ! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            if (! method_exists($class, $method)) {
                continue;
            }
            $scalar = array_values(array_filter(
                (new ReflectionMethod($class, $method))->getParameters(),
                fn ($p) => ! ($p->getType() instanceof ReflectionNamedType && ! $p->getType()->isBuiltin())
            ));
            foreach ($route->parameterNames() as $i => $name) {
                $type = ($scalar[$i] ?? null)?->getType();
                if (! $type instanceof ReflectionNamedType || $type->getName() !== 'int') {
                    continue;
                }
                $pattern = $route->wheres[$name] ?? $patterns[$name] ?? null;
                if ($pattern !== null && preg_match('#^(?:'.$pattern.')$#', 'abc') === 0) {
                    continue;
                }
                $offenders[] = implode('|', $route->methods())." {$route->uri()} {{$name}} -> {$action}";
            }
        }
        sort($offenders);

        return $offenders;
    }
}
