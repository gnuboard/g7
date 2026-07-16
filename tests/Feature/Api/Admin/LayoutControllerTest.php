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
 * LayoutController API 테스트
 *
 * GET /api/admin/templates/{id}/layouts 엔드포인트 테스트
 * GET /api/admin/templates/{id}/layouts/{name} 엔드포인트 테스트
 * PUT /api/admin/templates/{id}/layouts/{name} 엔드포인트 테스트
 */
class LayoutControllerTest extends TestCase
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

        // 관리자 사용자 생성 (필요한 권한 포함)
        // PUT layouts / POST restore 엔드포인트는 core.templates.layouts.edit 권한 필요
        $this->adminUser = $this->createAdminUser([
            'core.templates.read',
            'core.templates.activate',
            'core.templates.layouts.edit',
        ]);
        $this->token = $this->adminUser->createToken('test-token')->plainTextToken;

        // 일반 사용자 생성
        $this->normalUser = User::factory()->create();

        // 테스트용 템플릿 생성
        $this->template = Template::factory()->create();
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
                    'type' => 'admin',
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
                'type' => 'admin',
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
     * 레이아웃 목록 조회 성공
     */
    public function test_can_list_layouts(): void
    {
        // Arrange
        TemplateLayout::factory()->count(3)->create([
            'template_id' => $this->template->id,
        ]);

        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts");

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'template_id',
                        'name',
                        'endpoint',
                        'route_path',
                        'components',
                        'data_sources',
                        'metadata',
                    ],
                ],
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    /**
     * 레이아웃 목록 응답에 route_path 필드가 포함된다 (코드 편집기 ?route= 동기화용).
     *
     * 팩토리 템플릿은 실제 routes.json 이 없어 매핑이 비므로 route_path 는 null 이다.
     * 실제 routes.json 기반 매핑 검증은 TemplateService 단위 테스트가 담당.
     */
    public function test_layout_list_includes_route_path_field(): void
    {
        // Arrange
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'home',
        ]);

        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts");

        // Assert
        $response->assertStatus(200);
        $this->assertArrayHasKey('route_path', $response->json('data.0'));
        $this->assertNull($response->json('data.0.route_path'));
    }

    /**
     * 인증되지 않은 사용자 접근 거부
     */
    public function test_unauthenticated_user_cannot_list_layouts(): void
    {
        // Act
        $response = $this->getJson("/api/admin/templates/{$this->template->identifier}/layouts");

        // Assert
        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자 접근 거부
     */
    public function test_unauthorized_user_cannot_list_layouts(): void
    {
        // Act
        $response = $this->actingAs($this->normalUser)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts");

        // Assert
        $response->assertStatus(403);
    }

    /**
     * 빈 목록 조회 (레이아웃이 없을 때)
     */
    public function test_returns_empty_array_when_no_layouts(): void
    {
        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts");

        // Assert
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    /**
     * 레이아웃 상세 조회 성공
     */
    public function test_can_show_layout(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'test-layout',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/admin/test',
                'components' => [],
                'data_sources' => [],
                'metadata' => [],
            ],
        ]);

        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}");

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'template_id',
                    'name',
                    'endpoint',
                    'components',
                    'data_sources',
                    'metadata',
                ],
            ])
            ->assertJsonFragment([
                'name' => 'test-layout',
                'endpoint' => '/api/admin/test',
            ]);
    }

    /**
     * 존재하지 않는 레이아웃 조회 시 404 응답
     */
    public function test_returns_404_for_nonexistent_layout(): void
    {
        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/nonexistent");

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 인증되지 않은 사용자는 레이아웃 상세 조회 불가
     */
    public function test_unauthenticated_user_cannot_show_layout(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
        ]);

        // Act
        $response = $this->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}");

        // Assert
        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자는 레이아웃 상세 조회 불가
     */
    public function test_unauthorized_user_cannot_show_layout(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
        ]);

        // Act
        $response = $this->actingAs($this->normalUser)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}");

        // Assert
        $response->assertStatus(403);
    }

    /**
     * 레이아웃 업데이트 성공
     */
    public function test_can_update_layout(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'test-layout',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/test',
                'components' => ['component' => 'old'],
                'data_sources' => [],
                'metadata' => ['key' => 'old_value'],
            ],
        ]);

        $updateData = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/admin/test',
                'components' => [
                    [
                        'id' => 'comp_1',
                        'name' => 'NewContainer',
                        'type' => 'composite',
                        'props' => ['new' => 'value'],
                    ],
                ],
                'data_sources' => [],
                'metadata' => ['key' => 'new_value'],
            ],
        ];

        // Act
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}", $updateData);

        // Assert
        $response->assertStatus(200);

        // 응답 JSON 구조 확인
        $responseData = $response->json('data');
        $this->assertIsArray($responseData['components']);
        $this->assertCount(1, $responseData['components']);
        $this->assertEquals('comp_1', $responseData['components'][0]['id']);
        $this->assertEquals('NewContainer', $responseData['components'][0]['name']);
        $this->assertEquals('composite', $responseData['components'][0]['type']);

        $this->assertDatabaseHas('template_layouts', [
            'id' => $layout->id,
            'name' => 'test-layout',
        ]);

        // 현재(최신) 버전 번호 동봉.
        // 첫 저장은 baseline(v1) + 이번 저장본(v2) 두 버전을 적재하므로 current_version=2.
        $this->assertSame(2, $response->json('data.current_version'));
    }

    /**
     * 레이아웃 업데이트 시 유효성 검증 실패
     */
    public function test_update_layout_validation_fails(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
        ]);

        $invalidData = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test',
                'endpoint' => '/api/test',
                'components' => 'not_an_array', // 배열이 아님
                'metadata' => 'not_an_array', // 배열이 아님
            ],
        ];

        // Act
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}", $invalidData);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content.components', 'content.metadata']);
    }

    /**
     * 존재하지 않는 레이아웃 업데이트 시 500 응답
     */
    public function test_update_nonexistent_layout_returns_500(): void
    {
        // Arrange
        $updateData = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'nonexistent',
                'endpoint' => '/api/admin/test',
                'components' => [
                    [
                        'id' => 'comp_1',
                        'name' => 'NewContainer',
                        'type' => 'composite',
                        'props' => ['new' => 'value'],
                    ],
                ],
                'data_sources' => [],
                'metadata' => [],
            ],
        ];

        // Act
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layouts/nonexistent", $updateData);

        // Assert
        $response->assertStatus(500);
    }

    /**
     * 권한 없는 사용자는 레이아웃 업데이트 불가
     */
    public function test_unauthorized_user_cannot_update_layout(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
        ]);

        $updateData = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test',
                'endpoint' => '/api/test',
                'components' => ['component' => 'new'],
                'data_sources' => [],
                'metadata' => [],
            ],
        ];

        // Act
        $response = $this->actingAs($this->normalUser)
            ->putJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}", $updateData);

        // Assert
        $response->assertStatus(403);
    }

    /**
     * 레이아웃 상세 조회 시 민감한 정보 제외
     */
    public function test_response_excludes_sensitive_fields(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
        ]);

        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}");

        // Assert
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('created_by', $data);
        $this->assertArrayNotHasKey('updated_by', $data);
        $this->assertArrayNotHasKey('deleted_at', $data);
    }

    /**
     * 레이아웃 버전 목록 조회 성공
     */
    public function test_can_list_layout_versions(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'test-layout',
        ]);

        TemplateLayoutVersion::factory()->count(3)->create([
            'layout_id' => $layout->id,
        ]);

        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions");

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'layout_id',
                        'version',
                        'endpoint',
                        'components',
                        'data_sources',
                        'metadata',
                        'changes_summary',
                        'created_by_name',
                        'created_at',
                    ],
                ],
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    /**
     * 버전 목록에 저장자 이름(created_by_name)이 노출되되 created_by ID 는 제외
     *
     * @effects version_resource_exposes_creator_name_not_id, version_row_shows_creator_name_or_unknown_fallback
     */
    public function test_layout_versions_expose_creator_name_not_id(): void
    {
        // Arrange — 저장자 User 와 버전 생성
        $author = User::factory()->create(['name' => '버전작성자']);
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'authored-layout',
        ]);
        TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'created_by' => $author->id,
        ]);

        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions");

        // Assert — 이름은 노출, created_by ID 는 응답에 없음
        $response->assertStatus(200);
        $first = $response->json('data.0');
        $this->assertSame('버전작성자', $first['created_by_name']);
        $this->assertArrayNotHasKey('created_by', $first);
    }

    /**
     * 탈퇴/미상 저장자(created_by null)는 created_by_name 이 null
     */
    public function test_layout_versions_creator_name_null_when_no_creator(): void
    {
        // Arrange — created_by null 버전
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'anon-layout',
        ]);
        TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'created_by' => null,
        ]);

        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions");

        // Assert
        $response->assertStatus(200);
        $this->assertNull($response->json('data.0.created_by_name'));
    }

    /**
     * 존재하지 않는 레이아웃의 버전 조회 시 404 응답
     */
    public function test_returns_404_for_nonexistent_layout_versions(): void
    {
        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/nonexistent/versions");

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 권한 없는 사용자는 버전 목록 조회 불가
     */
    public function test_unauthorized_user_cannot_list_versions(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
        ]);

        // Act
        $response = $this->actingAs($this->normalUser)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions");

        // Assert
        $response->assertStatus(403);
    }

    /**
     * 특정 버전 조회 성공
     */
    public function test_can_show_specific_version(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'test-layout',
        ]);

        $version = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 2,
            'content' => [
                'endpoint' => '/api/test',
                'components' => ['component' => 'test'],
                'data_sources' => [],
                'metadata' => ['key' => 'value'],
                // extends 기반 레이아웃처럼 slots 에 실제 컴포넌트가 있는 경우 — 분해 키엔 안 잡힘.
                'slots' => ['content' => [['name' => 'Div']]],
            ],
            'changes_summary' => ['updated' => 'components'],
        ]);

        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions/{$version->version}");

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'layout_id',
                    'version',
                    'endpoint',
                    'components',
                    'data_sources',
                    'metadata',
                    'changes_summary',
                    'created_by_name',
                    // 단건 조회는 content 원본 전체(full_content) 노출 — 버전 비교 diff 용
                    'full_content',
                    'created_at',
                ],
            ])
            ->assertJsonFragment([
                'endpoint' => '/api/test',
            ]);

        $this->assertEquals(2, $response->json('data.version'));
        // full_content 는 slots 등 분해되지 않는 키까지 원본 그대로 보존해야 한다 (diff 정확성).
        $this->assertSame(
            [['name' => 'Div']],
            $response->json('data.full_content.slots.content')
        );
    }

    /**
     * 버전 목록(index)에는 full_content 를 포함하지 않는다 (페이로드 비대화 회피)
     */
    public function test_version_index_excludes_full_content(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'index-no-full',
        ]);
        TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'content' => ['slots' => ['content' => [['name' => 'Div']]]],
        ]);

        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions");

        // Assert — 목록 항목에 full_content 키 없음
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('full_content', $response->json('data.0'));
    }

    /**
     * 존재하지 않는 버전 조회 시 404 응답
     */
    public function test_returns_404_for_nonexistent_version(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
        ]);

        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions/999");

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 권한 없는 사용자는 특정 버전 조회 불가
     */
    public function test_unauthorized_user_cannot_show_version(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
        ]);

        $version = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
        ]);

        // Act
        $response = $this->actingAs($this->normalUser)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions/{$version->version}");

        // Assert
        $response->assertStatus(403);
    }

    /**
     * 버전 응답에 민감한 정보 제외
     */
    public function test_version_response_excludes_sensitive_fields(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
        ]);

        $version = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
        ]);

        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions/{$version->version}");

        // Assert
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('created_by', $data);
    }

    /**
     * 버전 복원 성공
     */
    public function test_can_restore_version(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'test-layout',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/current',
                'components' => ['component' => 'current'],
                'data_sources' => [],
                'metadata' => ['key' => 'current_value'],
            ],
        ]);

        // 이전 버전 생성
        $oldVersion = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/old',
                'components' => ['component' => 'old'],
                'data_sources' => [],
                'metadata' => ['key' => 'old_value'],
            ],
        ]);

        // Act
        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions/{$oldVersion->id}/restore");

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'layout_id',
                    'version',
                    'endpoint',
                    'components',
                ],
            ]);

        // 레이아웃의 content가 복원되었는지 확인
        $layout->refresh();
        $this->assertEquals('/api/old', $layout->content['endpoint']);
        $this->assertEquals(['component' => 'old'], $layout->content['components']);
        $this->assertEquals(['key' => 'old_value'], $layout->content['metadata']);

        // 새 버전이 생성되었는지 확인
        $this->assertDatabaseHas('template_layout_versions', [
            'layout_id' => $layout->id,
            'version' => 2, // 복원 후 새 버전 번호
        ]);
    }

    /**
     * 존재하지 않는 버전 복원 시 404 응답
     */
    public function test_restore_nonexistent_version_returns_404(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
        ]);

        // Act
        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions/999/restore");

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 레이아웃의 버전 복원 시 404 응답
     */
    public function test_restore_version_for_nonexistent_layout_returns_404(): void
    {
        // Act
        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layouts/nonexistent/versions/1/restore");

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 권한 없는 사용자는 버전 복원 불가
     */
    public function test_unauthorized_user_cannot_restore_version(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
        ]);

        $version = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
        ]);

        // Act
        $response = $this->actingAs($this->normalUser)
            ->postJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions/{$version->id}/restore");

        // Assert
        $response->assertStatus(403);
    }

    /**
     * 버전 복원 후 새 버전이 생성되는지 확인
     */
    public function test_restore_creates_new_version(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'content' => [
                'endpoint' => '/api/v3',
                'components' => ['version' => '3'],
            ],
        ]);

        // 버전 1 생성
        $version1 = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
            'content' => [
                'endpoint' => '/api/v1',
                'components' => ['version' => '1'],
            ],
        ]);

        // 버전 2 생성
        TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 2,
            'content' => [
                'endpoint' => '/api/v2',
                'components' => ['version' => '2'],
            ],
        ]);

        // Act - 버전 1로 복원
        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}/versions/{$version1->id}/restore");

        // Assert
        $response->assertStatus(200);

        // 버전 3이 생성되었는지 확인 (복원 결과 = 복원된 content 를 저장)
        $newVersion = TemplateLayoutVersion::where('layout_id', $layout->id)
            ->where('version', 3)
            ->first();

        $this->assertNotNull($newVersion);
        // 새 버전은 복원된 content (v1)를 담는다 — "현재 상태가 v1 으로 돌아갔음"을 표현.
        // (종전엔 복원 전 content 를 저장해 복원인데 "추가"로 표기되던 결함 수정.)
        $this->assertEquals('/api/v1', $newVersion->content['endpoint']);
        $this->assertEquals(['version' => '1'], $newVersion->content['components']);

        // changes_summary 는 복원 직전(레이아웃의 v3 content) 대비 변경 — 복원으로 줄거나 바뀐다.
        $this->assertNotNull($newVersion->changes_summary);

        // 레이아웃이 v1으로 복원되었는지 확인
        $layout->refresh();
        $this->assertEquals('/api/v1', $layout->content['endpoint']);
        $this->assertEquals(['version' => '1'], $layout->content['components']);
    }

    /**
     * 다른 레이아웃의 버전으로 복원 시도 시 404 응답
     */
    public function test_cannot_restore_version_from_different_layout(): void
    {
        // Arrange
        $layout1 = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'layout-1',
        ]);

        $layout2 = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'layout-2',
        ]);

        $version2 = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout2->id,
            'version' => 1,
        ]);

        // Act - layout1에 layout2의 버전을 복원 시도
        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout1->name}/versions/{$version2->id}/restore");

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 레이아웃 업데이트 시 이전 버전과 현재 버전 모두 버전 히스토리에 저장
     *
     * 저장 시 2개의 버전이 생성되어야 함:
     * 1. 이전 버전 (롤백용)
     * 2. 현재 저장 버전 (최신 상태 기록)
     */
    public function test_update_layout_creates_both_old_and_new_versions(): void
    {
        // Arrange - user 템플릿 생성 (버전 히스토리는 user 템플릿에서만 저장)
        $userTemplate = Template::factory()->create([
            'type' => 'user',
        ]);

        $layout = TemplateLayout::factory()->create([
            'template_id' => $userTemplate->id,
            'name' => 'test-layout',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/admin/test',
                'components' => [['id' => 'old_comp', 'type' => 'basic', 'name' => 'OldComponent']],
                'data_sources' => [],
                'metadata' => ['key' => 'old_value'],
            ],
        ]);

        // 기존 버전 없음 확인
        $initialVersionCount = TemplateLayoutVersion::where('layout_id', $layout->id)->count();
        $this->assertEquals(0, $initialVersionCount);

        $updateData = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/admin/test',
                'components' => [['id' => 'new_comp', 'type' => 'basic', 'name' => 'NewComponent']],
                'data_sources' => [],
                'metadata' => ['key' => 'new_value'],
            ],
        ];

        // Act
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$userTemplate->identifier}/layouts/{$layout->name}", $updateData);

        // Assert
        $response->assertStatus(200);

        // 2개의 버전이 생성되어야 함 (이전 버전 + 현재 저장 버전)
        $versions = TemplateLayoutVersion::where('layout_id', $layout->id)
            ->orderBy('version', 'asc')
            ->get();

        $this->assertCount(2, $versions);

        // 버전 1: 이전 content (롤백용)
        $this->assertEquals(1, $versions[0]->version);
        $this->assertEquals('old_comp', $versions[0]->content['components'][0]['id']);
        $this->assertEquals('old_value', $versions[0]->content['metadata']['key']);

        // 버전 2: 현재 저장된 content (최신 상태)
        $this->assertEquals(2, $versions[1]->version);
        $this->assertEquals('new_comp', $versions[1]->content['components'][0]['id']);
        $this->assertEquals('new_value', $versions[1]->content['metadata']['key']);
    }

    /**
     * 연속 업데이트 시 버전 히스토리가 올바르게 누적되는지 확인
     */
    public function test_multiple_updates_accumulate_version_history_correctly(): void
    {
        // Arrange - user 템플릿 생성
        $userTemplate = Template::factory()->create([
            'type' => 'user',
        ]);

        $layout = TemplateLayout::factory()->create([
            'template_id' => $userTemplate->id,
            'name' => 'test-layout',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/admin/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
                'metadata' => ['step' => 'initial'],
            ],
        ]);

        // Act - 첫 번째 업데이트 (factory 초기 lock_version=0)
        $response1 = $this->authRequest()
            ->putJson("/api/admin/templates/{$userTemplate->identifier}/layouts/{$layout->name}", [
                'expected_lock_version' => 0,
                'content' => [
                    'version' => '1.0.0',
                    'layout_name' => 'test-layout',
                    'endpoint' => '/api/admin/test',
                    'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                    'data_sources' => [],
                    'metadata' => ['step' => 'first_update'],
                ],
            ]);
        $response1->assertStatus(200);

        // Act - 두 번째 업데이트 (첫 저장으로 lock_version=1 로 증가)
        $response2 = $this->authRequest()
            ->putJson("/api/admin/templates/{$userTemplate->identifier}/layouts/{$layout->name}", [
                'expected_lock_version' => 1,
                'content' => [
                    'version' => '1.0.0',
                    'layout_name' => 'test-layout',
                    'endpoint' => '/api/admin/test',
                    'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                    'data_sources' => [],
                    'metadata' => ['step' => 'second_update'],
                ],
            ]);
        $response2->assertStatus(200);

        // Assert
        $versions = TemplateLayoutVersion::where('layout_id', $layout->id)
            ->orderBy('version', 'asc')
            ->get();

        // 첫 번째 업데이트: baseline(v1: initial) + 수정본(v2: first_update)
        // 두 번째 업데이트: 수정본(v3: second_update) — baseline 은 이미 있어 추가 안 함
        // 총 3개 버전 (PUT 당 1건 + 최초 1회 baseline)
        $this->assertCount(3, $versions);

        // 버전 1: 수정 전 원본 baseline (initial)
        $this->assertEquals(1, $versions[0]->version);
        $this->assertEquals('initial', $versions[0]->content['metadata']['step']);

        // 버전 2: 첫 번째 업데이트된 content
        $this->assertEquals(2, $versions[1]->version);
        $this->assertEquals('first_update', $versions[1]->content['metadata']['step']);

        // 버전 3: 두 번째 업데이트된 content
        $this->assertEquals(3, $versions[2]->version);
        $this->assertEquals('second_update', $versions[2]->content['metadata']['step']);
    }

    /**
     * 레이아웃 업데이트 시 meta 필드 포함
     *
     * meta 필드가 validated() 결과에 포함되어 저장되는지 확인
     * (이전에는 rules에 없어서 meta가 사라지는 버그가 있었음)
     */
    public function test_update_layout_preserves_meta_field(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'test-layout',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'meta' => [
                    'title' => 'Old Title',
                    'description' => 'Old Description',
                ],
                'endpoint' => '/api/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
            ],
        ]);

        $updateData = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'meta' => [
                    'title' => '$t:new.title',
                    'description' => '$t:new.description',
                    'auth_required' => true,
                ],
                'endpoint' => '/api/admin/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
            ],
        ];

        // Act
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}", $updateData);

        // Assert
        $response->assertStatus(200);

        $layout->refresh();
        $this->assertArrayHasKey('meta', $layout->content);
        $this->assertEquals('$t:new.title', $layout->content['meta']['title']);
        $this->assertEquals('$t:new.description', $layout->content['meta']['description']);
        $this->assertTrue($layout->content['meta']['auth_required']);
    }

    /**
     * 레이아웃 업데이트 시 modals, state, init_actions 필드 포함
     */
    public function test_update_layout_preserves_additional_fields(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'test-layout',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
            ],
        ]);

        $updateData = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'meta' => [
                    'title' => 'Test Title',
                ],
                'endpoint' => '/api/admin/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
                'modals' => [
                    [
                        'id' => 'test_modal',
                        'type' => 'composite',
                        'name' => 'Modal',
                    ],
                ],
                'state' => [
                    'selectedItem' => null,
                    'isLoading' => false,
                ],
                'init_actions' => [
                    [
                        'handler' => 'setState',
                        'params' => [
                            'target' => 'global',
                            'testValue' => true,
                        ],
                    ],
                ],
            ],
        ];

        // Act
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}", $updateData);

        // Assert
        $response->assertStatus(200);

        $layout->refresh();
        $this->assertArrayHasKey('modals', $layout->content);
        $this->assertArrayHasKey('state', $layout->content);
        $this->assertArrayHasKey('init_actions', $layout->content);
        $this->assertCount(1, $layout->content['modals']);
        $this->assertEquals('test_modal', $layout->content['modals'][0]['id']);
        $this->assertFalse($layout->content['state']['isLoading']);
        $this->assertEquals('setState', $layout->content['init_actions'][0]['handler']);
    }

    /**
     * 레이아웃 업데이트 시 meta 하위의 비-SEO 메타 키 보존 (guest_only / is_error_layout / error_code / keywords)
     *
     * validated() 는 rules() 에 명시된 키만 반환하므로, content.meta 가 'array' 규칙이고
     * 하위에 title/description 등 일부 키만 명시돼 있으면 명시되지 않은 meta 하위 키는 저장 시
     * 누락된다. guest_only 가 사라지면 비로그인 전용 레이아웃(로그인/회원가입/비번찾기/재설정)이
     * 편집기 저장 후 reload 시 "이미 로그인" 가드가 오발화한다.
     */
    public function test_update_layout_preserves_non_seo_meta_keys(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'auth/forgot_password',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'auth/forgot_password',
                'meta' => [
                    'title' => '$t:auth.forgot_password.title',
                    'guest_only' => true,
                ],
                'endpoint' => '/api/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
            ],
        ]);

        $updateData = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'auth/forgot_password',
                'meta' => [
                    'title' => '$t:auth.forgot_password.title',
                    'guest_only' => true,
                    'is_error_layout' => true,
                    'error_code' => 404,
                    'keywords' => '{{page?.data?.keywords ?? \'\'}}',
                ],
                'endpoint' => '/api/admin/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
            ],
        ];

        // Act
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layouts/auth/forgot_password", $updateData);

        // Assert
        $response->assertStatus(200);

        $layout->refresh();
        $this->assertTrue($layout->content['meta']['guest_only'], 'guest_only 가 저장 시 유실됨');
        $this->assertTrue($layout->content['meta']['is_error_layout'], 'is_error_layout 가 저장 시 유실됨');
        $this->assertEquals(404, $layout->content['meta']['error_code'], 'error_code 가 저장 시 유실됨');
        $this->assertEquals('{{page?.data?.keywords ?? \'\'}}', $layout->content['meta']['keywords'], 'keywords 가 저장 시 유실됨');
    }

    /**
     * 레이아웃 업데이트 시 meta.seo 하위의 비표준 키 보존 (vars / page_type / extensions / toggle_setting)
     *
     * content.meta.seo 도 'array' 규칙이고 enabled/data_sources/priority 등 일부 하위 키만
     * 명시돼 있어, 명시되지 않은 seo 하위 키(SEO 페이지 생성기가 소비하는 vars/page_type 등)가
     * 저장 시 누락된다.
     */
    public function test_update_layout_preserves_non_standard_seo_keys(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'board/index',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'board/index',
                'endpoint' => '/api/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
            ],
        ]);

        $updateData = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'board/index',
                'meta' => [
                    'title' => 'Board',
                    'seo' => [
                        'enabled' => true,
                        'page_type' => 'board',
                        'toggle_setting' => '$module_settings:sirsoft-board:seo.seo_board',
                        'vars' => [
                            'site_name' => '$core_settings:general.site_name',
                            'board_name' => '{{posts.data.board.name ?? \'\'}}',
                        ],
                        'extensions' => [
                            ['type' => 'module', 'id' => 'sirsoft-board'],
                        ],
                    ],
                ],
                'endpoint' => '/api/admin/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
            ],
        ];

        // Act
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layouts/board/index", $updateData);

        // Assert
        $response->assertStatus(200);

        $layout->refresh();
        $seo = $layout->content['meta']['seo'];
        $this->assertEquals('board', $seo['page_type'], 'seo.page_type 가 저장 시 유실됨');
        $this->assertEquals('$module_settings:sirsoft-board:seo.seo_board', $seo['toggle_setting'], 'seo.toggle_setting 가 저장 시 유실됨');
        $this->assertArrayHasKey('vars', $seo, 'seo.vars 가 저장 시 유실됨');
        $this->assertEquals('$core_settings:general.site_name', $seo['vars']['site_name']);
        $this->assertArrayHasKey('extensions', $seo, 'seo.extensions 가 저장 시 유실됨');
        $this->assertEquals('sirsoft-board', $seo['extensions'][0]['id']);
    }

    /**
     * 레이아웃 업데이트 시 content 직속 상태/액션 키 보존 (initLocal / initGlobal / errorHandling / global_state / actions)
     *
     * 이 키들은 엔진이 상태 초기화/에러 처리/액션에 소비하지만 rules() 에 없어 validated() 에서
     * 누락된다. 저장 시마다 레이아웃 동작 정의가 사라지는 손상.
     */
    public function test_update_layout_preserves_content_top_state_action_keys(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'test-layout',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
            ],
        ]);

        $updateData = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/admin/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
                'initLocal' => ['driverTestResults' => [], 'activeChannel' => null],
                'initGlobal' => ['reviewImagePreview' => null],
                'errorHandling' => [
                    '404' => ['handler' => 'showErrorPage', 'params' => ['target' => 'content']],
                ],
                'global_state' => ['searchActiveTab' => 'all'],
                'actions' => [
                    ['id' => 'selectBank', 'handler' => 'setState', 'params' => ['target' => '_local.bank', 'value' => 'x']],
                ],
            ],
        ];

        // Act
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layouts/test-layout", $updateData);

        // Assert
        $response->assertStatus(200);

        $layout->refresh();
        $this->assertArrayHasKey('initLocal', $layout->content, 'initLocal 이 저장 시 유실됨');
        $this->assertArrayHasKey('initGlobal', $layout->content, 'initGlobal 이 저장 시 유실됨');
        $this->assertArrayHasKey('errorHandling', $layout->content, 'errorHandling 이 저장 시 유실됨');
        $this->assertArrayHasKey('global_state', $layout->content, 'global_state 가 저장 시 유실됨');
        $this->assertArrayHasKey('actions', $layout->content, 'actions 가 저장 시 유실됨');
        $this->assertEquals('all', $layout->content['global_state']['searchActiveTab']);
        $this->assertEquals('showErrorPage', $layout->content['errorHandling']['404']['handler']);
        $this->assertEquals('selectBank', $layout->content['actions'][0]['id']);
    }

    /**
     * 레이아웃 업데이트 시 플러그인 settings 전용 키 보존 (pageConfig / schema)
     *
     * 플러그인 설정 레이아웃이 content 직속에 두는 pageConfig/schema 키도 저장 시 누락된다.
     */
    public function test_update_layout_preserves_plugin_settings_keys(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'plugin_settings',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'plugin_settings',
                'endpoint' => '/api/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
            ],
        ]);

        $updateData = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'plugin_settings',
                'endpoint' => '/api/admin/test',
                'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
                'data_sources' => [],
                'pageConfig' => ['notice' => '$t:plugin.notice'],
                'schema' => [
                    'is_test_mode' => ['type' => 'boolean', 'default' => true],
                ],
            ],
        ];

        // Act
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layouts/plugin_settings", $updateData);

        // Assert
        $response->assertStatus(200);

        $layout->refresh();
        $this->assertArrayHasKey('pageConfig', $layout->content, 'pageConfig 가 저장 시 유실됨');
        $this->assertArrayHasKey('schema', $layout->content, 'schema 가 저장 시 유실됨');
        $this->assertEquals('$t:plugin.notice', $layout->content['pageConfig']['notice']);
        $this->assertTrue($layout->content['schema']['is_test_mode']['default']);
    }

    /**
     * 레이아웃 업데이트 시 extends 레이아웃도 모든 필드 보존
     */
    public function test_update_extends_layout_preserves_all_fields(): void
    {
        // Arrange - 부모 레이아웃 먼저 생성 (Base 레이아웃)
        // Base 레이아웃에는 slot이 정의된 컴포넌트가 있어야 자식이 해당 슬롯을 사용할 수 있음
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_admin_base',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => '_admin_base',
                'meta' => [
                    'title' => 'Admin Base',
                    'is_base' => true,
                ],
                'components' => [
                    [
                        'id' => 'root',
                        'type' => 'layout',
                        'name' => 'Container',
                        'children' => [
                            [
                                'id' => 'content_slot',
                                'type' => 'layout',
                                'name' => 'Div',
                                'slot' => 'content',  // content 슬롯 정의
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // extends 레이아웃 (부모 상속)
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'test-child-layout',
            'extends' => '_admin_base',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-child-layout',
                'extends' => '_admin_base',
                'meta' => [
                    'title' => 'Old Title',
                ],
                'slots' => [
                    'content' => [],
                ],
                'data_sources' => [],
            ],
        ]);

        $updateData = [
            'expected_lock_version' => 0,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-child-layout',
                'extends' => '_admin_base',
                'meta' => [
                    'title' => 'New Title',
                    'description' => 'New Description',
                ],
                'slots' => [
                    'content' => [
                        ['id' => 'new_component', 'type' => 'basic', 'name' => 'Div'],
                    ],
                ],
                'data_sources' => [
                    ['id' => 'test_data', 'type' => 'api', 'endpoint' => '/api/admin/test'],
                ],
                'state' => [
                    'selectedTab' => 'info',
                ],
                'modals' => [],
            ],
        ];

        // Act
        $response = $this->authRequest()
            ->putJson("/api/admin/templates/{$this->template->identifier}/layouts/{$layout->name}", $updateData);

        // Assert
        $response->assertStatus(200);

        $layout->refresh();
        $this->assertEquals('_admin_base', $layout->content['extends']);
        $this->assertEquals('New Title', $layout->content['meta']['title']);
        $this->assertEquals('New Description', $layout->content['meta']['description']);
        $this->assertCount(1, $layout->content['slots']['content']);
        $this->assertArrayHasKey('state', $layout->content);
        $this->assertEquals('info', $layout->content['state']['selectedTab']);
    }

    // ========================================
    // 미리보기 (Preview) 테스트
    // ========================================

    /**
     * 미리보기 생성 성공
     */
    public function test_can_store_preview(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'main',
        ]);

        $content = [
            'version' => '1.0.0',
            'layout_name' => 'main',
            'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
            'data_sources' => [],
        ];

        // Act
        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layouts/main/preview", [
                'content' => $content,
            ]);

        // Assert
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['token', 'preview_url', 'expires_at'],
        ]);

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertStringStartsWith('/preview/', $response->json('data.preview_url'));

        // DB에 저장 확인
        $this->assertDatabaseHas('template_layout_previews', [
            'token' => $token,
            'template_id' => $this->template->id,
            'layout_name' => 'main',
            'admin_id' => $this->adminUser->id,
        ]);
    }

    /**
     * 슬래시 포함 레이아웃 이름으로 미리보기 생성 성공
     */
    public function test_can_store_preview_with_slash_in_layout_name(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'auth/reset_password',
        ]);

        $content = [
            'version' => '1.0.0',
            'layout_name' => 'auth/reset_password',
            'components' => [],
        ];

        // Act
        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layouts/auth/reset_password/preview", [
                'content' => $content,
            ]);

        // Assert
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['token', 'preview_url', 'expires_at'],
        ]);
    }

    /**
     * 미리보기 생성 시 content 필수 검증
     */
    public function test_store_preview_requires_content(): void
    {
        // Act
        $response = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layouts/main/preview", []);

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['content']);
    }

    /**
     * 동일 조합으로 미리보기 재생성 시 이전 미리보기 삭제
     */
    public function test_store_preview_replaces_existing_preview(): void
    {
        // Arrange
        $content1 = ['version' => '1.0.0', 'components' => [['id' => 'v1']]];
        $content2 = ['version' => '1.0.0', 'components' => [['id' => 'v2']]];

        // Act - 첫 번째 미리보기 생성
        $response1 = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layouts/main/preview", [
                'content' => $content1,
            ]);
        $token1 = $response1->json('data.token');

        // Act - 두 번째 미리보기 생성 (동일 레이아웃)
        $response2 = $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layouts/main/preview", [
                'content' => $content2,
            ]);
        $token2 = $response2->json('data.token');

        // Assert - 첫 번째 토큰은 삭제되고 두 번째만 존재
        $this->assertDatabaseMissing('template_layout_previews', ['token' => $token1]);
        $this->assertDatabaseHas('template_layout_previews', ['token' => $token2]);
    }

    /**
     * 존재하지 않는 템플릿으로 미리보기 생성 시 404
     */
    public function test_store_preview_with_nonexistent_template_returns_404(): void
    {
        // Act
        $response = $this->authRequest()
            ->postJson('/api/admin/templates/nonexistent-template/layouts/main/preview', [
                'content' => ['version' => '1.0.0', 'components' => []],
            ]);

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 미인증 사용자 미리보기 생성 불가
     */
    public function test_unauthenticated_user_cannot_store_preview(): void
    {
        // Act
        $response = $this->postJson("/api/admin/templates/{$this->template->identifier}/layouts/main/preview", [
            'content' => ['version' => '1.0.0', 'components' => []],
        ]);

        // Assert
        $response->assertStatus(401);
    }

    /**
     * 슬래시 포함 레이아웃 이름 조회 성공
     */
    public function test_can_show_layout_with_slash_in_name(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'auth/login',
        ]);

        // Act
        $response = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/auth/login");

        // Assert
        $response->assertStatus(200);
    }
}
