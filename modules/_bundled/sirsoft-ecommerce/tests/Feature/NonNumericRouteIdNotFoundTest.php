<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * 번호 자리에 숫자가 아닌 값이 오면 500(TypeError) 대신 404 로 끝나는지 검증합니다.
 *
 * 컨트롤러가 번호 인자를 `int` 로 받는데 라우트에 숫자 제약(`whereNumber`)이 없으면 숫자 아닌 주소가 권한 판정을 지나
 * TypeError 로 500 이 된다. 라우트 제약이 표준이다(코어 라우트 선례) — 제약에 맞지 않으면 라우트가 없는 것과 같아 404 다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/ecommerce-non-numeric-route-id.yaml 참조.
 */
class NonNumericRouteIdNotFoundTest extends ModuleTestCase
{
    /**
     * 이커머스 모듈의 모든 라우트에서, 컨트롤러가 int 로 받는 번호 자리에 숫자 제약이 있다.
     *
     * @scenario case=route_contract
     *
     * @effects int_route_params_have_numeric_constraint
     *
     * @return void 반환값 없음
     */
    public function test_every_int_route_param_has_numeric_constraint(): void
    {
        $offenders = self::unconstrainedIntParams('api/modules/sirsoft-ecommerce/');

        $this->assertSame([], $offenders, "숫자 제약 없이 int 로 받는 번호 자리:\n".implode("\n", $offenders));
    }

    /**
     * 찜 삭제(회원)와 브랜드·카테고리 삭제(관리자)가 숫자 아닌 번호에 404 로 답한다(권한이 있어도).
     *
     * @scenario case=non_numeric_id_404
     *
     * @effects non_numeric_id_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_non_numeric_ids_return_404_not_500(): void
    {
        $this->actingAs($this->createUser())->deleteJson('/api/modules/sirsoft-ecommerce/wishlist/abc')->assertNotFound();

        $admin = $this->createAdminUser(['sirsoft-ecommerce.brands.delete', 'sirsoft-ecommerce.categories.delete']);
        $this->actingAs($admin)->deleteJson('/api/modules/sirsoft-ecommerce/admin/brands/abc')->assertNotFound();
        $this->actingAs($admin)->deleteJson('/api/modules/sirsoft-ecommerce/admin/categories/abc')->assertNotFound();
    }

    /**
     * 주어진 URI 접두의 라우트 중, 컨트롤러가 int 로 받는 라우트 인자에 숫자 제약이 없는 것을 모읍니다.
     *
     * 라라벨은 라우트 인자를 클래스가 아닌 메서드 인자에 **순서대로** 채운다 — 그 순서로 짝짓는다.
     *
     * @param  string  $uriPrefix  URI 접두 (예: api/modules/sirsoft-ecommerce/)
     * @return array<int, string> 「메서드 URI {인자} -> 컨트롤러@메서드」 목록
     */
    private static function unconstrainedIntParams(string $uriPrefix): array
    {
        $patterns = Route::getPatterns();
        $offenders = [];

        /** @var RoutingRoute $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $uriPrefix) || ! str_contains($route->getActionName(), '@')) {
                continue;
            }
            [$class, $method] = explode('@', $route->getActionName());
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
                $offenders[] = implode('|', $route->methods())." {$route->uri()} {{$name}} -> {$route->getActionName()}";
            }
        }
        sort($offenders);

        return $offenders;
    }
}
