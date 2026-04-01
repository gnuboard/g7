<?php

namespace Tests\Unit\Traits;

use App\Traits\FiltersFrontendSchema;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * FiltersFrontendSchema Trait 단위 테스트
 *
 * defaults.json에서 frontend_schema를 로드하고
 * expose 기반으로 설정을 필터링하는 기능을 테스트합니다.
 */
class FiltersFrontendSchemaTest extends TestCase
{
    use FiltersFrontendSchema;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = storage_path('app/testing/frontend_schema_test');

        if (! File::isDirectory($this->tempDir)) {
            File::makeDirectory($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    // ========================================================================
    // loadFrontendSchema() 테스트
    // ========================================================================

    /**
     * 경로가 null이면 빈 배열 반환
     */
    public function test_load_frontend_schema_returns_empty_when_path_null(): void
    {
        $result = $this->loadFrontendSchema(null);

        $this->assertSame([], $result);
    }

    /**
     * 파일이 존재하지 않으면 빈 배열 반환
     */
    public function test_load_frontend_schema_returns_empty_when_file_not_exists(): void
    {
        $result = $this->loadFrontendSchema($this->tempDir.'/nonexistent.json');

        $this->assertSame([], $result);
    }

    /**
     * 유효한 JSON에서 frontend_schema 섹션을 로드
     */
    public function test_load_frontend_schema_returns_schema_from_valid_json(): void
    {
        $defaultsPath = $this->tempDir.'/defaults.json';
        File::put($defaultsPath, json_encode([
            'defaults' => ['display_mode' => 'layer'],
            'frontend_schema' => [
                'display_mode' => ['expose' => true],
                'api_key' => ['expose' => false],
            ],
        ]));

        $result = $this->loadFrontendSchema($defaultsPath);

        $this->assertSame([
            'display_mode' => ['expose' => true],
            'api_key' => ['expose' => false],
        ], $result);
    }

    /**
     * JSON에 frontend_schema가 없으면 빈 배열 반환
     */
    public function test_load_frontend_schema_returns_empty_when_no_frontend_schema_key(): void
    {
        $defaultsPath = $this->tempDir.'/defaults.json';
        File::put($defaultsPath, json_encode([
            'defaults' => ['display_mode' => 'layer'],
        ]));

        $result = $this->loadFrontendSchema($defaultsPath);

        $this->assertSame([], $result);
    }

    /**
     * 잘못된 JSON이면 빈 배열 반환
     */
    public function test_load_frontend_schema_returns_empty_on_invalid_json(): void
    {
        $defaultsPath = $this->tempDir.'/defaults.json';
        File::put($defaultsPath, '{invalid json content');

        $result = $this->loadFrontendSchema($defaultsPath);

        $this->assertSame([], $result);
    }

    // ========================================================================
    // filterByFrontendSchema() 테스트
    // ========================================================================

    /**
     * 스키마가 비어있으면 빈 배열 반환
     */
    public function test_filter_by_frontend_schema_returns_empty_when_schema_empty(): void
    {
        $settings = ['display_mode' => 'layer', 'api_key' => 'secret'];

        $result = $this->filterByFrontendSchema($settings, []);

        $this->assertSame([], $result);
    }

    /**
     * 카테고리 expose 기반 필터링 (카테고리 구조)
     */
    public function test_filter_by_frontend_schema_filters_by_category_expose(): void
    {
        $settings = [
            'basic_info' => ['site_name' => 'Test', 'site_url' => 'http://test.com'],
            'secret_settings' => ['api_key' => 'secret123'],
        ];

        $frontendSchema = [
            'basic_info' => ['expose' => true],
            'secret_settings' => ['expose' => false],
        ];

        $result = $this->filterByFrontendSchema($settings, $frontendSchema);

        $this->assertArrayHasKey('basic_info', $result);
        $this->assertArrayNotHasKey('secret_settings', $result);
        $this->assertSame(['site_name' => 'Test', 'site_url' => 'http://test.com'], $result['basic_info']);
    }

    /**
     * 필드별 expose 기반 필터링
     */
    public function test_filter_by_frontend_schema_filters_by_field_expose(): void
    {
        $settings = [
            'payment' => [
                'currency' => 'KRW',
                'secret_key' => 'sk_live_xxx',
                'public_key' => 'pk_live_xxx',
            ],
        ];

        $frontendSchema = [
            'payment' => [
                'expose' => true,
                'fields' => [
                    'currency' => ['expose' => true],
                    'secret_key' => ['expose' => false],
                    'public_key' => ['expose' => true],
                ],
            ],
        ];

        $result = $this->filterByFrontendSchema($settings, $frontendSchema);

        $this->assertArrayHasKey('payment', $result);
        $this->assertSame('KRW', $result['payment']['currency']);
        $this->assertSame('pk_live_xxx', $result['payment']['public_key']);
        $this->assertArrayNotHasKey('secret_key', $result['payment']);
    }

    /**
     * fields 정의가 없으면 카테고리 전체 포함
     */
    public function test_filter_by_frontend_schema_includes_all_fields_when_no_fields_defined(): void
    {
        $settings = [
            'display' => [
                'mode' => 'layer',
                'width' => 500,
                'height' => 600,
            ],
        ];

        $frontendSchema = [
            'display' => ['expose' => true],
        ];

        $result = $this->filterByFrontendSchema($settings, $frontendSchema);

        $this->assertSame([
            'mode' => 'layer',
            'width' => 500,
            'height' => 600,
        ], $result['display']);
    }

    /**
     * fields가 빈 객체이면 아무 필드도 노출하지 않음
     */
    public function test_filter_by_frontend_schema_exposes_nothing_when_fields_empty(): void
    {
        $settings = [
            'basic_defaults' => [
                'type' => 'basic',
                'per_page' => 20,
                'blocked_keywords' => ['test'],
            ],
        ];

        $frontendSchema = [
            'basic_defaults' => [
                'expose' => true,
                'fields' => [],
            ],
        ];

        $result = $this->filterByFrontendSchema($settings, $frontendSchema);

        // fields: {} 이면 해당 카테고리가 결과에 포함되지 않아야 함
        $this->assertArrayNotHasKey('basic_defaults', $result);
    }

    /**
     * 설정에 없는 카테고리는 건너뛰기
     */
    public function test_filter_by_frontend_schema_skips_missing_categories(): void
    {
        $settings = [
            'basic_info' => ['site_name' => 'Test'],
        ];

        $frontendSchema = [
            'basic_info' => ['expose' => true],
            'nonexistent_category' => ['expose' => true],
        ];

        $result = $this->filterByFrontendSchema($settings, $frontendSchema);

        $this->assertArrayHasKey('basic_info', $result);
        $this->assertArrayNotHasKey('nonexistent_category', $result);
    }

    /**
     * 플러그인 flat 구조 설정에서 필터링 (카테고리 없이 키가 직접 노출)
     */
    public function test_filter_by_frontend_schema_works_with_flat_plugin_settings(): void
    {
        // 플러그인 설정은 flat 구조: { "display_mode": "layer", "popup_width": 500, ... }
        $settings = [
            'display_mode' => 'layer',
            'popup_width' => 500,
            'popup_height' => 600,
            'theme_color' => '#1D4ED8',
        ];

        // 각 키가 "카테고리"로 취급됨 (fields 없으므로 값이 그대로 포함/제외)
        $frontendSchema = [
            'display_mode' => ['expose' => true],
            'popup_width' => ['expose' => true],
            'popup_height' => ['expose' => true],
            'theme_color' => ['expose' => false],
        ];

        $result = $this->filterByFrontendSchema($settings, $frontendSchema);

        $this->assertSame('layer', $result['display_mode']);
        $this->assertSame(500, $result['popup_width']);
        $this->assertSame(600, $result['popup_height']);
        $this->assertArrayNotHasKey('theme_color', $result);
    }
}
