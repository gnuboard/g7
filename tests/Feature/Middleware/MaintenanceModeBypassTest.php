<?php

namespace Tests\Feature\Middleware;

use Illuminate\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Tests\TestCase;

/**
 * 점검 모드 통과 수단 — `down --secret`·`--except`·허용 IP·`--retry`·`--refresh`·`--redirect` (#888).
 *
 * Laravel 기본 점검 미들웨어를 `MaintenanceModePage` 로 바꾼 뒤로 이 미들웨어는 우회 쿠키를 검증만 하고 발급하지 않아,
 * `php artisan down --secret=abc` 뒤 `/abc` 를 열어도 503 이었다. `down` 데이터의 나머지 옵션도 읽지 않았다.
 *
 * @scenario-manifest tests/scenarios/maintenance-bypass.yaml
 */
class MaintenanceModeBypassTest extends TestCase
{
    /**
     * 점검 파일을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearDown();
    }

    /**
     * 점검 파일·설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearDown();
        parent::tearDown();
    }

    /**
     * 점검 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function clearDown(): void
    {
        $down = storage_path('framework/down');
        if (file_exists($down)) {
            unlink($down);
        }
    }

    /**
     * 점검 모드를 켭니다 (`php artisan down` 이 쓰는 파일 형식).
     *
     * @param  array<string, mixed>  $data  down 데이터
     * @return void 반환값 없음
     */
    private function down(array $data = []): void
    {
        file_put_contents(storage_path('framework/down'), (string) json_encode($data + [
            'except' => [], 'redirect' => null, 'retry' => null, 'refresh' => null, 'status' => 503, 'template' => null,
        ]));
    }

    /**
     * 비밀 경로는 우회 쿠키를 주고 첫 화면으로 보내며, 그 쿠키로 들어간다. 틀린 비밀 경로는 503.
     *
     * @scenario case=secret_path
     *
     * @effects secret_path_issues_bypass_cookie
     *
     * @return void 반환값 없음
     */
    public function test_secret_path_issues_cookie_and_cookie_passes(): void
    {
        $this->down(['secret' => 'probe-secret']);

        $this->get('/wrong-secret')->assertStatus(503);

        $response = $this->get('/probe-secret');
        $response->assertRedirect('/');
        $response->assertCookie('laravel_maintenance');

        $cookie = MaintenanceModeBypassCookie::create('probe-secret');
        $this->withUnencryptedCookie('laravel_maintenance', $cookie->getValue())->get('/')->assertStatus(200);
    }

    /**
     * 통과 경로(`G7_MAINTENANCE_EXCEPT` → `core.maintenance.except`, `*` 와일드카드)는 통과하고 그 밖은 503 이다.
     * `down` 명령에는 경로 예외 옵션이 없어 서버 로컬 설정이 유일한 입력이다(점검 파일의 except 는 함께 읽는다).
     *
     * @scenario case=except_path
     *
     * @effects except_and_allowed_ip_pass_maintenance
     *
     * @return void 반환값 없음
     */
    public function test_except_path_passes(): void
    {
        $this->down();
        config(['core.maintenance.except' => ['/', 'probe-maintenance/*']]);

        $this->get('/')->assertStatus(200);
        // 와일드카드 경로는 점검을 지나 화면 셸(200)이 응답한다
        $this->get('/probe-maintenance/anything')->assertStatus(200);
        $this->get('/login')->assertStatus(503);

        // 점검 파일의 except 도 그대로 읽는다(다른 도구가 만든 점검 파일 호환)
        config(['core.maintenance.except' => []]);
        $this->down(['except' => ['/login']]);
        $this->get('/login')->assertStatus(200);
    }

