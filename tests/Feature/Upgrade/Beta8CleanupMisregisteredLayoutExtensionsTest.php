<?php

namespace Tests\Feature\Upgrade;

use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\UpgradeContext;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Upgrades\Data\V7_0_0\Migrations\CleanupMisregisteredLayoutExtensions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * beta.8 오등록 레이아웃 확장 정리 업그레이드 스텝 회귀 가드.
 *
 * beta.8 이전 refreshLayoutExtensions 가 모듈/플러그인 확장을 모든 활성 템플릿에
 * 무차별 등록하여, admin 레이아웃 대상 확장이 user 템플릿에(또는 그 반대로)
 * 잘못 등록된 행을 정리하는지 검증한다.
 *
 * axis 전수:
 *   1. overlay — 대상 레이아웃이 그 템플릿에 존재 → 보존
 *   2. overlay — 대상 레이아웃이 그 템플릿에 부재 → soft delete
 *   3. extension_point — 확장점이 그 템플릿 레이아웃에 정의됨 → 보존
 *   4. extension_point — 확장점 정의 부재 → soft delete
 *   5. 이미 soft delete 된 행 → 재처리 안 함 (멱등)
 *   6. 두 번 실행 → 동일 결과 (멱등)
 */
class Beta8CleanupMisregisteredLayoutExtensionsTest extends TestCase
{
    use RefreshDatabase;

    private Template $adminTemplate;

    private Template $userTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/data/7.0.0/migrations/01_CleanupMisregisteredLayoutExtensions.php');

