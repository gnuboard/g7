<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AdminModuleController 테스트
 *
 * 모듈 관리 API 엔드포인트를 테스트합니다.
 */
class ModuleControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 역할 생성 및 할당
     *
     * 중요: AdminMiddleware가 isAdmin()을 체크하고, isAdmin()은 hasRole('admin')을 확인합니다.
     * 따라서 역할 identifier는 반드시 'admin'이어야 합니다.
     *
     * @param  array  $permissions  사용자에게 부여할 권한 식별자 목록
     */
    private function createAdminUser(array $permissions = ['core.modules.read', 'core.modules.install', 'core.modules.activate', 'core.modules.uninstall']): User
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
                    'type' => \App\Enums\PermissionType::Admin,
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

    // ========================================================================
    // 모듈 목록 조회 테스트 (index)
    // ========================================================================

    /**
     * 인증 없이 모듈 목록 조회 시 401 반환
     */
    public function test_index_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/modules');

        $response->assertStatus(401);
    }

    /**
     * 모듈 목록 조회 성공
     */
    public function test_index_returns_modules_list(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/modules');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data',
                    'pagination' => [
                        'total',
                        'current_page',
                        'per_page',
                    ],
                ],
            ]);
    }

    /**
     * 페이지네이션 파라미터 테스트
     */
    public function test_index_supports_pagination(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/modules?page=1&per_page=5');

        $response->assertStatus(200)
            ->assertJsonPath('data.pagination.per_page', 5)
            ->assertJsonPath('data.pagination.current_page', 1);
    }

    /**
     * with[] 파라미터로 custom_menus 조회 테스트
     */
    public function test_index_returns_custom_menus_with_param(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/modules?with[]=custom_menus');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data',
                    'custom_menus',
                ],
            ]);
    }

    /**
     * with[] 파라미터 없이 조회 시 custom_menus가 없음
     */
    public function test_index_does_not_return_custom_menus_without_param(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/modules');

        $response->assertStatus(200)
            ->assertJsonMissing(['custom_menus']);
    }

    /**
     * 유효하지 않은 with[] 파라미터 시 422 반환
     */
    public function test_index_returns_422_for_invalid_with_param(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/modules?with[]=invalid_option');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['with.0']);
    }

    // ========================================================================
    // core.menus.read 권한으로 모듈 목록 접근 테스트
    // ========================================================================

    /**
     * core.menus.read 권한만 가진 사용자가 모듈 목록 조회 시 200 반환
     */
    public function test_index_returns_200_with_menus_read_permission(): void
    {
        $menuUser = $this->createAdminUser(['core.menus.read']);
        $token = $menuUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/modules');

        $response->assertStatus(200)
            ->assertJsonPath('data.data', [])
            ->assertJsonPath('data.pagination.total', 0);
    }

    /**
     * core.menus.read 권한만 가진 사용자가 custom_menus 조회 시 데이터 반환
     */
    public function test_index_returns_custom_menus_with_menus_read_permission(): void
    {
        $menuUser = $this->createAdminUser(['core.menus.read']);
        $token = $menuUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/modules?with[]=custom_menus');

        $response->assertStatus(200)
            ->assertJsonPath('data.data', [])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data',
                    'pagination',
                    'custom_menus',
                ],
            ]);
    }

    /**
     * core.menus.read도 core.modules.read도 없는 사용자는 403 반환
     */
    public function test_index_returns_403_without_any_relevant_permission(): void
    {
        $noPermUser = $this->createAdminUser([]);
        $token = $noPermUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/modules');

        $response->assertStatus(403);
    }

    // ========================================================================
    // 모듈 검색 필터 테스트
    // ========================================================================

    /**
     * 단일 검색어로 필터링 테스트
     */
    public function test_index_filters_by_search(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/modules?search=sample');

        $response->assertStatus(200);
    }

    /**
     * filters 파라미터로 다중 검색 조건 테스트
     */
    public function test_index_filters_with_multiple_conditions(): void
    {
        $filters = [
            [
                'field' => 'name',
                'operator' => 'like',
                'value' => 'sample',
            ],
        ];

        $response = $this->authRequest()->getJson('/api/admin/modules?'.http_build_query(['filters' => $filters]));

        $response->assertStatus(200);
    }

    /**
     * status 필터링 테스트
     *
     * status는 별도의 top-level 파라미터로 처리됨
     */
    public function test_index_filters_by_status(): void
    {
        // status는 filters가 아닌 별도의 파라미터
        $response = $this->authRequest()->getJson('/api/admin/modules?status=active');

        $response->assertStatus(200);
    }

    /**
     * 전체 권한 유저의 index 응답에 abilities 포함 확인
     */
    public function test_index_returns_abilities_for_full_permission_user(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/modules');

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
        $readOnlyUser = $this->createAdminUser(['core.modules.read']);
        $readOnlyToken = $readOnlyUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readOnlyToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/modules');

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_install', false)
            ->assertJsonPath('data.abilities.can_activate', false)
            ->assertJsonPath('data.abilities.can_uninstall', false);
    }

    /**
     * 부분 권한 유저의 index 응답에 abilities 부분 확인
     */
    public function test_index_returns_partial_abilities(): void
    {
        $partialUser = $this->createAdminUser(['core.modules.read', 'core.modules.install']);
        $partialToken = $partialUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$partialToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/modules');

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_install', true)
            ->assertJsonPath('data.abilities.can_activate', false)
            ->assertJsonPath('data.abilities.can_uninstall', false);
    }

    // ========================================================================
    // 파일 업로드 설치 테스트 (installFromFile)
    // ========================================================================

    /**
     * 인증 없이 파일 업로드 설치 시 401 반환
     */
    public function test_install_from_file_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/modules/install-from-file', []);

        $response->assertStatus(401);
    }

    /**
     * 파일 없이 업로드 설치 요청 시 422 반환
     */
    public function test_install_from_file_returns_422_without_file(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-file', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 잘못된 파일 형식으로 업로드 시 422 반환
     */
    public function test_install_from_file_returns_422_for_invalid_file_type(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 파일 크기 초과 시 422 반환
     */
    public function test_install_from_file_returns_422_for_oversized_file(): void
    {
        Storage::fake('local');

        // 설정된 최대 크기보다 큰 파일 생성 (기본 50MB)
        $maxSize = config('module.upload_max_size', 50);
        $oversizedKb = ($maxSize * 1024) + 100; // 100KB 초과

        $file = UploadedFile::fake()->create('module.zip', $oversizedKb);

        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    // ========================================================================
    // GitHub 설치 테스트 (installFromGithub)
    // ========================================================================

    /**
     * 인증 없이 GitHub 설치 시 401 반환
     */
    public function test_install_from_github_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/modules/install-from-github', []);

        $response->assertStatus(401);
    }

    /**
     * GitHub URL 없이 설치 요청 시 422 반환
     */
    public function test_install_from_github_returns_422_without_url(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-github', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['github_url']);
    }

    /**
     * 잘못된 GitHub URL 형식으로 요청 시 422 반환
     */
    public function test_install_from_github_returns_422_for_invalid_url(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-github', [
            'github_url' => 'not-a-valid-url',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['github_url']);
    }

    /**
     * GitHub이 아닌 URL로 요청 시 422 반환
     */
    public function test_install_from_github_returns_422_for_non_github_url(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-github', [
            'github_url' => 'https://gitlab.com/vendor/module',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['github_url']);
    }

    /**
     * 유효한 GitHub URL 형식 테스트
     */
    public function test_install_from_github_accepts_valid_github_url(): void
    {
        // 실제 설치는 외부 의존성이 필요하므로 URL 형식 검증만 테스트
        // 실제 설치 로직은 mock이나 통합 테스트에서 수행
        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-github', [
            'github_url' => 'https://github.com/sirsoft/sample-module',
        ]);

        // URL 형식 검증 통과 확인: 422가 아니거나 github_url 관련 validation 에러가 없어야 함
        // 실제 설치 과정에서 500 에러가 발생할 수 있음 (저장소 미존재 등)
        if ($response->status() === 422) {
            // 422인 경우 github_url 검증 에러가 아닌지 확인
            $response->assertJsonMissingValidationErrors(['github_url']);
        } else {
            // 422가 아니면 URL 형식은 통과한 것
            $this->assertContains($response->status(), [200, 201, 500]);
        }
    }

    // ========================================================================
    // 파일 업로드 설치 - Service Mock 테스트
    // ========================================================================

    /**
     * 유효한 ZIP 파일로 모듈 설치 성공 (Service Mock)
     */
    public function test_install_from_file_succeeds_with_valid_zip(): void
    {
        $moduleServiceMock = \Mockery::mock(\App\Services\ModuleService::class);
        $moduleServiceMock->shouldReceive('installFromZipFile')
            ->once()
            ->andReturn([
                'identifier' => 'sirsoft-testmodule',
                'name' => 'Test Module',
                'version' => '1.0.0',
            ]);
        $this->app->instance(\App\Services\ModuleService::class, $moduleServiceMock);

        $file = UploadedFile::fake()->create('module.zip', 100, 'application/zip');

        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.identifier', 'sirsoft-testmodule');
    }

    /**
     * ModuleService에서 RuntimeException 발생 시 422 반환
     */
    public function test_install_from_file_returns_422_on_runtime_exception(): void
    {
        $moduleServiceMock = \Mockery::mock(\App\Services\ModuleService::class);
        $moduleServiceMock->shouldReceive('installFromZipFile')
            ->once()
            ->andThrow(new \RuntimeException('module.json을 찾을 수 없습니다.'));
        $this->app->instance(\App\Services\ModuleService::class, $moduleServiceMock);

        $file = UploadedFile::fake()->create('module.zip', 100, 'application/zip');

        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'module.json을 찾을 수 없습니다.');
    }

    /**
     * ModuleService에서 일반 Exception 발생 시 500 반환
     */
    public function test_install_from_file_returns_500_on_general_exception(): void
    {
        $moduleServiceMock = \Mockery::mock(\App\Services\ModuleService::class);
        $moduleServiceMock->shouldReceive('installFromZipFile')
            ->once()
            ->andThrow(new \Exception('예상치 못한 오류'));
        $this->app->instance(\App\Services\ModuleService::class, $moduleServiceMock);

        $file = UploadedFile::fake()->create('module.zip', 100, 'application/zip');

        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(500);
    }

    // ========================================================================
    // GitHub 설치 - Service Mock 테스트
    // ========================================================================

    /**
     * 유효한 GitHub URL로 모듈 설치 성공 (Service Mock)
     */
    public function test_install_from_github_calls_service_with_valid_url(): void
    {
        $moduleServiceMock = \Mockery::mock(\App\Services\ModuleService::class);
        $moduleServiceMock->shouldReceive('installFromGithub')
            ->once()
            ->with('https://github.com/sirsoft/sample-module')
            ->andReturn([
                'identifier' => 'sirsoft-samplemodule',
                'name' => 'Sample Module',
                'version' => '1.0.0',
            ]);
        $this->app->instance(\App\Services\ModuleService::class, $moduleServiceMock);

        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-github', [
            'github_url' => 'https://github.com/sirsoft/sample-module',
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.identifier', 'sirsoft-samplemodule');
    }

    /**
     * ModuleService에서 RuntimeException 발생 시 422 반환 (GitHub)
     */
    public function test_install_from_github_returns_422_on_runtime_exception(): void
    {
        $moduleServiceMock = \Mockery::mock(\App\Services\ModuleService::class);
        $moduleServiceMock->shouldReceive('installFromGithub')
            ->once()
            ->andThrow(new \RuntimeException('GitHub 저장소를 찾을 수 없습니다.'));
        $this->app->instance(\App\Services\ModuleService::class, $moduleServiceMock);

        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-github', [
            'github_url' => 'https://github.com/sirsoft/sample-module',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'GitHub 저장소를 찾을 수 없습니다.');
    }

    /**
     * ModuleService에서 일반 Exception 발생 시 500 반환 (GitHub)
     */
    public function test_install_from_github_returns_500_on_general_exception(): void
    {
        $moduleServiceMock = \Mockery::mock(\App\Services\ModuleService::class);
        $moduleServiceMock->shouldReceive('installFromGithub')
            ->once()
            ->andThrow(new \Exception('예상치 못한 오류'));
        $this->app->instance(\App\Services\ModuleService::class, $moduleServiceMock);

        $response = $this->authRequest()->postJson('/api/admin/modules/install-from-github', [
            'github_url' => 'https://github.com/sirsoft/sample-module',
        ]);

        $response->assertStatus(500);
    }

    // ========================================================================
    // 권한 테스트
    // ========================================================================

    /**
     * create 권한 없는 관리자가 모듈 설치 시도 시 403 반환
     *
     * 이 테스트는 setUp에서 생성된 admin 역할에 core.modules.create 권한이 이미 연결되어 있으므로,
     * admin 역할에서 create 권한을 분리하여 테스트합니다.
     */
    public function test_install_returns_403_without_create_permission(): void
    {
        // 새 사용자 생성
        $user = User::factory()->create();

        // admin 역할 가져오기 (setUp에서 이미 생성됨)
        $adminRole = Role::where('identifier', 'admin')->first();

        // admin 역할에서 create 권한 분리 (테스트용)
        $createPermission = Permission::where('identifier', 'core.modules.create')->first();
        if ($adminRole && $createPermission) {
            $adminRole->permissions()->detach($createPermission->id);
        }

        // 사용자에게 admin 역할 할당
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        Storage::fake('local');
        // 유효한 ZIP 파일 생성 (mimes:zip validation 통과)
        $file = UploadedFile::fake()->create('module.zip', 100, 'application/zip');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/modules/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(403);
    }

    // ========================================================================
    // 활성화 의존성 체크 테스트
    // ========================================================================

    /**
     * 모듈 활성화 시 필요한 의존성이 충족되지 않으면 409 경고 응답
     */
    public function test_activate_returns_409_when_dependencies_not_met(): void
    {
        // Arrange: ModuleService를 Mock
        $moduleServiceMock = \Mockery::mock(\App\Services\ModuleService::class);
        $moduleServiceMock->shouldReceive('activateModule')
            ->with('test-module', false)
            ->andReturn([
                'success' => false,
                'warning' => true,
                'missing_modules' => [
                    ['identifier' => 'sirsoft-core', 'name' => 'Core Module', 'status' => 'inactive'],
                ],
                'missing_plugins' => [
                    ['identifier' => 'sirsoft-payment', 'name' => 'Payment Plugin', 'status' => 'not_installed'],
                ],
                'message' => '이 모듈을 활성화하려면 필요한 의존성이 충족되어야 합니다.',
            ]);
        $this->app->instance(\App\Services\ModuleService::class, $moduleServiceMock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/modules/activate', [
            'module_name' => 'test-module',
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
        $this->assertEquals('sirsoft-core', $response->json('errors.missing_modules.0.identifier'));

        // missing_plugins 확인
        $this->assertNotEmpty($response->json('errors.missing_plugins'));
        $this->assertEquals('sirsoft-payment', $response->json('errors.missing_plugins.0.identifier'));
    }

    /**
     * 모듈 활성화 시 force=true로 의존성 경고 무시하고 강제 활성화
     */
    public function test_activate_with_force_bypasses_dependency_check(): void
    {
        // Arrange: ModuleService를 Mock
        $moduleServiceMock = \Mockery::mock(\App\Services\ModuleService::class);
        $moduleServiceMock->shouldReceive('activateModule')
            ->with('test-module', true)
            ->andReturn([
                'success' => true,
                'module_info' => [
                    'identifier' => 'test-module',
                    'name' => 'Test Module',
                    'status' => 'active',
                ],
            ]);
        $this->app->instance(\App\Services\ModuleService::class, $moduleServiceMock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/modules/activate', [
            'module_name' => 'test-module',
            'force' => true,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);
    }

    /**
     * 모듈 비활성화 시 의존하는 확장이 있으면 409 경고 응답
     */
    public function test_deactivate_returns_409_when_dependents_exist(): void
    {
        // Arrange: ModuleService를 Mock
        $moduleServiceMock = \Mockery::mock(\App\Services\ModuleService::class);
        $moduleServiceMock->shouldReceive('deactivateModule')
            ->with('test-module', false)
            ->andReturn([
                'success' => false,
                'warning' => true,
                'dependent_templates' => ['sirsoft-admin_basic'],
                'dependent_modules' => [
                    ['identifier' => 'sirsoft-ecommerce', 'name' => 'Ecommerce Module'],
                ],
                'dependent_plugins' => [],
                'message' => '이 모듈에 의존하는 활성화된 확장이 있습니다.',
            ]);
        $this->app->instance(\App\Services\ModuleService::class, $moduleServiceMock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/modules/deactivate', [
            'module_name' => 'test-module',
        ]);

        // Assert
        $response->assertStatus(409)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => [
                    'warning',
                    'dependent_templates',
                    'dependent_modules',
                ],
            ])
            ->assertJsonFragment(['warning' => true]);
    }

    /**
     * 모듈 비활성화 시 force=true로 의존성 경고 무시하고 강제 비활성화
     */
    public function test_deactivate_with_force_bypasses_dependent_check(): void
    {
        // Arrange: ModuleService를 Mock
        $moduleServiceMock = \Mockery::mock(\App\Services\ModuleService::class);
        $moduleServiceMock->shouldReceive('deactivateModule')
            ->with('test-module', true)
            ->andReturn([
                'success' => true,
                'module_info' => [
                    'identifier' => 'test-module',
                    'name' => 'Test Module',
                    'status' => 'inactive',
                ],
            ]);
        $this->app->instance(\App\Services\ModuleService::class, $moduleServiceMock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/modules/deactivate', [
            'module_name' => 'test-module',
            'force' => true,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);
    }

    // ========================================================================
    // 에셋 정보 포함 테스트
    // ========================================================================

    /**
     * 모듈 활성화 응답에 assets 정보가 포함됨
     */
    public function test_activate_response_includes_assets_when_module_has_assets(): void
    {
        // Arrange: ModuleService를 Mock
        $moduleServiceMock = \Mockery::mock(\App\Services\ModuleService::class);
        $moduleServiceMock->shouldReceive('activateModule')
            ->with('test-module', false)
            ->andReturn([
                'success' => true,
                'module_info' => [
                    'identifier' => 'test-module',
                    'name' => 'Test Module',
                    'status' => 'active',
                    'assets' => [
                        'js' => '/api/modules/assets/test-module/dist/js/module.iife.js',
                        'css' => '/api/modules/assets/test-module/dist/css/module.css',
                        'priority' => 100,
                    ],
                ],
            ]);
        $this->app->instance(\App\Services\ModuleService::class, $moduleServiceMock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/modules/activate', [
            'module_name' => 'test-module',
        ]);

        // Assert
        // 활성화 응답은 data.module 으로 ModuleResource 를 래핑하고, pending_language_packs 를 동시에 노출
        $response->assertStatus(200)
            ->assertJsonPath('data.module.assets.js', '/api/modules/assets/test-module/dist/js/module.iife.js')
            ->assertJsonPath('data.module.assets.css', '/api/modules/assets/test-module/dist/css/module.css')
            ->assertJsonPath('data.module.assets.priority', 100);
    }

    /**
     * 모듈 비활성화 응답에 assets 정보가 포함됨
     */
    public function test_deactivate_response_includes_assets_when_module_has_assets(): void
    {
        // Arrange: ModuleService를 Mock
        $moduleServiceMock = \Mockery::mock(\App\Services\ModuleService::class);
        $moduleServiceMock->shouldReceive('deactivateModule')
            ->with('test-module', false)
            ->andReturn([
                'success' => true,
                'module_info' => [
                    'identifier' => 'test-module',
                    'name' => 'Test Module',
                    'status' => 'inactive',
                    'assets' => [
                        'js' => '/api/modules/assets/test-module/dist/js/module.iife.js',
                        'css' => '/api/modules/assets/test-module/dist/css/module.css',
                        'priority' => 100,
                    ],
                ],
            ]);
        $this->app->instance(\App\Services\ModuleService::class, $moduleServiceMock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/modules/deactivate', [
            'module_name' => 'test-module',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonPath('data.assets.js', '/api/modules/assets/test-module/dist/js/module.iife.js')
            ->assertJsonPath('data.assets.css', '/api/modules/assets/test-module/dist/css/module.css')
            ->assertJsonPath('data.assets.priority', 100);
    }

    // ========================================================================
    // Changelog 조회 테스트
    // ========================================================================

    /**
     * CHANGELOG.md가 있는 모듈의 changelog를 조회합니다.
     */
    public function test_changelog_returns_parsed_entries(): void
    {
        // Arrange: 테스트용 CHANGELOG.md 생성
        $modulePath = base_path('modules/test-changelog-mod');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($modulePath);
        \Illuminate\Support\Facades\File::put($modulePath.'/CHANGELOG.md', "# Changelog\n\n## [0.1.1] - 2026-02-25\n\n### Added\n- 새 기능\n\n## [0.1.0] - 2026-02-20\n\n### Added\n- 초기 릴리스\n");

        try {
            $response = $this->authRequest()->getJson('/api/admin/modules/test-changelog-mod/changelog');

            $response->assertStatus(200)
                ->assertJsonPath('data.changelog.0.version', '0.1.1')
                ->assertJsonPath('data.changelog.0.categories.0.name', 'Added')
                ->assertJsonPath('data.changelog.1.version', '0.1.0');
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($modulePath);
        }
    }

    /**
     * CHANGELOG.md가 없는 모듈은 빈 배열을 반환합니다.
     */
    public function test_changelog_returns_empty_for_missing_file(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/modules/nonexistent-module/changelog');

        $response->assertStatus(200)
            ->assertJsonPath('data.changelog', []);
    }

    /**
     * 버전 범위 필터링이 동작합니다.
     */
    public function test_changelog_with_version_range(): void
    {
        $modulePath = base_path('modules/test-changelog-range');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($modulePath);
        \Illuminate\Support\Facades\File::put($modulePath.'/CHANGELOG.md', "# Changelog\n\n## [0.1.2] - 2026-02-28\n\n### Added\n- 기능 C\n\n## [0.1.1] - 2026-02-25\n\n### Added\n- 기능 B\n\n## [0.1.0] - 2026-02-20\n\n### Added\n- 초기 릴리스\n");

        try {
            $response = $this->authRequest()->getJson('/api/admin/modules/test-changelog-range/changelog?from_version=0.1.0&to_version=0.1.2');

            $response->assertStatus(200);
            $changelog = $response->json('data.changelog');
            $this->assertCount(2, $changelog);
            $this->assertSame('0.1.2', $changelog[0]['version']);
            $this->assertSame('0.1.1', $changelog[1]['version']);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($modulePath);
        }
    }

    /**
     * 인증 없이 changelog 조회 시 401 반환
     */
    public function test_changelog_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/modules/test-module/changelog');
        $response->assertStatus(401);
    }

    // ========================================================================
    // 라이선스 테스트
    // ========================================================================

    /**
     * LICENSE 파일이 있는 모듈의 라이선스를 반환합니다.
     */
    public function test_license_returns_content(): void
    {
        $modulePath = base_path('modules/test-license-mod');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($modulePath);
        \Illuminate\Support\Facades\File::put($modulePath.'/LICENSE', 'MIT License - Test');

        try {
            $response = $this->authRequest()->getJson('/api/admin/modules/test-license-mod/license');

            $response->assertStatus(200)
                ->assertJsonPath('data.content', 'MIT License - Test');
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($modulePath);
        }
    }

    /**
     * LICENSE 파일이 없는 모듈은 404를 반환합니다.
     */
    public function test_license_returns_404_for_missing_file(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/modules/nonexistent-module/license');

        $response->assertStatus(404);
    }

    /**
     * _bundled 디렉토리의 LICENSE 파일도 반환합니다.
     */
    public function test_license_falls_back_to_bundled(): void
    {
        // sirsoft-board는 _bundled에 있으므로 활성 디렉토리에 없을 수 있지만 _bundled에서 찾음
        $bundledPath = base_path('modules/_bundled/sirsoft-board/LICENSE');
        if (file_exists($bundledPath)) {
            $response = $this->authRequest()->getJson('/api/admin/modules/sirsoft-board/license');
            $response->assertStatus(200)
                ->assertJsonStructure(['data' => ['content']]);
        } else {
            $this->markTestSkipped('sirsoft-board LICENSE not found in _bundled');
        }
    }

    // ========================================================================
    // 식별자 형식 검증 테스트
    // ========================================================================

    /**
     * 잘못된 형식의 모듈 식별자로 설치 요청 시 422 반환
     */
    public function test_install_returns_422_for_invalid_identifier_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/modules/install', [
            'module_name' => 'sirsoftboard', // 하이픈 없음
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['module_name']);
    }

    /**
     * 숫자로 시작하는 단어가 포함된 모듈 식별자로 설치 요청 시 422 반환
     */
    public function test_install_returns_422_for_digit_starting_identifier(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/modules/install', [
            'module_name' => 'sirsoft-2shop',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['module_name']);
    }

    /**
     * 대문자가 포함된 모듈 식별자로 설치 요청 시 422 반환
     */
    public function test_install_returns_422_for_uppercase_identifier(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/modules/install', [
            'module_name' => 'Sirsoft-Board',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['module_name']);
    }

    /**
     * 특수문자가 포함된 모듈 식별자로 설치 요청 시 422 반환
     */
    public function test_install_returns_422_for_special_char_identifier(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/modules/install', [
            'module_name' => 'sirsoft-my@module',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['module_name']);
    }
}
