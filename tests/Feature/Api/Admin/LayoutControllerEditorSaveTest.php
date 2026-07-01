<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\LayoutExtension;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 레이아웃 편집기 저장 흐름 — 낙관적 잠금
 *
 * 코드 편집기 ↔ 레이아웃 편집기 동시 저장 시 silent overwrite 방지를 위한
 * `lock_version` + `expected_lock_version` 흐름을 검증합니다.
 *
 * @see tests/scenarios/layout-optimistic-lock.yaml
 */
class LayoutControllerEditorSaveTest extends TestCase
{
    use RefreshDatabase;

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
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    private function makeLayout(int $lockVersion = 0): TemplateLayout
    {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'editor-target',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'editor-target',
                'endpoint' => '/api/admin/test',
                'components' => [],
                'data_sources' => [],
            ],
            'lock_version' => $lockVersion,
        ]);
    }

    private function defaultUpdatePayload(int $expectedLockVersion): array
    {
        return [
            'expected_lock_version' => $expectedLockVersion,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'editor-target',
                'endpoint' => '/api/admin/test',
                'components' => [
                    [
                        'id' => 'edited',
                        'name' => 'Div',
                        'type' => 'basic',
                        'props' => ['className' => 'p-4'],
                    ],
                ],
                'data_sources' => [],
            ],
        ];
    }

    #[Test]
    public function it_returns_200_and_increments_lock_version_when_expected_matches(): void
    {
        $layout = $this->makeLayout(lockVersion: 0);

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}",
            $this->defaultUpdatePayload(expectedLockVersion: 0),
        );

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.lock_version'));

        $layout->refresh();
        $this->assertEquals(1, $layout->lock_version);
    }

    /**
     *  (D13) — 편집기 색 모드 × 디바이스 스타일 편집은 node 에 `responsive` /
     * `if` (최상위) 키를 추가한다. ValidLayoutStructure 는 화이트리스트가 아니라 형식
     * 검증이므로 미언급 키를 거부하지 않고, content.components 는 'array' 규칙으로 통째
     * 보존된다(validated() drop 은 meta/seo 등 nested 명시 키에만 해당 — node 내부 아님).
     * 저장 → DB 재로드 시 responsive/if 가 그대로 보존됨을 가드한다(백엔드 코드 변경 없음).
     */
    #[Test]
    public function it_preserves_node_responsive_and_if_overrides_on_save_and_reload(): void
    {
        $layout = $this->makeLayout(lockVersion: 0);

        $payload = $this->defaultUpdatePayload(expectedLockVersion: 0);
        $payload['content']['components'] = [
            [
                'id' => 'scoped-node',
                'name' => 'Div',
                'type' => 'basic',
                'props' => ['className' => 'bg-white dark:bg-slate-800'],
                'if' => '{{ _global?.currentUser?.uuid }}',
                'responsive' => [
                    'mobile' => [
                        'props' => ['className' => 'block'],
                        'if' => '{{ query?.tab === "m" }}',
                    ],
                    '600-900' => [
                        'props' => ['className' => 'flex'],
                    ],
                ],
            ],
        ];

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}",
            $payload,
        );

        $response->assertStatus(200);

        // DB 재로드 — responsive/if 가 그대로 보존되어야 한다.
        $node = $layout->fresh()->content['components'][0];

        $this->assertSame('{{ _global?.currentUser?.uuid }}', $node['if'], '최상위 if 보존');
        $this->assertArrayHasKey('responsive', $node, 'responsive 브랜치 보존');
        $this->assertSame('block', $node['responsive']['mobile']['props']['className']);
        $this->assertSame('{{ query?.tab === "m" }}', $node['responsive']['mobile']['if']);
        $this->assertSame('flex', $node['responsive']['600-900']['props']['className']);
        // 라이트/다크 공존 className 도 보존
        $this->assertSame('bg-white dark:bg-slate-800', $node['props']['className']);
    }

    #[Test]
    public function it_returns_409_when_expected_version_is_stale(): void
    {
        $layout = $this->makeLayout(lockVersion: 5);

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}",
            $this->defaultUpdatePayload(expectedLockVersion: 3),
        );

        $response->assertStatus(409);
        $this->assertEquals('concurrent_modification', $response->json('errors.error'));
        $this->assertEquals(5, $response->json('errors.current_version'));
        $this->assertEquals(3, $response->json('errors.your_version'));

        $layout->refresh();
        $this->assertEquals(5, $layout->lock_version, 'lock_version 은 충돌 시 증가하지 않아야 한다');
    }

    #[Test]
    public function it_returns_422_when_expected_lock_version_is_missing(): void
    {
        $layout = $this->makeLayout(lockVersion: 0);

        $payload = $this->defaultUpdatePayload(expectedLockVersion: 0);
        unset($payload['expected_lock_version']);

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}",
            $payload,
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['expected_lock_version']);
    }

    #[Test]
    public function concurrent_save_only_first_client_succeeds(): void
    {
        $layout = $this->makeLayout(lockVersion: 0);

        // 두 클라이언트 모두 lock_version=0 를 로드한 상태
        $payloadA = $this->defaultUpdatePayload(expectedLockVersion: 0);
        $payloadA['content']['components'][0]['id'] = 'edit_by_A';

        $payloadB = $this->defaultUpdatePayload(expectedLockVersion: 0);
        $payloadB['content']['components'][0]['id'] = 'edit_by_B';

        // 첫 번째 저장 — 성공
        $responseA = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}",
            $payloadA,
        );
        $responseA->assertStatus(200);

        // 두 번째 저장 — 같은 expected=0 으로 시도, current=1 이므로 409
        $responseB = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}",
            $payloadB,
        );
        $responseB->assertStatus(409);

        $layout->refresh();
        $this->assertEquals(1, $layout->lock_version);
        // A 의 변경이 반영되어 있어야 한다
        $this->assertEquals('edit_by_A', $layout->content['components'][0]['id']);
    }

    #[Test]
    public function it_exposes_lock_version_in_layout_resource(): void
    {
        $layout = $this->makeLayout(lockVersion: 7);

        $response = $this->authRequest()->getJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}",
        );

        $response->assertStatus(200);
        $this->assertEquals(7, $response->json('data.lock_version'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Phase 3 S5a-2 결함 I — 편집기 응답 메타 노드 마스킹 백엔드 2차 가드
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_strips_base_and_extension_and_partial_nodes_from_payload(): void
    {
        $layout = $this->makeLayout(lockVersion: 0);

        $payload = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'editor-target',
                'endpoint' => '/api/admin/test',
                // 편집기 응답이 그대로 전송된 케이스 — base/extension/partial 노드가 섞여 있음
                'components' => [
                    [
                        'id' => 'base-header',
                        'name' => 'Div',
                        'type' => 'basic',
                        '_fromBase' => true,
                        '__source' => ['kind' => 'base', 'layout' => '_base'],
                    ],
                    [
                        'id' => 'ext-banner',
                        'name' => 'AdBanner',
                        'type' => 'composite',
                        '__source' => ['kind' => 'extension', 'extensionId' => 7],
                    ],
                    [
                        'id' => 'partial-fragment',
                        'name' => 'Section',
                        'type' => 'basic',
                        '__source' => ['kind' => 'partial'],
                    ],
                    [
                        'id' => 'route-main',
                        'name' => 'Main',
                        'type' => 'basic',
                        '__source' => ['kind' => 'route', 'layout' => 'editor-target'],
                    ],
                ],
                'data_sources' => [],
            ],
        ];

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}",
            $payload,
        );

        $response->assertStatus(200);

        $layout->refresh();
        $components = $layout->content['components'];

        // base / extension / partial 출처 노드 3건은 제거되어 1건만 남아야 한다
        $this->assertCount(1, $components, 'base/extension/partial 출처 노드는 저장 전에 제거되어야 한다');
        $this->assertEquals('route-main', $components[0]['id']);
        // 살아남은 route 노드의 __source 메타도 제거되어야 한다 (저장 형식 = 원본 형식)
        $this->assertArrayNotHasKey('__source', $components[0]);
        $this->assertArrayNotHasKey('_fromBase', $components[0]);
    }

    #[Test]
    public function it_strips_inherited_nodes_recursively_inside_route_children(): void
    {
        $layout = $this->makeLayout(lockVersion: 0);

        $payload = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'editor-target',
                'endpoint' => '/api/admin/test',
                'components' => [
                    [
                        'id' => 'route-main',
                        'name' => 'Main',
                        'type' => 'basic',
                        '__source' => ['kind' => 'route', 'layout' => 'editor-target'],
                        'children' => [
                            [
                                'id' => 'keep-me',
                                'name' => 'P',
                                'type' => 'basic',
                            ],
                            [
                                'id' => 'inherited-from-base',
                                'name' => 'Header',
                                'type' => 'basic',
                                '_fromBase' => true,
                            ],
                            [
                                'id' => 'injected-from-ext',
                                'name' => 'AdBanner',
                                'type' => 'composite',
                                '__source' => ['kind' => 'extension', 'extensionId' => 3],
                            ],
                        ],
                    ],
                ],
                'data_sources' => [],
            ],
        ];

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}",
            $payload,
        );

        $response->assertStatus(200);

        $layout->refresh();
        $components = $layout->content['components'];
        $this->assertCount(1, $components);
        $children = $components[0]['children'];
        $this->assertCount(1, $children, 'route 노드 안의 base/extension 자손도 재귀 제거되어야 한다');
        $this->assertEquals('keep-me', $children[0]['id']);
        $this->assertArrayNotHasKey('_fromBase', $children[0]);
    }

    #[Test]
    public function it_uses_editor_original_container_when_present_for_extends_child(): void
    {
        // extends 자식 레이아웃을 만들고, 편집 모드 응답이 __editor.original 으로 자식 원본을 동봉한 케이스.
        // 백엔드 2차 가드가 그 원본을 SSoT 로 사용해 저장한다.
        $baseLayout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_base',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => '_base',
                'endpoint' => '/api/admin/test',
                'components' => [
                    ['type' => 'basic', 'name' => 'Slot', 'slot' => 'main'],
                ],
            ],
            'lock_version' => 0,
        ]);

        $childLayout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'child-page',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'child-page',
                'extends' => '_base',
                'slots' => [
                    'main' => [
                        ['type' => 'basic', 'name' => 'OriginalContent'],
                    ],
                ],
            ],
            'lock_version' => 0,
        ]);

        // 편집기 응답 시뮬레이션 — 머지된 components + __editor.original 컨테이너 동봉
        $payload = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'child-page',
                'components' => [
                    [
                        'type' => 'basic',
                        'name' => 'Slot',
                        '_fromBase' => true,
                        '__source' => ['kind' => 'base', 'layout' => '_base'],
                        'children' => [
                            [
                                'type' => 'basic',
                                'name' => 'EditedContent',
                                '__source' => ['kind' => 'route', 'layout' => 'child-page'],
                            ],
                        ],
                    ],
                ],
                'lock_version' => 0,
                '__editor' => [
                    'original' => [
                        'version' => '1.0.0',
                        'layout_name' => 'child-page',
                        'extends' => '_base',
                        'slots' => [
                            'main' => [
                                ['type' => 'basic', 'name' => 'EditedContent'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$childLayout->name}",
            $payload,
        );

        $response->assertStatus(200);

        $childLayout->refresh();
        // 자식 레이아웃 원본 형식으로 저장되어야 한다 — extends + slots
        $this->assertEquals('_base', $childLayout->content['extends']);
        $this->assertArrayHasKey('slots', $childLayout->content);
        $this->assertEquals('EditedContent', $childLayout->content['slots']['main'][0]['name']);
        // 머지된 components 는 자식에 박히지 않아야 한다
        $this->assertArrayNotHasKey('components', $childLayout->content);
        // 응답 전용 메타 모두 제거
        $this->assertArrayNotHasKey('lock_version', $childLayout->content);
        $this->assertArrayNotHasKey('__editor', $childLayout->content);
    }

    #[Test]
    public function it_drops_response_only_lock_version_meta_from_content(): void
    {
        $layout = $this->makeLayout(lockVersion: 0);

        $payload = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'editor-target',
                'endpoint' => '/api/admin/test',
                // 응답 전용 메타 — 페이로드에 잘못 포함된 케이스
                'lock_version' => 42,
                'components' => [
                    [
                        'id' => 'route-main',
                        'name' => 'Main',
                        'type' => 'basic',
                    ],
                ],
                'data_sources' => [],
            ],
        ];

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}",
            $payload,
        );

        $response->assertStatus(200);

        $layout->refresh();
        // content 안의 lock_version 메타는 저장되지 않아야 한다
        $this->assertArrayNotHasKey('lock_version', $layout->content);
        // 실제 lock_version 은 DB 컬럼 기반으로 1 증가
        $this->assertEquals(1, $layout->lock_version);
    }

    #[Test]
    public function layout_extension_update_shares_same_concurrency_guard(): void
    {
        $extension = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => 'extension_point',
            'target_name' => 'header',
            'source_type' => 'template',
            'source_identifier' => $this->template->identifier,
            'content' => [
                'extension_point' => 'header',
                'components' => [],
            ],
            'lock_version' => 4,
        ]);

        // stale expected → 409
        $stale = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}",
            [
                'expected_lock_version' => 2,
                'content' => [
                    'extension_point' => 'header',
                    'components' => [['type' => 'basic', 'name' => 'Span']],
                ],
            ],
        );
        $stale->assertStatus(409);
        $this->assertEquals('concurrent_modification', $stale->json('errors.error'));

        // match → 200, lock_version 증가
        $ok = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}",
            [
                'expected_lock_version' => 4,
                'content' => [
                    'extension_point' => 'header',
                    'components' => [['type' => 'basic', 'name' => 'Span']],
                ],
            ],
        );
        $ok->assertStatus(200);

        $extension->refresh();
        $this->assertEquals(5, $extension->lock_version);
    }
}