        $this->adminTemplate = Template::factory()->create(['type' => 'admin']);
        $this->userTemplate = Template::factory()->create(['type' => 'user']);
    }

    /**
     * 업그레이드 컨텍스트 생성 (beta.7 → beta.8 transition).
     */
    private function context(): UpgradeContext
    {
        return new UpgradeContext('7.0.0-beta.7', '7.0.0', '7.0.0');
    }

    /**
     * 마이그레이션 1회 실행.
     */
    private function runMigration(): void
    {
        (new CleanupMisregisteredLayoutExtensions)->run($this->context());
    }

    /**
     * overlay — 대상 레이아웃이 존재하는 템플릿의 확장은 보존
     */
    public function test_overlay_with_existing_target_layout_is_preserved(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'name' => 'admin_user_detail',
        ]);

        $ext = LayoutExtension::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);

        $this->runMigration();

        $this->assertNotSoftDeleted('template_layout_extensions', ['id' => $ext->id]);
    }

    /**
     * overlay — 대상 레이아웃이 없는 템플릿의 확장은, 동일 4키가 다른 템플릿엔
     * 적용 가능할 때만 soft delete (cross-template 판정).
     *
     * cross-template 전환 후 의미 변화: 단순 "이 템플릿에 부재 → 삭제" 가 아니라
     * "이 템플릿엔 부재 ∧ 동일 확장이 다른 템플릿엔 적용 가능 = 오등록" 일 때만 삭제한다.
     * admin 템플릿엔 admin_user_detail 레이아웃이 존재하므로, user 행은 오등록으로 판정.
     */
    public function test_overlay_without_target_layout_is_soft_deleted(): void
    {
        // admin 템플릿엔 대상 레이아웃 존재 + admin overlay 행 (= "다른 템플릿엔 적용 가능")
        TemplateLayout::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'name' => 'admin_user_detail',
        ]);
        $valid = LayoutExtension::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);

        // user 템플릿에는 admin_user_detail 레이아웃이 없음 → 오등록
        $orphan = LayoutExtension::factory()->create([
            'template_id' => $this->userTemplate->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);

        $this->runMigration();

        $this->assertNotSoftDeleted('template_layout_extensions', ['id' => $valid->id]);
        $this->assertSoftDeleted('template_layout_extensions', ['id' => $orphan->id]);
        // soft delete 시 is_active=0 동반 (모순 상태 방지)
        $this->assertDatabaseHas('template_layout_extensions', [
            'id' => $orphan->id,
            'is_active' => 0,
        ]);
    }

    /**
     * extension_point — 확장점이 정의된 템플릿의 확장은 보존
     */
    public function test_extension_point_with_definition_is_preserved(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->adminTemplate->id,
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

        $ext = LayoutExtension::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);

        $this->runMigration();

        $this->assertNotSoftDeleted('template_layout_extensions', ['id' => $ext->id]);
    }

    /**
     * extension_point — 확장점 정의가 없는 템플릿의 확장은, 동일 확장점이 다른
     * 템플릿엔 정의돼 있을 때만 soft delete (cross-template 판정).
     *
     * admin 템플릿엔 admin.dashboard.widgets 확장점이 정의돼 있으므로,
     * 확장점이 없는 user 행은 오등록으로 판정되어 삭제된다.
     */
    public function test_extension_point_without_definition_is_soft_deleted(): void
    {
        // admin 템플릿엔 확장점 정의 + admin 확장점 행 (= "다른 템플릿엔 적용 가능")
        TemplateLayout::factory()->create([
            'template_id' => $this->adminTemplate->id,
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
        $valid = LayoutExtension::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);

        // user 템플릿엔 확장점 정의 없음 → 오등록
        TemplateLayout::factory()->create([
            'template_id' => $this->userTemplate->id,
            'name' => 'home',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'home',
                'components' => [['type' => 'basic', 'name' => 'Div']],
            ],
        ]);

        $orphan = LayoutExtension::factory()->create([
            'template_id' => $this->userTemplate->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin.dashboard.widgets',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);

        $this->runMigration();

        $this->assertNotSoftDeleted('template_layout_extensions', ['id' => $valid->id]);
        $this->assertSoftDeleted('template_layout_extensions', ['id' => $orphan->id]);
    }

    /**
     * 이미 soft delete 된 행은 재처리 대상이 아님
     */
    public function test_already_soft_deleted_rows_are_not_reprocessed(): void
    {
        $ext = LayoutExtension::factory()->create([
            'template_id' => $this->userTemplate->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);
        $ext->delete();
        $deletedAt = $ext->fresh()->deleted_at;

        $this->runMigration();

        // deleted_at 이 변경되지 않음 (재처리 안 함)
        $this->assertEquals(
            $deletedAt->toDateTimeString(),
            $ext->fresh()->deleted_at->toDateTimeString()
        );
    }

    /**
     * 멱등성 — 두 번 실행해도 결과 동일
     */
    public function test_migration_is_idempotent(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'name' => 'admin_user_detail',
        ]);

        $valid = LayoutExtension::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);
        $orphan = LayoutExtension::factory()->create([
            'template_id' => $this->userTemplate->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);

        $this->runMigration();
        $this->runMigration();

        $this->assertNotSoftDeleted('template_layout_extensions', ['id' => $valid->id]);
        $this->assertSoftDeleted('template_layout_extensions', ['id' => $orphan->id]);
    }

    /**
     * 원격 전멸 재현 (가장 가혹) — admin_dashboard 호스트 레이아웃이 *모든* 템플릿에서
     * 구버전(슬롯 부재)이고, 그 확장점이 admin·user 양쪽에 등록된 상태.
     *
     * 이것이 원격에서 admin_dashboard_commerce/community/quick_menu 가 전멸한 실제 상황이다
     * (cleanup 실행 시점 16:42:42 < admin_dashboard 레이아웃 갱신 16:42:56 — 스텝 시점엔
     * 호스트가 아직 구버전). 어느 템플릿에서도 슬롯이 안 박혀 호스트명을 content 로 알 수
     * 없으므로, 호스트명 식별에 의존하면 admin 정상 행도 죽는다.
     *
     * 무결 원리: "모든 등록 템플릿에서 부재(전부 stale)" 면 그건 오등록이 아니라 content
     * 가 아직 stale 인 것이므로 **전부 보존**해야 한다. 한쪽만 부재일 때만 그 한쪽이 오등록.
     *
     * 올바른 결과: admin·user 4건(board×{admin,user} + ecommerce×{admin,user}) 전부 보존.
     */
    public function test_all_templates_stale_host_preserves_every_registration(): void
    {
        // admin·user 양쪽에 admin_dashboard 레이아웃이 있으나 모두 구버전(슬롯 없음)
        foreach ([$this->adminTemplate->id, $this->userTemplate->id] as $tid) {
            TemplateLayout::factory()->create([
                'template_id' => $tid,
                'name' => 'admin_dashboard',
                'content' => [
                    'version' => '0.9.0',
                    'layout_name' => 'admin_dashboard',
                    'components' => [['type' => 'basic', 'name' => 'Div']],
                ],
            ]);
        }

        // 두 source(board, ecommerce)가 각각 admin·user 양쪽에 quick_menu 확장점 등록 (원격 동일)
        $ids = [];
        foreach (['sirsoft-board', 'sirsoft-ecommerce'] as $source) {
            foreach ([$this->adminTemplate->id, $this->userTemplate->id] as $tid) {
                $ids[] = LayoutExtension::factory()->create([
                    'template_id' => $tid,
                    'extension_type' => LayoutExtensionType::ExtensionPoint,
                    'target_name' => 'admin_dashboard_quick_menu',
                    'source_type' => LayoutSourceType::Module,
                    'source_identifier' => $source,
                ])->id;
            }
        }

        $this->runMigration();

        // 전부 stale → 4건 모두 보존 (전멸 차단)
        foreach ($ids as $id) {
            $this->assertNotSoftDeleted('template_layout_extensions', ['id' => $id]);
        }
    }

    /**
     * 원격 재현 (#대시보드 미주입) 핵심 — admin 호스트 레이아웃(admin_dashboard)의 content 가
     * 구버전(슬롯 부재)이지만, 호스트 레이아웃 *행 자체*는 admin 에 존재하는 상태.
     *
     * 코어 업데이트 흐름에서 cleanup 이 도는 시점엔 호스트 레이아웃 content 가 아직
     * 구버전이라 슬롯이 안 박혔다. content 슬롯만으로 판정하면 admin 정상 행이 "부재" 로
     * 오판되어 삭제된다(관리자 대시보드 위젯 소실 회귀). 그러나 admin_dashboard 레이아웃
     * 행은 admin 에 존재하므로(content 만 구버전), 호스트 레이아웃 존재 신호로 보존해야 한다.
     *
     * user 에는 admin_dashboard 호스트 레이아웃 자체가 없으므로 user 행은 오등록 → 삭제.
     *
     * 호스트명은 "어느 템플릿이든 신버전이라 슬롯이 박힌 레이아웃" 에서 식별한다(여기선
     * 별도 admin2 템플릿이 신버전 admin_dashboard 를 가져 호스트명을 제공).
     *
     * 올바른 결과: admin 행 보존, user 행 삭제.
     */
    public function test_admin_host_stale_content_but_layout_row_exists_preserves_admin(): void
    {
        // 신버전 admin 템플릿 — admin_dashboard 에 슬롯이 박혀 호스트명을 식별 가능하게 함
        $admin2 = Template::factory()->create(['type' => 'admin']);
        TemplateLayout::factory()->create([
            'template_id' => $admin2->id,
            'name' => 'admin_dashboard',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'admin_dashboard',
                'components' => [
                    ['type' => 'extension_point', 'name' => 'admin_dashboard_quick_menu'],
                ],
            ],
        ]);

        // 대상 admin 템플릿: admin_dashboard 행은 존재하나 content 는 구버전(슬롯 부재)
        TemplateLayout::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'name' => 'admin_dashboard',
            'content' => [
                'version' => '0.9.0',
                'layout_name' => 'admin_dashboard',
                'components' => [['type' => 'basic', 'name' => 'Div']],
            ],
        ]);
        // user 템플릿엔 admin_dashboard 호스트 레이아웃 자체가 없음

        $adminExt = LayoutExtension::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin_dashboard_quick_menu',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);
        $userExt = LayoutExtension::factory()->create([
            'template_id' => $this->userTemplate->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin_dashboard_quick_menu',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);

        $this->runMigration();

        // admin 정상 행 보존 (구버전 content 라도 호스트 레이아웃 행이 존재 → 보존)
        $this->assertNotSoftDeleted('template_layout_extensions', ['id' => $adminExt->id]);
        // user 행은 호스트 레이아웃 부재 → 오등록 삭제
        $this->assertSoftDeleted('template_layout_extensions', ['id' => $userExt->id]);
    }

    /**
     * 원격 재현 (#대시보드 미주입) — 스텝 시점에 확장 레이아웃이 구버전이고,
     * 대상 확장점이 admin 템플릿 전용 레이아웃(admin_dashboard)에만 정의되는 케이스.
     *
     * 스텝 실행 시점:
     *   - admin 템플릿: admin_dashboard 레이아웃 존재하나 content 는 구버전(슬롯 부재)
     *   - user 템플릿: admin_dashboard 레이아웃 자체가 없음
     *   - 양쪽에 admin_dashboard_commerce extension_point 행 등록됨
     *
     * 올바른 결과: admin 행은 admin 전용 확장의 정상 등록이므로 **보존**되어야 한다.
     * (구버전이라 슬롯 판정이 불가하면, 최소한 정상 확장을 죽여선 안 됨)
     */
    public function test_admin_only_extension_point_preserved_when_step_runs_before_layout_sync(): void
    {
        // admin 템플릿: admin_dashboard 레이아웃 존재하나 구버전(슬롯 부재)
        TemplateLayout::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'name' => 'admin_dashboard',
            'content' => [
                'version' => '0.9.0',
                'layout_name' => 'admin_dashboard',
                'components' => [['type' => 'basic', 'name' => 'Div']], // 슬롯 없음
            ],
        ]);
        // user 템플릿엔 admin_dashboard 레이아웃 자체가 없음 (생성 안 함)

        $adminExt = LayoutExtension::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin_dashboard_commerce',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
        ]);
        $userExt = LayoutExtension::factory()->create([
            'template_id' => $this->userTemplate->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin_dashboard_commerce',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
        ]);

        $this->runMigration();

        // admin 정상 행은 반드시 보존 (이게 원격에서 깨진 회귀)
        $this->assertNotSoftDeleted('template_layout_extensions', ['id' => $adminExt->id]);
    }

    /**
     * cross-template 회귀 가드 핵심 — 모든 템플릿의 레이아웃이 stale(슬롯 미포함)이면
     * 정상 확장도 전부 보존한다.
     *
     * 결함 재현: 코어 업데이트 흐름에서 cleanup 이 레이아웃 content 갱신 *전* 시점에
     * 돌면, admin·user 양쪽 레이아웃이 아직 슬롯을 갖지 못해 모든 행이 "대상 부재" 로
     * 오판된다. 기존(절대 부재) 로직이면 정상 확장(commerce) 이 admin·user 양쪽에서
     * 모두 soft delete 되어 대시보드 UI 가 사라진다.
     *
     * cross-template 로직: "이 템플릿엔 부재 ∧ 동일 4키가 *다른* 템플릿엔 적용 가능"
     * 일 때만 삭제하므로, 어느 템플릿에서도 적용 불가(전부 stale)면 전부 보존 → 전멸 차단.
     */
    public function test_cleanup_preserves_valid_extension_when_all_layouts_are_stale(): void
    {
        // admin·user 양쪽 레이아웃 모두 확장점 슬롯 미포함 (stale 재현)
        TemplateLayout::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'name' => 'admin_dashboard',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'admin_dashboard',
                'components' => [['type' => 'basic', 'name' => 'Div']],
            ],
        ]);
        TemplateLayout::factory()->create([
            'template_id' => $this->userTemplate->id,
            'name' => 'admin_dashboard',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'admin_dashboard',
                'components' => [['type' => 'basic', 'name' => 'Div']],
            ],
        ]);

        // 동일 확장점 commerce 가 admin·user 양쪽에 등록됨
        $adminExt = LayoutExtension::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin_dashboard_commerce',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
        ]);
        $userExt = LayoutExtension::factory()->create([
            'template_id' => $this->userTemplate->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin_dashboard_commerce',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
        ]);

        $this->runMigration();

        // 전부 stale → 둘 다 보존 (정상 확장 전멸 차단 — 이 가드가 버그 ① 핵심)
        $this->assertNotSoftDeleted('template_layout_extensions', ['id' => $adminExt->id]);
        $this->assertNotSoftDeleted('template_layout_extensions', ['id' => $userExt->id]);
    }

    /**
     * cross-template — admin 레이아웃엔 확장점이 정의됐고 user 엔 없을 때, user 행만
     * soft delete(+is_active=0) 되고 admin 행은 보존된다 (오등록 정확 제거).
     */
    public function test_cleanup_removes_only_misregistered_when_layout_current(): void
    {
        // admin 레이아웃엔 commerce 확장점 정의 (= current/신버전)
        TemplateLayout::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'name' => 'admin_dashboard',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'admin_dashboard',
                'components' => [
                    [
                        'type' => 'basic',
                        'name' => 'Div',
                        'children' => [
                            ['type' => 'extension_point', 'name' => 'admin_dashboard_commerce'],
                        ],
                    ],
                ],
            ],
        ]);
        // user 템플릿엔 admin_dashboard 레이아웃 자체가 없음 (admin_dashboard 는 admin 전용)

        $adminExt = LayoutExtension::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin_dashboard_commerce',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
        ]);
        $userExt = LayoutExtension::factory()->create([
            'template_id' => $this->userTemplate->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'admin_dashboard_commerce',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
        ]);

        $this->runMigration();

        // admin 보존(호스트 admin_dashboard 존재), user 만 soft delete + is_active=0
        // (user 엔 admin_dashboard 호스트 레이아웃 자체가 없으므로 오등록 확정)
        $this->assertNotSoftDeleted('template_layout_extensions', ['id' => $adminExt->id]);
        $this->assertSoftDeleted('template_layout_extensions', ['id' => $userExt->id]);
        $this->assertDatabaseHas('template_layout_extensions', [
            'id' => $userExt->id,
            'is_active' => 0,
        ]);
    }
}
