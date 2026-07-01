<?php

namespace Tests\Feature\View;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Models\Template;
use App\Services\TemplateService;
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

    public function test_admin_response_renders_sirsoft_admin_basic_externals_before_template_css(): void
    {
        $templateService = app(TemplateService::class);
        $templateService->installTemplate('sirsoft-admin_basic');
        $template = Template::where('identifier', 'sirsoft-admin_basic')->firstOrFail();
        $templateService->activateTemplate($template->id);

        $response = $this->get('/admin');
        $response->assertStatus(200);

        $html = $response->getContent();

        $this->assertStringContainsString('href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"', $html);
        $this->assertStringContainsString('href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.min.css"', $html);
        $this->assertStringContainsString('href="https://cdn.jsdelivr.net/npm/flag-icons@7.2.3/css/flag-icons.min.css"', $html);
        $this->assertBefore($html, 'href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"', '/api/templates/assets/sirsoft-admin_basic/css/components.css?v=');
        $this->assertBefore($html, 'href="https://cdn.jsdelivr.net"', 'href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.min.css"');
    }

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
        $this->assertStringContainsString("templateType: 'user'", $html);
        $this->assertBefore($html, 'href="https://cdn.example.com"', 'href="https://cdn.example.com/user.css"');
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
            'error_config' => [
                'layouts' => [
                    '404' => 'error_404',
                    '403' => 'error_403',
                    '500' => 'error_500',
                ],
            ],
            'externals' => $externals,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        File::put($this->fixtureTemplatePath.'/components.json', json_encode(['components' => []], JSON_PRETTY_PRINT));
        File::put($this->fixtureTemplatePath.'/routes.json', json_encode(['routes' => []], JSON_PRETTY_PRINT));
        File::put($this->fixtureTemplatePath.'/dist/js/components.iife.js', '// test bundle');
        File::put($this->fixtureTemplatePath.'/dist/css/components.css', '/* test css */');
        $errorLayout = json_encode([
            'version' => '1.0.0',
            'layout_name' => 'error_template',
            'meta' => ['title' => 'Error'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        File::put($this->fixtureTemplatePath.'/layouts/errors/error_404.json', $errorLayout);
        File::put($this->fixtureTemplatePath.'/layouts/errors/error_403.json', $errorLayout);
        File::put($this->fixtureTemplatePath.'/layouts/errors/error_500.json', $errorLayout);
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
