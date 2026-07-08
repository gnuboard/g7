<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 레이아웃 출처 메타 옵션 서빙 검증
 *
 * `GET /api/layouts/{id}/{name}.json?with_source_meta=1` 가 응답의 각 노드에
 * `__source` 메타를 부여하고, 옵션 미사용 시 응답 형식이 종전과 100% 동일함을
 * 보장한다 (계획서).
 *
 * 동시에 권한 가드(편집 권한 필수) 와 비파괴 보장(일반 사이트 렌더 영향 0) 회귀
 * 가드도 함께 검증한다.
 */
class LayoutSourceMetaServingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // 권한 시더 명시 실행 — `core.templates.layouts.edit` 등 코어 권한 등록
        $this->seed(RolePermissionSeeder::class);
    }

    private function createActiveTemplate(): Template
    {
        return Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * 편집 권한을 가진 사용자 생성 (`core.templates.layouts.edit`)
     */
    private function makeEditor(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'identifier' => 'meta_test_role_'.uniqid(),
            'name' => ['ko' => '테스트 편집자', 'en' => 'Test Editor'],
            'description' => ['ko' => '', 'en' => ''],
            'is_active' => true,
        ]);
        $permission = Permission::query()
            ->where('identifier', 'core.templates.layouts.edit')
            ->first();
        if ($permission !== null) {
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * extends + slots 구조의 부모/자식 레이아웃 쌍을 생성한다.
     *
     * 부모(`_admin_base`) 가 헤더 + 콘텐츠 슬롯을 정의하고, 자식(`dashboard`) 이
     * 콘텐츠 슬롯을 채운다. 메타 옵션 검증을 위해 의도적으로 단순한 구조 사용.
     */
    private function createBaseAndChildLayouts(Template $template): array
    {
        $base = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => '_admin_base',
            'content' => [
                'meta' => ['title' => 'Base'],
                'components' => [
                    [
                        'type' => 'basic',
                        'name' => 'Div',
                        'props' => ['className' => 'header'],
                        'children' => [
                            ['type' => 'basic', 'name' => 'Span', 'text' => 'Header'],
                        ],
                    ],
                    [
                        'type' => 'basic',
                        'name' => 'Div',
                        'slot' => 'content',
                        'props' => ['className' => 'content'],
                    ],
                ],
            ],
        ]);

        $child = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'extends' => '_admin_base',
                'meta' => ['title' => 'Dashboard'],
                'slots' => [
                    'content' => [
                        [
                            'type' => 'basic',
                            'name' => 'H1',
                            'text' => 'Dashboard',
                        ],
                    ],
                ],
            ],
        ]);

        return ['base' => $base, 'child' => $child];
    }

    #[Test]
    public function serve_without_option_returns_response_without_source_meta(): void
    {
        $template = $this->createActiveTemplate();
        $this->createBaseAndChildLayouts($template);

        $response = $this->getJson("/api/layouts/{$template->identifier}/dashboard.json");

        $response->assertStatus(200);

        $components = $response->json('data.components');
        $this->assertIsArray($components);

        // 옵션 미전달 — 어느 노드에도 __source 가 없어야 한다 (비파괴 보장)
        $hasSourceMeta = $this->treeHasKey($components, '__source');
        $this->assertFalse(
            $hasSourceMeta,
            '옵션 미전달 시 응답에 __source 메타가 포함되면 안 됨 (일반 사이트 렌더 영향 0)'
        );
    }

    #[Test]
    public function serve_with_option_returns_response_with_source_meta_on_every_node(): void
    {
        $template = $this->createActiveTemplate();
        $this->createBaseAndChildLayouts($template);

        $admin = $this->makeEditor();
        Sanctum::actingAs($admin, ['*'], 'sanctum');

        $response = $this->getJson("/api/layouts/{$template->identifier}/dashboard.json?with_source_meta=1");

        $response->assertStatus(200);

        $components = $response->json('data.components');
        $this->assertIsArray($components);

        // 첫 번째 컴포넌트(헤더) 는 base 출처
        $this->assertEquals('base', $components[0]['__source']['kind'] ?? null);
        $this->assertEquals('_admin_base', $components[0]['__source']['layout'] ?? null);

        // 헤더 children 도 base 출처 (부모로부터 상속)
        $this->assertEquals('base', $components[0]['children'][0]['__source']['kind'] ?? null);

        // 두 번째 컴포넌트(콘텐츠 슬롯 래퍼) 는 base 출처 — slot 위치는 부모가 정의
        $this->assertEquals('base', $components[1]['__source']['kind'] ?? null);

        // 콘텐츠 슬롯의 children(H1 Dashboard) 은 route 출처 — 자식 레이아웃의 slot 콘텐츠
        $contentChildren = $components[1]['children'] ?? [];
        $this->assertNotEmpty($contentChildren);
        $this->assertEquals('route', $contentChildren[0]['__source']['kind'] ?? null);
    }

    #[Test]
    public function serve_with_option_requires_authenticated_user(): void
    {
        $template = $this->createActiveTemplate();
        $this->createBaseAndChildLayouts($template);

        // 비로그인 사용자의 메타 옵션 요청은 401
        $response = $this->getJson("/api/layouts/{$template->identifier}/dashboard.json?with_source_meta=1");

        $response->assertStatus(401);
    }

    #[Test]
    public function serve_with_option_requires_edit_permission(): void
    {
        $template = $this->createActiveTemplate();
        $this->createBaseAndChildLayouts($template);

        // 일반 사용자 (편집 권한 없음) 의 메타 옵션 요청은 403
        $user = User::factory()->create(['is_super' => false]);
        Sanctum::actingAs($user, ['*'], 'sanctum');

        $response = $this->getJson("/api/layouts/{$template->identifier}/dashboard.json?with_source_meta=1");

        $response->assertStatus(403);
    }

    #[Test]
    public function meta_cache_is_separate_from_normal_response_cache(): void
    {
        $template = $this->createActiveTemplate();
        $this->createBaseAndChildLayouts($template);

        $admin = $this->makeEditor();

        // 일반 응답 먼저 캐시 적재 (비인증 사이트 렌더)
        $normal = $this->getJson("/api/layouts/{$template->identifier}/dashboard.json");
        $normal->assertStatus(200);
        $this->assertFalse($this->treeHasKey($normal->json('data.components'), '__source'));

        // 메타 옵션 응답이 일반 캐시를 오염시키지 않아야 한다
        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $meta = $this->getJson("/api/layouts/{$template->identifier}/dashboard.json?with_source_meta=1");
        $meta->assertStatus(200);
        $this->assertTrue($this->treeHasKey($meta->json('data.components'), '__source'));

        // 일반 요청을 다시 호출 (비인증) → 여전히 메타 없음 (캐시 분리 확인)
        app('auth')->guard('sanctum')->forgetUser();
        $normalAgain = $this->getJson("/api/layouts/{$template->identifier}/dashboard.json");
        $normalAgain->assertStatus(200);
        $this->assertFalse($this->treeHasKey($normalAgain->json('data.components'), '__source'));
    }

    /**
     * data_sources 항목을 가진 레이아웃을 생성한다 (방안 B 출처 메타 검증용).
     */
    private function createLayoutWithDataSources(Template $template): TemplateLayout
    {
        return TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'with_sources',
            'content' => [
                'meta' => ['title' => 'With Sources'],
                'data_sources' => [
                    ['id' => 'settings', 'type' => 'api', 'endpoint' => '/api/admin/settings', 'method' => 'GET'],
                    ['id' => 'roles', 'type' => 'api', 'endpoint' => '/api/admin/roles', 'method' => 'GET'],
                ],
                'components' => [
                    ['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'root']],
                ],
            ],
        ]);
    }

    #[Test]
    public function data_source_source_meta_present_with_flag(): void
    {
        $template = $this->createActiveTemplate();
        $this->createLayoutWithDataSources($template);

        $admin = $this->makeEditor();
        Sanctum::actingAs($admin, ['*'], 'sanctum');

        $response = $this->getJson("/api/layouts/{$template->identifier}/with_sources.json?with_source_meta=1");

        $response->assertStatus(200);

        $dataSources = $response->json('data.data_sources');
        $this->assertIsArray($dataSources);
        $this->assertNotEmpty($dataSources);

        // 템플릿 자체 레이아웃(prefix 없음) → kind=route / identifier=null (방안 B §deriveDataSourceSourceMeta)
        foreach ($dataSources as $ds) {
            $this->assertArrayHasKey('__source', $ds, "data_source '{$ds['id']}' 에 __source 메타가 부여되어야 함");
            $this->assertEquals('route', $ds['__source']['kind'] ?? null);
            $this->assertArrayHasKey('identifier', $ds['__source']);
            $this->assertNull($ds['__source']['identifier']);
        }
    }

    /**
     * 플러그인 소유 레이아웃(`source_type = Plugin`)을 prefix 이름으로 생성한다.
     *
     * 이름 패턴(`{ext}.{layout}`)만으로는 module/plugin 을 구분하지 못하므로,
     * 행의 `source_type` 을 명시해 `LayoutResolverService::resolve` 가 plugin 으로
     * 해석하게 한다.
     */
    private function createPluginLayoutWithDataSources(Template $template): TemplateLayout
    {
        return TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'sirsoft-gdpr.plugin_settings',
            'source_type' => LayoutSourceType::Plugin->value,
            'source_identifier' => 'sirsoft-gdpr',
            'content' => [
                'meta' => ['title' => 'GDPR Settings'],
                'data_sources' => [
                    ['id' => 'settings', 'type' => 'api', 'endpoint' => '/api/plugins/sirsoft-gdpr/settings', 'method' => 'GET'],
                ],
                'components' => [
                    ['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'root']],
                ],
            ],
        ]);
    }

    #[Test]
    public function data_source_source_meta_classifies_plugin_layout_as_plugin_kind(): void
    {
        $template = $this->createActiveTemplate();
        $this->createPluginLayoutWithDataSources($template);

        $admin = $this->makeEditor();
        Sanctum::actingAs($admin, ['*'], 'sanctum');

        $response = $this->getJson("/api/layouts/{$template->identifier}/sirsoft-gdpr.plugin_settings.json?with_source_meta=1");

        $response->assertStatus(200);

        $dataSources = $response->json('data.data_sources');
        $this->assertIsArray($dataSources);
        $this->assertNotEmpty($dataSources);

        // 플러그인 레이아웃 data_source → kind=plugin / identifier=sirsoft-gdpr.
        // 엔진 editorSpecLoader 가 plugin 스펙을 `plugin:{id}` 키로 보존하므로 kind 가
        // plugin 이어야 그 확장 샘플이 매칭된다. (module 오분류 시 `plugin:` 스펙을 빗나감)
        foreach ($dataSources as $ds) {
            $this->assertArrayHasKey('__source', $ds, "data_source '{$ds['id']}' 에 __source 메타가 부여되어야 함");
            $this->assertEquals('plugin', $ds['__source']['kind'] ?? null, "plugin 레이아웃 data_source '{$ds['id']}' 의 kind 는 plugin 이어야 함");
            $this->assertEquals('sirsoft-gdpr', $ds['__source']['identifier'] ?? null);
        }
    }

    #[Test]
    public function data_source_source_meta_classifies_module_layout_as_module_kind(): void
    {
        $template = $this->createActiveTemplate();
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'sirsoft-ecommerce.admin_ecommerce_settings',
            'source_type' => LayoutSourceType::Module->value,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => [
                'meta' => ['title' => 'Shop Settings'],
                'data_sources' => [
                    ['id' => 'settings', 'type' => 'api', 'endpoint' => '/api/admin/ecommerce/settings', 'method' => 'GET'],
                ],
                'components' => [
                    ['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'root']],
                ],
            ],
        ]);

        $admin = $this->makeEditor();
        Sanctum::actingAs($admin, ['*'], 'sanctum');

        $response = $this->getJson("/api/layouts/{$template->identifier}/sirsoft-ecommerce.admin_ecommerce_settings.json?with_source_meta=1");

        $response->assertStatus(200);

        $dataSources = $response->json('data.data_sources');
        $this->assertIsArray($dataSources);
        $this->assertNotEmpty($dataSources);

        foreach ($dataSources as $ds) {
            $this->assertArrayHasKey('__source', $ds);
            $this->assertEquals('module', $ds['__source']['kind'] ?? null, "module 레이아웃 data_source '{$ds['id']}' 의 kind 는 module 이어야 함");
            $this->assertEquals('sirsoft-ecommerce', $ds['__source']['identifier'] ?? null);
        }
    }

    #[Test]
    public function data_source_source_meta_absent_without_flag(): void
    {
        $template = $this->createActiveTemplate();
        $this->createLayoutWithDataSources($template);

        // 옵션 미전달 — 일반 사이트 렌더 경로에는 __source 가 없어야 한다 (비파괴 보장)
        $response = $this->getJson("/api/layouts/{$template->identifier}/with_sources.json");

        $response->assertStatus(200);

        $dataSources = $response->json('data.data_sources');
        $this->assertIsArray($dataSources);
        foreach ($dataSources as $ds) {
            $this->assertArrayNotHasKey('__source', $ds, '옵션 미전달 시 data_source 에 __source 가 포함되면 안 됨');
        }
    }

    /**
     * comment / _comment 필드를 가진 레이아웃을 생성합니다.
     *
     * @param  Template  $template  대상 템플릿
     * @return TemplateLayout 생성된 레이아웃
     */
    private function createCommentedLayout(Template $template): TemplateLayout
    {
        return TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'commented',
            'content' => [
                'comment' => '레이아웃 최상단 개발자 주석',
                'meta' => ['title' => 'Commented'],
                'components' => [
                    [
                        'comment' => '컴포넌트 설명 주석',
                        'type' => 'basic',
                        'name' => 'Div',
                        'props' => ['className' => 'container'],
                        'children' => [
                            [
                                '_comment' => '중첩 자식 주석',
                                'type' => 'basic',
                                'name' => 'Span',
                                'text' => 'Hello',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * 편집 모드(with_source_meta) 서빙에서는 comment 필드가 보존되어야 합니다.
     *
     * 편집기는 comment 를 편집 가능한 속성으로 노출하므로 편집 경로에서는 제거하지 않는다.
     * 공개 서빙과 별도 캐시 키로 분리되어 조건부 제거가 안전함을 함께 검증한다.
     */
    #[Test]
    public function serve_with_source_meta_preserves_developer_comments(): void
    {
        $template = $this->createActiveTemplate();
        $this->createCommentedLayout($template);

        $admin = $this->makeEditor();
        Sanctum::actingAs($admin, ['*'], 'sanctum');

        $response = $this->getJson("/api/layouts/{$template->identifier}/commented.json?with_source_meta=1");

        $response->assertStatus(200);

        // 편집 모드에서는 comment / _comment 가 보존됨
        $data = $response->json('data');
        $this->assertSame('레이아웃 최상단 개발자 주석', $data['comment'] ?? null);
        $this->assertSame('컴포넌트 설명 주석', $data['components'][0]['comment'] ?? null);
        $this->assertSame('중첩 자식 주석', $data['components'][0]['children'][0]['_comment'] ?? null);
    }

    /**
     * 공개 서빙(옵션 미전달)에서는 comment 필드가 제거되어야 합니다.
     *
     * 편집 경로와 동일 레이아웃 소스를 사용해 공개/편집 캐시 키 분리를 함께 검증한다.
     */
    #[Test]
    public function serve_without_source_meta_strips_developer_comments(): void
    {
        $template = $this->createActiveTemplate();
        $this->createCommentedLayout($template);

        $response = $this->getJson("/api/layouts/{$template->identifier}/commented.json");

        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringNotContainsString('"comment"', $body);
        $this->assertStringNotContainsString('"_comment"', $body);

        // 실제 콘텐츠는 보존
        $data = $response->json('data');
        $this->assertSame('Div', $data['components'][0]['name']);
    }

    /**
     * 컴포넌트 트리에서 특정 키가 존재하는지 재귀 탐색
     */
    private function treeHasKey(array $components, string $key): bool
    {
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            if (array_key_exists($key, $component)) {
                return true;
            }

            if (isset($component['children']) && is_array($component['children'])) {
                if ($this->treeHasKey($component['children'], $key)) {
                    return true;
                }
            }
        }

        return false;
    }
}
