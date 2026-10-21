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

    /**
     * 저장소와 기본 템플릿을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(LayoutExtensionRepositoryInterface::class);
        $this->template = Template::factory()->create();
    }

    /**
     * Extension Point 조회 테스트
     *
     * @effects repository_lists_active_rows_of_a_target_in_priority_order
     *
     * @return void 반환값 없음
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
     *
     * @effects repository_lists_active_rows_of_a_target_in_priority_order
     *
     * @return void 반환값 없음
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
     *
     * @effects repository_lists_active_rows_of_a_target_in_priority_order
     *
     * @return void 반환값 없음
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
     *
     * @effects repository_lists_active_rows_of_a_target_in_priority_order
     *
     * @return void 반환값 없음
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
     *
     * @effects repository_creates_extension_row
     *
     * @return void 반환값 없음
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
     *
     * @effects repository_source_lifecycle_soft_delete_trashed_lookup_force_delete
     *
     * @return void 반환값 없음
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
     * 출처의 지워진 행만, 조각 키로 걸러 조회한다 — 어느 행을 되살릴지는 서비스가 정한다(#725).
     *
     * @return void 반환값 없음
     *
     * @effects repository_source_lifecycle_soft_delete_trashed_lookup_force_delete
     */
    public function test_get_trashed_by_source(): void
    {
        // Arrange — 지울 행 둘(키 a·b) + 살아 있는 다른 출처 행 하나
        LayoutExtension::factory()->create(['template_id' => $this->template->id, 'source_type' => LayoutSourceType::Module, 'source_identifier' => 'sirsoft-ecommerce', 'fragment_key' => 'a']);
        LayoutExtension::factory()->create(['template_id' => $this->template->id, 'source_type' => LayoutSourceType::Module, 'source_identifier' => 'sirsoft-ecommerce', 'fragment_key' => 'b']);
        LayoutExtension::factory()->create(['template_id' => $this->template->id, 'source_type' => LayoutSourceType::Module, 'source_identifier' => 'sirsoft-other', 'fragment_key' => 'a']);

        $this->repository->softDeleteBySource(LayoutSourceType::Module, 'sirsoft-ecommerce');

        // Act
        $all = $this->repository->getTrashedBySource(LayoutSourceType::Module, 'sirsoft-ecommerce');
        $onlyA = $this->repository->getTrashedBySource(LayoutSourceType::Module, 'sirsoft-ecommerce', ['a']);

        // Assert — 조회만 하고 되살리지 않는다
        $this->assertSame(['a', 'b'], $all->pluck('fragment_key')->all());
        $this->assertSame(['a'], $onlyA->pluck('fragment_key')->all());
        $this->assertSame(1, LayoutExtension::count(), '조회가 행을 되살렸다');
    }

    /**
     * 출처별 영구 삭제 테스트
     *
     * @effects repository_source_lifecycle_soft_delete_trashed_lookup_force_delete
     *
     * @return void 반환값 없음
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
     *
     * @effects repository_finds_template_override_for_a_target
     *
     * @return void 반환값 없음
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
     *
     * @effects repository_finds_template_override_for_a_target
     *
     * @return void 반환값 없음
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
     *
     * @effects repository_finds_template_override_for_a_target
     *
     * @return void 반환값 없음
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
     * 템플릿 ID로 모든 확장 조회 테스트
     *
     * @effects repository_scopes_rows_by_template
     *
     * @return void 반환값 없음
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
     * 템플릿 ID로 모든 확장 삭제 테스트
     *
     * @effects repository_scopes_rows_by_template
     *
     * @return void 반환값 없음
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
     *
     * @effects repository_scopes_rows_by_template
     *
     * @return void 반환값 없음
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
