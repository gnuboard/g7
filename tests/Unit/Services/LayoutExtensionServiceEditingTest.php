<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\Traits\ComputesLayoutContentHash;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutExtensionVersion;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LayoutExtensionService 편집/버전관리 메서드 테스트
 *
 * 관리자 레이아웃 확장 편집 기능 (updateExtension, hasModifiedExtensions,
 * registerExtension preserveModified) 을 검증합니다.
 */
class LayoutExtensionServiceEditingTest extends TestCase
{
    use ComputesLayoutContentHash;
    use RefreshDatabase;

    /**
     * 같은 스위트의 레이아웃/GDPR 미들웨어 의존 테스트와 migrate:fresh 정합성을
     * 맞추기 위해 GDPR 플러그인 마이그레이션을 일관 선언한다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private LayoutExtensionService $service;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LayoutExtensionService::class);
        $this->template = Template::factory()->create([
            'identifier' => 'test-admin',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * updateExtension - content 갱신 + 버전 2건 생성
     */
    public function test_update_extension_saves_content_and_creates_two_versions(): void
    {
        $extension = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'content' => ['extension_point' => 'header', 'components' => []],
        ]);

        $newContent = ['extension_point' => 'header', 'components' => [['type' => 'basic', 'name' => 'Span']]];

        $updated = $this->service->updateExtension($extension->id, ['content' => $newContent]);

        $this->assertEquals($newContent, $updated->content);
        // 이전 버전 + 현재 버전 = 2건
        $this->assertEquals(2, TemplateLayoutExtensionVersion::where('extension_id', $extension->id)->count());
    }

    /**
     * updateExtension - original_content_hash 는 불변
     */
    public function test_update_extension_keeps_original_content_hash(): void
    {
        $originalContent = ['extension_point' => 'header', 'components' => []];
        $originalHash = $this->computeContentHash($originalContent);

        $extension = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'content' => $originalContent,
            'original_content_hash' => $originalHash,
            'original_content_size' => $this->computeContentSize($originalContent),
        ]);

        $this->service->updateExtension($extension->id, [
            'content' => ['extension_point' => 'header', 'components' => [['type' => 'basic', 'name' => 'Div']]],
        ]);

        $extension->refresh();
        // 수정 감지를 위해 원본 해시는 유지되어야 함
        $this->assertEquals($originalHash, $extension->original_content_hash);
    }

    /**
     * hasModifiedExtensions - 사용자 수정 확장 감지
     */
    public function test_has_modified_extensions_detects_user_edits(): void
    {
        // 미수정 확장 (현재 content 해시 = original)
        $unmodifiedContent = ['extension_point' => 'sidebar', 'components' => []];
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'content' => $unmodifiedContent,
            'original_content_hash' => $this->computeContentHash($unmodifiedContent),
        ]);

        // 수정된 확장 (현재 content 가 original 과 다름)
        $modified = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'content' => ['extension_point' => 'header', 'components' => [['type' => 'basic', 'name' => 'Span']]],
            'original_content_hash' => $this->computeContentHash(['extension_point' => 'header', 'components' => []]),
        ]);

        $result = $this->service->hasModifiedExtensions($this->template->id);

        $this->assertCount(1, $result);
        $this->assertEquals($modified->id, $result[0]['id']);
    }

    /**
     * registerExtension preserveModified=true - 사용자 수정 확장은 SKIP
     */
    public function test_register_extension_preserve_modified_skips_user_edited(): void
    {
        // 모듈이 처음 만든 원본
        $originalContent = ['extension_point' => 'header', 'priority' => 100, 'components' => []];
        $extension = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'header',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => $originalContent,
            'original_content_hash' => $this->computeContentHash($originalContent),
        ]);

        // 사용자가 편집 (현재 content 변경 → 해시 불일치 발생)
        $extension->update(['content' => ['extension_point' => 'header', 'priority' => 100, 'components' => [['type' => 'basic', 'name' => 'Span']]]]);

        // 모듈 업데이트 시 새 파일 content 로 register (preserveModified=true)
        $newFileContent = ['extension_point' => 'header', 'priority' => 200, 'components' => [['type' => 'basic', 'name' => 'Button']]];
        $result = $this->service->registerExtension(
            $newFileContent,
            LayoutSourceType::Module,
            'sirsoft-test',
            $this->template->id,
            true
        );

        $this->assertEquals('skipped', $result);

        // 사용자 수정 content 가 보존되어야 함 (덮어쓰기 안 됨)
        $extension->refresh();
        $this->assertEquals(
            [['type' => 'basic', 'name' => 'Span']],
            $extension->content['components']
        );
    }

    /**
     * registerExtension preserveModified=true - 미수정 확장은 덮어쓰기 + 해시 갱신
     */
    public function test_register_extension_preserve_modified_overwrites_unmodified(): void
    {
        $originalContent = ['extension_point' => 'header', 'priority' => 100, 'components' => []];
        $extension = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'header',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => $originalContent,
            'original_content_hash' => $this->computeContentHash($originalContent),
        ]);

        // 사용자 수정 없음 → 모듈 업데이트가 덮어씀
        $newFileContent = ['extension_point' => 'header', 'priority' => 200, 'components' => [['type' => 'basic', 'name' => 'Button']]];
        $result = $this->service->registerExtension(
            $newFileContent,
            LayoutSourceType::Module,
            'sirsoft-test',
            $this->template->id,
            true
        );

        $this->assertEquals('updated', $result);

        $extension->refresh();
        $this->assertEquals($newFileContent, $extension->content);
        // original_content_hash 도 새 파일 기준으로 갱신
        $this->assertEquals($this->computeContentHash($newFileContent), $extension->original_content_hash);
    }

    /**
     * registerExtension - 신규 생성 시 original_content_hash 함께 저장
     */
    public function test_register_extension_creates_with_original_hash(): void
    {
        $content = ['target_layout' => 'admin/dashboard', 'injections' => []];

        $result = $this->service->registerExtension(
            $content,
            LayoutSourceType::Plugin,
            'sirsoft-analytics',
            $this->template->id
        );

        $this->assertEquals('created', $result);

        $extension = LayoutExtension::where('template_id', $this->template->id)
            ->where('source_identifier', 'sirsoft-analytics')
            ->first();

        $this->assertNotNull($extension);
        $this->assertEquals($this->computeContentHash($content), $extension->original_content_hash);
    }

    /**
     * getExtensionsByTemplateId - 출처별 그룹핑
     */
    public function test_get_extensions_grouped_by_source(): void
    {
        LayoutExtension::factory()->count(2)->create([
            'template_id' => $this->template->id,
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'module-a',
        ]);
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'plugin-b',
        ]);

        $groups = $this->service->getExtensionsByTemplateId($this->template->id);

        $this->assertCount(2, $groups);
        $sourceIds = array_column($groups, 'source_identifier');
        $this->assertContains('module-a', $sourceIds);
        $this->assertContains('plugin-b', $sourceIds);
    }

    /**
     * getExtensionsByTemplateId - 템플릿 오버라이드 행에 is_override 플래그 부착
     *
     * 오버라이드 행은 트리에서 일반 확장과 동일하게 출처 기준으로 묶이되,
     * is_override=true 로 오버라이드 상황이 별도 표시되어야 한다.
     */
    public function test_get_extensions_marks_override_rows(): void
    {
        // 일반 플러그인 확장
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin/dashboard',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'plugin-b',
            'is_active' => true,
        ]);
        // 템플릿 오버라이드 행
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'address_search_slot',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => $this->template->identifier,
            'override_target' => 'some-plugin',
            'is_active' => true,
        ]);

        $groups = $this->service->getExtensionsByTemplateId($this->template->id);

        $allExtensions = collect($groups)->flatMap(fn ($g) => $g['extensions']);
        $override = $allExtensions->firstWhere('target_name', 'address_search_slot');
        $normal = $allExtensions->firstWhere('target_name', 'admin/dashboard');

        $this->assertNotNull($override);
        $this->assertTrue($override->is_override, '오버라이드 행에 is_override 플래그 누락');
        $this->assertNotNull($normal);
        $this->assertFalse($normal->is_override, '일반 확장에 is_override 가 true 로 설정됨');
    }

    /**
     * isExtensionApplicableToTemplate - overlay: 대상 레이아웃 존재 시 true
     */
    public function test_overlay_applicable_when_target_layout_exists(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'admin_user_detail',
        ]);

        $content = ['target_layout' => 'admin_user_detail', 'injections' => []];

        $this->assertTrue($this->service->isExtensionApplicableToTemplate($content, $this->template->id));
    }

    /**
     * isExtensionApplicableToTemplate - overlay: 대상 레이아웃 부재 시 false
     */
    public function test_overlay_not_applicable_when_target_layout_missing(): void
    {
        $content = ['target_layout' => 'admin_user_detail', 'injections' => []];

        // 해당 템플릿에 admin_user_detail 레이아웃이 없음
        $this->assertFalse($this->service->isExtensionApplicableToTemplate($content, $this->template->id));
    }

    /**
     * isExtensionApplicableToTemplate - extension_point: 확장점 정의 존재 시 true
     */
    public function test_extension_point_applicable_when_defined_in_layout(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'dashboard',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'dashboard',
                'components' => [
                    [
                        'type' => 'basic',
                        'name' => 'Div',
                        'children' => [
                            ['type' => 'extension_point', 'name' => 'admin.dashboard.widgets'],
                        ],
                    ],
                ],
            ],
        ]);

        $content = ['extension_point' => 'admin.dashboard.widgets', 'components' => []];

        $this->assertTrue($this->service->isExtensionApplicableToTemplate($content, $this->template->id));
    }

    /**
     * isExtensionApplicableToTemplate - extension_point: 정의 부재 시 false
     */
    public function test_extension_point_not_applicable_when_undefined(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'dashboard',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'dashboard',
                'components' => [['type' => 'basic', 'name' => 'Div']],
            ],
        ]);

        $content = ['extension_point' => 'admin.dashboard.widgets', 'components' => []];

        $this->assertFalse($this->service->isExtensionApplicableToTemplate($content, $this->template->id));
    }

    /**
     * removeInapplicableExtension - 동일 확장이 다른 템플릿엔 적용 가능할 때만 오등록 삭제.
     *
     * cross-template 가드: "이 템플릿엔 부재" 만으로 삭제하지 않는다. 같은 4키 확장이
     * 다른 템플릿(여기선 user, 대상 레이아웃 존재)엔 적용 가능하면 이 행이 오등록 → 삭제.
     */
    public function test_remove_inapplicable_extension_soft_deletes_orphan_row(): void
    {
        // user 템플릿엔 admin_user_detail 레이아웃 존재 + user 행 (= 다른 템플릿엔 적용 가능)
        $userTemplate = Template::factory()->create(['type' => 'user']);
        TemplateLayout::factory()->create([
            'template_id' => $userTemplate->id,
            'name' => 'admin_user_detail',
        ]);
        LayoutExtension::factory()->create([
            'template_id' => $userTemplate->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);

        // this->template(admin)엔 admin_user_detail 레이아웃 없음 → 오등록
        $orphan = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);

        $content = ['target_layout' => 'admin_user_detail', 'injections' => []];

        $removed = $this->service->removeInapplicableExtension(
            $content,
            LayoutSourceType::Module,
            'sirsoft-board',
            $this->template->id
        );

        $this->assertTrue($removed);
        $this->assertSoftDeleted('template_layout_extensions', ['id' => $orphan->id]);
    }

    /**
     * removeInapplicableExtension - 모든 템플릿에서 부재(전부 stale)면 삭제하지 않는다.
     *
     * cross-template 가드 핵심: 호스트 레이아웃이 전반적으로 구버전(슬롯 부재)이라 모든
     * 등록 템플릿에서 동시에 "부재" 가 될 때, 이는 오등록이 아니므로 정상 확장을 보존한다
     * (관리자 대시보드 위젯 소실 회귀 차단).
     */
    public function test_remove_inapplicable_extension_preserves_when_all_templates_stale(): void
    {
        // admin·user 양쪽에 같은 확장 등록 + 어느 템플릿에도 대상 레이아웃 없음(전부 부재)
        $userTemplate = Template::factory()->create(['type' => 'user']);
        $adminRow = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin_dashboard_commerce',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
        ]);
        LayoutExtension::factory()->create([
            'template_id' => $userTemplate->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin_dashboard_commerce',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
        ]);

        $content = ['extension_point' => 'admin_dashboard_commerce'];

        $removed = $this->service->removeInapplicableExtension(
            $content,
            LayoutSourceType::Module,
            'sirsoft-ecommerce',
            $this->template->id
        );

        // 다른 템플릿에서도 적용 불가(전부 stale) → 삭제 보류
        $this->assertFalse($removed);
        $this->assertNotSoftDeleted('template_layout_extensions', ['id' => $adminRow->id]);
    }

    /**
     * removeInapplicableExtension - 해당 행이 없으면 false
     */
    public function test_remove_inapplicable_extension_returns_false_when_no_row(): void
    {
        $content = ['target_layout' => 'admin_user_detail', 'injections' => []];

        $removed = $this->service->removeInapplicableExtension(
            $content,
            LayoutSourceType::Module,
            'sirsoft-board',
            $this->template->id
        );

        $this->assertFalse($removed);
    }
}
