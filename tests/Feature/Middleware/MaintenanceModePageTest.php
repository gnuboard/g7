<?php

namespace Tests\Feature\Middleware;

use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Tests\TestCase;

/**
 * 점검 모드 화면 미들웨어(`MaintenanceModePage`) — 웹·관리자 요청은 정적 점검 화면, API 는 JSON 503, 유효한 우회 쿠키는 통과.
 *
 * 시나리오 SSoT: tests/scenarios/maintenance-mode-page.yaml
 */
class MaintenanceModePageTest extends TestCase
{
    /**
     * 점검 파일을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupMaintenanceMode();
    }

    /**
     * 점검 파일을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupMaintenanceMode();
        parent::tearDown();
    }

    /**
     * 메인터넌스 모드 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    private function cleanupMaintenanceMode(): void
    {
        $downFile = storage_path('framework/down');
        if (file_exists($downFile)) {
            unlink($downFile);
        }
    }

    /**
     * 메인터넌스 모드를 활성화합니다.
     *
     * @param  string|null  $secret  bypass 시크릿
     * @return void 반환값 없음
     */
    private function enableMaintenanceMode(?string $secret = null): void
    {
        $data = [
            'except' => [],
            'redirect' => null,
            'retry' => null,
            'refresh' => null,
            'status' => 503,
            'template' => null,
        ];

        if ($secret) {
            $data['secret'] = $secret;
        }

        file_put_contents(
            storage_path('framework/down'),
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    /**
     * 메인터넌스 모드가 아닐 때 정상 통과합니다.
     *
     * @scenario case=not_down
     *
     * @effects passes_when_not_in_maintenance
     *
     * @return void 반환값 없음
     */
    public function test_passes_through_when_not_in_maintenance_mode(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    /**
     * 메인터넌스 모드일 때 웹 요청에 503 정적 페이지를 반환합니다.
     *
     * @scenario case=web_page
     *
     * @effects maintenance_page_served_to_web_and_admin
     *
     * @return void 반환값 없음
     */
    public function test_returns_maintenance_page_for_web_requests(): void
    {
        $this->enableMaintenanceMode();

        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertViewIs('maintenance');
    }

    /**
     * 메인터넌스 모드일 때 관리자 페이지에도 503 정적 페이지를 반환합니다.
     *
     * @scenario case=admin_page
     *
     * @effects maintenance_page_served_to_web_and_admin
     *
     * @return void 반환값 없음
     */
    public function test_returns_maintenance_page_for_admin_requests(): void
    {
        $this->enableMaintenanceMode();

        $response = $this->get('/admin');

        $response->assertStatus(503);
        $response->assertViewIs('maintenance');
    }

    /**
     * 메인터넌스 모드일 때 API 요청에 JSON 503을 반환합니다.
     *
     * @scenario case=api_json
     *
     * @effects api_requests_get_json_503
     *
     * @return void 반환값 없음
     */
    public function test_returns_json_for_api_requests(): void
    {
        $this->enableMaintenanceMode();

        $response = $this->getJson('/api/test');

        $response->assertStatus(503);
        $response->assertJson([
            'success' => false,
        ]);
    }

    /**
     * 메인터넌스 모드일 때 API 경로 패턴 요청에 JSON 503을 반환합니다.
     *
     * @scenario case=api_path_json
     *
     * @effects api_requests_get_json_503
     *
     * @return void 반환값 없음
     */
    public function test_returns_json_for_api_path_requests(): void
    {
        $this->enableMaintenanceMode();

        $response = $this->get('/api/settings');

        $response->assertStatus(503);
    }

    /**
     * secret 쿠키로 메인터넌스 모드를 bypass할 수 있습니다.
     *
     * @scenario case=secret_cookie
     *
     * @effects valid_bypass_cookie_passes
     *
     * @return void 반환값 없음
     */
    public function test_bypass_with_secret_cookie(): void
    {
        $secret = 'test-bypass-secret';
        $this->enableMaintenanceMode($secret);

        // Laravel 표준 MaintenanceModeBypassCookie 형식 사용
        $cookie = MaintenanceModeBypassCookie::create($secret);

        $response = $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/');

        // secret 쿠키가 있으면 정상 통과 (503이 아닌 응답)
        $this->assertNotEquals(503, $response->getStatusCode());
    }

    /**
     * 잘못된 secret 쿠키로는 bypass할 수 없습니다.
     *
     * @scenario case=invalid_cookie
     *
     * @effects invalid_bypass_cookie_blocked
     *
     * @return void 반환값 없음
     */
    public function test_invalid_secret_cookie_does_not_bypass(): void
    {
        $this->enableMaintenanceMode('test-bypass-secret');

        $response = $this->withCookie('laravel_maintenance', 'invalid-cookie-value')->get('/');

        $response->assertStatus(503);
        $response->assertViewIs('maintenance');
    }

    /**
     * 메인터넌스 페이지가 다국어 텍스트를 포함합니다 (한국어).
     *
     * @scenario case=locale_ko
     *
     * @effects maintenance_text_localized
     *
     * @return void 반환값 없음
     */
    public function test_maintenance_page_contains_localized_text_ko(): void
    {
        $this->enableMaintenanceMode();

        $response = $this->withHeaders([
            'Accept-Language' => 'ko,en;q=0.9',
        ])->get('/');

        $response->assertStatus(503);
        $response->assertSee(__('maintenance.title', [], 'ko'));
    }

    /**
     * 메인터넌스 페이지가 다국어 텍스트를 포함합니다 (영어).
     *
     * @scenario case=locale_en
     *
     * @effects maintenance_text_localized
     *
     * @return void 반환값 없음
     */
    public function test_maintenance_page_contains_localized_text_en(): void
    {
        $this->enableMaintenanceMode();

        $response = $this->withHeaders([
            'Accept-Language' => 'en,ko;q=0.9',
        ])->get('/');

        $response->assertStatus(503);
        $response->assertSee(__('maintenance.title', [], 'en'));
    }

    /**
     * 메인터넌스 페이지가 외부 의존성(JS/CSS CDN) 없이 렌더링됩니다 — 스타일은 코어 정적 파일 하나(같은 출처).
     *
     * @scenario case=no_external_deps
     *
     * @effects static_page_without_external_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_maintenance_page_has_no_external_dependencies(): void
    {
        $this->enableMaintenanceMode();

        $response = $this->get('/');
        $content = $response->getContent();

        $response->assertStatus(503);

        // 외부 script 가 없어야 함
        $this->assertStringNotContainsString('<script src=', $content);

        // 스타일은 코어 정적 파일(같은 출처) 하나만 — 엄격한 스타일 정책에서 뷰 안 `<style>` 블록이 막혀 정적 파일로 옮겼다(#163).
        // 외부 CDN 스타일시트는 없어야 한다.
        $this->assertStringNotContainsString('<style>', $content);
        $this->assertSame(1, preg_match_all('/<link rel="stylesheet" href="([^"]+)"/', $content, $links));
        $this->assertStringContainsString('/assets/core/css/g7-standalone.css', $links[1][0]);
    }
}
