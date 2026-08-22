<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin UserController 유효성 검증 테스트
 *
 * 관리자 사용자 생성/수정 시 mobile, phone 정규식 검증을 테스트합니다.
 */
class UserControllerValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    private Role $adminRole;

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
        $user = User::factory()->create();

        // 관리자 권한 생성
        $permissions = [];
        foreach (['core.users.read', 'core.users.create', 'core.users.update'] as $identifier) {
            $permissions[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                    'type' => \App\Enums\PermissionType::Admin,
                ]
            );
        }

        // 관리자 역할 생성
        $this->adminRole = $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
                'is_active' => true,
            ]
        );

        foreach ($permissions as $permission) {
            if (! $adminRole->permissions()->where('permissions.id', $permission->id)->exists()) {
                $adminRole->permissions()->attach($permission->id);
            }
        }

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
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    // ========================================================================
    // 사용자 생성 시 mobile/phone 정규식 검증 (store)
    // ========================================================================

    /**
     * 사용자 생성 시 유효한 mobile 번호 허용
     */
    public function test_store_accepts_valid_mobile_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/users', [
            'name' => '테스트 사용자',
            'email' => 'valid-mobile@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'mobile' => '010-1234-5678',
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(201);
    }

    /**
     * 사용자 생성 시 잘못된 mobile 형식 거부
     */
    public function test_store_rejects_invalid_mobile_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/users', [
            'name' => '테스트 사용자',
            'email' => 'invalid-mobile@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'mobile' => 'abc-invalid-phone',
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mobile']);
    }

    /**
     * 사용자 생성 시 잘못된 phone 형식 거부
     */
    public function test_store_rejects_invalid_phone_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/users', [
            'name' => '테스트 사용자',
            'email' => 'invalid-phone@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone' => 'abc-invalid-phone',
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    // ========================================================================
    // 사용자 수정 시 mobile/phone 정규식 검증 (update)
    // ========================================================================

    /**
     * 사용자 수정 시 유효한 mobile 번호 허용
     */
    public function test_update_accepts_valid_mobile_format(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->authRequest()->putJson("/api/admin/users/{$targetUser->uuid}", [
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'mobile' => '+82-10-1234-5678',
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(200);
    }

    /**
     * 사용자 수정 시 잘못된 mobile 형식 거부
     */
    public function test_update_rejects_invalid_mobile_format(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->authRequest()->putJson("/api/admin/users/{$targetUser->uuid}", [
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'mobile' => 'not-a-phone-number!',
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mobile']);
    }

    /**
     * 사용자 수정 시 잘못된 phone 형식 거부
     */
    public function test_update_rejects_invalid_phone_format(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->authRequest()->putJson("/api/admin/users/{$targetUser->uuid}", [
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'phone' => 'not-a-phone-number!',
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    /**
     * 사용자 수정 시 유효한 phone 번호 허용
     */
    public function test_update_accepts_valid_phone_format(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->authRequest()->putJson("/api/admin/users/{$targetUser->uuid}", [
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'phone' => '02-123-4567',
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(200);
    }

    // ========================================================================
    // 사용자 생성/수정 시 timezone 검증
    // ========================================================================

    /**
     * 사용자 생성 시 유효한 timezone 허용
     */
    public function test_store_accepts_valid_timezone(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/users', [
            'name' => '테스트 사용자',
            'email' => 'valid-tz@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'Asia/Seoul',
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(201);
    }

    /**
     * 사용자 생성 시 잘못된 timezone 거부
     */
    public function test_store_rejects_invalid_timezone(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/users', [
            'name' => '테스트 사용자',
            'email' => 'invalid-tz@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'Invalid/Timezone',
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['timezone']);
    }

    /**
     * 사용자 수정 시 유효한 timezone 허용
     */
    public function test_update_accepts_valid_timezone(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->authRequest()->putJson("/api/admin/users/{$targetUser->uuid}", [
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'timezone' => 'America/New_York',
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(200);
    }

    /**
     * 사용자 수정 시 잘못된 timezone 거부
     */
    public function test_update_rejects_invalid_timezone(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->authRequest()->putJson("/api/admin/users/{$targetUser->uuid}", [
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'timezone' => 'Mars/Olympus',
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['timezone']);
    }

    // ========================================================================
    // 사용자 생성/수정 시 역할 필수 검증 (roles required)
    // ========================================================================

    /**
     * 사용자 생성 시 역할 미지정 거부
     */
    public function test_store_rejects_without_roles(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/users', [
            'name' => '테스트 사용자',
            'email' => 'no-role@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['roles', 'role_ids']);
    }

    /**
     * 사용자 생성 시 빈 역할 배열 거부
     */
    public function test_store_rejects_empty_role_ids(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/users', [
            'name' => '테스트 사용자',
            'email' => 'empty-role@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_ids' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role_ids']);
    }

    /**
     * 사용자 수정 시 역할 미지정 거부
     */
    public function test_update_rejects_without_roles(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->authRequest()->putJson("/api/admin/users/{$targetUser->uuid}", [
            'name' => $targetUser->name,
            'email' => $targetUser->email,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['roles', 'role_ids']);
    }

    /**
     * 사용자 수정 시 roles(빈 배열)와 role_ids가 동시 전송되어도 정상 처리
     *
     * API 응답에 roles: []이 포함된 상태에서 사용자가 role_ids를 선택하면
     * 두 필드가 동시에 전송됩니다. prepareForValidation()에서 role_ids 우선 처리.
     */
    public function test_update_accepts_role_ids_when_empty_roles_also_sent(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->authRequest()->putJson("/api/admin/users/{$targetUser->uuid}", [
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'roles' => [],
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(200);
    }

    /**
     * 사용자 생성 시 roles(빈 배열)와 role_ids가 동시 전송되어도 정상 처리
     */
    public function test_store_accepts_role_ids_when_empty_roles_also_sent(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/users', [
            'name' => '테스트 사용자',
            'email' => 'roles-conflict@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [],
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(201);
    }

    /**
     * 사용자 수정 시 null timezone 허용 (nullable)
     */
    public function test_update_accepts_null_timezone(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->authRequest()->putJson("/api/admin/users/{$targetUser->uuid}", [
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'timezone' => null,
            'role_ids' => [$this->adminRole->id],
        ]);

        $response->assertStatus(200);
    }
}
