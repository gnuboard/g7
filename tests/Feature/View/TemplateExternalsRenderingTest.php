<?php

namespace Tests\Feature\View;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Models\Template;
use App\Services\TemplateService;
use App\Support\AssetUrl;
use App\Support\TemplateExternals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TemplateExternalsRenderingTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureTemplatePath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(public_path('build/core/template-engine.min.js'))) {
            $this->markTestSkipped('Core template engine not built. Run npm run build to generate build files.');
        }

        $this->fixtureTemplatePath = base_path('templates/test-userexternals');
        $this->deleteFixtureTemplate();
    }

    protected function tearDown(): void
    {
        $this->deleteFixtureTemplate();

        parent::tearDown();
    }

    /**
     * @scenario asset_class=vendored, outcome=loaded
     *
     * @effects template_external_asset_field_resolves_to_asset_url
     */
    public function test_blade_partials_render_all_external_types_attributes_and_positions(): void
    {
        $externals = TemplateExternals::normalize($this->externalsFixture());

        $head = view('partials.template-externals-head', ['templateExternals' => $externals])->render();
        $beforeCore = view('partials.template-externals-scripts', [
            'templateExternals' => $externals,
            'position' => 'before-core',
        ])->render();
        $beforeTemplate = view('partials.template-externals-scripts', [
            'templateExternals' => $externals,
            'position' => 'before-template',
        ])->render();
        $bodyEnd = view('partials.template-externals-scripts', [
            'templateExternals' => $externals,
            'position' => 'body-end',
        ])->render();

        $this->assertSame(1, substr_count($head, 'rel="preconnect" href="https://cdn.example.com"'));
        $this->assertStringContainsString('rel="dns-prefetch" href="https://static.example.com"', $head);
        $this->assertStringContainsString('rel="stylesheet" href="https://cdn.example.com/main.css"', $head);
        $this->assertStringContainsString('id="style-main"', $head);
        $this->assertStringContainsString('integrity="sha384-style"', $head);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $head);
        $this->assertStringContainsString('media="screen"', $head);
        $this->assertStringContainsString('crossorigin="anonymous"', $head);
        $this->assertStringContainsString('rel="preload" href="https://cdn.example.com/font.woff2"', $head);
        $this->assertStringContainsString('as="font"', $head);
        $this->assertStringContainsString('type="font/woff2"', $head);
        $this->assertStringContainsString('fetchpriority="high"', $head);
        $this->assertStringContainsString('rel="modulepreload" href="https://cdn.example.com/module.js"', $head);
        $this->assertStringContainsString('src="https://cdn.example.com/head.js"', $head);
        $this->assertStringContainsString('async', $head);

        $this->assertBefore($head, 'rel="preconnect" href="https://cdn.example.com"', 'rel="stylesheet" href="https://cdn.example.com/main.css"');
        $this->assertStringContainsString('src="https://cdn.example.com/before-core.js"', $beforeCore);
        $this->assertStringContainsString('src="https://cdn.example.com/before-template.js"', $beforeTemplate);
        $this->assertStringContainsString('defer', $beforeTemplate);
        $this->assertStringContainsString('src="https://cdn.example.com/default-position.js"', $beforeTemplate);
        $this->assertStringContainsString('src="https://cdn.example.com/body-end.js"', $bodyEnd);
    }

    /**
     * @effects runtime_asset_served_same_origin, no_third_party_request_on_page_load
     */
    public function test_admin_response_renders_sirsoft_admin_basic_externals_before_template_css(): void
    {
        $templateService = app(TemplateService::class);
        $templateService->installTemplate('sirsoft-admin_basic');
        $template = Template::where('identifier', 'sirsoft-admin_basic')->firstOrFail();
        $templateService->activateTemplate($template->id);

        $response = $this->get('/admin');
        $response->assertStatus(200);

        $html = $response->getContent();

        // 자체 제공으로 전환됐다 (공개 #123) — 종전에는 이 세 자산이 각각 다른 외부 CDN 에서 왔다.
        $fontAwesome = 'vendor/font-awesome/6.4.0/css/all.inlined.css';
        $pretendard = 'vendor/pretendard/1.3.9/pretendard-variable.css';
        $flagIcons = 'vendor/flag-icons/7.2.3/css/flag-icons.min.css';

        foreach ([$fontAwesome, $pretendard, $flagIcons] as $asset) {
            $this->assertStringContainsString('/api/templates/assets/sirsoft-admin_basic/'.$asset, $html);
        }

        // externals 는 템플릿 자체 CSS 보다 먼저 온다 (종전 순서 계약 유지)
        $this->assertBefore(
            $html,
            '/api/templates/assets/sirsoft-admin_basic/'.$fontAwesome,
            '/api/templates/assets/sirsoft-admin_basic/css/components.css?v='
        );

        // 자체 제공 항목에는 외부 전용 힌트가 붙지 않는다 (자기 origin 에 preconnect 는 무의미)
        $this->assertStringNotContainsString('rel="preconnect"', $html);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $html);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
    }

    /**
     * @effects runtime_asset_served_same_origin
     */
    public function test_user_response_renders_template_externals_with_same_manifest_syntax(): void
    {
        $this->createFixtureTemplate('test-userexternals', 'user', [
            [
                'id' => 'user-style',
                'type' => 'style',
                'url' => 'https://cdn.example.com/user.css',
                'preconnect' => 'https://cdn.example.com',
            ],
            [
                'id' => 'user-script',
                'type' => 'script',
                'url' => 'https://cdn.example.com/user.js',
                'position' => 'body-end',
            ],
        ]);

        $templateManager = app(TemplateManagerInterface::class);
        $templateManager->loadTemplates();
        $templateManager->installTemplate('test-userexternals');
        $templateManager->activateTemplate('test-userexternals', true);

        $response = $this->get('/');
        $response->assertStatus(200);

        $html = $response->getContent();

        $this->assertStringContainsString('href="https://cdn.example.com/user.css"', $html);
        $this->assertStringContainsString('src="https://cdn.example.com/user.js"', $html);
        $this->assertStringContainsString('"templateType":"user"', $html);
        $this->assertBefore($html, 'href="https://cdn.example.com"', 'href="https://cdn.example.com/user.css"');
    }

    /**
     * `asset` 필드가 만드는 URL 은 자산 URL 모드 × 정적 게시 4조합 전부에서 same-origin 이다.
     *
     * 이 축이 이 작업의 잔여 리스크다 — 확장자를 정적 location 이 가로채는 서버에서는
     * `extensionless` 모드가 쓰이고, 그 조합에서 URL 형태가 달라진다. 어느 조합에서든
     * 외부 origin 이 새어 나가면 자체 제공 전환이 그 조합에서만 무효가 되는데,
     * 화면에는 "아이콘이 안 보인다" 로만 나타난다.
     *
     * 한계: 정적 게시 게이트(`AssetUrl::staticExtBase`)는 `production` 환경도 함께 요구하므로
     * 테스트 환경에서 `static=on` 다리는 실제 게시 경로(`/build/ext/{v}/…`)를 타지 않는다.
     * 여기서 고정하는 것은 **설정 축에서 URL 이 외부로 새지 않는다** 는 것까지이며,
     * 게시 경로 자체의 실제 형태는 브라우저 점검(T10-f/T10-g)이 맡는다.
     *
     * @scenario asset_class=vendored, outcome=loaded
     *
     * @effects runtime_asset_served_same_origin
     */
    public function test_asset_field_stays_same_origin_across_url_mode_and_static_cache(): void
    {
        $combinations = [
            ['mode' => 'extension', 'static' => false],
            ['mode' => 'extension', 'static' => true],
            ['mode' => 'extensionless', 'static' => false],
            ['mode' => 'extensionless', 'static' => true],
        ];

        try {
            foreach ($combinations as $combination) {
                AssetUrl::forceMode($combination['mode']);
                config(['core.static_cache.enabled' => $combination['static']]);
                AssetUrl::resetStaticExtBaseMemo();

                $normalized = TemplateExternals::normalize([
                    [
                        'id' => 'style-vendored',
                        'type' => 'style',
                        'asset' => 'vendor/font-awesome/6.4.0/css/all.inlined.css',
                    ],
                ], 'test-userexternals', 7);

                $label = "mode={$combination['mode']} static=".($combination['static'] ? 'on' : 'off');

                $this->assertCount(1, $normalized, "항목이 사라졌습니다 ({$label})");

                $url = $normalized[0]['url'];

                $this->assertDoesNotMatchRegularExpression(
                    '#^(https?:)?//#',
                    $url,
                    "외부 origin 으로 새어 나갔습니다 ({$label}): {$url}"
                );
                $this->assertStringStartsWith('/', $url, "same-origin 경로가 아닙니다 ({$label}): {$url}");
                $this->assertStringContainsString('all.inlined.css', rawurldecode($url), $label);

                // 외부 전용 키는 same-origin 항목에서 붙지 않는다 — preconnect 는 외부 origin
                // 을 미리 여는 장치라 자기 자신에게는 의미가 없다.
                $this->assertArrayNotHasKey('preconnect', $normalized[0], $label);
                $this->assertArrayNotHasKey('crossorigin', $normalized[0], $label);
            }
        } finally {
            AssetUrl::forceMode(null);
            config(['core.static_cache.enabled' => true]);
            AssetUrl::resetStaticExtBaseMemo();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function externalsFixture(): array
    {
        return [
            ['type' => 'preconnect', 'url' => 'https://cdn.example.com', 'crossorigin' => 'anonymous'],
            ['type' => 'dns-prefetch', 'url' => 'https://static.example.com'],
            [
                'id' => 'style-main',
                'type' => 'style',
                'url' => 'https://cdn.example.com/main.css',
                'preconnect' => 'https://cdn.example.com',
                'crossorigin' => true,
                'integrity' => 'sha384-style',
                'referrerpolicy' => 'no-referrer',
                'media' => 'screen',
            ],
            [
                'id' => 'font-main',
                'type' => 'webfont',
                'url' => 'https://fonts.example.com/font.css',
                'preconnect' => 'https://fonts.example.com',
                'crossorigin' => 'anonymous',
            ],
            [
                'id' => 'preload-font',
                'type' => 'preload',
                'url' => 'https://cdn.example.com/font.woff2',
                'as' => 'font',
                'mimeType' => 'font/woff2',
                'fetchpriority' => 'high',
                'crossorigin' => 'use-credentials',
            ],
            [
                'id' => 'module-main',
                'type' => 'modulepreload',
                'url' => 'https://cdn.example.com/module.js',
                'mimeType' => 'text/javascript',
                'fetchpriority' => 'auto',
            ],
            ['id' => 'script-head', 'type' => 'script', 'url' => 'https://cdn.example.com/head.js', 'position' => 'head', 'async' => true],
            ['id' => 'script-before-core', 'type' => 'script', 'url' => 'https://cdn.example.com/before-core.js', 'position' => 'before-core'],
            ['id' => 'script-before-template', 'type' => 'script', 'url' => 'https://cdn.example.com/before-template.js', 'position' => 'before-template', 'defer' => true],
            ['id' => 'script-default', 'type' => 'script', 'url' => 'https://cdn.example.com/default-position.js'],
            ['id' => 'script-body-end', 'type' => 'script', 'url' => 'https://cdn.example.com/body-end.js', 'position' => 'body-end'],
        ];
    }

    private function createFixtureTemplate(string $identifier, string $type, array $externals): void
    {
        File::makeDirectory($this->fixtureTemplatePath.'/dist/js', 0755, true);
        File::makeDirectory($this->fixtureTemplatePath.'/dist/css', 0755, true);
        File::makeDirectory($this->fixtureTemplatePath.'/layouts/errors', 0755, true);

        File::put($this->fixtureTemplatePath.'/template.json', json_encode([
            'identifier' => $identifier,
            'vendor' => 'test',
            'name' => ['ko' => 'Test User Externals', 'en' => 'Test User Externals'],
            'version' => '1.0.0',
            'license' => 'MIT',
            'description' => ['ko' => 'Test template', 'en' => 'Test template'],
            'type' => $type,
            'locales' => ['ko', 'en'],
            'dependencies' => ['modules' => [], 'plugins' => []],
            'assets' => [
                'css' => ['dist/css/components.css'],
                'js' => ['dist/js/components.iife.js'],
            ],
            'components' => ['basic' => [], 'composite' => [], 'layout' => []],
            // 에러 레이아웃 식별자는 디렉토리 접두사를 포함한다 (실제 템플릿과 동일 규약).
            // 접두사를 빼면 활성화 검증이 layouts/error_404.json 을 찾다가 실패한다.
            'error_config' => [
                'layouts' => [
                    '404' => 'errors/404',
                    '403' => 'errors/403',
                    '500' => 'errors/500',
                ],
            ],
            'externals' => $externals,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        File::put($this->fixtureTemplatePath.'/components.json', json_encode(['components' => []], JSON_PRETTY_PRINT));

        // 사용자 템플릿은 홈 라우트가 있어야 `/` 요청이 렌더된다 — 없으면 catch-all 이 404 를
        // 돌려주어 externals 가 실린 화면 자체를 검사할 수 없다.
        File::put($this->fixtureTemplatePath.'/routes.json', json_encode([
            'routes' => [
                ['path' => '/', 'layout' => 'home', 'auth_required' => false],
            ],
        ], JSON_PRETTY_PRINT));
        File::put($this->fixtureTemplatePath.'/layouts/home.json', json_encode([
            'version' => '1.0.0',
            'layout_name' => 'home',
            'meta' => ['title' => 'Home'],
            'components' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put($this->fixtureTemplatePath.'/dist/js/components.iife.js', '// test bundle');
        File::put($this->fixtureTemplatePath.'/dist/css/components.css', '/* test css */');
        $errorLayout = json_encode([
            'version' => '1.0.0',
            'layout_name' => 'error_template',
            'meta' => ['title' => 'Error'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        File::put($this->fixtureTemplatePath.'/layouts/errors/404.json', $errorLayout);
        File::put($this->fixtureTemplatePath.'/layouts/errors/403.json', $errorLayout);
        File::put($this->fixtureTemplatePath.'/layouts/errors/500.json', $errorLayout);
    }

    private function deleteFixtureTemplate(): void
    {
        if (isset($this->fixtureTemplatePath) && File::exists($this->fixtureTemplatePath)) {
            File::deleteDirectory($this->fixtureTemplatePath);
        }
    }

    private function assertBefore(string $html, string $before, string $after): void
    {
        $beforePosition = strpos($html, $before);
        $afterPosition = strpos($html, $after);

        $this->assertNotFalse($beforePosition, "Expected to find [{$before}].");
        $this->assertNotFalse($afterPosition, "Expected to find [{$after}].");
        $this->assertLessThan($afterPosition, $beforePosition, "Expected [{$before}] before [{$after}].");
    }
}
