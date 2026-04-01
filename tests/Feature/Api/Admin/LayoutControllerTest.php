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

    private User $adminUser;
    private User $normalUser;
    private Template $template;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성 (필요한 권한 포함)
        $this->adminUser = $this->createAdminUser([
            'core.templates.read',
            'core.templates.activate',
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
                        'components',
                        'data_sources',
                        'metadata',
                    ],
                ],
            ]);

        $this->assertCount(3, $response->json('data'));
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
                        'created_at',
                    ],
                ],
            ]);

        $this->assertCount(3, $response->json('data'));
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
                    'created_at',
                ],
            ])
            ->assertJsonFragment([
                'endpoint' => '/api/test',
            ]);

        $this->assertEquals(2, $response->json('data.version'));
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

        // 버전 3이 생성되었는지 확인 (복원 전의 content를 저장)
        $newVersion = TemplateLayoutVersion::where('layout_id', $layout->id)
            ->where('version', 3)
            ->first();

        $this->assertNotNull($newVersion);
        // 새 버전은 복원 전의 content (v3)를 저장하여 히스토리 보존
        $this->assertEquals('/api/v3', $newVersion->content['endpoint']);
        $this->assertEquals(['version' => '3'], $newVersion->content['components']);

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

        // Act - 첫 번째 업데이트
        $response1 = $this->authRequest()
            ->putJson("/api/admin/templates/{$userTemplate->identifier}/layouts/{$layout->name}", [
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

        // Act - 두 번째 업데이트
        $response2 = $this->authRequest()
            ->putJson("/api/admin/templates/{$userTemplate->identifier}/layouts/{$layout->name}", [
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

        // 첫 번째 업데이트: 2개 버전 (v1: old, v2: new)
        // 두 번째 업데이트: 2개 버전 추가 (v3: old, v4: new)
        // 총 4개 버전
        $this->assertCount(4, $versions);

        // 버전 1: 최초 content
        $this->assertEquals(1, $versions[0]->version);
        $this->assertEquals('initial', $versions[0]->content['metadata']['step']);

        // 버전 2: 첫 번째 업데이트된 content
        $this->assertEquals(2, $versions[1]->version);
        $this->assertEquals('first_update', $versions[1]->content['metadata']['step']);

        // 버전 3: 두 번째 업데이트 전 content (first_update)
        $this->assertEquals(3, $versions[2]->version);
        $this->assertEquals('first_update', $versions[2]->content['metadata']['step']);

        // 버전 4: 두 번째 업데이트된 content
        $this->assertEquals(4, $versions[3]->version);
        $this->assertEquals('second_update', $versions[3]->content['metadata']['step']);
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
