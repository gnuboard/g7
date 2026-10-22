<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 관리자 API 권한 매트릭스 — 관리자 라우트 16개 × 이 플러그인 권한 4종.
 *
 * 각 라우트는 정한 권한 하나로만 열린다(조회 `settings.view` · 변경과 시험 `settings.update` · 회원 연결 열람 `accounts.view` ·
 * 강제 해제 `accounts.unlink`). 다른 권한만 가진 관리자는 403 이다. 라우트 미들웨어를 바꾸거나 빠뜨리면 이 시험이 red 가 된다.
 *
 * @since 1.0.0
 */
class AdminPermissionMatrixTest extends PluginTestCase
{
    /** API 접두 */
    private const BASE = '/api/plugins/sirsoft-social_login/admin';

    /** 권한 4종 */
    private const PERMISSIONS = [
        'sirsoft-social_login.settings.view',
        'sirsoft-social_login.settings.update',
        'sirsoft-social_login.accounts.view',
        'sirsoft-social_login.accounts.unlink',
    ];

    /**
     * 카카오를 시딩하고 외부 요청은 모두 가짜로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableKakao();
        Http::fake();
    }

    /**
     * 라우트 16개마다 정한 권한만 통과시키고 나머지 세 권한은 403 으로 막는다.
     *
     * @scenario actor=viewer|editor|accounts_viewer|unlinker, route=all_admin
     *
     * @effects admin_routes_gated_by_single_permission
     *
     * @return void 반환값 없음
     */
    public function test_each_admin_route_opens_only_for_its_permission(): void
    {
        $member = User::factory()->create(['email' => $this->uniqueEmail('mx')]);
        $users = self::BASE.'/users/'.$member->uuid.'/accounts';
        $routes = [
            ['GET', self::BASE.'/providers', 'settings.view'],
            ['GET', self::BASE.'/providers/kakao/impact', 'settings.view'],
            ['GET', self::BASE.'/diagnostics', 'settings.view'],
            ['GET', self::BASE.'/attempts', 'settings.view'],
            ['GET', self::BASE.'/webhook-events', 'settings.view'],
            ['GET', self::BASE.'/stats', 'settings.view'],
            ['POST', self::BASE.'/providers', 'settings.update'],
            ['POST', self::BASE.'/providers/reorder', 'settings.update'],
            ['POST', self::BASE.'/providers/discover', 'settings.update'],
            ['PUT', self::BASE.'/providers/kakao', 'settings.update'],
            ['DELETE', self::BASE.'/providers/kakao', 'settings.update'],
            ['POST', self::BASE.'/providers/kakao/test', 'settings.update'],
            ['POST', self::BASE.'/providers/kakao/test-login', 'settings.update'],
            ['GET', self::BASE.'/linked-providers', 'accounts.view'],
            ['GET', $users, 'accounts.view'],
            ['DELETE', $users.'/kakao', 'accounts.unlink'],
        ];

        $admins = [];
        foreach (self::PERMISSIONS as $permission) {
            $admins[$permission] = $this->makeAdminUser(['email' => $this->uniqueEmail('mx')], [$permission]);
        }

        $mismatches = [];
        foreach ($routes as [$method, $uri, $required]) {
            foreach ($admins as $permission => $admin) {
                Sanctum::actingAs($admin);
                $status = $this->json($method, $uri)->status();
                $allowed = $permission === 'sirsoft-social_login.'.$required;
                $denied = in_array($status, [401, 403], true);
                if ($allowed === $denied) {
                    $mismatches[] = sprintf('%s %s as %s → %d', $method, $uri, $permission, $status);
                }
            }
        }

        $this->assertSame([], $mismatches);

        // 위 목록이 등록된 관리자 라우트 전부인지 — 라우트가 늘었는데 목록에 없으면 그 라우트의 권한은 아무도 재지 않는다.
        $registered = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), ltrim(self::BASE, '/').'/'))
            ->flatMap(fn ($route) => array_diff($route->methods(), ['HEAD']))
            ->count();
        $this->assertGreaterThan(0, $registered);
        $this->assertSame($registered, count($routes));
    }
}
