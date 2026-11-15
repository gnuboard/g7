<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 코어 오류 화면(401·403·404·500·503 과 묶음·대체 화면)이 코어 뷰로 그려진다.
 *
 * @scenario-manifest tests/scenarios/strict-style-csp-compat.yaml
 */
class ErrorPageTest extends TestCase
{
    /**
     * 404 에러 페이지가 G7 커스텀 페이지로 렌더링되는지 확인합니다.
     * /dev 라우트는 디버그 모드 꺼졌을 때 abort(404)를 호출합니다.
     *
     * @scenario case=standalone_pages_no_inline_style
     *
     * @effects error_pages_render_core_views
     *
     * @return void 반환값 없음
     */
    public function test_404_page_renders_custom_error_page(): void
    {
        Config::set('app.debug', false);

        $response = $this->get('/dev');

        $response->assertStatus(404);
        $response->assertSee(__('errors.404.title'));
        $response->assertSee(__('errors.back_home'));
    }

    /**
     * 에러 페이지에 다크 모드 스타일이 포함되어 있는지 확인합니다.
     *
     * @scenario case=standalone_pages_no_inline_style
     *
     * @effects error_pages_render_core_views, core_css_files_served
     *
     * @return void 반환값 없음
     */
    public function test_error_page_includes_dark_mode_styles(): void
    {
        Config::set('app.debug', false);

        $response = $this->get('/dev');

        $response->assertStatus(404);
        // 다크 모드 규칙은 응답 HTML 의 <style> 이 아니라 독립 화면 정적 CSS 에 있다(스타일 보안 정책 호환)
        $response->assertSee('assets/core/css/g7-standalone.css', false);
        $this->assertStringContainsString(
            'prefers-color-scheme: dark',
            (string) file_get_contents(public_path('assets/core/css/g7-standalone.css'))
        );
    }

    /**
     * /dev 경로의 에러 페이지에서 홈 링크가 /를 가리키는지 확인합니다.
     * (/dev는 admin 접두사가 아니므로 루트로 이동)
     *
     * @scenario case=standalone_pages_no_inline_style
     *
     * @effects error_pages_render_core_views
     *
     * @return void 반환값 없음
     */
    public function test_error_page_links_to_root_home(): void
    {
        Config::set('app.debug', false);

        $response = $this->get('/dev');

        $response->assertStatus(404);
        $response->assertSee('href="/"', false);
    }

    /**
     * 에러 Blade 뷰 파일이 모두 존재하는지 확인합니다.
     *
     * @scenario case=standalone_pages_no_inline_style
     *
     * @effects error_pages_render_core_views, fallback_error_pages_use_core_views
     *
     * @return void 반환값 없음
     */
    public function test_all_error_blade_views_exist(): void
    {
        // 402·419·429 는 프레임워크 자체 뷰를 가리는 코어 화면, 4xx·5xx 는 전용 화면이 없는 응답의 묶음 화면
        $errorCodes = [401, 402, 403, 404, 419, 429, 500, 503, '4xx', '5xx'];

        foreach ($errorCodes as $code) {
            $this->assertTrue(
                is_file(resource_path("views/errors/{$code}.blade.php")),
                "에러 뷰 errors.{$code}가 존재해야 합니다."
            );
        }
    }

    /**
     * 에러 레이아웃 Blade 뷰가 존재하는지 확인합니다.
     *
     * @scenario case=standalone_pages_no_inline_style
     *
     * @effects error_pages_render_core_views
     *
     * @return void 반환값 없음
     */
    public function test_error_layout_view_exists(): void
    {
        $this->assertTrue(
            view()->exists('errors.layout'),
            '에러 레이아웃 뷰 errors.layout이 존재해야 합니다.'
        );
    }

    /**
     * 다국어 키가 모든 에러 코드에 대해 정의되어 있는지 확인합니다.
     *
     * @scenario case=standalone_pages_no_inline_style
     *
     * @effects error_pages_render_core_views, fallback_error_pages_use_core_views
     *
     * @return void 반환값 없음
     */
    public function test_all_error_translation_keys_exist(): void
    {
        $errorCodes = [401, 403, 404, 500, 503, '4xx', '5xx'];

        foreach ($errorCodes as $code) {
            $this->assertNotEquals(
                "errors.{$code}.title",
                __("errors.{$code}.title"),
                "errors.{$code}.title 다국어 키가 정의되어야 합니다."
            );
            $this->assertNotEquals(
                "errors.{$code}.message",
                __("errors.{$code}.message"),
                "errors.{$code}.message 다국어 키가 정의되어야 합니다."
            );
        }

        $this->assertNotEquals(
            'errors.back_home',
            __('errors.back_home'),
            'errors.back_home 다국어 키가 정의되어야 합니다.'
        );
    }
}
