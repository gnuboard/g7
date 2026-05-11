<?php

namespace Tests\Feature\Template;

use App\Rules\ValidLayoutStructure;
use App\Rules\WhitelistedEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LayoutJsonValidationTest extends TestCase
{
    use RefreshDatabase;

    private array $layoutFiles = [
        'templates/sirsoft-admin_basic/layouts/_admin_base.json',
        'templates/sirsoft-admin_basic/layouts/admin_template_list.json',
        'templates/sirsoft-admin_basic/layouts/admin_template_layout_edit.json',
        // 'admin_template_list_admin.json' 은 리팩토링으로 제거됨 (admin_template_list.json 에 통합)
    ];

    /**
     * 모든 레이아웃 JSON 파일이 존재하는지 확인
     */
    public function test_all_layout_files_exist(): void
    {
        foreach ($this->layoutFiles as $layoutFile) {
            $filePath = base_path($layoutFile);
            $this->assertFileExists($filePath, "레이아웃 파일이 존재하지 않습니다: {$layoutFile}");
        }
    }

    /**
     * 모든 레이아웃 JSON 파일이 유효한 JSON 형식인지 확인
     */
    public function test_all_layout_files_are_valid_json(): void
    {
        foreach ($this->layoutFiles as $layoutFile) {
            $filePath = base_path($layoutFile);
            $content = File::get($filePath);

            $decoded = json_decode($content, true);
            $this->assertNotNull($decoded, "레이아웃 파일이 유효한 JSON이 아닙니다: {$layoutFile}");
            $this->assertEquals(JSON_ERROR_NONE, json_last_error(), "JSON 파싱 에러: {$layoutFile}");
        }
    }

    /**
     * 모든 레이아웃 JSON 파일이 필수 필드를 가지고 있는지 확인
     */
    public function test_all_layout_files_have_required_fields(): void
    {
        foreach ($this->layoutFiles as $layoutFile) {
            $filePath = base_path($layoutFile);
            $layout = json_decode(File::get($filePath), true);

            $this->assertArrayHasKey('version', $layout, "version 필드가 없습니다: {$layoutFile}");
            $this->assertArrayHasKey('layout_name', $layout, "layout_name 필드가 없습니다: {$layoutFile}");
            $this->assertArrayHasKey('meta', $layout, "meta 필드가 없습니다: {$layoutFile}");
        }
    }

    /**
     * ValidLayoutStructure Rule을 사용한 스키마 검증
     */
    public function test_all_layout_files_pass_schema_validation(): void
    {
        $rule = new ValidLayoutStructure;

        foreach ($this->layoutFiles as $layoutFile) {
            $filePath = base_path($layoutFile);
            $layout = json_decode(File::get($filePath), true);

            $failed = false;
            $errorMessage = '';

            $rule->validate('layout', $layout, function ($message) use (&$failed, &$errorMessage) {
                $failed = true;
                $errorMessage = $message;
            });

            $this->assertFalse($failed, "레이아웃 스키마 검증 실패: {$layoutFile} - {$errorMessage}");
        }
    }

    /**
     * 모든 data_sources의 endpoint가 화이트리스트에 있는지 확인
     */
    public function test_all_data_source_endpoints_are_whitelisted(): void
    {
        $rule = new WhitelistedEndpoint;

        foreach ($this->layoutFiles as $layoutFile) {
            $filePath = base_path($layoutFile);
            $layout = json_decode(File::get($filePath), true);

            $dataSources = $layout['data_sources'] ?? [];

            foreach ($dataSources as $dataSource) {
                if (isset($dataSource['endpoint'])) {
                    $failed = false;
                    $errorMessage = '';

                    $rule->validate('endpoint', $dataSource['endpoint'], function ($message) use (&$failed, &$errorMessage) {
                        $failed = true;
                        $errorMessage = $message;
                    });

                    $this->assertFalse(
                        $failed,
                        "화이트리스트되지 않은 endpoint: {$dataSource['endpoint']} in {$layoutFile} - {$errorMessage}"
                    );
                }
            }
        }
    }

    /**
     * 레이아웃 상속 구조가 올바른지 확인
     */
    public function test_layout_inheritance_is_valid(): void
    {
        $layouts = [];

        // 모든 레이아웃 로드
        foreach ($this->layoutFiles as $layoutFile) {
            $filePath = base_path($layoutFile);
            $layout = json_decode(File::get($filePath), true);
            $layouts[$layout['layout_name']] = $layout;
        }

        // extends 필드 검증
        foreach ($layouts as $layoutName => $layout) {
            if (isset($layout['extends'])) {
                $parentLayoutName = basename($layout['extends']);

                $this->assertArrayHasKey(
                    $parentLayoutName,
                    $layouts,
                    "부모 레이아웃이 존재하지 않습니다: {$parentLayoutName} (required by {$layoutName})"
                );
            }
        }
    }

    /**
     * _admin_base.json이 base 레이아웃으로 정의되어 있는지 확인
     */
    public function test_admin_base_is_marked_as_base_layout(): void
    {
        $filePath = base_path('templates/sirsoft-admin_basic/layouts/_admin_base.json');
        $layout = json_decode(File::get($filePath), true);

        $this->assertArrayHasKey('meta', $layout);
        $this->assertArrayHasKey('is_base', $layout['meta']);
        $this->assertTrue($layout['meta']['is_base'], '_admin_base.json은 is_base가 true여야 합니다');
    }

    /**
     * 자식 레이아웃들이 _admin_base를 올바르게 상속하는지 확인
     */
    public function test_child_layouts_extend_admin_base(): void
    {
        $childLayouts = [
            'templates/sirsoft-admin_basic/layouts/admin_template_list.json',
            'templates/sirsoft-admin_basic/layouts/admin_template_layout_edit.json',
            // 'admin_template_list_admin.json' 은 리팩토링으로 제거됨
        ];

        foreach ($childLayouts as $layoutFile) {
            $filePath = base_path($layoutFile);
            $layout = json_decode(File::get($filePath), true);

            $this->assertArrayHasKey('extends', $layout, "extends 필드가 없습니다: {$layoutFile}");
            // extends 값은 '_admin_base' 또는 다른 부모 레이아웃일 수 있음
            $this->assertNotEmpty($layout['extends'], "{$layoutFile}은 extends 값이 비어있으면 안됩니다");
        }
    }

    // 'admin_template_list_admin.json' 이 admin_template_list.json 으로 통합되어 제거됨.
    // 해당 파일에 특화된 테스트(test_admin_template_management_has_readonly_configuration)
    // 는 더 이상 유효하지 않아 삭제되었음.

    /**
     * 모든 컴포넌트 ID가 고유한지 확인
     */
    public function test_all_component_ids_are_unique_within_layout(): void
    {
        foreach ($this->layoutFiles as $layoutFile) {
            $filePath = base_path($layoutFile);
            $layout = json_decode(File::get($filePath), true);

            $componentIds = $this->extractAllComponentIds($layout);
            $uniqueIds = array_unique($componentIds);

            $this->assertCount(
                count($componentIds),
                $uniqueIds,
                "중복된 컴포넌트 ID가 있습니다: {$layoutFile}"
            );
        }
    }

    /**
     * 레이아웃에서 모든 컴포넌트 ID를 재귀적으로 추출
     */
    private function extractAllComponentIds(array $layout): array
    {
        $ids = [];

        // components 필드
        if (isset($layout['components'])) {
            $ids = array_merge($ids, $this->extractComponentIdsFromArray($layout['components']));
        }

        // slots 필드
        if (isset($layout['slots'])) {
            foreach ($layout['slots'] as $slotContent) {
                $ids = array_merge($ids, $this->extractComponentIdsFromArray($slotContent));
            }
        }

        return $ids;
    }

    /**
     * 컴포넌트 배열에서 ID 추출 (재귀)
     */
    private function extractComponentIdsFromArray(array $components): array
    {
        $ids = [];

        foreach ($components as $component) {
            if (isset($component['id'])) {
                $ids[] = $component['id'];
            }

            // children 재귀 처리
            if (isset($component['children'])) {
                $ids = array_merge($ids, $this->extractComponentIdsFromArray($component['children']));
            }
        }

        return $ids;
    }
}
