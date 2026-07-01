<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * InitActionsInheritedSaveTest — init_actions 부모/자식 상속 저장 라운드트립
 *
 * 부모(_user_base) + 자식(extends) init_actions 가 편집 모드(with_source_meta=1)에서 병합·메타
 * 부착되어 노출되지만, 자식 저장은 자식 init_actions 만 영속하고 부모분/`__source` 는 PUT 에서
 * 제거된다. 핵심 사실(plan):
 *  - 편집 응답 raw 는 병합본(부모+자식), 자식 원본은 `__editor.original` 에 별도 보존
 *    (LayoutService::loadAndMergeLayoutInternal).
 *  - 저장 시 클라이언트가 `__editor.original` 에서 자식 원본만 복원해 PUT → 부모분 자동 제외.
 *  - 백엔드 `validated()` 는 `content.init_actions` 를 `['nullable','array']` 로 통째 보존하므로
 *    자식이 보낸 배열을 가공 없이 적재한다(R9 부류). `__source` 마킹은 운영/저장 페이로드에 없다.
 *
 * 위조 PUT(부모 init_action 을 자식 content 에 섞어 보냄)은 백엔드가 거부하지 않고 그대로
 * 적재한다 — init_actions 는 노드 트리가 아니라 최상위 배열이라 `stripInheritedFromLayoutContent`
 * 의 노드 마스킹 대상이 아니기 때문이다. 따라서 부모분 제외의 SSoT 는 클라이언트(`__editor.original`)
 * 이며, 백엔드가 보장하는 것은 `__source` 같은 편집 전용 메타가 페이로드에 섞여도 저장 후 재로드
 * 시 운영 동작에 영향이 없다는 점이다. 본 테스트는 그 실제 동작을 그대로 가드한다.
 */
