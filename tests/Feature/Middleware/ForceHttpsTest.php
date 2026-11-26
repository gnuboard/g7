<?php

namespace Tests\Feature\Middleware;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * HTTPS 강제 이동 미들웨어 (`ForceHttps`)
 *
 * 관리자 환경설정 > 보안의 「HTTPS 강제 적용」은 저장만 되고 아무 코드도 읽지 않았다 — 켜도 http 로 그대로 열렸다.
 * 켜져 있으면 web·api 그룹의 http 요청을 같은 호스트·경로·쿼리의 https 로 보내야 하고(GET 301, 그 밖 308),
 * https 요청과 신뢰 프록시가 https 라고 알린 요청은 그대로 통과해야 한다(그렇지 않으면 이동이 끝없이 반복된다).
 */
class ForceHttpsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 시험용 web·api 라우트를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 사용자 화면 catch-all(`/{any?}`)이 먼저 등록돼 있어 뒤에 더한 시험 경로가 가려진다 — 시험 경로를 맨 앞에 둔다
        $router = app('router');
        $existing = $router->getRoutes();
        $router->setRoutes(new RouteCollection);
        Route::middleware('web')->get('/__force_https_probe', fn () => response('web-ok'));
        Route::middleware('web')->post('/__force_https_probe', fn () => response('web-post-ok'));
        Route::middleware('api')->get('/api/__force_https_probe', fn () => response()->json(['ok' => true]));
        $probes = $router->getRoutes();
        foreach ($existing->getRoutes() as $route) {
            $probes->add($route);
        }
        $probes->refreshNameLookups();
        $probes->refreshActionLookups();
    }

    /**
     * 설정이 꺼져 있으면 http 요청도 그대로 통과한다.
     *
     * @scenario case=off_http_get
     *
     * @effects force_https_off_passes_http
     *
     * @return void 반환값 없음
     */
    public function test_setting_off_passes_http_request(): void
    {
        config(['g7_settings.core.security.force_https' => false]);

        $this->get('http://localhost/__force_https_probe')->assertOk()->assertSee('web-ok');
    }

    /**
     * 켜져 있으면 http GET 은 같은 경로·쿼리의 https 로 301 이동한다 (web·api 둘 다).
     *
     * @scenario case=on_http_get
     *
     * @effects force_https_get_redirects_301_to_same_url
     *
     * @return void 반환값 없음
     */
    public function test_http_get_redirects_with_301_to_same_url(): void
    {
        config(['g7_settings.core.security.force_https' => true]);

        $this->get('http://localhost/__force_https_probe?a=1&b=two')
            ->assertStatus(301)
            ->assertRedirect('https://localhost/__force_https_probe?a=1&b=two');

        $this->getJson('http://localhost/api/__force_https_probe')
            ->assertStatus(301)
            ->assertRedirect('https://localhost/api/__force_https_probe');
    }

    /**
     * 켜져 있으면 http POST 는 메서드·본문을 지키는 308 로 이동한다.
     *
     * @scenario case=on_http_post
     *
     * @effects force_https_non_get_redirects_308
     *
     * @return void 반환값 없음
     */
    public function test_http_post_redirects_with_308(): void
    {
        config(['g7_settings.core.security.force_https' => true]);

        $this->post('http://localhost/__force_https_probe', ['x' => 1])
            ->assertStatus(308)
            ->assertRedirect('https://localhost/__force_https_probe');
    }

    /**
     * 켜져 있어도 https 요청은 그대로 통과한다.
     *
     * @scenario case=on_https
     *
     * @effects force_https_passes_secure_request
     *
     * @return void 반환값 없음
     */
    public function test_https_request_passes(): void
    {
        config(['g7_settings.core.security.force_https' => true]);

        $this->get('https://localhost/__force_https_probe')->assertOk()->assertSee('web-ok');
        $this->getJson('https://localhost/api/__force_https_probe')->assertOk()->assertJson(['ok' => true]);
    }

    /**
     * 신뢰 프록시가 `X-Forwarded-Proto: https` 로 알린 요청은 통과하고, 신뢰하지 않는 곳에서 온 같은 헤더는 무시된다.
     *
     * @scenario case=on_forwarded_https
     *
     * @effects force_https_trusts_forwarded_proto_from_trusted_proxy
     *
     * @return void 반환값 없음
     */
    public function test_forwarded_proto_from_trusted_proxy_passes(): void
    {
        config(['g7_settings.core.security.force_https' => true]);
        config(['trustedproxy.proxies' => '127.0.0.1']);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://localhost/__force_https_probe')
            ->assertOk()
            ->assertSee('web-ok');

        // 신뢰하지 않는 주소에서 온 헤더로는 https 로 인정하지 않는다 — 위조 헤더로 이동을 피할 수 없다.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://localhost/__force_https_probe')
            ->assertStatus(301);
    }
}