    /**
     * 허용 IP 는 통과하고, 그 밖의 IP 는 503 이다(CIDR 허용).
     *
     * @scenario case=allowed_ip
     *
     * @effects except_and_allowed_ip_pass_maintenance
     *
     * @return void 반환값 없음
     */
    public function test_allowed_ip_passes_and_others_do_not(): void
    {
        $this->down();
        config(['core.maintenance.allow_ips' => ['203.0.113.0/24']]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/')->assertStatus(200);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->get('/')->assertStatus(503);
    }

    /**
     * 허용 IP 는 신뢰 프록시 설정을 거친 클라이언트 IP 로 판정한다 — 신뢰 프록시가 보낸 X-Forwarded-For 는 따르고,
     * 신뢰하지 않는 곳에서 온 X-Forwarded-For 는 무시한다.
     *
     * 점검 미들웨어는 전역 스택 맨 앞이라 TrustProxies 보다 먼저 돈다. 그 순서 그대로 `$request->ip()` 를 읽으면
     * 프록시 뒤에서는 늘 프록시 주소로 판정돼 허용 IP 가 듣지 않았다.
     *
     * @scenario case=allowed_ip
     *
     * @effects except_and_allowed_ip_pass_maintenance
     *
     * @return void 반환값 없음
     */
    public function test_allowed_ip_uses_trusted_proxy_client_ip(): void
    {
        $this->down();
        config(['core.maintenance.allow_ips' => ['203.0.113.0/24'], 'trustedproxy.proxies' => '10.0.0.0/8']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->get('/')->assertStatus(200);

        // 신뢰하지 않는 곳에서 온 X-Forwarded-For 는 위조로 보고 무시한다
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->get('/')->assertStatus(503);
    }

    /**
     * 점검 모드 저장소가 캐시(`APP_MAINTENANCE_DRIVER=cache`)여도 비밀 경로로 받은 우회 쿠키로 들어간다.
     *
     * 쿠키 발급은 점검 드라이버의 데이터를 읽었는데 검증은 `storage/framework/down` 파일만 읽어, 캐시 드라이버에서는
     * 받은 쿠키가 늘 무효였다.
     *
     * @scenario case=secret_path
     *
     * @effects secret_path_issues_bypass_cookie
     *
     * @return void 반환값 없음
     */
    public function test_bypass_cookie_passes_with_cache_maintenance_driver(): void
    {
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->forgetInstance(MaintenanceModeContract::class);
        app()->maintenanceMode()->activate([
            'except' => [], 'redirect' => null, 'retry' => null, 'refresh' => null, 'status' => 503, 'template' => null,
            'secret' => 'probe-cache-secret',
        ]);

        try {
            $this->assertFalse(file_exists(storage_path('framework/down')), '점검 파일 없이 캐시에만 점검 상태가 있다');
            $this->get('/')->assertStatus(503);

            $response = $this->get('/probe-cache-secret');
            $response->assertRedirect('/');
            $response->assertCookie('laravel_maintenance');

            $cookie = MaintenanceModeBypassCookie::create('probe-cache-secret');
            $this->withUnencryptedCookie('laravel_maintenance', $cookie->getValue())->get('/')->assertStatus(200);
        } finally {
            app()->maintenanceMode()->deactivate();
            $this->app->forgetInstance(MaintenanceModeContract::class);
        }
    }

    /**
     * `--retry`·`--refresh`·`--status` 가 응답에 반영된다.
     *
     * @scenario case=headers
     *
     * @effects down_options_shape_the_response
     *
     * @return void 반환값 없음
     */
    public function test_retry_refresh_and_status_are_applied(): void
    {
        $this->down(['retry' => 120, 'refresh' => 30, 'status' => 503]);

        $response = $this->get('/');
        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '120');
        $response->assertHeader('Refresh', '30');
    }

    /**
     * `--redirect` 가 있으면 다른 경로는 그쪽으로 보낸다.
     *
     * @scenario case=redirect
     *
     * @effects down_options_shape_the_response
     *
     * @return void 반환값 없음
     */
    public function test_redirect_option_sends_other_paths_there(): void
    {
        $this->down(['redirect' => '/notice']);

        $this->get('/somewhere')->assertRedirect('/notice');
    }
}
