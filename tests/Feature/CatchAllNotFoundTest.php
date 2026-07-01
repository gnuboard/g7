<?php

namespace Tests\Feature;

use App\Seo\TemplateRouteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * catch-all 미등록 경로 HTTP 404 응답 테스트 (공개#47).
 *
 * routes/web.php 의 user/admin catch-all 이 TemplateRouteResolver::routeExists() 판별로
 * 미등록 경로에 SPA 셸 본문 + HTTP 404 를, 등록 경로에 200 을 반환하는지 검증한다.
 *
 * routeExists() 자체의 매칭 로직은 TemplateRouteResolverTest(Unit)가 커버하고,
 * 본 Feature 는 catch-all 라우트 ↔ routeExists 결과의 HTTP status 연결을 검증한다.
 */
class CatchAllNotFoundTest extends TestCase
{
    use RefreshDatabase;

    /**
     * routeExists 가 지정 값을 반환하도록 resolver 를 바인딩합니다.
     *
     * @param  bool  $exists  routeExists 반환값
     */
    private function bindResolver(bool $exists): void
    {
        $resolver = Mockery::mock(TemplateRouteResolver::class);
        $resolver->shouldReceive('routeExists')->andReturn($exists);
        $this->app->instance(TemplateRouteResolver::class, $resolver);
    }

    /**
     * 미등록 user 경로는 404 + SPA 셸 본문을 반환합니다.
     */
    public function test_unregistered_user_path_returns_404_with_shell(): void
    {
        $this->bindResolver(false);

        $response = $this->get('/this-does-not-exist');

        $response->assertStatus(404);
        $response->assertSee('id="app"', false); // SPA 셸 마커 (본문은 그대로)
    }

    /**
     * 등록된 user 경로는 200 을 반환합니다.
     */
    public function test_registered_user_path_returns_200(): void
    {
        $this->bindResolver(true);

        $response = $this->get('/some-registered-route');

        $response->assertStatus(200);
        $response->assertSee('id="app"', false);
    }

    /**
     * 미등록 admin 경로는 404 + admin 셸 본문을 반환합니다.
     */
    public function test_unregistered_admin_path_returns_404(): void
    {
        $this->bindResolver(false);

        $response = $this->get('/admin/nonexistent');

        $response->assertStatus(404);
    }

    /**
     * 등록된 admin 경로는 200 을 반환합니다.
     */
    public function test_registered_admin_path_returns_200(): void
    {
        $this->bindResolver(true);

        $response = $this->get('/admin/users');

        $response->assertStatus(200);
    }

    /**
     * 봇(Googlebot) 미등록 경로도 404 를 받습니다 (봇/비봇 일관).
     */
    public function test_bot_unregistered_path_returns_404(): void
    {
        $this->bindResolver(false);

        $response = $this->get('/this-does-not-exist', [
            'User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
        ]);

        $response->assertStatus(404);
    }

    /**
     * 정적 자산 확장자 경로는 catch-all 에 진입하지 않습니다 (where 정규식 제외).
     */
    public function test_static_asset_path_not_handled_by_catchall(): void
    {
        $this->bindResolver(false);

        // .js 확장자는 catch-all where 정규식에서 제외 → 404(라우트 미정의)이되
        // routeExists 호출 경로를 타지 않음. 상태만 확인(파일 미존재로 404).
        $response = $this->get('/assets/app.js');

        $this->assertContains($response->getStatusCode(), [404]);
    }
}
