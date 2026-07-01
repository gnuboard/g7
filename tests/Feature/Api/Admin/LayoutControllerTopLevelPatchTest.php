<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LayoutControllerTopLevelPatchTest — 최상위/중첩 키 validated 보존 회귀 가드 (R9)
 *
 * 페이지 설정 탭이 패치하는 최상위 키(errorHandling/initIsolated/transition_overlay) 와
 * data_sources nested 키(onSuccess/onError/errorHandling/contentType/initLocal/initIsolated/
 * if/conditions/refetchOnMount/channel/event/channel_type/target_source/onReceive) 가 PUT →
 * DB 라운드트립에서 `validated()` 에 의해 탈락되지 않음을 전수 가드한다.
 *
 * 근거: `content.data_sources` 는 `['array', ValidDataSourceMerge]` 로 `.*` 하위 규칙이 없어
 * Laravel validated() 가 배열을 통째 보존한다(노드 내부 미가공). `content.errorHandling` 동일.
 * `content.initIsolated` 는 본 작업에서 신규 명시(미명시 시 누락 — 이 테스트가 가드).
 */
class LayoutControllerTopLevelPatchTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private Template $template;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser(['core.templates.read', 'core.templates.layouts.edit']);
        $this->token = $this->adminUser->createToken('test-token')->plainTextToken;
        $this->template = Template::factory()->create();
    }

    private function createAdminUser(array $permissions = []): User
    {
        $user = User::factory()->create();
        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                ]
            );
            $permissionIds[] = $permission->id;
        }
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'is_system' => true,
                'priority' => 0,
            ]
        );
        $adminRole->permissions()->syncWithoutDetaching($permissionIds);
        $user->roles()->syncWithoutDetaching([$adminRole->id]);

        return $user;
    }

    private function authRequest()
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']);
    }

    private function makeLayout(): TemplateLayout
    {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'patch-target',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'patch-target',
                'endpoint' => '/api/admin/test',
                'components' => [],
                'data_sources' => [],
            ],
            'lock_version' => 0,
        ]);
    }

    private function putContent(TemplateLayout $layout, array $contentOverrides): array
    {
        $content = array_merge([
            'version' => '1.0.0',
            'layout_name' => 'patch-target',
            'endpoint' => '/api/admin/test',
            'components' => [
                ['id' => 'root', 'name' => 'Div', 'type' => 'basic', 'props' => []],
            ],
            'data_sources' => [],
        ], $contentOverrides);

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}",
            ['expected_lock_version' => 0, 'content' => $content],
        );
        $response->assertStatus(200);
        $layout->refresh();

        return $layout->content;
    }

    #[Test]
    public function it_preserves_data_source_nested_keys_on_save(): void
    {
        $layout = $this->makeLayout();
        $ds = [
            'id' => 'products',
            'type' => 'api',
            'endpoint' => '/api/products',
            'contentType' => 'multipart/form-data',
            'refetchOnMount' => true,
            'onSuccess' => [['handler' => 'setState', 'target' => 'local', 'params' => ['loaded' => true]]],
            'onError' => [['handler' => 'toast', 'params' => ['message' => '{{error.message}}']]],
            'errorHandling' => ['403' => ['handler' => 'showErrorPage', 'target' => '403']],
            'errorCondition' => ['if' => '{{response?.data?.x !== true}}', 'errorCode' => 403],
            'initLocal' => 'form',
            'initGlobal' => ['key' => 'cartCount', 'path' => 'count'],
            'initIsolated' => 'sliderState',
            'if' => '{{ route.id }}',
            'conditions' => [['field' => 'x', 'op' => '==', 'value' => 1]],
        ];

        $stored = $this->putContent($layout, ['data_sources' => [$ds]]);
        $savedDs = $stored['data_sources'][0];

        foreach (['contentType', 'refetchOnMount', 'onSuccess', 'onError', 'errorHandling', 'errorCondition', 'initLocal', 'initGlobal', 'initIsolated', 'if', 'conditions'] as $key) {
            $this->assertArrayHasKey($key, $savedDs, "data_source.$key 가 저장 시 탈락됨 (R9)");
        }
        $this->assertSame('multipart/form-data', $savedDs['contentType']);
        $this->assertTrue($savedDs['refetchOnMount']);
        $this->assertCount(1, $savedDs['onSuccess']);
        $this->assertSame('showErrorPage', $savedDs['errorHandling']['403']['handler']);
    }

    #[Test]
    public function it_preserves_websocket_data_source_keys_on_save(): void
    {
        $layout = $this->makeLayout();
        $ds = [
            'id' => 'live',
            'type' => 'websocket',
            'channel' => 'core.admin.dashboard',
            'event' => 'dashboard.stats.updated',
            'channel_type' => 'private',
            'target_source' => 'dashboard_stats',
            'onReceive' => [['handler' => 'refetchDataSource', 'params' => ['dataSourceId' => 'dashboard_stats']]],
        ];
        $stored = $this->putContent($layout, ['data_sources' => [$ds]]);
        $savedDs = $stored['data_sources'][0];
        foreach (['channel', 'event', 'channel_type', 'target_source', 'onReceive'] as $key) {
            $this->assertArrayHasKey($key, $savedDs, "websocket data_source.$key 탈락 (R9)");
        }
        $this->assertSame('private', $savedDs['channel_type']);
    }

    #[Test]
    public function it_preserves_top_level_error_handling_codes_on_save(): void
    {
        $layout = $this->makeLayout();
        $stored = $this->putContent($layout, [
            'errorHandling' => [
                '403' => ['handler' => 'showErrorPage', 'target' => '403'],
                'default' => ['handler' => 'toast', 'params' => ['message' => '{{error.message}}']],
            ],
        ]);
        $this->assertArrayHasKey('errorHandling', $stored);
        $this->assertSame('showErrorPage', $stored['errorHandling']['403']['handler']);
        $this->assertSame('toast', $stored['errorHandling']['default']['handler']);
    }

    #[Test]
    public function it_preserves_node_top_level_data_key_on_save(): void
    {
        // dataKey 는 노드 **최상위** 구조 키(`node.dataKey`, props 아님 —/DynamicRenderer.tsx:2470).
        // 저장 경로에서 `content.components` 는 `['array']`(하위 `.*` 규칙 없음)라 validated() 가
        // 컴포넌트 트리를 통째 보존하고, `stripInheritedNode` 는 `__source`/`_fromBase` 만 제거하므로
        // dataKey 는 살아남는다. 누군가 `content.components.*` 하위 규칙을 추가하면 dataKey 가 조용히
        // 떨어지는 회귀가 발생하므로 그 회귀를 본 가드가 차단한다(if/actions 등 다른 노드 최상위 키와 동일 부류).
        $layout = $this->makeLayout();
        $stored = $this->putContent($layout, [
            'components' => [
                [
                    'id' => 'order_form',
                    'name' => 'Form',
                    'type' => 'basic',
                    'dataKey' => 'form',
                    'props' => [],
                    'children' => [
                        ['id' => 'inner', 'name' => 'Div', 'type' => 'basic', 'dataKey' => '_global.formData', 'props' => []],
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('dataKey', $stored['components'][0], 'node.dataKey 가 저장 시 탈락됨');
        $this->assertSame('form', $stored['components'][0]['dataKey']);
        // props 오염 0 가드 — dataKey 가 props 로 새지 않음
        $this->assertArrayNotHasKey('dataKey', $stored['components'][0]['props'] ?? [], 'dataKey 가 props 로 새면 안 됨');
        // 중첩 자식의 노드 최상위 dataKey(접두 표현식 포함)도 보존
        $this->assertSame('_global.formData', $stored['components'][0]['children'][0]['dataKey']);
    }

    #[Test]
    public function it_preserves_top_level_init_isolated_on_save(): void
    {
        // initIsolated 는 본 작업 신규 명시 키 — 미명시였다면 validated() 가 떨궜다(R9 가드).
        $layout = $this->makeLayout();
        $stored = $this->putContent($layout, [
            'initIsolated' => ['sliderState' => ['idx' => 0], 'wizard' => ['step' => 1]],
        ]);
        $this->assertArrayHasKey('initIsolated', $stored);
        $this->assertSame(0, $stored['initIsolated']['sliderState']['idx']);
        $this->assertSame(1, $stored['initIsolated']['wizard']['step']);
    }

    #[Test]
    public function it_preserves_top_level_computed_on_save(): void
    {
        // [자동 계산] 탭이 패치하는 최상위 computed — `content.computed` 는 `['array']`(하위 `.*`
        // 규칙 없음)라 validated() 가 맵을 통째 보존. 누군가 하위 규칙을 추가하면 키가 조용히
        // 떨어지는 회귀를 차단한다(부모/자식 병합본 저장 라운드트립).
        $layout = $this->makeLayout();
        $stored = $this->putContent($layout, [
            'computed' => [
                'isReadOnly' => '{{ _local.forced ?? false }}',
                'total' => '{{ products.data.data.length }}',
            ],
        ]);
        $this->assertArrayHasKey('computed', $stored);
        $this->assertSame('{{ _local.forced ?? false }}', $stored['computed']['isReadOnly']);
        $this->assertSame('{{ products.data.data.length }}', $stored['computed']['total']);
    }

    #[Test]
    public function it_preserves_top_level_transition_overlay_on_save(): void
    {
        // [로딩 화면] 탭이 패치하는 최상위 transition_overlay — 하위 키가 rules 에 명시돼 있어
        // validated() 가 명시 하위(enabled/style/target/fallback_target/skeleton.*/spinner.*/
        // wait_for)를 보존한다. 명시 누락 하위 키가 생기면 조용히 떨어지는 회귀를 차단.
        $layout = $this->makeLayout();
        // wait_for 는 progressive 데이터소스만 허용(withValidator) — 동반 data_source 를 둔다.
        $stored = $this->putContent($layout, [
            'data_sources' => [
                ['id' => 'products', 'type' => 'api', 'endpoint' => '/api/products', 'loading_strategy' => 'progressive'],
            ],
            'transition_overlay' => [
                'enabled' => true,
                'style' => 'skeleton',
                'target' => 'main-content',
                'skeleton' => ['component' => 'PageSkeleton', 'animation' => 'wave', 'iteration_count' => 3],
                'wait_for' => ['products'],
            ],
        ]);
        $this->assertArrayHasKey('transition_overlay', $stored);
        $ov = $stored['transition_overlay'];
        $this->assertTrue($ov['enabled']);
        $this->assertSame('skeleton', $ov['style']);
        $this->assertSame('main-content', $ov['target']);
        $this->assertSame('PageSkeleton', $ov['skeleton']['component']);
        $this->assertSame('wave', $ov['skeleton']['animation']);
        $this->assertSame(3, $ov['skeleton']['iteration_count']);
        $this->assertSame(['products'], $ov['wait_for']);
    }

    #[Test]
    public function it_preserves_node_top_level_isolated_state_on_save(): void
    {
        // [격리 영역] 컨트롤이 부여하는 node.isolatedState / node.isolatedScopeId 는 노드 최상위
        // 구조 키(props 아님). dataKey 와 동일하게 components 트리 통째 보존으로 살아남는다.
        $layout = $this->makeLayout();
        $stored = $this->putContent($layout, [
            'components' => [
                [
                    'id' => 'scroll_area',
                    'name' => 'Div',
                    'type' => 'basic',
                    'isolatedState' => ['idx' => 0],
                    'isolatedScopeId' => 'scroll_area-scope',
                    'props' => [],
                ],
            ],
        ]);
        $this->assertArrayHasKey('isolatedState', $stored['components'][0], 'node.isolatedState 탈락');
        $this->assertArrayHasKey('isolatedScopeId', $stored['components'][0], 'node.isolatedScopeId 탈락');
        $this->assertSame('scroll_area-scope', $stored['components'][0]['isolatedScopeId']);
        $this->assertArrayNotHasKey('isolatedState', $stored['components'][0]['props'] ?? [], 'props 오염 금지');
    }
}
