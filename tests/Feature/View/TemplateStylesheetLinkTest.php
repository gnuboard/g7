<?php

namespace Tests\Feature\View;

use App\Contracts\Extension\TemplateManagerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 사용자·관리자 화면 HTML 의 템플릿 CSS 링크가 템플릿 매니페스트 선언(`assets.css`)과 산출물을 따르는지 검증합니다.
 *
 * 종전에는 두 화면이 템플릿과 무관하게 `{템플릿}?file=css/components.css` 링크를 늘 넣어, CSS 를 선언하지 않은
 * 템플릿에서 모든 화면이 `GET /api/templates/assets/{id}?file=css/components.css → 422` 를 남겼다
 * (2026-10-07 실측, CSS 빌드가 없는 학습용 템플릿).
 */
class TemplateStylesheetLinkTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureTemplatePath;

    /**
     * 코어 엔진 빌드 유무를 확인하고 이전 실행이 남긴 테스트 템플릿을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(public_path('build/core/template-engine.min.js'))) {
            $this->markTestSkipped('Core template engine not built. Run npm run build to generate build files.');
        }

        $this->fixtureTemplatePath = base_path('templates/test-stylesheet_link');
        $this->deleteFixtureTemplate();
    }

    /**
     * 테스트 템플릿을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->deleteFixtureTemplate();

        parent::tearDown();
    }

    /**
     * CSS 를 선언하지 않은 사용자 템플릿 화면에는 템플릿 CSS 링크가 없다.
     *
     * @scenario template_type=user, css_declaration=none
     *
     * @effects template_stylesheet_link_omitted_without_declaration
     *
     * @return void 반환값 없음
     */
    public function test_user_page_omits_stylesheet_when_template_declares_no_css(): void
    {
        $this->activateFixtureTemplate('user', [], false);

        $html = $this->get('/')->assertStatus(200)->getContent();

        $this->assertStringContainsString('data-template-id="test-stylesheet_link"', $html);
        $this->assertStringNotContainsString('test-stylesheet_link/css/components.css', $html);
        $this->assertStringNotContainsString('rel="stylesheet" href="/api/templates/assets/test-stylesheet_link', $html);
    }

    /**
     * CSS 를 선언했어도 산출물이 없으면 링크하지 않는다 — 없는 파일 링크는 요청 실패만 남긴다.
     *
     * @scenario template_type=user, css_declaration=declared_without_build
     *
     * @effects template_stylesheet_link_omitted_without_build_output
     *
     * @return void 반환값 없음
     */
    public function test_user_page_omits_declared_stylesheet_without_build_output(): void
    {
        $this->activateFixtureTemplate('user', ['dist/css/components.css'], false);

        $html = $this->get('/')->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('test-stylesheet_link/css/components.css', $html);
    }

    /**
     * CSS 를 선언하고 산출물이 있으면 선언한 파일을 링크한다(자가 복구 onerror 포함).
     *
     * @scenario template_type=user, css_declaration=declared_and_built
     *
     * @effects template_stylesheet_link_follows_declaration
     *
     * @return void 반환값 없음
     */
    public function test_user_page_links_declared_and_built_stylesheet(): void
    {
        $this->activateFixtureTemplate('user', ['dist/css/components.css'], true);

        $html = $this->get('/')->assertStatus(200)->getContent();

        $this->assertMatchesRegularExpression(
            '#<link rel="stylesheet"\s+href="/api/templates/assets/test-stylesheet_link/css/components\.css\?v=[^"]+"\s+onerror="window\.__g7AssetUrl#',
            $html
        );
    }

    /**
     * CSS 를 선언하지 않은 관리자 템플릿 화면에도 템플릿 CSS 링크가 없다.
     *
     * @scenario template_type=admin, css_declaration=none
     *
     * @effects template_stylesheet_link_omitted_without_declaration
     *
     * @return void 반환값 없음
     */
    public function test_admin_page_omits_stylesheet_when_template_declares_no_css(): void
    {
        $this->activateFixtureTemplate('admin', [], false);

        $html = $this->get('/admin')->assertStatus(200)->getContent();

        $this->assertStringContainsString('data-template-id="test-stylesheet_link"', $html);
        $this->assertStringNotContainsString('test-stylesheet_link/css/components.css', $html);
    }

    /**
     * 관리자 템플릿도 CSS 를 선언했지만 산출물이 없으면 링크하지 않는다 — 사용자 화면과 같은 기준이다.
     *
     * @scenario template_type=admin, css_declaration=declared_without_build
     *
     * @effects template_stylesheet_link_omitted_without_build_output
     *
     * @return void 반환값 없음
     */
    public function test_admin_page_omits_declared_stylesheet_without_build_output(): void
    {
        $this->activateFixtureTemplate('admin', ['dist/css/components.css'], false);

        $html = $this->get('/admin')->assertStatus(200)->getContent();

        $this->assertStringContainsString('data-template-id="test-stylesheet_link"', $html);
        $this->assertStringNotContainsString('test-stylesheet_link/css/components.css', $html);
    }

    /**
     * 테스트 템플릿을 만들어 설치·활성화합니다.
     *
     * @param  string  $type  템플릿 유형 (user|admin)
     * @param  array<int, string>  $css  매니페스트 `assets.css` 선언
     * @param  bool  $withCssBuild  `{템플릿}/dist/css/components.css` 산출물을 둘지 여부
     * @return void 반환값 없음
     */
    private function activateFixtureTemplate(string $type, array $css, bool $withCssBuild): void
    {
        $root = $this->fixtureTemplatePath;
        File::makeDirectory($root.'/dist/js', 0755, true);
        File::makeDirectory($root.'/layouts/errors', 0755, true);

        File::put($root.'/template.json', json_encode([
            'identifier' => 'test-stylesheet_link',
            'vendor' => 'test',
            'name' => ['ko' => 'Test Stylesheet Link', 'en' => 'Test Stylesheet Link'],
            'version' => '1.0.0',
            'license' => 'MIT',
            'description' => ['ko' => 'Test template', 'en' => 'Test template'],
            'type' => $type,
            'locales' => ['ko', 'en'],
            'dependencies' => ['modules' => [], 'plugins' => []],
            'assets' => [
                'css' => $css,
                'js' => ['dist/js/components.iife.js'],
            ],
            'components' => ['basic' => [], 'composite' => [], 'layout' => []],
            'error_config' => [
                'layouts' => [
                    '404' => 'errors/404',
                    '403' => 'errors/403',
                    '500' => 'errors/500',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        File::put($root.'/components.json', json_encode(['components' => []], JSON_PRETTY_PRINT));
        File::put($root.'/routes.json', json_encode([
            'routes' => [
                // 관리자 템플릿 경로는 `*/admin` 형식이다 — 없으면 `/admin` 이 404 로 렌더된다
                ['path' => $type === 'admin' ? '*/admin' : '/', 'layout' => 'home', 'auth_required' => false],
            ],
        ], JSON_PRETTY_PRINT));
        File::put($root.'/layouts/home.json', json_encode([
            'version' => '1.0.0',
            'layout_name' => 'home',
            'meta' => ['title' => 'Home'],
            'components' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put($root.'/dist/js/components.iife.js', '// test bundle');

        if ($withCssBuild) {
            File::makeDirectory($root.'/dist/css', 0755, true);
            File::put($root.'/dist/css/components.css', '/* test css */');
        }

        $errorLayout = json_encode([
            'version' => '1.0.0',
            'layout_name' => 'error_template',
            'meta' => ['title' => 'Error'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        foreach (['404', '403', '500'] as $code) {
            File::put($root.'/layouts/errors/'.$code.'.json', $errorLayout);
        }

        $templateManager = app(TemplateManagerInterface::class);
        $templateManager->loadTemplates();
        $templateManager->installTemplate('test-stylesheet_link');
        $templateManager->activateTemplate('test-stylesheet_link', true);
    }

    /**
     * 테스트 템플릿 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function deleteFixtureTemplate(): void
    {
        if (isset($this->fixtureTemplatePath) && File::exists($this->fixtureTemplatePath)) {
            File::deleteDirectory($this->fixtureTemplatePath);
        }
    }
}
