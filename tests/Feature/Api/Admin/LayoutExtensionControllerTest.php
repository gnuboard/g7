<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutExtensionVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LayoutExtensionController API 테스트
 *
 * 레이아웃 확장 편집·버전관리·미리보기 엔드포인트를 검증합니다.
 */
class LayoutExtensionControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 레이아웃/템플릿 요청 경로가 GDPR 정책 테이블을 조회하므로
     * 해당 플러그인 마이그레이션을 테스트 DB 에 포함시킨다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private User $adminUser;

    private User $normalUser;

    private Template $template;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'core.templates.read',
            'core.templates.layouts.edit',
        ]);
        $this->token = $this->adminUser->createToken('test-token')->plainTextToken;

        $this->normalUser = User::factory()->create();
        $this->template = Template::factory()->create();
    }

    /**
     * 관리자 사용자 생성 (필요한 권한 포함)
     *
     * @param  array<string>  $permissions  부여할 권한 식별자 목록
     */
    private function createAdminUser(array $permissions = []): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $permissionIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permissionIdentifier],
                [
                    'name' => json_encode(['ko' => $permissionIdentifier, 'en' => $permissionIdentifier]),
                    'description' => json_encode(['ko' => $permissionIdentifier, 'en' => $permissionIdentifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $testRole = Role::create([
            'identifier' => 'admin_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
                'is_active' => true,
            ]
        );

        $testRole->permissions()->sync($permissionIds);
        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 인증된 요청 헬퍼
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 테스트용 extension_point 확장 생성
     */
    private function makeExtensionPoint(array $overrides = []): LayoutExtension
    {
        return LayoutExtension::factory()->create(array_merge([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'header',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => ['extension_point' => 'header', 'components' => []],
        ], $overrides));
    }

    /**
     * index - 출처별 그룹핑 구조 반환
     */
    public function test_index_returns_source_grouped_extensions(): void
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

        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-extensions");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['source_identifier', 'source_type', 'source_label', 'extensions'],
                ],
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    /**
     * index - 각 확장에 host_layouts 부착
     *
     * 라우트 트리가 클릭(캔버스 로드) 없이도 layoutName 매칭으로 화면별 연결 확장 목록을
     * 정적 구성할 수 있도록, 목록 응답의 각 확장에 호스트 레이아웃 목록이 포함되어야 한다.
     * overlay = [target_layout], extension_point = 그 확장점을 포함하는 레이아웃 전체.
     */
    public function test_index_attaches_host_layouts_to_each_extension(): void
    {
        // overlay — 실재 target_id 노드에 편집 가능 components 를 주입 (modals/
        // data_sources 전용·target_id 부재 overlay 는 시각 편집 호스트로 인정 안 됨).
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'admin_user_detail',
            'content' => ['components' => [['name' => 'Div', 'props' => ['id' => 'detail_panel']]]],
        ]);
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'module-overlay',
            'content' => [
                'target_layout' => 'admin_user_detail',
                'injections' => [['target_id' => 'detail_panel', 'position' => 'append_child', 'components' => [['name' => 'Div']]]],
            ],
        ]);

        // extension_point — 슬롯을 포함하는 레이아웃 2개 생성 후 그 슬롯 대상 확장 등록
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'shop_checkout',
            'content' => ['components' => [['type' => 'extension_point', 'name' => 'checkout.payment']]],
        ]);
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'shop_reorder',
            'content' => ['components' => [['type' => 'extension_point', 'name' => 'checkout.payment']]],
        ]);
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'checkout.payment',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'plugin-pay',
            // extension_point 가 편집 가능 컴포넌트를 실제로 주입해야 시각 편집 호스트로 인정.
            'content' => ['extension_point' => 'checkout.payment', 'components' => [['name' => 'Div']]],
        ]);

        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-extensions");

        $response->assertStatus(200);

        $allExtensions = collect($response->json('data'))->flatMap(fn ($g) => $g['extensions']);

        $overlay = $allExtensions->firstWhere('target_name', 'admin_user_detail');
        $this->assertNotNull($overlay);
        $this->assertSame(['admin_user_detail'], $overlay['host_layouts']);

        $ep = $allExtensions->firstWhere('target_name', 'checkout.payment');
        $this->assertNotNull($ep);
        $this->assertContains('shop_checkout', $ep['host_layouts']);
        $this->assertContains('shop_reorder', $ep['host_layouts']);
    }

    /**
     * index - 템플릿 오버라이드에 가려진 확장은 트리에서 제외됨
     *
     * 회귀: 편집 화면 좌측 트리가 화면에 실제 적용되지 않는 "가려진 행"을
     * 노출하여, 관리자가 그 행을 편집해도 반영되지 않던 문제.
     */
    public function test_index_excludes_overridden_extensions(): void
    {
        // 플러그인 Extension Point 원본
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'address_search_slot',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-daum_postcode',
            'priority' => 100,
            'is_active' => true,
        ]);
        // 템플릿 오버라이드 (위 플러그인 확장을 가림)
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'address_search_slot',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => $this->template->identifier,
            'override_target' => 'sirsoft-daum_postcode',
            'priority' => 50,
            'is_active' => true,
        ]);

        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-extensions");

        $response->assertStatus(200);

        // 가려진 플러그인 원본(2건 등록)은 트리에서 제외되고 오버라이드 행 1건만 노출.
        // 오버라이드 행은 is_override=true 로 표시된다.
        $allExtensions = collect($response->json('data'))->flatMap(fn ($g) => $g['extensions']);
        $slotExtensions = $allExtensions->where('target_name', 'address_search_slot');
        $this->assertCount(1, $slotExtensions, '오버라이드에 가려진 원본이 함께 노출됨');
        $this->assertTrue($slotExtensions->first()['is_override'], '오버라이드 행에 is_override 플래그 누락');
        $this->assertSame('template', $slotExtensions->first()['source_type']);
    }

    /**
     * index - 권한 없는 사용자 403
     */
    public function test_index_forbidden_for_unauthorized_user(): void
    {
        $response = $this->actingAs($this->normalUser)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-extensions");

        $response->assertStatus(403);
    }

    /**
     * show - content 직렬화
     */
    public function test_show_returns_extension_with_serialized_content(): void
    {
        $extension = $this->makeExtensionPoint();

        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $extension->id)
            ->assertJsonStructure(['data' => ['id', 'extension_type', 'target_name', 'content', 'is_modified']]);

        $this->assertIsString($response->json('data.content'));
    }

    /**
     * show - 호스트 레이아웃 후보 반환
     */
    public function test_show_returns_host_layouts_for_overlay(): void
    {
        // 호스트 레이아웃: injection target_id 노드가 실재 (편집 가능 컴포넌트를
        // 실재 target_id 에 주입하는 overlay 만 시각 편집 호스트로 인정).
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'admin_user_detail',
            'content' => ['components' => [['name' => 'Div', 'props' => ['id' => 'detail_panel']]]],
        ]);
        $extension = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => [
                'target_layout' => 'admin_user_detail',
                'injections' => [['target_id' => 'detail_panel', 'position' => 'append_child', 'components' => [['name' => 'Div']]]],
            ],
        ]);

        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.host_layouts', ['admin_user_detail']);
    }

    /**
     * 시각 편집기 inject_props 교차 저장 페이로드 — 호스트 노드
     * 속성 모달에서 편집한 주입 props 가 그 확장 행으로 저장되고 original_content_hash 보존.
     */
    public function test_inject_props_cross_save_preserves_hash(): void
    {
        $originalContent = [
            'target_layout' => 'admin_user_detail',
            'injections' => [
                ['target_id' => 'user_detail_tabs', 'position' => 'inject_props', 'props' => ['tabs' => ['_append' => [['id' => 'old']]]]],
            ],
        ];
        $extension = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-test',
            'content' => $originalContent,
            'original_content_hash' => hash('sha256', json_encode($originalContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
        $originalHash = $extension->original_content_hash;

        // 시각 편집기 교차 저장 = injection props 만 교체한 전체 content PUT.
        $newContent = $originalContent;
        $newContent['injections'][0]['props'] = ['tabs' => ['_append' => [['id' => 'edited']]]];

        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}", [
                'expected_lock_version' => 0,
                'content' => $newContent,
            ]);

        $response->assertStatus(200);
        $extension->refresh();
        // 주입 props 갱신 + 수정 감지용 original_content_hash 불변(보존).
        $this->assertSame('edited', $extension->content['injections'][0]['props']['tabs']['_append'][0]['id']);
        $this->assertEquals($originalHash, $extension->original_content_hash);
        // 버전 적재(편집 이력).
        $this->assertGreaterThanOrEqual(1, TemplateLayoutExtensionVersion::where('extension_id', $extension->id)->count());
    }

    /**
     * show - 다른 템플릿의 확장 ID 는 404
     */
    public function test_show_returns_404_for_extension_of_other_template(): void
    {
        $otherTemplate = Template::factory()->create();
        $extension = LayoutExtension::factory()->create(['template_id' => $otherTemplate->id]);

        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}");

        $response->assertStatus(404);
    }

    /**
     * update - 200 + 버전 2건 생성 + original_content_hash 불변
     */
    public function test_update_succeeds_and_creates_versions(): void
    {
        $originalContent = ['extension_point' => 'header', 'components' => []];
        $extension = $this->makeExtensionPoint([
            'content' => $originalContent,
            'original_content_hash' => hash('sha256', json_encode($originalContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
        $originalHash = $extension->original_content_hash;

        $newContent = ['extension_point' => 'header', 'components' => [['type' => 'basic', 'name' => 'Span']]];

        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}", [
                'expected_lock_version' => 0,
                'content' => $newContent,
            ]);

        $response->assertStatus(200);

        $this->assertEquals(2, TemplateLayoutExtensionVersion::where('extension_id', $extension->id)->count());

        $extension->refresh();
        $this->assertEquals($newContent, $extension->content);
        $this->assertEquals($originalHash, $extension->original_content_hash);

        // 현재(최신) 버전 번호 동봉.
        // 첫 저장은 baseline(v1) + 이번 저장본(v2) 두 버전 적재 → current_version=2.
        $this->assertSame(2, $response->json('data.current_version'));
    }

    /**
     * index - 각 확장에 current_version 부착
     *
     * 라우트 트리 확장 노드 버전 배지의 데이터 소스 — 버전 이력이 있는 확장은 최신 버전
     * 번호, 이력이 없는(원본) 확장은 null 이어야 한다(배지 미표시).
     */
    public function test_index_attaches_current_version_to_each_extension(): void
    {
        $withHistory = $this->makeExtensionPoint();
        TemplateLayoutExtensionVersion::factory()->create(['extension_id' => $withHistory->id, 'version' => 1]);
        TemplateLayoutExtensionVersion::factory()->create(['extension_id' => $withHistory->id, 'version' => 5]);
        $noHistory = $this->makeExtensionPoint();

        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-extensions");

        $response->assertStatus(200);

        $byId = [];
        foreach ($response->json('data') as $group) {
            foreach ($group['extensions'] as $ext) {
                $byId[$ext['id']] = $ext;
            }
        }

        $this->assertArrayHasKey('current_version', $byId[$withHistory->id]);
        $this->assertSame(5, $byId[$withHistory->id]['current_version']);
        $this->assertNull($byId[$noHistory->id]['current_version']);
    }

    /**
     * update - expected_lock_version 불일치 시 409 Conflict (낙관적 잠금)
     */
    public function test_update_returns_409_on_lock_version_mismatch(): void
    {
        // 신규 확장은 lock_version=0. expected_lock_version=1 을 보내면 불일치 → 409.
        $extension = $this->makeExtensionPoint([
            'content' => ['extension_point' => 'header', 'components' => []],
        ]);

        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}", [
                'expected_lock_version' => 1,
                'content' => ['extension_point' => 'header', 'components' => [['type' => 'basic', 'name' => 'Span']]],
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('errors.error', 'concurrent_modification')
            ->assertJsonPath('errors.current_version', 0)
            ->assertJsonPath('errors.your_version', 1);

        // 충돌 시 저장되지 않음 — content 불변 + 버전 미적재.
        $extension->refresh();
        $this->assertEquals([], $extension->content['components']);
        $this->assertEquals(0, TemplateLayoutExtensionVersion::where('extension_id', $extension->id)->count());
    }

    /**
     * update - 잘못된 구조는 422
     */
    public function test_update_rejects_invalid_structure(): void
    {
        $extension = $this->makeExtensionPoint();

        // extension_point 와 target_layout 둘 다 없는 잘못된 content
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}", [
                'expected_lock_version' => 0,
                'content' => ['components' => []],
            ]);

        $response->assertStatus(422);
    }

    /**
     * update - 권한 없는 사용자 403
     */
    public function test_update_forbidden_for_unauthorized_user(): void
    {
        $extension = $this->makeExtensionPoint();

        $response = $this->actingAs($this->normalUser)
            ->putJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}", [
                'content' => ['extension_point' => 'header', 'components' => []],
            ]);

        $response->assertStatus(403);
    }

    /**
     * versions - 버전 목록 조회
     */
    public function test_versions_returns_version_list(): void
    {
        $extension = $this->makeExtensionPoint();
        TemplateLayoutExtensionVersion::factory()->count(3)->sequence(
            ['version' => 1],
            ['version' => 2],
            ['version' => 3],
        )->create(['extension_id' => $extension->id]);

        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}/versions");

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    /**
     * showVersion - 특정 버전 조회
     */
    public function test_show_version_returns_specific_version(): void
    {
        $extension = $this->makeExtensionPoint();
        TemplateLayoutExtensionVersion::factory()->create([
            'extension_id' => $extension->id,
            'version' => 1,
        ]);

        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}/versions/1");

        $response->assertStatus(200)
            ->assertJsonPath('data.version', 1);
    }

    /**
     * restoreVersion - content 복원 + 새 버전 생성
     */
    public function test_restore_version_restores_content(): void
    {
        $oldContent = ['extension_point' => 'header', 'components' => [['type' => 'basic', 'name' => 'Span']]];
        $extension = $this->makeExtensionPoint(['content' => ['extension_point' => 'footer']]);

        $version = TemplateLayoutExtensionVersion::factory()->create([
            'extension_id' => $extension->id,
            'version' => 1,
            'content' => $oldContent,
        ]);

        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}/versions/{$version->id}/restore");

        $response->assertStatus(200);

        $extension->refresh();
        $this->assertEquals($oldContent, $extension->content);
    }

    /**
     * restoreVersion - 존재하지 않는 버전 404
     */
    public function test_restore_version_returns_404_for_missing_version(): void
    {
        $extension = $this->makeExtensionPoint();

        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}/versions/99999/restore");

        $response->assertStatus(404);
    }

    /**
     * storePreview - overlay 타입 미리보기 토큰 반환
     */
    public function test_store_preview_returns_token_for_overlay(): void
    {
        $extension = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin/dashboard',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-analytics',
            'content' => ['target_layout' => 'admin/dashboard', 'injections' => []],
        ]);

        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}/preview", [
                'content' => ['target_layout' => 'admin/dashboard', 'injections' => []],
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['token', 'preview_url', 'expires_at']]);
    }

    /**
     * storePreview - extension_point 타입은 preview_layout 없으면 422
     */
    public function test_store_preview_requires_preview_layout_for_extension_point(): void
    {
        $extension = $this->makeExtensionPoint();

        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}/preview", [
                'content' => ['extension_point' => 'header', 'components' => []],
            ]);

        $response->assertStatus(422);
    }
}
