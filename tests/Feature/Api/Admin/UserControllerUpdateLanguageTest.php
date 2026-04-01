<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 사용자 언어 설정 업데이트 테스트
 */
class UserControllerUpdateLanguageTest extends TestCase
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
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create([
            'language' => 'ko',
        ]);

        // 관리자 권한 생성
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.read'],
            [
                'name' => json_encode(['ko' => '사용자 조회', 'en' => 'Read Users']),
                'description' => json_encode(['ko' => '사용자 목록 조회 권한', 'en' => 'Permission to read users']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                    'type' => 'admin',
            ]
        );

        // 관리자 역할 생성
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

        // 역할에 권한 할당
        if (! $adminRole->permissions()->where('permissions.id', $permission->id)->exists()) {
            $adminRole->permissions()->attach($permission->id);
        }

        // 사용자에게 역할 할당
        $user->roles()->attach($adminRole->id, [
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
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 언어 설정을 성공적으로 업데이트할 수 있다.
     */
    public function test_can_update_my_language_successfully(): void
    {
        $this->assertEquals('ko', $this->admin->language);

        $response = $this->authRequest()->patchJson('/api/admin/users/me/language', [
            'language' => 'en',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'id',
                'name',
                'email',
                'language',
            ],
        ]);

        $response->assertJson([
            'success' => true,
            'data' => [
                'language' => 'en',
            ],
        ]);

        // DB에서 실제로 변경되었는지 확인
        $this->admin->refresh();
        $this->assertEquals('en', $this->admin->language);
    }

    /**
     * 동일한 언어로 업데이트 시에도 성공 응답을 반환한다.
     */
    public function test_update_same_language_returns_success(): void
    {
        $this->assertEquals('ko', $this->admin->language);

        $response = $this->authRequest()->patchJson('/api/admin/users/me/language', [
            'language' => 'ko',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'language' => 'ko',
            ],
        ]);

        // DB에서 여전히 같은 값인지 확인
        $this->admin->refresh();
        $this->assertEquals('ko', $this->admin->language);
    }

    /**
     * 언어 필드가 누락되면 422 오류를 반환한다.
     */
    public function test_validation_fails_without_language_field(): void
    {
        $response = $this->authRequest()->patchJson('/api/admin/users/me/language', []);

        $response->assertStatus(422);
    }

    /**
     * 지원하지 않는 언어 코드로 요청하면 422 오류를 반환한다.
     */
    public function test_validation_fails_for_unsupported_language(): void
    {
        $response = $this->authRequest()->patchJson('/api/admin/users/me/language', [
            'language' => 'ja',
        ]);

        $response->assertStatus(422);
    }

    /**
     * 빈 문자열로 요청하면 422 오류를 반환한다.
     */
    public function test_validation_fails_for_empty_language(): void
    {
        $response = $this->authRequest()->patchJson('/api/admin/users/me/language', [
            'language' => '',
        ]);

        $response->assertStatus(422);
    }

    /**
     * 인증되지 않은 사용자는 401 오류를 반환한다.
     */
    public function test_unauthenticated_user_cannot_update_language(): void
    {
        $response = $this->patchJson('/api/admin/users/me/language', [
            'language' => 'en',
        ]);

        $response->assertStatus(401);
    }

    /**
     * 한국어에서 영어로 변경 후 다시 한국어로 변경할 수 있다.
     */
    public function test_can_switch_language_back_and_forth(): void
    {
        // ko -> en
        $response = $this->authRequest()->patchJson('/api/admin/users/me/language', [
            'language' => 'en',
        ]);
        $response->assertOk();
        $this->admin->refresh();
        $this->assertEquals('en', $this->admin->language);

        // en -> ko
        $response = $this->authRequest()->patchJson('/api/admin/users/me/language', [
            'language' => 'ko',
        ]);
        $response->assertOk();
        $this->admin->refresh();
        $this->assertEquals('ko', $this->admin->language);
    }

    /**
     * 숫자 타입으로 요청하면 422 오류를 반환한다.
     */
    public function test_validation_fails_for_non_string_language(): void
    {
        $response = $this->authRequest()->patchJson('/api/admin/users/me/language', [
            'language' => 123,
        ]);

        $response->assertStatus(422);
    }
}