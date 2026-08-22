<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CoreUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CoreUpdateController 테스트
 *
 * 코어 업데이트 확인 및 변경사항 조회 API 엔드포인트를 테스트합니다.
 */
class CoreUpdateControllerTest extends TestCase
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
     * @return User 생성된 관리자 사용자
     */
    private function createAdminUser(array $permissions = ['core.settings.update', 'core.settings.read']): User
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
                    'type' => PermissionType::Admin,
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
     *
     * @return static
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    // ========================================================================
    // 코어 업데이트 확인 테스트 (checkForUpdates)
    // ========================================================================

    /**
     * 코어 업데이트 확인 시 버전 정보를 반환합니다.
     */
    public function test_check_for_updates_returns_version_info(): void
    {
        // Arrange: CoreUpdateService를 Mock
        $this->mock(CoreUpdateService::class, function ($mock) {
            $mock->shouldReceive('checkForUpdates')
                ->once()
                ->andReturn([
                    'update_available' => true,
                    'current_version' => '1.0.0',
                    'latest_version' => '1.1.0',
                    'github_url' => 'https://github.com/sirsoft/g7',
                ]);
        });

        // Act
        $response = $this->authRequest()->postJson('/api/admin/core-update/check');

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'update_available',
                    'current_version',
                    'latest_version',
                    'github_url',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.current_version', '1.0.0')
            ->assertJsonPath('data.latest_version', '1.1.0');
    }

    /**
     * GitHub API 호출 실패 시 422 에러와 구체적인 에러 원인을 반환합니다.
     */
    public function test_check_for_updates_returns_error_when_github_check_fails(): void
    {
        // Arrange: CoreUpdateService를 Mock - check_failed 응답
        $this->mock(CoreUpdateService::class, function ($mock) {
            $mock->shouldReceive('checkForUpdates')
                ->once()
                ->andReturn([
                    'update_available' => false,
                    'current_version' => '1.0.0',
                    'latest_version' => '1.0.0',
                    'github_url' => 'https://github.com/gnuboard/g7',
                    'check_failed' => true,
                    'error' => '프라이빗 저장소입니다. GitHub 액세스 토큰을 설정해주세요.',
                ]);
        });

        // Act
        $response = $this->authRequest()->postJson('/api/admin/core-update/check');

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.reason', '프라이빗 저장소입니다. GitHub 액세스 토큰을 설정해주세요.')
            ->assertJsonPath('errors.current_version', '1.0.0')
            ->assertJsonPath('errors.github_url', 'https://github.com/gnuboard/g7');
    }

    /**
     * 인증 없이 코어 업데이트 확인 시 401을 반환합니다.
     */
    public function test_check_for_updates_requires_authentication(): void
    {
        $response = $this->postJson('/api/admin/core-update/check');

        $response->assertStatus(401);
    }

    /**
     * core.settings.update 권한 없이 코어 업데이트 확인 시 403을 반환합니다.
     */
    public function test_check_for_updates_requires_permission(): void
    {
        // core.settings.read 권한만 부여 (core.settings.update 권한 없음)
        $userWithoutPermission = $this->createAdminUser(['core.settings.read']);
        $token = $userWithoutPermission->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/core-update/check');

        $response->assertStatus(403);
    }

    // ========================================================================
    // 변경사항 조회 테스트 (changelog)
    // ========================================================================

    /**
     * 변경사항 조회 시 파싱된 데이터를 반환합니다.
     */
    public function test_changelog_returns_parsed_data(): void
    {
        // Arrange: CoreUpdateService를 Mock
        $this->mock(CoreUpdateService::class, function ($mock) {
            $mock->shouldReceive('getChangelog')
                ->once()
                ->with(null, null)
                ->andReturn([
                    [
                        'version' => '1.1.0',
                        'date' => '2026-03-01',
                        'categories' => [
                            ['name' => 'Added', 'items' => ['새 기능 추가']],
                        ],
                    ],
                    [
                        'version' => '1.0.0',
                        'date' => '2026-02-01',
                        'categories' => [
                            ['name' => 'Added', 'items' => ['초기 릴리스']],
                        ],
                    ],
                ]);
        });

        // Act
        $response = $this->authRequest()->getJson('/api/admin/core-update/changelog');

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'changelog' => [
                        '*' => [
                            'version',
                            'date',
                            'categories',
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.changelog.0.version', '1.1.0')
            ->assertJsonPath('data.changelog.1.version', '1.0.0');
    }

    /**
     * 버전 범위를 지정하여 변경사항을 조회합니다.
     */
    public function test_changelog_with_version_range(): void
    {
        // Arrange: CoreUpdateService를 Mock
        $this->mock(CoreUpdateService::class, function ($mock) {
            $mock->shouldReceive('getChangelog')
                ->once()
                ->with('1.0.0', '1.2.0')
                ->andReturn([
                    [
                        'version' => '1.2.0',
                        'date' => '2026-03-15',
                        'categories' => [
                            ['name' => 'Added', 'items' => ['기능 C']],
                        ],
                    ],
                    [
                        'version' => '1.1.0',
                        'date' => '2026-03-01',
                        'categories' => [
                            ['name' => 'Added', 'items' => ['기능 B']],
                        ],
                    ],
                ]);
        });

        // Act
        $response = $this->authRequest()->getJson('/api/admin/core-update/changelog?from_version=1.0.0&to_version=1.2.0');

        // Assert
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.changelog.0.version', '1.2.0')
            ->assertJsonPath('data.changelog.1.version', '1.1.0');

        $changelog = $response->json('data.changelog');
        $this->assertCount(2, $changelog);
    }

    /**
     * 인증 없이 변경사항 조회 시 401을 반환합니다.
     */
    public function test_changelog_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/core-update/changelog');

        $response->assertStatus(401);
    }
}
