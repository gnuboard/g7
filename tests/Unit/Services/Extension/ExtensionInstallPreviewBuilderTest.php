<?php

namespace Tests\Unit\Services\Extension;

use App\Enums\ExtensionStatus;
use App\Enums\LanguagePackScope;
use App\Models\Template;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ExtensionInstallPreviewBuilder 의 cascade 프리뷰 응답 검증.
 *
 * 회귀: 템플릿 설치 모달에서 의존 확장의 식별자/이름/required_version 이 표시되지 않는 버그
 * — TemplateService::getTemplateInfo() 는 manifest 의 raw `{modules, plugins}` shape 를
 * 반환(TemplateResource 가 raw 를 사용해서 변경 불가)하므로, builder 단계에서 Template 스코프 한정으로
 * DependencyEnricher::enrich() 를 거쳐야 enriched 평면 배열로 변환된다.
 */
class ExtensionInstallPreviewBuilderTest extends TestCase
{
    use RefreshDatabase;

    private ExtensionInstallPreviewBuilder $builder;

    private string $testTemplatePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = app(ExtensionInstallPreviewBuilder::class);
        $this->testTemplatePath = base_path('templates/test-preview');

        if (File::exists($this->testTemplatePath)) {
            File::deleteDirectory($this->testTemplatePath);
        }
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testTemplatePath)) {
            File::deleteDirectory($this->testTemplatePath);
        }

        parent::tearDown();
    }

    private function createTestTemplate(array $dependencies): void
    {
        File::makeDirectory($this->testTemplatePath.'/layouts', 0755, true);

        File::put(
            $this->testTemplatePath.'/template.json',
            json_encode([
                'identifier' => 'test-preview',
                'vendor' => 'test',
                'name' => ['ko' => '테스트 프리뷰', 'en' => 'Test Preview'],
                'version' => '1.0.0',
                'type' => 'admin',
                'description' => ['ko' => '', 'en' => ''],
                'dependencies' => $dependencies,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // builder 가 TemplateService 를 통해 매니페스트를 다시 읽도록 매니저 캐시 갱신
        app(\App\Extension\TemplateManager::class)->loadTemplates();
    }

    public function test_template_dependencies_are_returned_as_enriched_flat_array(): void
    {
        $this->createTestTemplate([
            'modules' => ['missing-foo' => '>=1.0.0'],
            'plugins' => ['missing-bar' => '>=2.0.0'],
        ]);

        $preview = $this->builder->build(LanguagePackScope::Template, 'test-preview');

        $this->assertIsArray($preview['dependencies']);
        $this->assertCount(2, $preview['dependencies'], '의존성은 enriched 평면 배열이어야 함 (modules+plugins 합산)');

        $byId = [];
        foreach ($preview['dependencies'] as $dep) {
            $this->assertArrayHasKey('identifier', $dep);
            $this->assertArrayHasKey('name', $dep);
            $this->assertArrayHasKey('type', $dep);
            $this->assertArrayHasKey('required_version', $dep);
            $this->assertArrayHasKey('is_installed', $dep);
            $this->assertArrayHasKey('is_met', $dep);
            $this->assertArrayHasKey('default_selected', $dep);
            $byId[$dep['identifier']] = $dep;
        }

        $this->assertArrayHasKey('missing-foo', $byId);
        $this->assertSame('module', $byId['missing-foo']['type']);
        $this->assertSame('>=1.0.0', $byId['missing-foo']['required_version']);
        $this->assertFalse($byId['missing-foo']['is_installed']);
        $this->assertFalse($byId['missing-foo']['is_met']);
        $this->assertTrue($byId['missing-foo']['default_selected']);

        $this->assertArrayHasKey('missing-bar', $byId);
        $this->assertSame('plugin', $byId['missing-bar']['type']);
        $this->assertSame('>=2.0.0', $byId['missing-bar']['required_version']);
    }

    public function test_template_with_empty_dependencies_returns_empty_array(): void
    {
        $this->createTestTemplate([
            'modules' => [],
            'plugins' => [],
        ]);

        $preview = $this->builder->build(LanguagePackScope::Template, 'test-preview');

        $this->assertSame([], $preview['dependencies']);
    }
}
