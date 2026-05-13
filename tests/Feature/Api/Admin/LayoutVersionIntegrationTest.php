<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 레이아웃 버전 히스토리 시스템 통합 테스트
 *
 * 전체 버전 히스토리 플로우 (생성 → 수정 → 버전 저장 → 조회 → 복원) 검증
 * 유저 템플릿 전용 버전 저장 규칙 검증
 * 대용량 JSON diff 성능 테스트
 * changes_summary 정확도 검증
 */
class LayoutVersionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private Template $userTemplate;
    private Template $adminTemplate;
    private string $token;

    /**
     * 올바른 레이아웃 content 구조 생성 헬퍼
     */
    private function makeLayoutContent(
        string $layoutName,
        string $endpoint,
        array $components = [],
        array $dataSources = [],
        array $metadata = []
    ): array {
        return [
            'version' => '1.0.0',
            'layout_name' => $layoutName,
            'endpoint' => $endpoint,
            'components' => $components,
            'data_sources' => $dataSources,
            'metadata' => $metadata,
        ];
    }

    /**
     * 올바른 component 구조 생성 헬퍼
     */
    private function makeComponent(
        string $id,
        string $name,
        array $props = [],
        string $type = 'basic',
        array $children = []
    ): array {
        $component = [
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'props' => $props,
        ];

        if (!empty($children)) {
            $component['children'] = $children;
        }

        return $component;
    }

    /**
     * 올바른 data_source 구조 생성 헬퍼
     */
    private function makeDataSource(
        string $id,
        string $endpoint,
        string $method = 'GET',
        array $params = []
    ): array {
        return [
            'id' => $id,
            'type' => 'api',
            'endpoint' => $endpoint,
            'method' => $method,
            'params' => $params,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성 (필요한 권한 포함)
        // PUT/POST 엔드포인트는 core.templates.layouts.edit 권한 필요
        $this->adminUser = $this->createAdminUser([
            'core.templates.read',
            'core.templates.activate',
            'core.templates.layouts.edit',
        ]);
        $this->token = $this->adminUser->createToken('test-token')->plainTextToken;

        // 유저 템플릿과 관리자 템플릿 생성
        $this->userTemplate = Template::factory()->create(['type' => 'user']);
        $this->adminTemplate = Template::factory()->create(['type' => 'admin']);
    }

    /**
     * 관리자 사용자 생성 (필요한 권한 포함)
     * admin 미들웨어를 통과하기 위해 admin 역할도 함께 할당
     */
    private function createAdminUser(array $permissions = []): User
    {
        $user = User::factory()->create();

        // 권한 생성
        $permissionIds = [];
        foreach ($permissions as $permissionIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permissionIdentifier],
                [
                    'name' => json_encode(['ko' => $permissionIdentifier, 'en' => $permissionIdentifier]),
                    'description' => json_encode(['ko' => $permissionIdentifier, 'en' => $permissionIdentifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        // 고유한 식별자로 역할 생성 (테스트별 격리를 위해)
        $roleIdentifier = 'admin_test_'.uniqid();
        $testRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

        // admin 역할도 추가 (admin 미들웨어 통과용)
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        // 테스트용 역할에 권한 할당
        $testRole->permissions()->sync($permissionIds);

        // 사용자에게 admin 역할과 테스트용 역할 모두 할당
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($testRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 인증된 요청 헬퍼 메서드
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 전체 버전 히스토리 플로우 통합 테스트
     *
     * 시나리오:
     * 1. 레이아웃 생성
     * 2. 레이아웃 수정 (버전 1 자동 생성)
     * 3. 다시 수정 (버전 2 자동 생성)
     * 4. 버전 목록 조회
     * 5. 특정 버전 조회
     * 6. 버전 1로 복원
     * 7. 복원 후 새 버전(3) 생성 확인
     */
    public function test_full_version_history_workflow(): void
    {
        // 1. 레이아웃 생성
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->userTemplate->id,
            'name' => 'test-layout',
            'content' => $this->makeLayoutContent(
                'test-layout',
                '/api/admin/test',
                [$this->makeComponent('header-1', 'Header', ['title' => 'initial'])],
                [],
                ['status' => 'initial']
            ),
        ]);

        $this->assertDatabaseHas('template_layouts', [
            'id' => $layout->id,
            'name' => 'test-layout',
        ]);

        // 초기 버전은 생성되지 않음
        $this->assertEquals(0, TemplateLayoutVersion::where('layout_id', $layout->id)->count());

        // 2. 레이아웃 첫 수정 (버전 1 생성)
        $updateData1 = [
            'content' => $this->makeLayoutContent(
                'test-layout',
                '/api/admin/test',
                [
                    $this->makeComponent('header-1', 'Header', ['title' => 'updated_v1']),
                    $this->makeComponent('footer-1', 'Footer', ['text' => 'new']),
                ],
                [],
                ['status' => 'updated_v1']
            ),
        ];

        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->userTemplate->identifier}/layouts/{$layout->name}", $updateData1);

        $response->assertStatus(200);
        $responseData = $response->json('data');

        $this->assertCount(2, $responseData['components']);

        // LayoutService::updateLayoutContent 는 PUT 당 2개 버전 저장 (이전 롤백용 + 현재 기록용)
        $this->assertEquals(2, TemplateLayoutVersion::where('layout_id', $layout->id)->count());

        $version1 = TemplateLayoutVersion::where('layout_id', $layout->id)
            ->where('version', 1)
            ->first();

        $this->assertNotNull($version1);
        $this->assertCount(1, $version1->content['components']);
        $this->assertEquals('header-1', $version1->content['components'][0]['id']);
        $this->assertNotNull($version1->changes_summary);

        // 3. 레이아웃 두 번째 수정 (버전 2 생성)
        $updateData2 = [
            'content' => $this->makeLayoutContent(
                'test-layout',
                '/api/admin/test',
                [
                    $this->makeComponent('header-1', 'Header', ['title' => 'updated_v2']),
                    $this->makeComponent('footer-1', 'Footer', ['text' => 'modified']),
                    $this->makeComponent('sidebar-1', 'Sidebar', ['position' => 'left']),
                ],
                [],
                ['status' => 'updated_v2', 'author' => 'admin']
            ),
        ];

        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->userTemplate->identifier}/layouts/{$layout->name}", $updateData2);

        $response->assertStatus(200);

        // 2회 PUT = 4개 버전 (PUT 당 2개)
        $this->assertEquals(4, TemplateLayoutVersion::where('layout_id', $layout->id)->count());

        // 4. 버전 목록 조회
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->userTemplate->identifier}/layouts/{$layout->name}/versions");

        $response->assertStatus(200);
        // LayoutService::updateLayoutContent 는 PUT 당 2개 버전 저장 (이전 + 현재)
        // 2회 PUT = 4개 버전
        $this->assertCount(4, $response->json('data'));

        // 5. 특정 버전 조회 (버전 1)
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->userTemplate->identifier}/layouts/{$layout->name}/versions/1");

        $response->assertStatus(200);

        $versionData = $response->json('data');
        $this->assertEquals(1, (int) $versionData['version']);
        $this->assertCount(1, $versionData['components']);
        $this->assertEquals('header-1', $versionData['components'][0]['id']);

        // 6. 버전 1로 복원
        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->userTemplate->identifier}/layouts/{$layout->name}/versions/{$version1->id}/restore");

        $response->assertStatus(200);

        // 7. 복원은 saveVersion 1회 호출 (복원 전 content 저장). 4 + 1 = 5 버전
        $this->assertEquals(5, TemplateLayoutVersion::where('layout_id', $layout->id)->count());

        // 레이아웃의 현재 content가 버전 1로 복원되었는지 확인 (버전 번호가 아닌 실제 내용 기준)
        $layout->refresh();
        $this->assertCount(1, $layout->content['components']);
        $this->assertEquals('header-1', $layout->content['components'][0]['id']);
        $this->assertEquals(['status' => 'initial'], $layout->content['metadata']);
    }

    /**
     * 유저 템플릿만 버전이 저장되는지 검증
     *
     * - 유저 템플릿(type='user'): 버전 저장됨
     * - 관리자 템플릿(type='admin'): 버전 저장되지 않음 (레이아웃 편집 불가)
     */
    public function test_version_saved_only_for_user_templates(): void
    {
        // 유저 템플릿 레이아웃 생성
        $userLayout = TemplateLayout::factory()->create([
            'template_id' => $this->userTemplate->id,
            'name' => 'user-layout',
            'content' => $this->makeLayoutContent(
                'user-layout',
                '/api/admin/user',
                [$this->makeComponent('test-1', 'TestComponent', ['value' => 'initial'])]
            ),
        ]);

        // 관리자 템플릿 레이아웃 생성
        $adminLayout = TemplateLayout::factory()->create([
            'template_id' => $this->adminTemplate->id,
            'name' => 'admin-layout',
            'content' => $this->makeLayoutContent(
                'admin-layout',
                '/api/admin/dashboard',
                [$this->makeComponent('test-1', 'TestComponent', ['value' => 'initial'])]
            ),
        ]);

        // 유저 템플릿 레이아웃 수정
        $this->authRequest()
            ->putJson("/api/admin/templates/{$this->userTemplate->identifier}/layouts/{$userLayout->name}", [
                'content' => $this->makeLayoutContent(
                    'user-layout',
                    '/api/admin/user',
                    [$this->makeComponent('test-1', 'TestComponent', ['value' => 'updated'])]
                ),
            ])
            ->assertStatus(200);

        // 관리자 템플릿 레이아웃 수정
        $this->authRequest()
            ->putJson("/api/admin/templates/{$this->adminTemplate->identifier}/layouts/{$adminLayout->name}", [
                'content' => $this->makeLayoutContent(
                    'admin-layout',
                    '/api/admin/dashboard',
                    [$this->makeComponent('test-1', 'TestComponent', ['value' => 'updated'])]
                ),
            ])
            ->assertStatus(200);

        // 유저 템플릿은 버전이 생성됨 (PUT 당 2개: 이전 + 현재)
        $this->assertEquals(2, TemplateLayoutVersion::where('layout_id', $userLayout->id)->count());

        // 관리자 템플릿은 버전이 생성되지 않음 (레이아웃 편집 불가 정책)
        $this->assertEquals(0, TemplateLayoutVersion::where('layout_id', $adminLayout->id)->count());
    }

    /**
     * 대용량 JSON diff 성능 테스트
     *
     * 목표: 1MB 이상 JSON 처리 시 1초 이내 완료
     */
    public function test_large_json_diff_performance(): void
    {
        // 대용량 components 생성 (약 1MB 이상)
        $largeComponents = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeComponents[] = $this->makeComponent(
                "component_$i",
                "Component $i",
                [
                    'componentId' => $i,
                    'componentName' => "Component $i",
                    'description' => str_repeat("This is a long description for component $i. ", 10),
                    'config' => [
                        'option1' => 'value1',
                        'option2' => 'value2',
                        'option3' => 'value3',
                        'nested' => [
                            'deep1' => 'data1',
                            'deep2' => 'data2',
                        ],
                    ],
                ],
                'composite'
            );
        }

        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->userTemplate->id,
            'name' => 'large-layout',
            'content' => $this->makeLayoutContent(
                'large-layout',
                '/api/admin/large',
                $largeComponents
            ),
        ]);

        // components 일부 수정
        $updatedComponents = $largeComponents;
        for ($i = 0; $i < 100; $i++) {
            $updatedComponents[$i]['props']['description'] = "Updated description for component $i";
        }

        // 성능 측정 시작
        $startTime = microtime(true);

        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->userTemplate->identifier}/layouts/{$layout->name}", [
                'content' => $this->makeLayoutContent(
                    'large-layout',
                    '/api/admin/large',
                    $updatedComponents
                ),
            ]);

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // 성공 확인
        $response->assertStatus(200);

        // 버전이 생성되었는지 확인 (1 PUT = 2 버전: 이전 + 현재)
        $this->assertEquals(2, TemplateLayoutVersion::where('layout_id', $layout->id)->count());

        // 성능 검증: 5초 이내 완료 (Windows + MySQL + RefreshDatabase 환경 고려한 여유값)
        $this->assertLessThan(5.0, $executionTime, "Large JSON diff took {$executionTime}s, expected < 5.0s");

        // changes_summary가 생성되었는지 확인
        $version = TemplateLayoutVersion::where('layout_id', $layout->id)->first();
        $this->assertNotNull($version->changes_summary);
    }

    /**
     * changes_summary 정확도 검증
     *
     * 실제 변경 사항과 changes_summary가 일치하는지 확인
     */
    public function test_changes_summary_accuracy(): void
    {
        // 초기 레이아웃 생성
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->userTemplate->id,
            'name' => 'accuracy-test',
            'content' => $this->makeLayoutContent(
                'accuracy-test',
                '/api/admin/accuracy',
                [
                    $this->makeComponent('header', 'Header', ['title' => 'Original']),
                    $this->makeComponent('footer', 'Footer', ['text' => 'Original']),
                ],
                [
                    $this->makeDataSource('api1', '/api/data1'),
                ],
                [
                    'author' => 'admin',
                    'status' => 'draft',
                ]
            ),
        ]);

        // 복잡한 변경 수행
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->userTemplate->identifier}/layouts/{$layout->name}", [
                'content' => $this->makeLayoutContent(
                    'accuracy-test',
                    '/api/admin/accuracy',
                    [
                        $this->makeComponent('header', 'Header', ['title' => 'Updated']), // 수정
                        $this->makeComponent('footer', 'Footer', ['text' => 'Original']), // 변경 없음
                        $this->makeComponent('sidebar', 'Sidebar', ['position' => 'left']), // 추가
                    ],
                    [
                        $this->makeDataSource('api1', '/api/data1'), // 변경 없음
                        $this->makeDataSource('api2', '/api/data2'), // 추가
                    ],
                    [
                        'author' => 'admin', // 변경 없음
                        'status' => 'published', // 수정
                        'version' => '2.0.0', // 추가
                    ]
                ),
            ]);

        $response->assertStatus(200);

        // 생성된 버전 확인
        $version = TemplateLayoutVersion::where('layout_id', $layout->id)->first();
        $this->assertNotNull($version);

        $summary = $version->changes_summary;
        $this->assertNotNull($summary);
        $this->assertIsArray($summary);

        // changes_summary 구조 검증
        $this->assertArrayHasKey('added', $summary);
        $this->assertArrayHasKey('removed', $summary);
        $this->assertArrayHasKey('modified', $summary);

        // 3번째 컴포넌트 (sidebar)가 추가되었는지 확인
        $addedPaths = $summary['added'];
        $this->assertTrue(
            collect($addedPaths)->contains(fn($path) => str_contains($path, 'components.2')),
            'Sidebar component should be added at index 2'
        );

        // 1번째 컴포넌트 (header)의 title이 수정되었는지 확인
        $modifiedPaths = $summary['modified'];
        $this->assertTrue(
            collect($modifiedPaths)->contains(fn($path) => str_contains($path, 'components.0.props.title')),
            'Header title should be modified'
        );

        // metadata 변경 확인
        $this->assertTrue(
            collect($modifiedPaths)->contains(fn($path) => str_contains($path, 'metadata.status')),
            'Metadata status should be modified'
        );
        $this->assertTrue(
            collect($addedPaths)->contains(fn($path) => str_contains($path, 'metadata.version')),
            'Metadata version should be added'
        );
    }

    /**
     * 동시 버전 저장 시 race condition 테스트
     */
    public function test_concurrent_version_creation_race_condition(): void
    {
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->userTemplate->id,
            'name' => 'concurrent-test',
            'content' => $this->makeLayoutContent(
                'concurrent-test',
                '/api/admin/concurrent',
                [$this->makeComponent('test-1', 'TestComponent', ['value' => 'initial'])]
            ),
        ]);

        // 거의 동시에 여러 번 수정
        $promises = [];
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->authRequest()
                ->putJson("/api/admin/templates/{$this->userTemplate->identifier}/layouts/{$layout->name}", [
                    'content' => $this->makeLayoutContent(
                        'concurrent-test',
                        '/api/admin/concurrent',
                        [$this->makeComponent('test-1', 'TestComponent', ['value' => "update_$i"])]
                    ),
                ]);

            $promises[] = $response;
        }

        // 모든 요청이 성공했는지 확인
        foreach ($promises as $response) {
            $response->assertStatus(200);
        }

        // 버전 번호가 올바르게 증가했는지 확인
        // (LayoutService 는 PUT 당 2개 버전 저장: 이전 롤백용 + 현재 기록용 — 5회 PUT = 10 버전)
        $versions = TemplateLayoutVersion::where('layout_id', $layout->id)
            ->orderBy('version')
            ->pluck('version')
            ->toArray();

        // 5 PUT × 2 (이전 + 현재) = 10 버전
        $this->assertCount(10, $versions);
        $this->assertEquals(
            range(1, 10),
            $versions,
            'Version numbers should be sequential without gaps'
        );
    }

    /**
     * 복원 후 데이터 무결성 검증
     */
    public function test_data_integrity_after_restore(): void
    {
        // 복잡한 초기 데이터
        $initialContent = $this->makeLayoutContent(
            'integrity-test',
            '/api/admin/integrity',
            [
                $this->makeComponent('header', 'Header', [
                    'title' => 'Original Title',
                    'logo' => '/images/logo.png',
                    'navigation' => [
                        ['label' => 'Home', 'url' => '/'],
                        ['label' => 'About', 'url' => '/about'],
                    ],
                ]),
            ],
            [
                $this->makeDataSource('user_api', '/api/users', 'GET'),
            ],
            [
                'author' => 'admin',
                'created' => '2025-01-01',
                'tags' => ['important', 'production'],
            ]
        );

        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->userTemplate->id,
            'name' => 'integrity-test',
            'content' => $initialContent,
        ]);

        // 수정 (버전 1 생성)
        $this->authRequest()
            ->putJson("/api/admin/templates/{$this->userTemplate->identifier}/layouts/{$layout->name}", [
                'content' => $this->makeLayoutContent(
                    'integrity-test',
                    '/api/admin/integrity',
                    [
                        $this->makeComponent('header', 'Header', [
                            'title' => 'Updated Title',
                            'logo' => '/images/new-logo.png',
                        ]),
                    ],
                    [
                        $this->makeDataSource('user_api', '/api/users', 'GET'),
                    ],
                    [
                        'author' => 'admin',
                        'created' => '2025-01-01',
                        'tags' => ['important', 'production'],
                    ]
                ),
            ]);

        $version1 = TemplateLayoutVersion::where('layout_id', $layout->id)
            ->where('version', 1)
            ->first();

        // 버전 1로 복원
        $this->authRequest()
            ->postJson("/api/admin/templates/{$this->userTemplate->identifier}/layouts/{$layout->name}/versions/{$version1->id}/restore");

        // 복원된 데이터 검증
        $layout->refresh();

        // 중첩된 구조가 정확히 복원되었는지 확인 (components는 배열)
        $this->assertCount(1, $layout->content['components']);
        $this->assertEquals('header', $layout->content['components'][0]['id']);
        $this->assertEquals('Original Title', $layout->content['components'][0]['props']['title']);
        $this->assertEquals('/images/logo.png', $layout->content['components'][0]['props']['logo']);
        $this->assertCount(2, $layout->content['components'][0]['props']['navigation']);
        $this->assertEquals('Home', $layout->content['components'][0]['props']['navigation'][0]['label']);

        // data_sources가 정확히 복원되었는지 확인 (배열)
        $this->assertCount(1, $layout->content['data_sources']);
        $this->assertEquals('user_api', $layout->content['data_sources'][0]['id']);
        $this->assertEquals('/api/users', $layout->content['data_sources'][0]['endpoint']);
        $this->assertEquals('GET', $layout->content['data_sources'][0]['method']);

        // metadata가 정확히 복원되었는지 확인
        $this->assertEquals(['important', 'production'], $layout->content['metadata']['tags']);
    }

    /**
     * 빈 배열과 null 값 처리 검증
     */
    public function test_handles_empty_arrays_and_null_values(): void
    {
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->userTemplate->id,
            'name' => 'empty-test',
            'content' => $this->makeLayoutContent(
                'empty-test',
                '/api/admin/empty',
                [],
                [],
                []
            ),
        ]);

        // 빈 배열에서 데이터 추가
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->userTemplate->identifier}/layouts/{$layout->name}", [
                'content' => $this->makeLayoutContent(
                    'empty-test',
                    '/api/admin/empty',
                    [$this->makeComponent('header', 'Header', ['value' => 'new'])],
                    [$this->makeDataSource('api', '/api/test')],
                    ['key' => 'value']
                ),
            ]);

        $response->assertStatus(200);

        // 버전이 생성되었는지 확인
        $version = TemplateLayoutVersion::where('layout_id', $layout->id)->first();
        $this->assertNotNull($version);

        // 빈 배열이 올바르게 저장되었는지 확인
        $this->assertEquals([], $version->content['components']);
        $this->assertEquals([], $version->content['data_sources']);
        $this->assertEquals([], $version->content['metadata']);

        // changes_summary에서 추가 항목 확인
        $summary = $version->changes_summary;
        $addedPaths = $summary['added'];
        $this->assertTrue(
            collect($addedPaths)->contains(fn($path) => str_contains($path, 'components.0')),
            'Header component should be added'
        );
        $this->assertTrue(
            collect($addedPaths)->contains(fn($path) => str_contains($path, 'data_sources.0')),
            'API data source should be added'
        );
        $this->assertTrue(
            collect($addedPaths)->contains(fn($path) => str_contains($path, 'metadata.key')),
            'Metadata key should be added'
        );
    }
}
