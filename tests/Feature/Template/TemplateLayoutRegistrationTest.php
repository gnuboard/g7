<?php

namespace Tests\Feature\Template;

use App\Enums\LayoutSourceType;
use App\Extension\TemplateManager;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\Helpers\ProtectsExtensionDirectories;
use Tests\TestCase;

class TemplateLayoutRegistrationTest extends TestCase
{
    use ProtectsExtensionDirectories;
    use RefreshDatabase;

    protected TemplateManager $templateManager;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 레이아웃 JSON 파일 생성 (보호 활성화 전에 수행)
        $this->createTestLayoutFiles();

        // 확장 디렉토리 보호 활성화
        $this->setUpExtensionProtection();

        $this->templateManager = app(TemplateManager::class);
        $this->templateManager->loadTemplates();
    }

    protected function tearDown(): void
    {
        // 캐시 부작용 방지: 테스트 중 생성/삭제된 캐시를 정리
        // RefreshDatabase는 DB만 롤백하므로, 캐시는 수동 정리 필수
        Cache::flush();

        // 확장 디렉토리 보호 해제
        $this->tearDownExtensionProtection();

        // 테스트용 레이아웃 파일 정리
        $this->cleanupTestLayoutFiles();

        parent::tearDown();
    }

    /**
     * 템플릿 설치 시 레이아웃 JSON이 DB에 등록되는지 테스트
     */
    public function test_install_template_registers_layouts(): void
    {
        // Act
        $result = $this->templateManager->installTemplate('sirsoft-admin_basic');

        // Assert
        $this->assertTrue($result);

        $template = Template::where('identifier', 'sirsoft-admin_basic')->first();
        $this->assertNotNull($template);

        // 레이아웃이 등록되었는지 확인
        $layouts = TemplateLayout::where('template_id', $template->id)->get();
        $this->assertGreaterThan(0, $layouts->count(), '레이아웃이 최소 1개 이상 등록되어야 합니다.');

        // 특정 레이아웃 확인
        $adminBaseLayout = TemplateLayout::where('template_id', $template->id)
            ->where('name', 'test_layout')
            ->first();
        $this->assertNotNull($adminBaseLayout, 'test_layout 레이아웃이 등록되어야 합니다.');
        $this->assertIsArray($adminBaseLayout->content);
        $this->assertEquals('test_layout', $adminBaseLayout->content['layout_name']);
    }

    /**
     * 템플릿 제거 시 레이아웃이 DB에서 삭제되는지 테스트
     */
    public function test_uninstall_template_removes_layouts(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');
        $template = Template::where('identifier', 'sirsoft-admin_basic')->first();

        $layoutsBeforeUninstall = TemplateLayout::where('template_id', $template->id)->count();
        $this->assertGreaterThan(0, $layoutsBeforeUninstall);

        // Act (spy가 활성 디렉토리 삭제 차단)
        $result = $this->templateManager->uninstallTemplate('sirsoft-admin_basic');

        // Assert
        $this->assertTrue($result);

        $layoutsAfterUninstall = TemplateLayout::where('template_id', $template->id)->count();
        $this->assertEquals(0, $layoutsAfterUninstall, '템플릿 제거 시 모든 레이아웃이 삭제되어야 합니다.');
    }

    /**
     * 활성화/비활성화 시 템플릿 자체 레이아웃에 영향 없음 확인
     *
     * activateTemplate()은 활성 모듈/플러그인 레이아웃을 해당 템플릿에 추가 등록하므로
     * 전체 레이아웃 수는 증가할 수 있지만, 템플릿 자체(source_type=template) 레이아웃은 변하지 않아야 합니다.
     */
    public function test_activate_deactivate_template_does_not_affect_layouts(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');
        $template = Template::where('identifier', 'sirsoft-admin_basic')->first();

        $templateLayoutsBefore = TemplateLayout::where('template_id', $template->id)
            ->where('source_type', LayoutSourceType::Template)
            ->count();
        $this->assertGreaterThan(0, $templateLayoutsBefore, '설치 후 템플릿 자체 레이아웃이 존재해야 합니다.');

        // Act - 활성화
        $this->templateManager->activateTemplate('sirsoft-admin_basic');

        // Assert - 템플릿 자체 레이아웃 수 불변
        $templateLayoutsAfterActivate = TemplateLayout::where('template_id', $template->id)
            ->where('source_type', LayoutSourceType::Template)
            ->count();
        $this->assertEquals($templateLayoutsBefore, $templateLayoutsAfterActivate, '활성화 시 템플릿 자체 레이아웃 수는 변경되지 않아야 합니다.');

        // Assert - 모듈/플러그인 레이아웃이 추가 등록될 수 있음 (정상 동작)
        $totalAfterActivate = TemplateLayout::where('template_id', $template->id)->count();
        $this->assertGreaterThanOrEqual($templateLayoutsBefore, $totalAfterActivate, '활성화 시 모듈/플러그인 레이아웃이 추가 등록될 수 있습니다.');

        // Act - 비활성화
        $this->templateManager->deactivateTemplate('sirsoft-admin_basic');

        // Assert - 템플릿 자체 레이아웃 수 불변
        $templateLayoutsAfterDeactivate = TemplateLayout::where('template_id', $template->id)
            ->where('source_type', LayoutSourceType::Template)
            ->count();
        $this->assertEquals($templateLayoutsBefore, $templateLayoutsAfterDeactivate, '비활성화 시 템플릿 자체 레이아웃 수는 변경되지 않아야 합니다.');
    }

    /**
     * 멱등성 테스트: 같은 템플릿 2번 설치 시 레이아웃 중복 생성 방지
     */
    public function test_install_template_twice_does_not_duplicate_layouts(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');
        $template = Template::where('identifier', 'sirsoft-admin_basic')->first();

        $layoutsAfterFirstInstall = TemplateLayout::where('template_id', $template->id)->count();

        // Act - 2번째 설치
        $this->templateManager->installTemplate('sirsoft-admin_basic');

        // Assert
        $layoutsAfterSecondInstall = TemplateLayout::where('template_id', $template->id)->count();
        $this->assertEquals(
            $layoutsAfterFirstInstall,
            $layoutsAfterSecondInstall,
            '같은 템플릿을 2번 설치해도 레이아웃 수는 동일해야 합니다 (updateOrCreate 멱등성).'
        );
    }

    /**
     * 레이아웃 디렉토리(에러 레이아웃 포함)가 없으면 템플릿 설치 실패
     *
     * 템플릿에는 error_config의 에러 레이아웃 파일이 필수이므로,
     * 레이아웃 디렉토리가 없으면 설치가 실패해야 합니다.
     */
    public function test_install_template_without_layouts_directory_fails(): void
    {
        // Arrange - 레이아웃 디렉토리 제거
        $layoutsPath = base_path('templates/sirsoft-admin_basic/layouts');
        $originalPath = $layoutsPath.'_backup_'.time();

        if (File::exists($layoutsPath)) {
            File::moveDirectory($layoutsPath, $originalPath);
        }

        try {
            // Act & Assert - 레이아웃 디렉토리가 없으면 에러 레이아웃도 없으므로 예외 발생
            $this->expectException(\Exception::class);
            $this->templateManager->installTemplate('sirsoft-admin_basic');
        } finally {
            // Cleanup - 원래 디렉토리 복원
            if (File::exists($originalPath)) {
                File::moveDirectory($originalPath, $layoutsPath);
            }
        }
    }

    /**
     * 테스트용 레이아웃 JSON 파일 생성
     */
    protected function createTestLayoutFiles(): void
    {
        $layoutsPath = base_path('templates/sirsoft-admin_basic/layouts');

        if (! File::exists($layoutsPath)) {
            File::makeDirectory($layoutsPath, 0755, true);
        }

        // 테스트용 레이아웃 JSON 생성
        $testLayout = [
            'version' => '1.0.0',
            'layout_name' => 'test_layout',
            'meta' => [
                'title' => 'Test Layout',
                'description' => 'Test layout for automated tests',
            ],
            'components' => [
                [
                    'id' => 'root',
                    'type' => 'basic',
                    'name' => 'div',
                    'props' => [
                        'className' => 'container',
                    ],
                ],
            ],
        ];

        File::put(
            "{$layoutsPath}/test_layout.json",
            json_encode($testLayout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 테스트용 레이아웃 파일 정리
     */
    protected function cleanupTestLayoutFiles(): void
    {
        $layoutsPath = base_path('templates/sirsoft-admin_basic/layouts');
        $testLayoutFile = "{$layoutsPath}/test_layout.json";

        if (File::exists($testLayoutFile)) {
            File::delete($testLayoutFile);
        }
    }
}
