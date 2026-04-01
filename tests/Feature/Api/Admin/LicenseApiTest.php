<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 코어 라이선스 및 Changelog API 테스트
 *
 * GET /api/admin/license, GET /api/admin/changelog 엔드포인트를 테스트합니다.
 */
class LicenseApiTest extends TestCase
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
     * 관리자 사용자를 생성합니다.
     *
     * @return User 관리자 사용자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

        // admin 타입 권한 생성 (isAdmin() 통과용)
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.settings.read'],
            [
                'name' => json_encode(['ko' => '설정 조회', 'en' => 'Settings Read']),
                'description' => json_encode(['ko' => '설정 조회 권한', 'en' => 'Settings Read Permission']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );

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

        $adminRole->permissions()->syncWithoutDetaching([$permission->id]);

        $user->roles()->attach($adminRole->id, [
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

    /**
     * 코어 LICENSE 파일이 존재할 때 내용을 반환합니다.
     */
    public function test_license_returns_content(): void
    {
        // 루트 LICENSE 파일은 이미 존재함
        $this->assertFileExists(base_path('LICENSE'));

        $response = $this->authRequest()->getJson('/api/admin/license');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['content']])
            ->assertJsonPath('data.content', file_get_contents(base_path('LICENSE')));
    }

    /**
     * 인증 없이 접근 시 401을 반환합니다.
     */
    public function test_license_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/license');
        $response->assertStatus(401);
    }

    /**
     * 코어 CHANGELOG.md 파일이 존재할 때 내용을 반환합니다.
     */
    public function test_changelog_returns_content(): void
    {
        $this->assertFileExists(base_path('CHANGELOG.md'));

        $response = $this->authRequest()->getJson('/api/admin/changelog');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['content']])
            ->assertJsonPath('data.content', file_get_contents(base_path('CHANGELOG.md')));
    }

    /**
     * 인증 없이 changelog 접근 시 401을 반환합니다.
     */
    public function test_changelog_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/changelog');
        $response->assertStatus(401);
    }
}
