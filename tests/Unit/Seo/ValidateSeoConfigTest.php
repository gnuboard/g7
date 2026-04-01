<?php

namespace Tests\Unit\Seo;

use App\Extension\TemplateManager;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

class ValidateSeoConfigTest extends TestCase
{
    private TemplateManager $templateManager;

    private string $testTemplateDir;

    private string $templateId = 'test-seo-validate';

    /**
     * 테스트 초기화 - TemplateManager와 임시 디렉토리를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->templateManager = app(TemplateManager::class);
        $this->testTemplateDir = base_path("templates/{$this->templateId}");

        if (File::exists($this->testTemplateDir)) {
            File::deleteDirectory($this->testTemplateDir);
        }
        File::makeDirectory($this->testTemplateDir, 0755, true);
    }

    /**
     * 테스트 정리 - 임시 디렉토리를 삭제합니다.
     */
    protected function tearDown(): void
    {
        if (File::exists($this->testTemplateDir)) {
            File::deleteDirectory($this->testTemplateDir);
        }
        parent::tearDown();
    }

    /**
     * 올바른 seo-config.json → true를 반환합니다.
     */
    public function test_valid_seo_config_passes(): void
    {
        $this->writeSeoConfig([
            'stylesheets' => ['https://example.com/style.css'],
            'self_closing' => ['img', 'hr'],
            'render_modes' => [
                'image_gallery' => ['type' => 'iterate', 'source' => '$props_source', 'item_tag' => 'img'],
                'text_format' => ['type' => 'format'],
                'html_content' => ['type' => 'raw', 'source' => '$props_source'],
            ],
            'component_map' => [
                'Div' => ['tag' => 'div'],
                'Img' => ['tag' => 'img'],
                'ProductImageViewer' => ['tag' => 'div', 'render' => 'image_gallery', 'props_source' => 'images'],
                'StarRating' => ['tag' => 'span', 'render' => 'text_format', 'format' => '{value} / {max}'],
                'HtmlContent' => ['tag' => 'div', 'render' => 'html_content', 'props_source' => 'content'],
            ],
        ]);

        $this->assertTrue($this->callValidateSeoConfig());
    }

    /**
     * 잘못된 JSON → false를 반환합니다.
     */
    public function test_invalid_json_fails(): void
    {
        file_put_contents(
            $this->testTemplateDir.'/seo-config.json',
            '{ invalid json ,,, }'
        );

        $this->expectException(\Exception::class);
        $this->callValidateSeoConfig();
    }

    /**
     * component_map에서 미정의 render mode 참조 → false를 반환합니다.
     */
    public function test_undefined_render_mode_reference_fails(): void
    {
        $this->writeSeoConfig([
            'render_modes' => [
                'image_gallery' => ['type' => 'iterate'],
            ],
            'component_map' => [
                'MyComponent' => ['tag' => 'div', 'render' => 'nonexistent_mode'],
            ],
        ]);

        $this->expectException(\Exception::class);
        $this->callValidateSeoConfig();
    }

    /**
     * render_modes에 잘못된 type → false를 반환합니다.
     */
    public function test_invalid_render_mode_type_fails(): void
    {
        $this->writeSeoConfig([
            'render_modes' => [
                'my_mode' => ['type' => 'unknown_type'],
            ],
            'component_map' => [],
        ]);

        $this->expectException(\Exception::class);
        $this->callValidateSeoConfig();
    }

    /**
     * component_map에 tag 키 없음 → false를 반환합니다.
     */
    public function test_missing_tag_in_component_map_fails(): void
    {
        $this->writeSeoConfig([
            'component_map' => [
                'Div' => ['className' => 'wrapper'],
            ],
        ]);

        $this->expectException(\Exception::class);
        $this->callValidateSeoConfig();
    }

    /**
     * 파일 미존재 → true (경고만)를 반환합니다.
     */
    public function test_seo_config_not_found_passes_with_warning(): void
    {
        // seo-config.json을 생성하지 않음
        @unlink($this->testTemplateDir.'/seo-config.json');

        $this->assertTrue($this->callValidateSeoConfig());
    }

    /**
     * stylesheets가 문자열 → false를 반환합니다.
     */
    public function test_stylesheets_not_array_fails(): void
    {
        $this->writeSeoConfig([
            'stylesheets' => 'https://example.com/style.css',
            'component_map' => [
                'Div' => ['tag' => 'div'],
            ],
        ]);

        $this->expectException(\Exception::class);
        $this->callValidateSeoConfig();
    }

    /**
     * seo-config.json 파일을 작성합니다.
     *
     * @param  array  $config  설정 배열
     */
    private function writeSeoConfig(array $config): void
    {
        file_put_contents(
            $this->testTemplateDir.'/seo-config.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * protected validateSeoConfig()를 Reflection으로 호출합니다.
     *
     * @return bool 검증 결과
     */
    private function callValidateSeoConfig(): bool
    {
        $method = new ReflectionMethod(TemplateManager::class, 'validateSeoConfig');
        $method->setAccessible(true);

        return $method->invoke($this->templateManager, $this->templateId);
    }
}
