<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Providers;

use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원 클레임 라우트 전수의 인증·권한 미들웨어 부착 검사 (S5)
 *
 * 개별 Feature 단언은 권한 슬러그마다 대표 라우트 한 건씩만 403 을 확인한다. 그래서 나머지
 * 라우트에서 `permission:` 이 사라져도 전 스위트가 green 이다 — 비회원 축은
 * `GuestOrderTokenMiddlewareRegistrationTest` 가 라우트 테이블에서 모집단을 파생시켜 그 사각을
 * 막고 있는데 회원 축에는 대응물이 없었다. 이 테스트가 그 비대칭을 해소한다.
 *
 * 모집단을 라우트 테이블에서 파생시키므로 새 클레임 라우트가 늘면 자동으로 검사 대상이 된다.
 */
class UserClaimRoutePermissionTest extends ModuleTestCase
{
    /**
     * 회원 클레임 라우트 이름 접두사 (이 셋이 S5 가 추가한 회원 축 전부다)
     */
    private const PREFIXES = [
        'api.modules.sirsoft-ecommerce.user.claims',
        'api.modules.sirsoft-ecommerce.user.claim-payments',
        'api.modules.sirsoft-ecommerce.user.orders.claim',
    ];

    /**
     * 주문 스코프 신청 라우트 (이름이 `user.orders.*` 라 접두사만으로는 클레임 축이 구분되지 않는다)
     */
    private const ORDER_SCOPED = [
        'api.modules.sirsoft-ecommerce.user.orders.claimable-options',
        'api.modules.sirsoft-ecommerce.user.orders.claims.preview',
        'api.modules.sirsoft-ecommerce.user.orders.exchanges.store',
        'api.modules.sirsoft-ecommerce.user.orders.returns.store',
        'api.modules.sirsoft-ecommerce.user.orders.refund-requests.store',
        'api.modules.sirsoft-ecommerce.user.orders.claim-images.store',
    ];

    /**
     * 회원 클레임 라우트를 수집합니다.
     *
     * @return array<string, array<int, string>> 라우트명 => 미들웨어 목록
     */
    private function claimRoutes(): array
    {
        $collected = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (! is_string($name)) {
                continue;
            }

            $matches = in_array($name, self::ORDER_SCOPED, true);

            foreach (self::PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $matches = true;
                }
            }

            if ($matches) {
                $collected[$name] = $route->gatherMiddleware();
            }
        }

        return $collected;
    }

    public function test_every_member_claim_route_requires_authentication_and_a_claim_permission(): void
    {
        $routes = $this->claimRoutes();

        // 13 → 14: 신청 수정(`user.claims.update`) 이 열렸다 (#599 후속 D13).
        $this->assertCount(
            14,
            $routes,
            '회원 클레임 라우트가 14건이 아닙니다(수집: '.count($routes).'). 라우트가 늘었다면 그것이 '
            .'검사 대상에 들어왔는지 확인하고, 이름 규칙이 바뀌었다면 PREFIXES·ORDER_SCOPED 를 함께 고치십시오 '
            .'— 그러지 않으면 이 테스트는 아무것도 검사하지 못하는 상태로 green 이 됩니다.',
        );

        $unauthenticated = [];
        $unpermissioned = [];

        foreach ($routes as $name => $middleware) {
            if (! in_array('auth:sanctum', $middleware, true)) {
                $unauthenticated[] = $name;
            }

            $hasClaimPermission = false;

            foreach ($middleware as $entry) {
                if (str_starts_with($entry, 'permission:') && str_contains($entry, 'sirsoft-ecommerce.user-claims.')) {
                    $hasClaimPermission = true;
                }
            }

            if (! $hasClaimPermission) {
                $unpermissioned[] = $name;
            }
        }

        $this->assertSame([], $unauthenticated, '인증 미들웨어 없이 열린 회원 클레임 라우트: '.implode(', ', $unauthenticated));
        $this->assertSame([], $unpermissioned, '`user-claims.*` 권한 없이 열린 회원 클레임 라우트: '.implode(', ', $unpermissioned));
    }

    public function test_each_claim_route_uses_a_permission_slug_that_the_module_declares(): void
    {
        // 슬러그 오타는 미들웨어가 붙어 있어도 통과를 막을 뿐 아니라, 권한 화면에 존재하지 않는
        // 권한을 요구해 어떤 역할로도 열 수 없는 라우트를 만든다.
        $declared = [];

        foreach ((new Module)->getPermissions()['categories'] ?? [] as $category) {
            foreach ($category['permissions'] ?? [] as $permission) {
                if (isset($category['identifier'], $permission['action'])) {
                    $declared[] = 'sirsoft-ecommerce.'.$category['identifier'].'.'.$permission['action'];
                }
            }
        }
        $this->assertNotEmpty($declared, '모듈이 선언한 권한을 하나도 읽지 못했습니다.');

        $undeclared = [];

        foreach ($this->claimRoutes() as $name => $middleware) {
            foreach ($middleware as $entry) {
                if (! str_starts_with($entry, 'permission:')) {
                    continue;
                }

                foreach (explode(',', substr($entry, strlen('permission:'))) as $slug) {
                    $slug = trim($slug);

                    if (str_contains($slug, 'user-claims.') && ! in_array($slug, $declared, true)) {
                        $undeclared[] = $name.' → '.$slug;
                    }
                }
            }
        }

        $this->assertSame([], $undeclared, '모듈이 선언하지 않은 권한 슬러그를 요구하는 라우트: '.implode(', ', $undeclared));
    }
}
