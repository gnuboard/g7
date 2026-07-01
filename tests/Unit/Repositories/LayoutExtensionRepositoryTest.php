<?php

namespace Tests\Unit\Repositories;

use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LayoutExtensionRepository 단위 테스트
 */
class LayoutExtensionRepositoryTest extends TestCase
{
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

    private LayoutExtensionRepositoryInterface $repository;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(LayoutExtensionRepositoryInterface::class);
        $this->template = Template::factory()->create();
    }

    /**
     * Extension Point 조회 테스트
     */
    public function test_get_by_extension_point(): void
    {
        // Arrange
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-top',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'is_active' => true,
        ]);

        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-bottom', // 다른 확장점
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'is_active' => true,
        ]);

        // Act
        $result = $this->repository->getByExtensionPoint($this->template->id, 'sidebar-top');

        // Assert
        $this->assertCount(1, $result);
        $this->assertEquals('sidebar-top', $result->first()->target_name);
    }

    /**
     * Extension Point 우선순위 정렬 테스트
     */
    public function test_get_by_extension_point_ordered_by_priority(): void
    {
        // Arrange
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-top',
            'source_identifier' => 'low-priority',
            'priority' => 50,
            'is_active' => true,
        ]);

        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-top',
            'source_identifier' => 'high-priority',
            'priority' => 10,
            'is_active' => true,
        ]);

        // Act
        $result = $this->repository->getByExtensionPoint($this->template->id, 'sidebar-top');

        // Assert
        $this->assertCount(2, $result);
        $this->assertEquals('high-priority', $result->first()->source_identifier);
        $this->assertEquals('low-priority', $result->last()->source_identifier);
    }

    /**
     * 비활성 Extension Point 제외 테스트
     */
    public function test_get_by_extension_point_excludes_inactive(): void
    {
        // Arrange
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-top',
            'is_active' => true,
        ]);

        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-top',
            'is_active' => false,
        ]);

        // Act
        $result = $this->repository->getByExtensionPoint($this->template->id, 'sidebar-top');

        // Assert
        $this->assertCount(1, $result);
    }

    /**
     * Overlay 조회 테스트
     */
    public function test_get_overlays_by_layout(): void
    {
        // Arrange
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin/dashboard',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'is_active' => true,
        ]);

        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint, // 다른 타입
            'target_name' => 'admin/dashboard',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-other',
            'is_active' => true,
        ]);

        // Act
        $result = $this->repository->getOverlaysByLayout($this->template->id, 'admin/dashboard');

        // Assert
        $this->assertCount(1, $result);
        $this->assertEquals(LayoutExtensionType::Overlay, $result->first()->extension_type);
    }

    /**
     * 확장 생성 테스트
     */
    public function test_create_extension(): void
    {
        // Arrange
        $data = [
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'header-actions',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-analytics',
            'content' => ['component' => ['type' => 'basic', 'name' => 'Button']],
            'priority' => 20,
            'is_active' => true,
        ];

        // Act
        $result = $this->repository->create($data);

        // Assert
        $this->assertInstanceOf(LayoutExtension::class, $result);
        $this->assertEquals('header-actions', $result->target_name);
        $this->assertEquals(LayoutSourceType::Plugin, $result->source_type);
        $this->assertDatabaseHas('template_layout_extensions', [
            'target_name' => 'header-actions',
            'source_identifier' => 'sirsoft-analytics',
        ]);
    }

    /**
     * 출처별 soft delete 테스트
     */
    public function test_soft_delete_by_source(): void
    {
        // Arrange
        LayoutExtension::factory()->count(3)->create([
            'template_id' => $this->template->id,
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
        ]);

        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-other',
        ]);

        // Act
        $deleted = $this->repository->softDeleteBySource(
            LayoutSourceType::Module,
            'sirsoft-ecommerce'
        );

        // Assert
        $this->assertEquals(3, $deleted);
        $this->assertEquals(1, LayoutExtension::count());
        $this->assertEquals(3, LayoutExtension::onlyTrashed()->count());
    }

    /**
     * 출처별 복원 테스트
     */
    public function test_restore_by_source(): void
    {
        // Arrange
        LayoutExtension::factory()->count(2)->create([
            'template_id' => $this->template->id,
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
        ]);

        $this->repository->softDeleteBySource(LayoutSourceType::Module, 'sirsoft-ecommerce');
        $this->assertEquals(0, LayoutExtension::count());

        // Act
        $restored = $this->repository->restoreBySource(LayoutSourceType::Module, 'sirsoft-ecommerce');

        // Assert
        $this->assertEquals(2, $restored);
        $this->assertEquals(2, LayoutExtension::count());
    }

    /**
     * 출처별 영구 삭제 테스트
     */
    public function test_force_delete_by_source(): void
    {
        // Arrange
        LayoutExtension::factory()->count(2)->create([
            'template_id' => $this->template->id,
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
        ]);

        // Soft delete first
        $this->repository->softDeleteBySource(LayoutSourceType::Module, 'sirsoft-ecommerce');

        // Act
        $deleted = $this->repository->forceDeleteBySource(LayoutSourceType::Module, 'sirsoft-ecommerce');

        // Assert
        $this->assertEquals(2, $deleted);
        $this->assertEquals(0, LayoutExtension::withTrashed()->count());
    }

    /**
     * Extension Point 템플릿 오버라이드 조회 테스트
     */
    public function test_find_template_override_for_extension_point(): void
    {
        // Arrange
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-top',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'sirsoft-admin_basic',
            'override_target' => 'sirsoft-ecommerce',
            'is_active' => true,
        ]);

        // Act
        $result = $this->repository->findTemplateOverrideForExtensionPoint(
            $this->template->id,
            'sidebar-top',
            'sirsoft-ecommerce'
        );

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals('sirsoft-ecommerce', $result->override_target);
        $this->assertEquals(LayoutSourceType::Template, $result->source_type);
    }

    /**
     * Extension Point 템플릿 오버라이드 없음 테스트
     */
    public function test_find_template_override_for_extension_point_returns_null_when_not_found(): void
    {
        // Arrange
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-top',
            'source_type' => LayoutSourceType::Module, // 모듈 출처 (템플릿 아님)
            'source_identifier' => 'sirsoft-ecommerce',
            'is_active' => true,
        ]);

        // Act
        $result = $this->repository->findTemplateOverrideForExtensionPoint(
            $this->template->id,
            'sidebar-top',
            'sirsoft-ecommerce'
        );

        // Assert
        $this->assertNull($result);
    }

    /**
     * Overlay 템플릿 오버라이드 조회 테스트
     */
    public function test_find_template_override_for_overlay(): void
    {
        // Arrange
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin/settings',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'sirsoft-admin_basic',
            'override_target' => 'sirsoft-ecommerce',
            'is_active' => true,
        ]);

        // Act
        $result = $this->repository->findTemplateOverrideForOverlay(
            $this->template->id,
            'admin/settings',
            'sirsoft-ecommerce'
        );

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals(LayoutExtensionType::Overlay, $result->extension_type);
    }

    /**
     * 오버라이드 고려한 Extension Point 조회 테스트
     */
    public function test_get_resolved_extension_points(): void
    {
        // Arrange - 모듈 확장
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-top',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'priority' => 50,
            'is_active' => true,
        ]);

        // Arrange - 다른 모듈 확장
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-top',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-other',
            'priority' => 40,
            'is_active' => true,
        ]);

        // Arrange - 템플릿 오버라이드 (sirsoft-ecommerce 확장을 오버라이드)
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-top',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'sirsoft-admin_basic',
            'override_target' => 'sirsoft-ecommerce',
            'priority' => 10,
            'is_active' => true,
        ]);

        // Act
        $result = $this->repository->getResolvedExtensionPoints($this->template->id, 'sidebar-top');

        // Assert - sirsoft-ecommerce 모듈 확장은 제외되고, 템플릿 오버라이드와 sirsoft-other만 남음
        $this->assertCount(2, $result);

        $identifiers = $result->pluck('source_identifier')->toArray();
        $this->assertContains('sirsoft-admin_basic', $identifiers);
        $this->assertContains('sirsoft-other', $identifiers);
        $this->assertNotContains('sirsoft-ecommerce', $identifiers);
    }

    /**
     * 오버라이드 고려한 Overlay 조회 테스트
     */
    public function test_get_resolved_overlays(): void
    {
        // Arrange - 모듈 오버레이
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin/dashboard',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'priority' => 50,
            'is_active' => true,
        ]);

        // Arrange - 템플릿 오버라이드
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin/dashboard',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'sirsoft-admin_basic',
            'override_target' => 'sirsoft-ecommerce',
            'priority' => 10,
            'is_active' => true,
        ]);

        // Act
        $result = $this->repository->getResolvedOverlays($this->template->id, 'admin/dashboard');

        // Assert
        $this->assertCount(1, $result);
        $this->assertEquals(LayoutSourceType::Template, $result->first()->source_type);
    }

    /**
     * 템플릿 ID로 모든 확장 조회 테스트
     */
    public function test_get_by_template_id(): void
    {
        // Arrange
        $otherTemplate = Template::factory()->create();

        LayoutExtension::factory()->count(3)->create([
            'template_id' => $this->template->id,
            'is_active' => true,
        ]);

        LayoutExtension::factory()->count(2)->create([
            'template_id' => $otherTemplate->id,
            'is_active' => true,
        ]);

        // Act
        $result = $this->repository->getByTemplateId($this->template->id);

        // Assert
        $this->assertCount(3, $result);
        $result->each(function ($extension) {
            $this->assertEquals($this->template->id, $extension->template_id);
        });
    }

    /**
     * 오버라이드 해석 조회 시 템플릿 오버라이드에 가려진 모듈/플러그인 확장은 제외됨
     *
     * 회귀: 레이아웃 편집 화면 좌측 트리(getResolvedByTemplateId 기반)가
     * 화면에 실제 적용되지 않는 "가려진 행"을 노출하여, 관리자가 그 행을
     * 편집해도 반영되지 않던 문제. 렌더링 경로(getResolvedExtensionPoints/
     * getResolvedOverlays)와 동일한 오버라이드 해석을 적용해야 한다.
     */
    public function test_get_resolved_by_template_id_excludes_overridden_extensions(): void
    {
        // Arrange - 플러그인 Extension Point 확장
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'address_search_slot',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-daum_postcode',
            'priority' => 100,
            'is_active' => true,
        ]);

        // Arrange - 템플릿 오버라이드 (위 플러그인 확장을 가림)
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'address_search_slot',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'sirsoft-admin_basic',
            'override_target' => 'sirsoft-daum_postcode',
            'priority' => 50,
            'is_active' => true,
        ]);

        // Arrange - 가려지지 않은 다른 모듈 확장 (다른 확장점)
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin/dashboard',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'priority' => 50,
            'is_active' => true,
        ]);

        // Act
        $result = $this->repository->getResolvedByTemplateId($this->template->id);

        // Assert - 오버라이드된 플러그인 원본은 제외, 오버라이드 + 무관 확장만 남음
        $this->assertCount(2, $result);

        $identifiers = $result->pluck('source_identifier')->toArray();
        $this->assertContains('sirsoft-admin_basic', $identifiers);
        $this->assertContains('sirsoft-ecommerce', $identifiers);
        $this->assertNotContains('sirsoft-daum_postcode', $identifiers);
    }

    /**
     * 오버라이드가 없으면 오버라이드 해석 조회는 모든 확장을 그대로 반환함
     */
    public function test_get_resolved_by_template_id_returns_all_when_no_override(): void
    {
        // Arrange - 오버라이드 없는 확장 3개 (서로 다른 확장점/레이아웃)
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'address_search_slot',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-daum_postcode',
            'is_active' => true,
        ]);
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin/dashboard',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'is_active' => true,
        ]);
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'sidebar-top',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-other',
            'is_active' => true,
        ]);

        // Act
        $result = $this->repository->getResolvedByTemplateId($this->template->id);

        // Assert - 가림 대상이 없으므로 전부 반환
        $this->assertCount(3, $result);
    }

    /**
     * 템플릿 ID로 모든 확장 삭제 테스트
     */
    public function test_delete_by_template_id(): void
    {
        // Arrange
        $otherTemplate = Template::factory()->create();

        LayoutExtension::factory()->count(3)->create([
            'template_id' => $this->template->id,
        ]);

        LayoutExtension::factory()->count(2)->create([
            'template_id' => $otherTemplate->id,
        ]);

        // Act
        $deleted = $this->repository->deleteByTemplateId($this->template->id);

        // Assert
        $this->assertEquals(3, $deleted);
        $this->assertEquals(2, LayoutExtension::count());
        $this->assertEquals(0, LayoutExtension::withTrashed()->where('template_id', $this->template->id)->count());
    }

    /**
     * 삭제된 확장 포함하여 삭제 테스트
     */
    public function test_delete_by_template_id_includes_trashed(): void
    {
        // Arrange
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
        ]);

        // Soft delete
        $this->repository->softDeleteBySource(LayoutSourceType::Module, 'sirsoft-ecommerce');

        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'source_identifier' => 'another-module',
        ]);

        // 현재: 1개 활성, 이전 soft delete된 것들
        $trashedCount = LayoutExtension::onlyTrashed()->where('template_id', $this->template->id)->count();
        $activeCount = LayoutExtension::where('template_id', $this->template->id)->count();

        // Act
        $deleted = $this->repository->deleteByTemplateId($this->template->id);

        // Assert
        $this->assertEquals($trashedCount + $activeCount, $deleted);
        $this->assertEquals(0, LayoutExtension::withTrashed()->where('template_id', $this->template->id)->count());
    }
}
