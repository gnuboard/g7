<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private LayoutService $layoutService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->layoutService = app(LayoutService::class);
    }

    /**
     * meta 병합 테스트 - 자식 우선
     */
    public function test_merge_meta_with_child_priority(): void
    {
        $parent = [
            'meta' => [
                'title' => 'Parent Title',
                'description' => 'Parent Description',
            ],
            'data_sources' => [],
            'components' => [],
        ];

        $child = [
            'meta' => [
                'title' => 'Child Title',
            ],
            'data_sources' => [],
            'components' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertEquals('Child Title', $result['meta']['title']);
        $this->assertEquals('Parent Description', $result['meta']['description']);
    }

    /**
     * data_sources 병합 테스트 - 부모와 자식 모두 포함
     */
    public function test_merge_data_sources_without_duplicates(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [
                ['id' => 'users', 'endpoint' => '/api/users'],
            ],
            'components' => [],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [
                ['id' => 'roles', 'endpoint' => '/api/roles'],
            ],
            'components' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertCount(2, $result['data_sources']);
        $this->assertEquals('users', $result['data_sources'][0]['id']);
        $this->assertEquals('roles', $result['data_sources'][1]['id']);
    }

    /**
     * data_sources ID 중복 예외 테스트
     */
    public function test_merge_data_sources_throws_exception_on_duplicate_id(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [
                ['id' => 'users', 'endpoint' => '/api/users'],
            ],
            'components' => [],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [
                ['id' => 'users', 'endpoint' => '/api/v2/users'],
            ],
            'components' => [],
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('data_sources ID 중복: users');

        $this->layoutService->mergeLayouts($parent, $child);
    }

    /**
     * components slot 교체 테스트 - 단일 컴포넌트
     *
     * 현재 구현: 슬롯 래퍼 컴포넌트의 children에 슬롯 내용이 삽입됨
     * 슬롯 래퍼의 id, name, props 등은 유지됨
     */
    public function test_merge_components_replaces_single_slot(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [
                [
                    'component' => 'Container',
                    'children' => [
                        ['component' => 'Header', 'slot' => 'header'],
                        ['component' => 'Body', 'slot' => 'content'],
                    ],
                ],
            ],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'slots' => [
                'header' => [
                    'component' => 'CustomHeader',
                    'props' => ['title' => 'Dashboard'],
                ],
                'content' => [
                    'component' => 'DashboardContent',
                ],
            ],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertEquals('Container', $result['components'][0]['component']);
        // 슬롯 래퍼(Header)의 children에 슬롯 내용(CustomHeader)이 삽입됨
        $this->assertEquals('Header', $result['components'][0]['children'][0]['component']);
        $this->assertEquals('CustomHeader', $result['components'][0]['children'][0]['children'][0]['component']);
        $this->assertEquals('Dashboard', $result['components'][0]['children'][0]['children'][0]['props']['title']);
        // 슬롯 래퍼(Body)의 children에 슬롯 내용(DashboardContent)이 삽입됨
        $this->assertEquals('Body', $result['components'][0]['children'][1]['component']);
        $this->assertEquals('DashboardContent', $result['components'][0]['children'][1]['children'][0]['component']);
    }

    /**
     * components slot 교체 테스트 - 복수 컴포넌트
     *
     * 현재 구현: 슬롯 래퍼 컴포넌트의 children에 복수의 슬롯 내용이 삽입됨
     */
    public function test_merge_components_replaces_multiple_slots(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [
                [
                    'component' => 'Layout',
                    'children' => [
                        ['component' => 'Slot', 'slot' => 'widgets'],
                    ],
                ],
            ],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'slots' => [
                'widgets' => [
                    [
                        'component' => 'Widget1',
                    ],
                    [
                        'component' => 'Widget2',
                    ],
                ],
            ],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertEquals('Layout', $result['components'][0]['component']);
        // 슬롯 래퍼(Slot)의 children에 복수의 슬롯 내용이 삽입됨
        $this->assertEquals('Slot', $result['components'][0]['children'][0]['component']);
        $this->assertCount(2, $result['components'][0]['children'][0]['children']);
        $this->assertEquals('Widget1', $result['components'][0]['children'][0]['children'][0]['component']);
        $this->assertEquals('Widget2', $result['components'][0]['children'][0]['children'][1]['component']);
    }

    /**
     * 불필요한 필드 제거 테스트
     */
    public function test_removes_unnecessary_fields(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [
                ['component' => 'Header', 'slot' => 'header'],
            ],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'extends' => 'layouts/_base',
            'slots' => [
                'header' => ['component' => 'CustomHeader'],
            ],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        // extends와 slots 필드가 제거되어야 함
        $this->assertArrayNotHasKey('extends', $result);
        $this->assertArrayNotHasKey('slots', $result);

        // components 내부의 slot 필드도 제거되어야 함
        $this->assertArrayNotHasKey('slot', $result['components'][0]);
    }

    /**
     * 복잡한 중첩 구조 병합 테스트
     *
     * 현재 구현: 슬롯 래퍼 컴포넌트의 children에 슬롯 내용이 삽입됨
     */
    public function test_merge_complex_nested_structure(): void
    {
        $parent = [
            'meta' => ['version' => '1.0'],
            'data_sources' => [
                ['id' => 'config', 'endpoint' => '/api/config'],
            ],
            'components' => [
                [
                    'component' => 'Layout',
                    'children' => [
                        [
                            'component' => 'Sidebar',
                            'children' => [
                                ['component' => 'Menu', 'slot' => 'menu'],
                            ],
                        ],
                        ['component' => 'Main', 'slot' => 'content'],
                    ],
                ],
            ],
        ];

        $child = [
            'meta' => ['title' => 'Dashboard'],
            'data_sources' => [
                ['id' => 'stats', 'endpoint' => '/api/stats'],
            ],
            'slots' => [
                'menu' => [
                    'component' => 'DashboardMenu',
                ],
                'content' => [
                    'component' => 'DashboardContent',
                ],
            ],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        // meta 병합 확인
        $this->assertEquals('1.0', $result['meta']['version']);
        $this->assertEquals('Dashboard', $result['meta']['title']);

        // data_sources 병합 확인
        $this->assertCount(2, $result['data_sources']);

        // 중첩된 slot 교체 확인 - 슬롯 래퍼(Menu)의 children에 DashboardMenu가 삽입됨
        $this->assertEquals('Menu', $result['components'][0]['children'][0]['children'][0]['component']);
        $this->assertEquals('DashboardMenu', $result['components'][0]['children'][0]['children'][0]['children'][0]['component']);
        // 슬롯 래퍼(Main)의 children에 DashboardContent가 삽입됨
        $this->assertEquals('Main', $result['components'][0]['children'][1]['component']);
        $this->assertEquals('DashboardContent', $result['components'][0]['children'][1]['children'][0]['component']);

        // slot 필드 제거 확인 (슬롯 래퍼에서)
        $this->assertArrayNotHasKey('slot', $result['components'][0]['children'][0]['children'][0]);
        $this->assertArrayNotHasKey('slot', $result['components'][0]['children'][1]);
    }

    /**
     * slot이 없는 컴포넌트는 그대로 유지되는지 테스트
     *
     * 현재 구현: 슬롯 래퍼의 children에 슬롯 내용이 삽입됨
     */
    public function test_preserves_components_without_slots(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [
                ['component' => 'Header', 'props' => ['title' => 'App']],
                ['component' => 'Body', 'slot' => 'content'],
            ],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'slots' => [
                'content' => ['component' => 'CustomBody'],
            ],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        // slot이 없는 Header는 그대로 유지
        $this->assertEquals('Header', $result['components'][0]['component']);
        $this->assertEquals('App', $result['components'][0]['props']['title']);

        // slot이 있는 Body의 children에 CustomBody가 삽입됨
        $this->assertEquals('Body', $result['components'][1]['component']);
        $this->assertEquals('CustomBody', $result['components'][1]['children'][0]['component']);
    }

    /**
     * getLayout - 정상적인 레이아웃 병합 테스트
     *
     * 현재 구현: 슬롯 래퍼의 children에 슬롯 내용이 삽입됨
     */
    public function test_get_layout_returns_merged_layout(): void
    {
        // 템플릿 생성 - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-'.uniqid();
        $template = Template::create([
            'identifier' => $identifier,
            'vendor' => 'test',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        // 부모 레이아웃 생성
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'base',
            'content' => [
                'meta' => ['title' => 'Base Layout'],
                'data_sources' => [],
                'components' => [
                    ['component' => 'Container', 'children' => [
                        ['component' => 'Header', 'slot' => 'header'],
                    ]],
                ],
            ],
        ]);

        // 자식 레이아웃 생성
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'extends' => 'base',
                'meta' => ['description' => 'Dashboard'],
                'data_sources' => [],
                'slots' => [
                    'header' => ['component' => 'DashboardHeader'],
                ],
            ],
        ]);

        // getLayout 메서드 호출
        $result = $this->layoutService->getLayout($identifier, 'dashboard');

        // 병합 결과 검증
        $this->assertEquals('Base Layout', $result['meta']['title']);
        $this->assertEquals('Dashboard', $result['meta']['description']);
        // 슬롯 래퍼(Header)의 children에 DashboardHeader가 삽입됨
        $this->assertEquals('Header', $result['components'][0]['children'][0]['component']);
        $this->assertEquals('DashboardHeader', $result['components'][0]['children'][0]['children'][0]['component']);
    }

    /**
     * getLayout - 템플릿을 찾을 수 없는 경우 예외 발생
     */
    public function test_get_layout_throws_exception_when_template_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage(__('exceptions.template_not_found', ['identifier' => 'non-existent']));

        $this->layoutService->getLayout('non-existent', 'dashboard');
    }

    /**
     * getLayout - 비활성화된 템플릿 접근 시 예외 발생
     */
    public function test_get_layout_throws_exception_when_template_not_active(): void
    {
        // 비활성화된 템플릿 생성
        Template::create([
            'identifier' => 'inactive-template',
            'vendor' => 'test',
            'name' => ['ko' => '비활성 템플릿', 'en' => 'Inactive Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage(__('exceptions.template_not_active', [
            'identifier' => 'inactive-template',
            'status' => ExtensionStatus::Inactive->value,
        ]));

        $this->layoutService->getLayout('inactive-template', 'dashboard');
    }

    /**
     * sanitizeLayoutJson이 script 태그를 제거하는지 테스트
     */
    public function test_sanitize_removes_script_tags(): void
    {
        $layout = [
            'components' => [
                [
                    'id' => 'c1',
                    'type' => 'basic',
                    'name' => 'Component',
                    'props' => [
                        'html' => '<div>Safe</div><script>alert("XSS")</script>',
                    ],
                ],
            ],
        ];

        $sanitized = $this->layoutService->sanitizeLayoutJson($layout);

        $this->assertStringNotContainsString('<script>', $sanitized['components'][0]['props']['html']);
        $this->assertStringNotContainsString('alert', $sanitized['components'][0]['props']['html']);
        $this->assertStringContainsString('Safe', $sanitized['components'][0]['props']['html']);
    }

    /**
     * sanitizeLayoutJson이 iframe 태그를 제거하는지 테스트
     */
    public function test_sanitize_removes_iframe_tags(): void
    {
        $layout = [
            'components' => [
                [
                    'id' => 'c1',
                    'type' => 'basic',
                    'name' => 'Component',
                    'props' => [
                        'html' => '<iframe src="http://malicious.com"></iframe>Normal',
                    ],
                ],
            ],
        ];

        $sanitized = $this->layoutService->sanitizeLayoutJson($layout);

        $this->assertStringNotContainsString('<iframe', $sanitized['components'][0]['props']['html']);
        $this->assertStringContainsString('Normal', $sanitized['components'][0]['props']['html']);
    }

    /**
     * sanitizeLayoutJson이 인라인 이벤트 핸들러를 제거하는지 테스트
     */
    public function test_sanitize_removes_inline_event_handlers(): void
    {
        $layout = [
            'components' => [
                [
                    'id' => 'c1',
                    'type' => 'basic',
                    'name' => 'Component',
                    'props' => [
                        'html' => '<img src="x" onerror="alert(\'XSS\')">',
                        'button' => '<button onclick="malicious()">Click</button>',
                    ],
                ],
            ],
        ];

        $sanitized = $this->layoutService->sanitizeLayoutJson($layout);

        $this->assertStringNotContainsString('onerror', $sanitized['components'][0]['props']['html']);
        $this->assertStringNotContainsString('onclick', $sanitized['components'][0]['props']['button']);
    }

    /**
     * sanitizeLayoutJson이 javascript: 프로토콜을 제거하는지 테스트
     */
    public function test_sanitize_removes_javascript_protocol(): void
    {
        $layout = [
            'components' => [
                [
                    'id' => 'c1',
                    'type' => 'basic',
                    'name' => 'Component',
                    'props' => [
                        'link' => '<a href="javascript:alert(\'XSS\')">Click</a>',
                    ],
                ],
            ],
        ];

        $sanitized = $this->layoutService->sanitizeLayoutJson($layout);

        $this->assertStringNotContainsString('javascript:', $sanitized['components'][0]['props']['link']);
    }

    /**
     * sanitizeLayoutJson이 data: 프로토콜을 제거하는지 테스트
     */
    public function test_sanitize_removes_data_protocol(): void
    {
        $layout = [
            'components' => [
                [
                    'id' => 'c1',
                    'type' => 'basic',
                    'name' => 'Component',
                    'props' => [
                        'img' => '<img src="data:text/html,<script>alert(\'XSS\')</script>">',
                    ],
                ],
            ],
        ];

        $sanitized = $this->layoutService->sanitizeLayoutJson($layout);

        $this->assertStringNotContainsString('data:', $sanitized['components'][0]['props']['img']);
    }

    /**
     * sanitizeLayoutJson이 HTML entities를 변환하는지 테스트
     */
    public function test_sanitize_converts_html_entities(): void
    {
        $layout = [
            'components' => [
                [
                    'id' => 'c1',
                    'type' => 'basic',
                    'name' => 'Component',
                    'props' => [
                        'text' => '<>&"\'',
                    ],
                ],
            ],
        ];

        $sanitized = $this->layoutService->sanitizeLayoutJson($layout);

        $this->assertStringContainsString('&lt;', $sanitized['components'][0]['props']['text']);
        $this->assertStringContainsString('&gt;', $sanitized['components'][0]['props']['text']);
        $this->assertStringContainsString('&amp;', $sanitized['components'][0]['props']['text']);
        $this->assertStringContainsString('&quot;', $sanitized['components'][0]['props']['text']);
    }

    /**
     * sanitizeLayoutJson이 중첩된 컴포넌트의 props도 sanitize하는지 테스트
     */
    public function test_sanitize_nested_components(): void
    {
        $layout = [
            'components' => [
                [
                    'id' => 'c1',
                    'type' => 'composite',
                    'name' => 'Container',
                    'children' => [
                        [
                            'id' => 'c1-1',
                            'type' => 'basic',
                            'name' => 'Child',
                            'props' => [
                                'html' => '<script>alert("XSS")</script>',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $sanitized = $this->layoutService->sanitizeLayoutJson($layout);

        $this->assertStringNotContainsString('<script>', $sanitized['components'][0]['children'][0]['props']['html']);
    }

    /**
     * sanitizeLayoutJson이 data_sources의 endpoint를 sanitize하는지 테스트
     */
    public function test_sanitize_data_sources_endpoint(): void
    {
        $layout = [
            'data_sources' => [
                [
                    'id' => 'ds1',
                    'type' => 'api',
                    'endpoint' => 'javascript:alert("XSS")',
                ],
                [
                    'id' => 'ds2',
                    'type' => 'api',
                    'endpoint' => '/api/admin/users',
                ],
            ],
        ];

        $sanitized = $this->layoutService->sanitizeLayoutJson($layout);

        $this->assertEquals('', $sanitized['data_sources'][0]['endpoint']); // javascript: 제거됨
        $this->assertEquals('/api/admin/users', $sanitized['data_sources'][1]['endpoint']); // 정상 URL 유지
    }

    /**
     * sanitizeLayoutJson이 정상적인 HTML과 속성은 유지하는지 테스트
     */
    public function test_sanitize_preserves_safe_content(): void
    {
        $layout = [
            'components' => [
                [
                    'id' => 'c1',
                    'type' => 'basic',
                    'name' => 'Component',
                    'props' => [
                        'html' => '<div class="container"><p>Hello World</p></div>',
                        'number' => 123,
                        'boolean' => true,
                        'nested' => [
                            'key' => 'value',
                        ],
                    ],
                ],
            ],
        ];

        $sanitized = $this->layoutService->sanitizeLayoutJson($layout);

        // 숫자와 불리언 타입은 그대로 유지
        $this->assertEquals(123, $sanitized['components'][0]['props']['number']);
        $this->assertTrue($sanitized['components'][0]['props']['boolean']);
        $this->assertEquals(['key' => 'value'], $sanitized['components'][0]['props']['nested']);
    }

    /**
     * permissions 병합 테스트 - 부모와 자식 권한이 합집합으로 병합됨
     */
    public function test_merge_permissions_combines_parent_and_child(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
            'permissions' => ['core.admin.access', 'core.dashboard.read'],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'permissions' => ['core.users.read'],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayHasKey('permissions', $result);
        $this->assertCount(3, $result['permissions']);
        $this->assertContains('core.admin.access', $result['permissions']);
        $this->assertContains('core.dashboard.read', $result['permissions']);
        $this->assertContains('core.users.read', $result['permissions']);
    }

    /**
     * permissions 병합 테스트 - 중복 권한은 제거됨
     */
    public function test_merge_permissions_removes_duplicates(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
            'permissions' => ['core.admin.access', 'core.dashboard.read'],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'permissions' => ['core.dashboard.read', 'core.users.read'],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayHasKey('permissions', $result);
        $this->assertCount(3, $result['permissions']);
        // 중복된 'core.dashboard.read'가 한 번만 포함됨
        $this->assertEquals(
            1,
            count(array_filter($result['permissions'], fn ($p) => $p === 'core.dashboard.read'))
        );
    }

    /**
     * permissions 병합 테스트 - 빈 배열이면 결과에 포함되지 않음
     */
    public function test_merge_permissions_excludes_empty_array(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
            'permissions' => [],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'permissions' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayNotHasKey('permissions', $result);
    }

    /**
     * permissions 병합 테스트 - 부모에만 권한이 있는 경우
     */
    public function test_merge_permissions_preserves_parent_only(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
            'permissions' => ['core.admin.access'],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayHasKey('permissions', $result);
        $this->assertCount(1, $result['permissions']);
        $this->assertContains('core.admin.access', $result['permissions']);
    }

    /**
     * permissions 병합 테스트 - 자식에만 권한이 있는 경우
     */
    public function test_merge_permissions_preserves_child_only(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'permissions' => ['core.users.read'],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayHasKey('permissions', $result);
        $this->assertCount(1, $result['permissions']);
        $this->assertContains('core.users.read', $result['permissions']);
    }

    /**
     * permissions 병합 테스트 - 권한 필드가 없는 경우 결과에도 없음
     */
    public function test_merge_permissions_absent_when_not_defined(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayNotHasKey('permissions', $result);
    }

    /**
     * globalHeaders 병합 테스트 - 부모와 자식 헤더가 병합됨
     */
    public function test_merge_global_headers_combines_parent_and_child(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
            'globalHeaders' => [
                ['pattern' => '*', 'headers' => ['X-Template' => 'basic']],
                ['pattern' => '/api/shop/*', 'headers' => ['X-Shop' => 'true']],
            ],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'globalHeaders' => [
                ['pattern' => '/api/cart/*', 'headers' => ['X-Cart' => 'true']],
            ],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayHasKey('globalHeaders', $result);
        $this->assertCount(3, $result['globalHeaders']);
    }

    /**
     * globalHeaders 병합 테스트 - 동일 pattern의 headers가 병합됨 (자식 우선)
     */
    public function test_merge_global_headers_same_pattern_merges_headers(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
            'globalHeaders' => [
                ['pattern' => '*', 'headers' => ['X-Template' => 'basic', 'X-Parent' => 'true']],
            ],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'globalHeaders' => [
                ['pattern' => '*', 'headers' => ['X-Template' => 'child', 'X-Child' => 'true']],
            ],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayHasKey('globalHeaders', $result);
        $this->assertCount(1, $result['globalHeaders']);
        $this->assertEquals('*', $result['globalHeaders'][0]['pattern']);
        // 자식의 X-Template이 부모를 덮어씀
        $this->assertEquals('child', $result['globalHeaders'][0]['headers']['X-Template']);
        // 부모의 X-Parent 유지
        $this->assertEquals('true', $result['globalHeaders'][0]['headers']['X-Parent']);
        // 자식의 X-Child 추가
        $this->assertEquals('true', $result['globalHeaders'][0]['headers']['X-Child']);
    }

    /**
     * globalHeaders 병합 테스트 - 빈 배열이면 결과에 포함되지 않음
     */
    public function test_merge_global_headers_excludes_empty_array(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
            'globalHeaders' => [],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'globalHeaders' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayNotHasKey('globalHeaders', $result);
    }

    /**
     * globalHeaders 병합 테스트 - 부모에만 헤더가 있는 경우
     */
    public function test_merge_global_headers_preserves_parent_only(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
            'globalHeaders' => [
                ['pattern' => '*', 'headers' => ['X-Template' => 'basic']],
            ],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayHasKey('globalHeaders', $result);
        $this->assertCount(1, $result['globalHeaders']);
        $this->assertEquals('*', $result['globalHeaders'][0]['pattern']);
        $this->assertEquals('basic', $result['globalHeaders'][0]['headers']['X-Template']);
    }

    /**
     * globalHeaders 병합 테스트 - 자식에만 헤더가 있는 경우
     */
    public function test_merge_global_headers_preserves_child_only(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'globalHeaders' => [
                ['pattern' => '/api/shop/*', 'headers' => ['X-Cart-Key' => '{{_global.cartKey}}']],
            ],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayHasKey('globalHeaders', $result);
        $this->assertCount(1, $result['globalHeaders']);
        $this->assertEquals('/api/shop/*', $result['globalHeaders'][0]['pattern']);
    }

    /**
     * globalHeaders 병합 테스트 - 헤더 필드가 없는 경우 결과에도 없음
     */
    public function test_merge_global_headers_absent_when_not_defined(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayNotHasKey('globalHeaders', $result);
    }

    /**
     * named_actions 병합 테스트 - 부모와 자식의 named_actions가 병합됨
     */
    public function test_merge_named_actions_combines_parent_and_child(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
            'named_actions' => [
                'searchProducts' => [
                    'handler' => 'navigate',
                    'params' => ['path' => '/products', 'query' => ['page' => 1]],
                ],
            ],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'named_actions' => [
                'resetFilters' => [
                    'handler' => 'setState',
                    'params' => ['target' => 'local', 'key' => 'filters', 'value' => []],
                ],
            ],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayHasKey('named_actions', $result);
        $this->assertCount(2, $result['named_actions']);
        $this->assertArrayHasKey('searchProducts', $result['named_actions']);
        $this->assertArrayHasKey('resetFilters', $result['named_actions']);
    }

    /**
     * named_actions 병합 테스트 - 동일 키 시 자식이 부모를 오버라이드
     */
    public function test_merge_named_actions_child_overrides_parent(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
            'named_actions' => [
                'searchProducts' => [
                    'handler' => 'navigate',
                    'params' => ['path' => '/products', 'query' => ['page' => 1]],
                ],
            ],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'named_actions' => [
                'searchProducts' => [
                    'handler' => 'navigate',
                    'params' => ['path' => '/products/v2', 'query' => ['page' => 1, 'limit' => 50]],
                ],
            ],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayHasKey('named_actions', $result);
        $this->assertCount(1, $result['named_actions']);
        $this->assertEquals('/products/v2', $result['named_actions']['searchProducts']['params']['path']);
    }

    /**
     * named_actions 병합 테스트 - 빈 배열이면 결과에 포함되지 않음
     */
    public function test_merge_named_actions_excludes_empty_array(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
            'named_actions' => [],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'named_actions' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayNotHasKey('named_actions', $result);
    }

    /**
     * named_actions 병합 테스트 - 부모에만 정의된 경우
     */
    public function test_merge_named_actions_preserves_parent_only(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
            'named_actions' => [
                'searchProducts' => ['handler' => 'navigate', 'params' => ['path' => '/products']],
            ],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayHasKey('named_actions', $result);
        $this->assertCount(1, $result['named_actions']);
        $this->assertArrayHasKey('searchProducts', $result['named_actions']);
    }

    /**
     * named_actions 병합 테스트 - 자식에만 정의된 경우
     */
    public function test_merge_named_actions_preserves_child_only(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
            'named_actions' => [
                'resetFilters' => ['handler' => 'setState', 'params' => ['target' => 'local']],
            ],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayHasKey('named_actions', $result);
        $this->assertCount(1, $result['named_actions']);
        $this->assertArrayHasKey('resetFilters', $result['named_actions']);
    }

    /**
     * named_actions 병합 테스트 - 양쪽 모두 미정의 시 결과에도 없음
     */
    public function test_merge_named_actions_absent_when_not_defined(): void
    {
        $parent = [
            'meta' => [],
            'data_sources' => [],
            'components' => [],
        ];

        $child = [
            'meta' => [],
            'data_sources' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayNotHasKey('named_actions', $result);
    }

    // ============================================
    // isModuleLayoutName() 테스트
    // ============================================

    /**
     * DOT 포맷 모듈 레이아웃 이름 인식
     */
    public function test_is_module_layout_name_recognizes_dot_format(): void
    {
        $reflection = new \ReflectionClass($this->layoutService);
        $method = $reflection->getMethod('isModuleLayoutName');
        $method->setAccessible(true);

        // DOT 포맷: sirsoft-sample.admin_index
        $this->assertTrue($method->invoke($this->layoutService, 'sirsoft-sample.admin_index'));
        $this->assertTrue($method->invoke($this->layoutService, 'sirsoft-ecommerce.admin_products_index'));
    }

    /**
     * UNDERSCORE 포맷 모듈 레이아웃 이름 인식 (하위 호환)
     */
    public function test_is_module_layout_name_recognizes_underscore_format(): void
    {
        $reflection = new \ReflectionClass($this->layoutService);
        $method = $reflection->getMethod('isModuleLayoutName');
        $method->setAccessible(true);

        // UNDERSCORE 포맷: sirsoft-sample_admin_index
        $this->assertTrue($method->invoke($this->layoutService, 'sirsoft-sample_admin_index'));
        $this->assertTrue($method->invoke($this->layoutService, 'sirsoft-ecommerce_admin_products_index'));
    }

    /**
     * 일반 레이아웃 이름은 모듈 레이아웃으로 인식되지 않음
     */
    public function test_is_module_layout_name_rejects_plain_names(): void
    {
        $reflection = new \ReflectionClass($this->layoutService);
        $method = $reflection->getMethod('isModuleLayoutName');
        $method->setAccessible(true);

        // 모듈 패턴이 아닌 이름들
        $this->assertFalse($method->invoke($this->layoutService, 'admin_dashboard'));
        $this->assertFalse($method->invoke($this->layoutService, '_admin_base'));
        $this->assertFalse($method->invoke($this->layoutService, 'dashboard'));
    }

    /**
     * 다중 DOT이 있어도 첫 번째 DOT에서 매칭
     */
    public function test_is_module_layout_name_matches_first_dot(): void
    {
        $reflection = new \ReflectionClass($this->layoutService);
        $method = $reflection->getMethod('isModuleLayoutName');
        $method->setAccessible(true);

        // 첫 번째 DOT에서 매칭
        $this->assertTrue($method->invoke($this->layoutService, 'sirsoft-sample.admin.sub'));
    }

    /**
     * transition_overlay 병합 - 자식이 wait_for 만 명시해도 부모의 spinner 설정이 보존되어야 함
     *
     * 이슈 #245 — engine-v1.30.0
     */
    public function test_merge_transition_overlay_shallow_merge_preserves_parent_keys(): void
    {
        $parent = [
            'transition_overlay' => [
                'enabled' => true,
                'style' => 'spinner',
                'target' => 'main_content',
                'spinner' => ['component' => 'PageLoading'],
            ],
            'data_sources' => [],
            'components' => [],
        ];

        $child = [
            'transition_overlay' => [
                'wait_for' => ['settings'],
            ],
            'data_sources' => [],
            'components' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertSame(true, $result['transition_overlay']['enabled']);
        $this->assertSame('spinner', $result['transition_overlay']['style']);
        $this->assertSame('main_content', $result['transition_overlay']['target']);
        $this->assertSame(['component' => 'PageLoading'], $result['transition_overlay']['spinner']);
        $this->assertSame(['settings'], $result['transition_overlay']['wait_for']);
    }

    /**
     * transition_overlay 병합 - 자식이 동일 키를 명시하면 자식이 우선
     */
    public function test_merge_transition_overlay_child_overrides_parent_keys(): void
    {
        $parent = [
            'transition_overlay' => [
                'enabled' => true,
                'style' => 'spinner',
                'target' => 'main_content',
            ],
            'data_sources' => [],
            'components' => [],
        ];

        $child = [
            'transition_overlay' => [
                'target' => 'tab_content',
                'wait_for' => ['tab_data'],
            ],
            'data_sources' => [],
            'components' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertSame('tab_content', $result['transition_overlay']['target']);
        $this->assertSame('spinner', $result['transition_overlay']['style']);
        $this->assertSame(['tab_data'], $result['transition_overlay']['wait_for']);
    }

    /**
     * transition_overlay 병합 - 부모만 정의된 경우 자식 폴백
     */
    public function test_merge_transition_overlay_parent_only_fallback(): void
    {
        $parent = [
            'transition_overlay' => [
                'enabled' => true,
                'style' => 'spinner',
                'target' => 'main_content',
            ],
            'data_sources' => [],
            'components' => [],
        ];

        $child = [
            'data_sources' => [],
            'components' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertSame('main_content', $result['transition_overlay']['target']);
    }

    /**
     * transition_overlay 병합 - boolean 케이스는 shallow merge 가 의미 없으므로 자식 우선
     */
    public function test_merge_transition_overlay_boolean_uses_child_priority(): void
    {
        $parent = [
            'transition_overlay' => [
                'enabled' => true,
                'style' => 'spinner',
            ],
            'data_sources' => [],
            'components' => [],
        ];

        $child = [
            'transition_overlay' => false,
            'data_sources' => [],
            'components' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertSame(false, $result['transition_overlay']);
    }
}
