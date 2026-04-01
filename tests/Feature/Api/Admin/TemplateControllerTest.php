<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

/**
 * TemplateController API 테스트
 *
 * GET /api/admin/templates 엔드포인트 테스트
 * GET /api/admin/templates/{templateName} 엔드포인트 테스트
 * POST /api/admin/templates/install 엔드포인트 테스트
 * POST /api/admin/templates/activate 엔드포인트 테스트
 * POST /api/admin/templates/deactivate 엔드포인트 테스트
 * DELETE /api/admin/templates/uninstall 엔드포인트 테스트
 */
class TemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private User $normalUser;

    private string $adminToken;

    private string $normalToken;

    protected function setUp(): void
    {
        parent::setUp();

        // TemplateManager를 Mock하여 파일 시스템 의존성 제거
        $this->mockTemplateManager();

        // 관리자 사용자 생성 (모든 템플릿 권한)
        $this->adminUser = $this->createAdminUser([
            'core.templates.read',
            'core.templates.install',
            'core.templates.activate',
            'core.templates.uninstall',
        ]);
        $this->adminToken = $this->adminUser->createToken('test-token')->plainTextToken;

        // 일반 사용자 생성 (역할 없음)
        $this->normalUser = User::factory()->create();
        $this->normalToken = $this->normalUser->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 역할 생성 및 할당
     *
     * 중요: AdminMiddleware가 isAdmin()을 체크하고, isAdmin()은 hasRole('admin')을 확인합니다.
     * 따라서 역할 identifier는 반드시 'admin'이어야 합니다.
     *
     * @param  array  $permissions  사용자에게 부여할 권한 식별자 목록
     */
    private function createAdminUser(array $permissions = []): User
    {
        $user = User::factory()->create();

        // 권한 생성
        $permissionIds = [];
        foreach ($permissions as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier.' 권한', 'en' => $permIdentifier.' Permission']),
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
            'Authorization' => 'Bearer '.$this->adminToken,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 일반 사용자 요청 헬퍼 메서드
     */
    private function normalUserRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->normalToken,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * TemplateManager를 Mock 처리합니다.
     */
    protected function mockTemplateManager(): void
    {
        $mock = Mockery::mock(TemplateManagerInterface::class);

        // 기본 동작 설정
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('getInstalledTemplatesWithDetails')->andReturn([]);
        $mock->shouldReceive('getUninstalledTemplates')->andReturn([]);
        $mock->shouldReceive('getTemplateInfo')->andReturn(null);
        $mock->shouldReceive('installTemplate')->andReturn(true);
        $mock->shouldReceive('uninstallTemplate')->andReturn(true);
        $mock->shouldReceive('activateTemplate')->andReturn(true);
        $mock->shouldReceive('deactivateTemplate')->andReturn(true);

        $this->app->instance(TemplateManagerInterface::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // index() 테스트
    // ========================================

    /**
     * 템플릿 목록 조회 성공
     */
    public function test_can_list_templates(): void
    {
        // Arrange: Mock에서 반환할 데이터 설정
        $installedTemplates = [
            [
                'identifier' => 'test-template1',
                'vendor' => 'test',
                'name' => 'Test Template 1',
                'version' => '1.0.0',
                'type' => 'user',
                'status' => 'active',
                'description' => 'Test description 1',
            ],
            [
                'identifier' => 'test-template2',
                'vendor' => 'test',
                'name' => 'Test Template 2',
                'version' => '1.0.0',
                'type' => 'admin',
                'status' => 'inactive',
                'description' => 'Test description 2',
            ],
        ];

        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('getInstalledTemplatesWithDetails')->andReturn($installedTemplates);
        $mock->shouldReceive('getUninstalledTemplates')->andReturn([]);
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->getJson('/api/admin/templates');

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data',
                    'pagination' => [
                        'total',
                        'current_page',
                        'last_page',
                        'per_page',
                    ],
                ],
            ]);

        $this->assertEquals(2, $response->json('data.pagination.total'));
    }

    /**
     * type=user 필터링 테스트
     */
    public function test_can_filter_templates_by_type_user(): void
    {
        // Arrange
        $installedTemplates = [
            [
                'identifier' => 'test-template1',
                'vendor' => 'test',
                'name' => 'Test Template 1',
                'version' => '1.0.0',
                'type' => 'user',
                'status' => 'active',
                'description' => 'Test description',
            ],
            [
                'identifier' => 'test-template2',
                'vendor' => 'test',
                'name' => 'Test Template 2',
                'version' => '1.0.0',
                'type' => 'admin',
                'status' => 'inactive',
                'description' => 'Test description',
            ],
        ];

        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('getInstalledTemplatesWithDetails')->andReturn($installedTemplates);
        $mock->shouldReceive('getUninstalledTemplates')->andReturn([]);
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->getJson('/api/admin/templates?type=user');

        // Assert
        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.pagination.total'));
    }

    /**
     * type=admin 필터링 테스트
     */
    public function test_can_filter_templates_by_type_admin(): void
    {
        // Arrange
        $installedTemplates = [
            [
                'identifier' => 'test-template1',
                'vendor' => 'test',
                'name' => 'Test Template 1',
                'version' => '1.0.0',
                'type' => 'user',
                'status' => 'active',
                'description' => 'Test description',
            ],
            [
                'identifier' => 'test-template2',
                'vendor' => 'test',
                'name' => 'Test Template 2',
                'version' => '1.0.0',
                'type' => 'admin',
                'status' => 'inactive',
                'description' => 'Test description',
            ],
        ];

        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('getInstalledTemplatesWithDetails')->andReturn($installedTemplates);
        $mock->shouldReceive('getUninstalledTemplates')->andReturn([]);
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->getJson('/api/admin/templates?type=admin');

        // Assert
        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.pagination.total'));
    }

    /**
     * 잘못된 type 파라미터 검증
     */
    public function test_rejects_invalid_type_parameter(): void
    {
        // Act
        $response = $this->authRequest()->getJson('/api/admin/templates?type=invalid');

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    /**
     * 인증되지 않은 사용자 접근 거부
     */
    public function test_unauthenticated_user_cannot_access(): void
    {
        // Act
        $response = $this->getJson('/api/admin/templates');

        // Assert
        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자 접근 거부
     */
    public function test_unauthorized_user_cannot_access(): void
    {
        // Act
        $response = $this->normalUserRequest()->getJson('/api/admin/templates');

        // Assert
        $response->assertStatus(403);
    }

    /**
     * 빈 목록 조회 (템플릿이 없을 때)
     */
    public function test_returns_empty_array_when_no_templates(): void
    {
        // Act
        $response = $this->authRequest()->getJson('/api/admin/templates');

        // Assert
        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('data.total'));
        $this->assertEmpty($response->json('data.data'));
    }

    /**
     * 전체 권한 유저의 index 응답에 abilities 포함 확인
     */
    public function test_index_returns_abilities_for_full_permission_user(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->adminToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates');

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_install', true)
            ->assertJsonPath('data.abilities.can_activate', true)
            ->assertJsonPath('data.abilities.can_uninstall', true);
    }

    /**
     * read 전용 유저의 index 응답에 abilities false 확인
     */
    public function test_index_returns_false_abilities_for_read_only_user(): void
    {
        $readOnlyUser = $this->createAdminUser(['core.templates.read']);
        $readOnlyToken = $readOnlyUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readOnlyToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates');

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_install', false)
            ->assertJsonPath('data.abilities.can_activate', false)
            ->assertJsonPath('data.abilities.can_uninstall', false);
    }

    // ========================================
    // show() 테스트
    // ========================================

    /**
     * 템플릿 상세 조회 성공
     */
    public function test_can_show_template(): void
    {
        // Arrange - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-'.uniqid();
        $template = Template::factory()->create([
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => [
                'ko' => '테스트 템플릿',
                'en' => 'Test Template',
            ],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => 'active',
            'description' => [
                'ko' => '테스트 설명',
                'en' => 'Test description',
            ],
        ]);

        // Mock 설정
        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('getTemplateInfo')->andReturn([
            'id' => $template->id,
            'identifier' => $template->identifier,
            'vendor' => $template->vendor,
            'name' => $template->name,
            'version' => $template->version,
            'type' => $template->type,
            'status' => $template->status,
            'description' => $template->description,
        ]);
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->getJson("/api/admin/templates/{$template->identifier}");

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'identifier',
                    'vendor',
                    'name',
                    'version',
                    'type',
                    'status',
                ],
            ])
            ->assertJsonFragment([
                'identifier' => $identifier,
                'vendor' => 'test-vendor',
                'version' => '1.0.0',
                'type' => 'user',
                'status' => 'active',
            ]);
    }

    /**
     * 존재하지 않는 템플릿 조회 시 404 응답
     */
    public function test_returns_404_for_nonexistent_template(): void
    {
        // Act
        $response = $this->authRequest()->getJson('/api/admin/templates/nonexistent-template');

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 인증되지 않은 사용자는 템플릿 상세 조회 불가
     */
    public function test_unauthenticated_user_cannot_show_template(): void
    {
        // Arrange
        $template = Template::factory()->create();

        // Act
        $response = $this->getJson("/api/admin/templates/{$template->identifier}");

        // Assert
        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자는 템플릿 상세 조회 불가
     */
    public function test_unauthorized_user_cannot_show_template(): void
    {
        // Arrange
        $template = Template::factory()->create();

        // Act
        $response = $this->normalUserRequest()->getJson("/api/admin/templates/{$template->identifier}");

        // Assert
        $response->assertStatus(403);
    }

    /**
     * 템플릿 상세 조회 시 민감한 정보 제외
     */
    public function test_show_response_excludes_sensitive_fields(): void
    {
        // Arrange
        $template = Template::factory()->create();

        // Mock 설정
        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('getTemplateInfo')->andReturn([
            'id' => $template->id,
            'identifier' => $template->identifier,
            'vendor' => $template->vendor,
            'name' => $template->name,
            'version' => $template->version,
            'type' => $template->type,
            'status' => $template->status,
        ]);
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->getJson("/api/admin/templates/{$template->identifier}");

        // Assert
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('created_by', $data);
        $this->assertArrayNotHasKey('updated_by', $data);
        $this->assertArrayNotHasKey('deleted_at', $data);
    }

    // ========================================
    // activate() 테스트
    // ========================================

    /**
     * 템플릿 활성화 성공
     */
    public function test_can_activate_template(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'status' => 'inactive',
        ]);

        // Mock 설정
        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('getTemplate')->andReturn([
            'identifier' => $template->identifier,
            'vendor' => $template->vendor,
            'name' => $template->name,
            'version' => $template->version,
            'type' => $template->type,
        ]);
        $mock->shouldReceive('getTemplateInfo')->andReturn([
            'identifier' => $template->identifier,
            'vendor' => $template->vendor,
            'name' => $template->name,
            'version' => $template->version,
            'type' => $template->type,
            'status' => 'active',
        ]);
        $mock->shouldReceive('activateTemplate')->andReturnUsing(function () use ($template) {
            $template->update(['status' => 'active']);

            return [
                'success' => true,
                'template_info' => [
                    'identifier' => $template->identifier,
                    'name' => $template->name,
                    'status' => 'active',
                ],
            ];
        });
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/activate', [
            'template_name' => $template->identifier,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'active']);

        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'status' => 'active',
        ]);
    }

    /**
     * 템플릿 활성화 시 template_name 필수
     */
    public function test_activate_requires_template_name(): void
    {
        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/activate', []);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template_name']);
    }

    /**
     * 존재하지 않는 템플릿 활성화 시 422 응답 (ValidationException)
     */
    public function test_activate_nonexistent_template_returns_422(): void
    {
        // Arrange
        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('activateTemplate')->andThrow(
            \Illuminate\Validation\ValidationException::withMessages([
                'template' => ['No active template found'],
            ])
        );
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/activate', [
            'template_name' => 'nonexistent-template',
        ]);

        // Assert
        $response->assertStatus(422);
    }

    /**
     * 권한 없는 사용자는 템플릿 활성화 불가
     */
    public function test_unauthorized_user_cannot_activate_template(): void
    {
        // Arrange
        $template = Template::factory()->create(['status' => 'inactive']);

        // Act
        $response = $this->normalUserRequest()->postJson('/api/admin/templates/activate', [
            'template_name' => $template->identifier,
        ]);

        // Assert
        $response->assertStatus(403);
    }

    /**
     * 인증되지 않은 사용자는 템플릿 활성화 불가
     */
    public function test_unauthenticated_user_cannot_activate_template(): void
    {
        // Act
        $response = $this->postJson('/api/admin/templates/activate', [
            'template_name' => 'test-template',
        ]);

        // Assert
        $response->assertStatus(401);
    }

    // ========================================
    // install() 테스트
    // ========================================

    /**
     * 템플릿 설치 성공
     */
    public function test_can_install_template(): void
    {
        // Arrange
        $templateIdentifier = 'test-install-template';

        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('getTemplate')->andReturn([
            'identifier' => $templateIdentifier,
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'type' => 'admin',
        ]);
        $mock->shouldReceive('installTemplate')->andReturn(true);
        $mock->shouldReceive('getTemplateInfo')->andReturn([
            'identifier' => $templateIdentifier,
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'inactive',
        ]);
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/install', [
            'template_name' => $templateIdentifier,
        ]);

        // Assert
        $response->assertStatus(201)
            ->assertJsonFragment(['success' => true]);
    }

    /**
     * 템플릿 설치 시 template_name 필수
     */
    public function test_install_template_requires_template_name(): void
    {
        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/install', []);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template_name']);
    }

    /**
     * 존재하지 않는 템플릿 설치 시 422 응답 (ValidationException)
     */
    public function test_install_nonexistent_template_fails(): void
    {
        // Arrange
        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('getTemplate')->andReturn(null);
        $mock->shouldReceive('installTemplate')->andThrow(
            \Illuminate\Validation\ValidationException::withMessages([
                'identifier' => ['Failed to install template.: Template not found'],
            ])
        );
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/install', [
            'template_name' => 'nonexistent-template',
        ]);

        // Assert
        $response->assertStatus(422);
    }

    /**
     * 인증되지 않은 사용자는 템플릿 설치 불가
     */
    public function test_unauthenticated_user_cannot_install_template(): void
    {
        // Act
        $response = $this->postJson('/api/admin/templates/install', [
            'template_name' => 'test-template',
        ]);

        // Assert
        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자는 템플릿 설치 불가
     */
    public function test_unauthorized_user_cannot_install_template(): void
    {
        // Act
        $response = $this->normalUserRequest()->postJson('/api/admin/templates/install', [
            'template_name' => 'test-template',
        ]);

        // Assert
        $response->assertStatus(403);
    }

    // ========================================
    // installFromFile() 테스트
    // ========================================

    /**
     * ZIP 파일에서 템플릿 설치 시 파일 필수
     */
    public function test_install_from_file_requires_file(): void
    {
        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/install-from-file', []);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * ZIP 파일이 아닌 파일 업로드 시 실패
     */
    public function test_install_from_file_rejects_non_zip_file(): void
    {
        // Arrange
        $file = UploadedFile::fake()->create('test.txt', 100, 'text/plain');

        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/install-from-file', [
            'file' => $file,
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 인증되지 않은 사용자는 파일 설치 불가
     */
    public function test_unauthenticated_user_cannot_install_from_file(): void
    {
        // Arrange
        $file = UploadedFile::fake()->create('test.zip', 100, 'application/zip');

        // Act
        $response = $this->postJson('/api/admin/templates/install-from-file', [
            'file' => $file,
        ]);

        // Assert
        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자는 파일 설치 불가
     */
    public function test_unauthorized_user_cannot_install_from_file(): void
    {
        // Arrange
        $file = UploadedFile::fake()->create('test.zip', 100, 'application/zip');

        // Act
        $response = $this->normalUserRequest()->postJson('/api/admin/templates/install-from-file', [
            'file' => $file,
        ]);

        // Assert
        $response->assertStatus(403);
    }

    // ========================================
    // installFromGithub() 테스트
    // ========================================

    /**
     * GitHub URL 필수
     */
    public function test_install_from_github_requires_url(): void
    {
        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/install-from-github', []);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['github_url']);
    }

    /**
     * 잘못된 GitHub URL 형식 거부
     */
    public function test_install_from_github_rejects_invalid_url(): void
    {
        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/install-from-github', [
            'github_url' => 'not-a-valid-url',
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['github_url']);
    }

    /**
     * GitHub 이외의 URL 거부
     */
    public function test_install_from_github_rejects_non_github_url(): void
    {
        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/install-from-github', [
            'github_url' => 'https://gitlab.com/user/repo',
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['github_url']);
    }

    /**
     * 유효한 ZIP 파일로 템플릿 설치 성공 (Service Mock)
     */
    public function test_install_from_file_succeeds_with_valid_zip(): void
    {
        $templateServiceMock = \Mockery::mock(\App\Services\TemplateService::class);
        $templateServiceMock->shouldReceive('installFromZipFile')
            ->once()
            ->andReturn([
                'identifier' => 'sirsoft-testtemplate',
                'name' => 'Test Template',
                'version' => '1.0.0',
            ]);
        $this->app->instance(\App\Services\TemplateService::class, $templateServiceMock);

        $file = UploadedFile::fake()->create('template.zip', 100, 'application/zip');

        $response = $this->authRequest()->postJson('/api/admin/templates/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.identifier', 'sirsoft-testtemplate');
    }

    /**
     * TemplateService에서 RuntimeException 발생 시 422 반환
     */
    public function test_install_from_file_returns_422_on_runtime_exception(): void
    {
        $templateServiceMock = \Mockery::mock(\App\Services\TemplateService::class);
        $templateServiceMock->shouldReceive('installFromZipFile')
            ->once()
            ->andThrow(new \RuntimeException('template.json을 찾을 수 없습니다.'));
        $this->app->instance(\App\Services\TemplateService::class, $templateServiceMock);

        $file = UploadedFile::fake()->create('template.zip', 100, 'application/zip');

        $response = $this->authRequest()->postJson('/api/admin/templates/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'template.json을 찾을 수 없습니다.');
    }

    /**
     * TemplateService에서 일반 Exception 발생 시 500 반환
     */
    public function test_install_from_file_returns_500_on_general_exception(): void
    {
        $templateServiceMock = \Mockery::mock(\App\Services\TemplateService::class);
        $templateServiceMock->shouldReceive('installFromZipFile')
            ->once()
            ->andThrow(new \Exception('예상치 못한 오류'));
        $this->app->instance(\App\Services\TemplateService::class, $templateServiceMock);

        $file = UploadedFile::fake()->create('template.zip', 100, 'application/zip');

        $response = $this->authRequest()->postJson('/api/admin/templates/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(500);
    }

    // ========================================
    // GitHub 설치 - Service Mock 테스트
    // ========================================

    /**
     * 유효한 GitHub URL로 템플릿 설치 성공 (Service Mock)
     */
    public function test_install_from_github_calls_service_with_valid_url(): void
    {
        $templateServiceMock = \Mockery::mock(\App\Services\TemplateService::class);
        $templateServiceMock->shouldReceive('installFromGithub')
            ->once()
            ->with('https://github.com/sirsoft/sample-template')
            ->andReturn([
                'identifier' => 'sirsoft-sampletemplate',
                'name' => 'Sample Template',
                'version' => '1.0.0',
            ]);
        $this->app->instance(\App\Services\TemplateService::class, $templateServiceMock);

        $response = $this->authRequest()->postJson('/api/admin/templates/install-from-github', [
            'github_url' => 'https://github.com/sirsoft/sample-template',
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.identifier', 'sirsoft-sampletemplate');
    }

    /**
     * TemplateService에서 RuntimeException 발생 시 422 반환 (GitHub)
     */
    public function test_install_from_github_returns_422_on_runtime_exception(): void
    {
        $templateServiceMock = \Mockery::mock(\App\Services\TemplateService::class);
        $templateServiceMock->shouldReceive('installFromGithub')
            ->once()
            ->andThrow(new \RuntimeException('GitHub 저장소를 찾을 수 없습니다.'));
        $this->app->instance(\App\Services\TemplateService::class, $templateServiceMock);

        $response = $this->authRequest()->postJson('/api/admin/templates/install-from-github', [
            'github_url' => 'https://github.com/sirsoft/sample-template',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'GitHub 저장소를 찾을 수 없습니다.');
    }

    /**
     * TemplateService에서 일반 Exception 발생 시 500 반환 (GitHub)
     */
    public function test_install_from_github_returns_500_on_general_exception(): void
    {
        $templateServiceMock = \Mockery::mock(\App\Services\TemplateService::class);
        $templateServiceMock->shouldReceive('installFromGithub')
            ->once()
            ->andThrow(new \Exception('예상치 못한 오류'));
        $this->app->instance(\App\Services\TemplateService::class, $templateServiceMock);

        $response = $this->authRequest()->postJson('/api/admin/templates/install-from-github', [
            'github_url' => 'https://github.com/sirsoft/sample-template',
        ]);

        $response->assertStatus(500);
    }

    /**
     * 인증되지 않은 사용자는 GitHub 설치 불가
     */
    public function test_unauthenticated_user_cannot_install_from_github(): void
    {
        // Act
        $response = $this->postJson('/api/admin/templates/install-from-github', [
            'github_url' => 'https://github.com/user/repo',
        ]);

        // Assert
        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자는 GitHub 설치 불가
     */
    public function test_unauthorized_user_cannot_install_from_github(): void
    {
        // Act
        $response = $this->normalUserRequest()->postJson('/api/admin/templates/install-from-github', [
            'github_url' => 'https://github.com/user/repo',
        ]);

        // Assert
        $response->assertStatus(403);
    }

    // ========================================
    // uninstall() 테스트
    // ========================================

    /**
     * 템플릿 제거 성공
     */
    public function test_can_uninstall_template(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'status' => 'inactive',
        ]);

        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('getTemplate')->andReturn([
            'identifier' => $template->identifier,
        ]);
        $mock->shouldReceive('getTemplateInfo')->andReturn([
            'identifier' => $template->identifier,
            'vendor' => $template->vendor,
            'name' => $template->name,
            'version' => $template->version,
            'status' => $template->status,
        ]);
        $mock->shouldReceive('uninstallTemplate')->andReturnUsing(function () use ($template) {
            $template->delete();

            return true;
        });
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->deleteJson('/api/admin/templates/uninstall', [
            'template_name' => $template->identifier,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);
    }

    /**
     * 템플릿 제거 시 template_name 필수
     */
    public function test_uninstall_requires_template_name(): void
    {
        // Act
        $response = $this->authRequest()->deleteJson('/api/admin/templates/uninstall', []);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template_name']);
    }

    /**
     * 인증되지 않은 사용자는 템플릿 제거 불가
     */
    public function test_unauthenticated_user_cannot_uninstall_template(): void
    {
        // Act
        $response = $this->deleteJson('/api/admin/templates/uninstall', [
            'template_name' => 'test-template',
        ]);

        // Assert
        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자는 템플릿 제거 불가
     */
    public function test_unauthorized_user_cannot_uninstall_template(): void
    {
        // Act
        $response = $this->normalUserRequest()->deleteJson('/api/admin/templates/uninstall', [
            'template_name' => 'test-template',
        ]);

        // Assert
        $response->assertStatus(403);
    }

    // ========================================
    // deactivate() 테스트
    // ========================================

    /**
     * 템플릿 비활성화 성공
     */
    public function test_can_deactivate_template(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'type' => 'admin',
            'status' => 'active',
        ]);

        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('getTemplate')->andReturn([
            'identifier' => $template->identifier,
        ]);
        $mock->shouldReceive('getTemplateInfo')->andReturn([
            'identifier' => $template->identifier,
            'vendor' => $template->vendor,
            'name' => $template->name,
            'version' => $template->version,
            'type' => $template->type,
            'status' => 'inactive',
        ]);
        $mock->shouldReceive('deactivateTemplate')->andReturnUsing(function () use ($template) {
            $template->update(['status' => 'inactive']);

            return true;
        });
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/deactivate', [
            'template_name' => $template->identifier,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);

        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'status' => 'inactive',
        ]);
    }

    /**
     * 템플릿 비활성화 시 template_name 필수
     */
    public function test_deactivate_requires_template_name(): void
    {
        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/deactivate', []);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template_name']);
    }

    /**
     * 존재하지 않는 템플릿 비활성화 시 422 응답 (ValidationException)
     */
    public function test_deactivate_nonexistent_template_fails(): void
    {
        // Arrange
        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('deactivateTemplate')->andThrow(
            \Illuminate\Validation\ValidationException::withMessages([
                'template' => ['No active template found'],
            ])
        );
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/deactivate', [
            'template_name' => 'nonexistent-template',
        ]);

        // Assert
        $response->assertStatus(422);
    }

    /**
     * 이미 비활성화된 템플릿 비활성화 시 실패
     */
    public function test_deactivate_inactive_template_fails(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'status' => 'inactive',
        ]);

        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('getTemplate')->andReturn([
            'identifier' => $template->identifier,
        ]);
        $mock->shouldReceive('deactivateTemplate')->andReturn(false);
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/deactivate', [
            'template_name' => $template->identifier,
        ]);

        // Assert
        // 비활성화 실패 시 에러 응답
        $response->assertStatus(400);
    }

    /**
     * 인증되지 않은 사용자는 템플릿 비활성화 불가
     */
    public function test_unauthenticated_user_cannot_deactivate_template(): void
    {
        // Act
        $response = $this->postJson('/api/admin/templates/deactivate', [
            'template_name' => 'test-template',
        ]);

        // Assert
        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자는 템플릿 비활성화 불가
     */
    public function test_unauthorized_user_cannot_deactivate_template(): void
    {
        // Arrange
        $template = Template::factory()->create(['status' => 'active']);

        // Act
        $response = $this->normalUserRequest()->postJson('/api/admin/templates/deactivate', [
            'template_name' => $template->identifier,
        ]);

        // Assert
        $response->assertStatus(403);
    }

    // ========================================
    // 활성화 의존성 체크 테스트
    // ========================================

    /**
     * 템플릿 활성화 시 필요한 의존성이 충족되지 않으면 409 경고 응답
     */
    public function test_activate_returns_409_when_dependencies_not_met(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'identifier' => 'test-dependent-template',
            'status' => 'inactive',
        ]);

        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('activateTemplate')
            ->with($template->identifier, false)
            ->andReturn([
                'success' => false,
                'warning' => true,
                'missing_modules' => [
                    ['identifier' => 'sirsoft-ecommerce', 'name' => 'sirsoft-ecommerce', 'status' => 'not_installed'],
                ],
                'missing_plugins' => [
                    ['identifier' => 'sirsoft-payment', 'name' => 'sirsoft-payment', 'status' => 'inactive'],
                ],
                'message' => '이 템플릿을 활성화하려면 필요한 의존성이 충족되어야 합니다.',
            ]);
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/activate', [
            'template_name' => $template->identifier,
        ]);

        // Assert
        $response->assertStatus(409)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => [
                    'warning',
                    'missing_modules',
                    'missing_plugins',
                ],
            ])
            ->assertJsonFragment(['warning' => true]);

        // missing_modules 확인
        $this->assertNotEmpty($response->json('errors.missing_modules'));
        $this->assertEquals('sirsoft-ecommerce', $response->json('errors.missing_modules.0.identifier'));

        // missing_plugins 확인
        $this->assertNotEmpty($response->json('errors.missing_plugins'));
        $this->assertEquals('sirsoft-payment', $response->json('errors.missing_plugins.0.identifier'));
    }

    /**
     * 템플릿 활성화 시 force=true로 의존성 경고 무시하고 강제 활성화
     */
    public function test_activate_with_force_bypasses_dependency_check(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'identifier' => 'test-force-template',
            'status' => 'inactive',
        ]);

        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('activateTemplate')
            ->with($template->identifier, true)
            ->andReturnUsing(function () use ($template) {
                $template->update(['status' => 'active']);

                return ['success' => true];
            });
        $mock->shouldReceive('getTemplateInfo')->andReturn([
            'identifier' => $template->identifier,
            'vendor' => $template->vendor,
            'name' => $template->name,
            'version' => $template->version,
            'type' => $template->type,
            'status' => 'active',
        ]);
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/activate', [
            'template_name' => $template->identifier,
            'force' => true,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'active']);

        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'status' => 'active',
        ]);
    }

    /**
     * 템플릿 의존성 경고 응답에서 missing_modules의 형식 검증
     * dependencies.modules가 연관 배열 {"identifier": ">=version"} 형식일 때
     * identifier가 올바르게 파싱되는지 확인
     */
    public function test_activate_dependency_warning_parses_associative_array_format(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'identifier' => 'test-assoc-deps',
            'status' => 'inactive',
        ]);

        // 연관 배열 형식의 dependencies를 가진 템플릿 시뮬레이션
        // template.json: {"modules": {"sirsoft-board": ">=1.0.0", "sirsoft-ecommerce": ">=1.0.0"}}
        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('activateTemplate')
            ->with($template->identifier, false)
            ->andReturn([
                'success' => false,
                'warning' => true,
                'missing_modules' => [
                    ['identifier' => 'sirsoft-board', 'name' => 'sirsoft-board', 'status' => 'not_installed'],
                    ['identifier' => 'sirsoft-ecommerce', 'name' => 'sirsoft-ecommerce', 'status' => 'not_installed'],
                ],
                'missing_plugins' => [],
                'message' => '이 템플릿을 활성화하려면 필요한 의존성이 충족되어야 합니다.',
            ]);
        $this->app->instance(TemplateManagerInterface::class, $mock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/templates/activate', [
            'template_name' => $template->identifier,
        ]);

        // Assert
        $response->assertStatus(409);

        $missingModules = $response->json('errors.missing_modules');
        $this->assertCount(2, $missingModules);

        // identifier가 버전 문자열(>=1.0.0)이 아닌 실제 모듈 identifier인지 확인
        $identifiers = array_column($missingModules, 'identifier');
        $this->assertContains('sirsoft-board', $identifiers);
        $this->assertContains('sirsoft-ecommerce', $identifiers);
        $this->assertNotContains('>=1.0.0', $identifiers);
    }

    // ========================================================================
    // Changelog 조회 테스트
    // ========================================================================

    /**
     * CHANGELOG.md가 있는 템플릿의 changelog를 조회합니다.
     */
    public function test_changelog_returns_parsed_entries(): void
    {
        $templatePath = base_path('templates/test-changelog-tpl');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($templatePath);
        \Illuminate\Support\Facades\File::put($templatePath.'/CHANGELOG.md', "# Changelog\n\n## [0.1.1] - 2026-02-25\n\n### Changed\n- UI 개선\n");

        try {
            $response = $this->authRequest()->getJson('/api/admin/templates/test-changelog-tpl/changelog');

            $response->assertStatus(200)
                ->assertJsonPath('data.changelog.0.version', '0.1.1')
                ->assertJsonPath('data.changelog.0.categories.0.name', 'Changed');
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($templatePath);
        }
    }

    /**
     * CHANGELOG.md가 없는 템플릿은 빈 배열을 반환합니다.
     */
    public function test_changelog_returns_empty_for_missing_file(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/templates/nonexistent-template/changelog');

        $response->assertStatus(200)
            ->assertJsonPath('data.changelog', []);
    }

    /**
     * 인증 없이 changelog 조회 시 401 반환
     */
    public function test_changelog_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/templates/test-template/changelog');
        $response->assertStatus(401);
    }

    // ========================================================================
    // 식별자 형식 검증 테스트
    // ========================================================================

    /**
     * 잘못된 형식의 템플릿 식별자로 설치 요청 시 422 반환
     */
    public function test_install_returns_422_for_invalid_identifier_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/templates/install', [
            'template_name' => 'sirsofttemplate', // 하이픈 없음
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template_name']);
    }

    /**
     * 숫자로 시작하는 단어가 포함된 템플릿 식별자로 설치 요청 시 422 반환
     */
    public function test_install_returns_422_for_digit_starting_identifier(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/templates/install', [
            'template_name' => 'sirsoft-2admin',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template_name']);
    }

    /**
     * 특수문자가 포함된 템플릿 식별자로 설치 요청 시 422 반환
     */
    public function test_install_returns_422_for_special_char_identifier(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/templates/install', [
            'template_name' => 'sirsoft-admin@basic',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template_name']);
    }
}