class InitActionsInheritedSaveTest extends TestCase
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

    /**
     * 부모(base) 레이아웃 생성 — 슬롯 + init_actions 보유.
     */
    private function makeParent(array $initActions): TemplateLayout
    {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_user_base',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => '_user_base',
                'components' => [
                    ['id' => 'shell', 'name' => 'Div', 'type' => 'basic', 'props' => [], 'children' => [
                        ['id' => 'content_slot', 'name' => 'Div', 'type' => 'basic', 'slot' => 'content', 'props' => []],
                    ]],
                ],
                'data_sources' => [],
                'init_actions' => $initActions,
            ],
            'extends' => null,
            'lock_version' => 0,
        ]);
    }

    /**
     * 자식 레이아웃 생성 — 부모를 extends, init_actions 보유.
     */
    private function makeChild(array $initActions): TemplateLayout
    {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'home',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'home',
                'extends' => '_user_base',
                'slots' => [
                    'content' => [
                        ['id' => 'root', 'name' => 'Div', 'type' => 'basic', 'props' => []],
                    ],
                ],
                'data_sources' => [],
                'init_actions' => $initActions,
            ],
            'extends' => '_user_base',
            'lock_version' => 0,
        ]);
    }

    /**
     * 자식 레이아웃에 content 를 PUT 하고 갱신된 DB content 를 반환.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function putChild(TemplateLayout $child, array $content): array
    {
        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$child->name}",
            ['expected_lock_version' => 0, 'content' => $content],
        );
        $response->assertStatus(200);
        $child->refresh();

        return $child->content;
    }

    /**
     * 자식 PUT 의 기본 content 골격(extends + slots + 자식 init_actions).
     *
     * @param  array<int, array<string, mixed>>  $childInitActions
     * @return array<string, mixed>
     */
    private function childContent(array $childInitActions): array
    {
        return [
            'version' => '1.0.0',
            'layout_name' => 'home',
            'extends' => '_user_base',
            'slots' => [
                'content' => [
                    ['id' => 'root', 'name' => 'Div', 'type' => 'basic', 'props' => []],
                ],
            ],
            'data_sources' => [],
            'init_actions' => $childInitActions,
        ];
    }

    #[Test]
    public function it_persists_only_child_init_actions_on_save(): void
    {
        // ① 자식 [화면동작] 편집 후 PUT → 자식 init_actions 만 (부모분 미포함, __source 부재)
        // ② DB 저장된 자식 content.init_actions = 자식 원본만 (부모 복제 0)
        $this->makeParent([['handler' => 'initTheme'], ['handler' => 'initCartKey']]);
        $child = $this->makeChild([['handler' => 'toast']]);

        $childActions = [
            ['handler' => 'toast', 'params' => ['message' => 'hi']],
            ['handler' => 'setState', 'target' => 'local', 'params' => ['ready' => true]],
        ];

        $stored = $this->putChild($child, $this->childContent($childActions));

        $this->assertArrayHasKey('init_actions', $stored);
        $this->assertCount(2, $stored['init_actions'], '자식 init_actions 만 저장되어야 함(부모 복제 0)');
        $this->assertSame('toast', $stored['init_actions'][0]['handler']);
        $this->assertSame('setState', $stored['init_actions'][1]['handler']);

        // 부모 핸들러(initTheme/initCartKey)는 자식 content 에 없어야 함
        $handlers = array_column($stored['init_actions'], 'handler');
        $this->assertNotContains('initTheme', $handlers, '부모 init_action 이 자식에 영속되면 안 됨');
        $this->assertNotContains('initCartKey', $handlers);

        // __source 메타가 페이로드에 섞이지 않음
        foreach ($stored['init_actions'] as $item) {
            $this->assertArrayNotHasKey('__source', $item, '편집 전용 __source 메타가 저장되면 안 됨');
        }
    }

    #[Test]
    public function it_remerges_parent_and_reattaches_source_meta_on_reload(): void
    {
        // ③ 저장 후 재로드(with_source_meta=1) → 부모+자식 다시 병합·메타 재부착(영속 아님)
        $this->makeParent([['handler' => 'initTheme'], ['handler' => 'initCartKey']]);
        $child = $this->makeChild([['handler' => 'toast']]);

        // 자식 저장(자식 init_actions 만)
        $this->putChild($child, $this->childContent([['handler' => 'toast']]));

        // DB content 자체에는 부모분·__source 가 없다(영속 아님)
        $child->refresh();
        $this->assertCount(1, $child->content['init_actions']);
        $this->assertArrayNotHasKey('__source', $child->content['init_actions'][0]);

        // 편집 모드 재로드 → 부모 먼저 + 자식 나중 순서로 병합, __source 메타 재부착
        $merged = app(LayoutService::class)->loadAndMergeLayout($this->template->id, 'home', true);

        $this->assertArrayHasKey('initActions', $merged, '병합본은 initActions 키로 노출');
        $this->assertCount(3, $merged['initActions'], '부모 2 + 자식 1 = 3 병합');
        $this->assertSame('initTheme', $merged['initActions'][0]['handler']);
        $this->assertSame('initCartKey', $merged['initActions'][1]['handler']);
        $this->assertSame('toast', $merged['initActions'][2]['handler']);

        // __source 재부착: 부모=base/_user_base, 자식=route/home
        $this->assertSame(['kind' => 'base', 'layout' => '_user_base'], $merged['initActions'][0]['__source']);
        $this->assertSame(['kind' => 'base', 'layout' => '_user_base'], $merged['initActions'][1]['__source']);
        $this->assertSame(['kind' => 'route', 'layout' => 'home'], $merged['initActions'][2]['__source']);

        // 자식 원본은 __editor.original 에 별도 보존(자식분만)
        $this->assertArrayHasKey('__editor', $merged);
        $this->assertCount(1, $merged['__editor']['original']['init_actions']);
        $this->assertSame('toast', $merged['__editor']['original']['init_actions'][0]['handler']);
    }

    #[Test]
    public function it_persists_parent_init_actions_when_editing_base_layout(): void
    {
        // ④ base 편집 모드 저장 → 부모(_user_base) 행에 부모 init_actions 영속
        $parent = $this->makeParent([['handler' => 'initTheme']]);
        $this->makeChild([['handler' => 'toast']]);

        $newParentActions = [
            ['handler' => 'initTheme'],
            ['handler' => 'initCartKey'],
        ];

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$parent->name}",
            ['expected_lock_version' => 0, 'content' => [
                'version' => '1.0.0',
                'layout_name' => '_user_base',
                'components' => [
                    ['id' => 'shell', 'name' => 'Div', 'type' => 'basic', 'props' => [], 'children' => [
                        ['id' => 'content_slot', 'name' => 'Div', 'type' => 'basic', 'slot' => 'content', 'props' => []],
                    ]],
                ],
                'data_sources' => [],
                'init_actions' => $newParentActions,
            ]],
        );
        $response->assertStatus(200);

        $parent->refresh();
        $this->assertArrayHasKey('init_actions', $parent->content);
        $this->assertCount(2, $parent->content['init_actions']);
        $this->assertSame('initTheme', $parent->content['init_actions'][0]['handler']);
        $this->assertSame('initCartKey', $parent->content['init_actions'][1]['handler']);
    }

    #[Test]
    public function it_leaves_parent_row_unchanged_when_child_is_saved(): void
    {
        // ④ 보강 — 자식 저장이 부모 행을 변경하지 않음
        $parent = $this->makeParent([['handler' => 'initTheme'], ['handler' => 'initCartKey']]);
        $child = $this->makeChild([['handler' => 'toast']]);

        $this->putChild($child, $this->childContent([
            ['handler' => 'toast'],
            ['handler' => 'navigate', 'params' => ['path' => '/']],
        ]));

        $parent->refresh();
        $this->assertCount(2, $parent->content['init_actions'], '부모 행 init_actions 불변');
        $this->assertSame('initTheme', $parent->content['init_actions'][0]['handler']);
        $this->assertSame('initCartKey', $parent->content['init_actions'][1]['handler']);
        $this->assertSame(0, (int) $parent->lock_version, '부모 lock_version 불변');
    }

    #[Test]
    public function it_ignores_forged_source_meta_in_child_payload_on_reload(): void
    {
        // ⑤ 위조 PUT — 자식 content 에 __source 가 박힌 부모 init_action 을 섞어 보냄.
        //    init_actions 는 노드 트리가 아니라 최상위 배열이라 백엔드 노드 마스킹 대상이 아니다.
        //    백엔드는 배열을 그대로 적재하지만(거부하지 않음), 부모분 제외의 SSoT 는 클라이언트의
        //    __editor.original 이다. 본 테스트는 "편집 전용 __source 가 페이로드에 섞여 저장돼도
        //  재로드/운영 동작이 깨지지 않는다"는 백엔드 계약을 가드한다(plan dispatch 안전성).
        $this->makeParent([['handler' => 'initTheme']]);
        $child = $this->makeChild([['handler' => 'toast']]);

        $forged = [
            // 부모 항목을 __source 메타까지 그대로 위조해 자식 content 에 포함
            ['handler' => 'initTheme', '__source' => ['kind' => 'base', 'layout' => '_user_base']],
            ['handler' => 'toast', '__source' => ['kind' => 'route', 'layout' => 'home']],
        ];

        $stored = $this->putChild($child, $this->childContent($forged));

        // 백엔드는 최상위 배열을 통째 보존(R9) — 거부하지 않음
        $this->assertCount(2, $stored['init_actions']);

        // 재로드(with_source_meta=1) 시 부모분이 다시 앞에 붙고 __source 가 재계산된다.
        // 위조로 박힌 __source 가 운영 dispatch 화이트리스트에 미포함이라 런타임 무영향이며,
        // 재로드 병합은 위조 항목을 자식분(route)으로 취급해 부모(_user_base) 항목을 그 앞에 붙인다.
        $merged = app(LayoutService::class)->loadAndMergeLayout($this->template->id, 'home', true);

        // 부모 1 + 자식 2(위조 포함) = 3
        $this->assertCount(3, $merged['initActions']);
        // 부모 항목이 가장 앞 + base 메타로 재스탬프(위조 메타에 가려지지 않음)
        $this->assertSame('initTheme', $merged['initActions'][0]['handler']);
        $this->assertSame(['kind' => 'base', 'layout' => '_user_base'], $merged['initActions'][0]['__source']);
        // 자식 구간(위조 항목 포함)은 route/home 으로 재스탬프
        $this->assertSame(['kind' => 'route', 'layout' => 'home'], $merged['initActions'][1]['__source']);
        $this->assertSame(['kind' => 'route', 'layout' => 'home'], $merged['initActions'][2]['__source']);
    }
}
