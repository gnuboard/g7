<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\AttachmentSourceType;
use App\Models\Attachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ProfileController 테스트
 *
 * 사용자 프로필 관리 API 엔드포인트를 테스트합니다.
 */
class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUserWithPermissions();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    /**
     * 프로필 권한이 부여된 사용자 생성 헬퍼
     */
    private function createUserWithPermissions(array $permissions = [
        'core.profile.read',
        'core.profile.update',
    ]): User
    {
        $user = User::factory()->create([
            'name' => '테스트 사용자',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $permissionIds = [];
        foreach ($permissions as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'extension_type' => 'core',
                    'extension_identifier' => 'core',
                    'type' => 'user',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $testRole = Role::create([
            'identifier' => 'user_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 사용자', 'en' => 'Test User']),
            'is_active' => true,
        ]);

        $testRole->permissions()->sync($permissionIds);
        $user->roles()->attach($testRole->id, ['assigned_at' => now()]);

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
     * JSON 요청 헬퍼 메서드
     */
    private function jsonRequest(): static
    {
        return $this->withHeaders([
            'Accept' => 'application/json',
        ]);
    }

    // ========================================================================
    // 인증 테스트
    // ========================================================================

    /**
     * 인증 없이 프로필 조회 시 401 반환
     */
    public function test_show_returns_401_without_authentication(): void
    {
        $response = $this->jsonRequest()->getJson('/api/user/profile');

        $response->assertStatus(401);
    }

    /**
     * 인증 없이 프로필 수정 시 401 반환
     */
    public function test_update_returns_401_without_authentication(): void
    {
        $response = $this->jsonRequest()->putJson('/api/user/profile', [
            'name' => '새 이름',
            'email' => 'newemail@example.com',
        ]);

        $response->assertStatus(401);
    }

    // ========================================================================
    // 프로필 조회 테스트 (show)
    // ========================================================================

    /**
     * 프로필 조회 성공
     */
    public function test_show_returns_user_profile(): void
    {
        $response = $this->authRequest()->getJson('/api/user/profile');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 프로필 조회 시 필드 포함 확인
     */
    public function test_show_returns_profile_specific_fields(): void
    {
        $response = $this->authRequest()->getJson('/api/user/profile');

        $response->assertStatus(200)
            ->assertJsonPath('data.email', 'test@example.com')
            ->assertJsonPath('data.name', '테스트 사용자');
    }

    // ========================================================================
    // 프로필 수정 테스트 (update)
    // ========================================================================

    /**
     * 프로필 수정 성공
     */
    public function test_update_modifies_profile_successfully(): void
    {
        $response = $this->authRequest()->putJson('/api/user/profile', [
            'name' => '수정된 이름',
            'email' => 'test@example.com',
            'language' => 'ko',
            'country' => 'KR',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => '수정된 이름',
        ]);
    }

    /**
     * 이름 필수 검증
     */
    public function test_update_validates_name_required(): void
    {
        $response = $this->authRequest()->putJson('/api/user/profile', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * 이메일 고유성 검증 (본인 제외)
     */
    public function test_update_validates_email_unique_except_self(): void
    {
        // 다른 사용자 생성
        User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $response = $this->authRequest()->putJson('/api/user/profile', [
            'name' => '테스트 사용자',
            'email' => 'existing@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * 본인 이메일은 유지 가능
     */
    public function test_update_allows_keeping_own_email(): void
    {
        $response = $this->authRequest()->putJson('/api/user/profile', [
            'name' => '새 이름',
            'email' => 'test@example.com', // 본인 이메일
            'language' => 'ko',
            'country' => 'KR',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 비밀번호 변경 시 현재 비밀번호 필수
     */
    public function test_update_requires_current_password_for_password_change(): void
    {
        $response = $this->authRequest()->putJson('/api/user/profile', [
            'name' => '테스트 사용자',
            'email' => 'test@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    /**
     * 비밀번호 확인 검증
     */
    public function test_update_validates_password_confirmed(): void
    {
        $response = $this->authRequest()->putJson('/api/user/profile', [
            'name' => '테스트 사용자',
            'email' => 'test@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'differentpassword',
            'current_password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ========================================================================
    // 언어 설정 테스트 (updateLanguage)
    // ========================================================================

    /**
     * 언어 설정 변경 성공
     */
    public function test_update_language_changes_user_language(): void
    {
        $response = $this->authRequest()->postJson('/api/user/profile/update-language', [
            'language' => 'en',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'language' => 'en',
        ]);
    }

    /**
     * 지원하지 않는 언어 코드 검증
     */
    public function test_update_language_validates_supported_locales(): void
    {
        $response = $this->authRequest()->postJson('/api/user/profile/update-language', [
            'language' => 'invalid_language',
        ]);

        $response->assertStatus(400);
    }

    // ========================================================================
    // 활동 로그 테스트 (activityLog)
    // ========================================================================

    /**
     * 활동 로그 조회 (빈 배열 반환)
     */
    public function test_activity_log_returns_empty_array(): void
    {
        $response = $this->authRequest()->getJson('/api/user/profile/activity-log');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.activities', []);
    }

    // ========================================================================
    // /api/me 엔드포인트 테스트
    // ========================================================================

    /**
     * /api/me로 프로필 조회 성공
     */
    public function test_me_show_returns_user_profile(): void
    {
        $response = $this->authRequest()->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.email', 'test@example.com')
            ->assertJsonPath('data.name', '테스트 사용자');
    }

    /**
     * /api/me 인증 없이 조회 시 401 반환
     */
    public function test_me_show_returns_401_without_authentication(): void
    {
        $response = $this->jsonRequest()->getJson('/api/me');

        $response->assertStatus(401);
    }

    /**
     * /api/me로 프로필 수정 성공
     */
    public function test_me_update_modifies_profile_successfully(): void
    {
        $response = $this->authRequest()->putJson('/api/me', [
            'name' => '수정된 이름',
            'email' => 'test@example.com',
            'language' => 'ko',
            'country' => 'KR',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => '수정된 이름',
        ]);
    }

    /**
     * /api/me로 추가 필드 수정 성공 (mobile, phone, homepage 등)
     */
    public function test_me_update_modifies_additional_fields(): void
    {
        $response = $this->authRequest()->putJson('/api/me', [
            'name' => '테스트 사용자',
            'email' => 'test@example.com',
            'language' => 'ko',
            'country' => 'KR',
            'mobile' => '010-1234-5678',
            'phone' => '02-123-4567',
            'homepage' => 'https://example.com',
            'zipcode' => '12345',
            'address' => '서울시 강남구',
            'address_detail' => '테헤란로 123',
            'signature' => '테스트 서명입니다.',
            'bio' => '자기소개 내용입니다.',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'mobile' => '010-1234-5678',
            'phone' => '02-123-4567',
            'homepage' => 'https://example.com',
            'signature' => '테스트 서명입니다.',
            'bio' => '자기소개 내용입니다.',
        ]);
    }

    /**
     * mobile 필드 정규식 검증
     */
    public function test_me_update_validates_mobile_format(): void
    {
        $response = $this->authRequest()->putJson('/api/me', [
            'name' => '테스트 사용자',
            'email' => 'test@example.com',
            'mobile' => 'invalid-mobile-abc',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mobile']);
    }

    /**
     * phone 필드 정규식 검증
     */
    public function test_me_update_validates_phone_format(): void
    {
        $response = $this->authRequest()->putJson('/api/me', [
            'name' => '테스트 사용자',
            'email' => 'test@example.com',
            'phone' => 'invalid-phone-abc',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    // ========================================================================
    // 아바타 업로드/삭제 테스트
    // ========================================================================

    /**
     * 아바타 업로드 성공
     */
    public function test_upload_avatar_successfully(): void
    {
        Storage::fake('attachments');

        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $response = $this->authRequest()->postJson('/api/me/avatar', [
            'avatar' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['avatar', 'attachment_id']]);

        // avatarAttachment 관계로 확인
        $this->user->refresh();
        $attachment = $this->user->avatarAttachment;
        $this->assertNotNull($attachment);
        $this->assertEquals('avatar', $attachment->collection);
    }

    /**
     * 아바타 업로드 - 파일 필수 검증
     */
    public function test_upload_avatar_requires_file(): void
    {
        $response = $this->authRequest()->postJson('/api/me/avatar', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }

    /**
     * 아바타 업로드 - 이미지 형식만 허용
     */
    public function test_upload_avatar_only_accepts_images(): void
    {
        Storage::fake('attachments');

        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->authRequest()->postJson('/api/me/avatar', [
            'avatar' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }

    /**
     * 아바타 업로드 - 2MB 초과 시 실패
     */
    public function test_upload_avatar_validates_max_size(): void
    {
        Storage::fake('attachments');

        $file = UploadedFile::fake()->image('large-avatar.jpg')->size(3000);

        $response = $this->authRequest()->postJson('/api/me/avatar', [
            'avatar' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }

    /**
     * 아바타 삭제 성공
     */
    public function test_delete_avatar_successfully(): void
    {
        Storage::fake('attachments');

        // Attachment 레코드 생성 (다형성 관계)
        $attachment = Attachment::factory()
            ->image()
            ->attachedTo(User::class, $this->user->id)
            ->inCollection('avatar')
            ->create([
                'disk' => 'attachments',
                'path' => 'attachments/avatars/test-avatar.jpg',
                'source_type' => AttachmentSourceType::Core,
            ]);
        Storage::disk('attachments')->put('attachments/avatars/test-avatar.jpg', 'fake content');

        $response = $this->authRequest()->deleteJson('/api/me/avatar');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Attachment 레코드 삭제 확인
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    /**
     * 아바타 없을 때 삭제 시도 - 404 반환
     */
    public function test_delete_avatar_returns_404_when_no_avatar(): void
    {
        // avatarAttachment 관계가 없는 상태에서 삭제 시도
        $response = $this->authRequest()->deleteJson('/api/me/avatar');

        $response->assertStatus(404);
    }

    // ========================================================================
    // 비밀번호 변경 테스트 (changePassword)
    // ========================================================================

    /**
     * 비밀번호 변경 성공
     */
    public function test_change_password_successfully(): void
    {
        $response = $this->authRequest()->putJson('/api/me/password', [
            'current_password' => 'password123',
            'password' => 'newpassword456',
            'password_confirmation' => 'newpassword456',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // 새 비밀번호로 로그인 가능 확인
        $this->user->refresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newpassword456', $this->user->password));
    }

    /**
     * 현재 비밀번호가 틀린 경우 실패
     */
    public function test_change_password_fails_with_wrong_current_password(): void
    {
        $response = $this->authRequest()->putJson('/api/me/password', [
            'current_password' => 'wrongpassword',
            'password' => 'newpassword456',
            'password_confirmation' => 'newpassword456',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    /**
     * 비밀번호 확인이 일치하지 않는 경우 실패
     */
    public function test_change_password_fails_when_confirmation_does_not_match(): void
    {
        $response = $this->authRequest()->putJson('/api/me/password', [
            'current_password' => 'password123',
            'password' => 'newpassword456',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 새 비밀번호가 8자 미만인 경우 실패
     */
    public function test_change_password_validates_minimum_length(): void
    {
        $response = $this->authRequest()->putJson('/api/me/password', [
            'current_password' => 'password123',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 현재 비밀번호 필수 검증
     */
    public function test_change_password_requires_current_password(): void
    {
        $response = $this->authRequest()->putJson('/api/me/password', [
            'password' => 'newpassword456',
            'password_confirmation' => 'newpassword456',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    /**
     * 새 비밀번호 필수 검증
     */
    public function test_change_password_requires_new_password(): void
    {
        $response = $this->authRequest()->putJson('/api/me/password', [
            'current_password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 비밀번호 확인 필수 검증
     */
    public function test_change_password_requires_password_confirmation(): void
    {
        $response = $this->authRequest()->putJson('/api/me/password', [
            'current_password' => 'password123',
            'password' => 'newpassword456',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 인증 없이 비밀번호 변경 시 401 반환
     */
    public function test_change_password_returns_401_without_authentication(): void
    {
        $response = $this->jsonRequest()->putJson('/api/me/password', [
            'current_password' => 'password123',
            'password' => 'newpassword456',
            'password_confirmation' => 'newpassword456',
        ]);

        $response->assertStatus(401);
    }

    // ========================================================================
    // 회원 탈퇴 테스트 (destroy)
    // ========================================================================

    /**
     * 회원 탈퇴 성공
     */
    public function test_withdraw_user_successfully(): void
    {
        $originalEmail = $this->user->email;
        $originalName = $this->user->name;

        $response = $this->authRequest()->deleteJson('/api/me');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->user->refresh();

        // suffix가 추가되었는지 확인
        $this->assertStringContainsString('_deleted_', $this->user->email);
        $this->assertStringContainsString('_탈퇴_', $this->user->name);
        $this->assertNotEquals($originalEmail, $this->user->email);
        $this->assertNotEquals($originalName, $this->user->name);

        // 탈퇴 상태로 변경되었는지 확인
        $this->assertEquals('withdrawn', $this->user->status);
        $this->assertNotNull($this->user->withdrawn_at);
    }

    /**
     * 회원 탈퇴 후 토큰 삭제 확인
     */
    public function test_withdraw_revokes_all_tokens(): void
    {
        // 여러 토큰 생성
        $this->user->createToken('token1');
        $this->user->createToken('token2');

        $this->assertEquals(3, $this->user->tokens()->count()); // setUp의 토큰 포함

        $response = $this->authRequest()->deleteJson('/api/me');

        $response->assertStatus(200);

        $this->assertEquals(0, $this->user->tokens()->count());
    }

    /**
     * 회원 탈퇴 후 아바타 Attachment 삭제 확인
     */
    public function test_withdraw_deletes_avatar_attachment(): void
    {
        Storage::fake('attachments');

        // Attachment 레코드 생성 (다형성 관계)
        $attachment = Attachment::factory()
            ->image()
            ->attachedTo(User::class, $this->user->id)
            ->inCollection('avatar')
            ->create([
                'disk' => 'attachments',
                'path' => 'attachments/avatars/test-avatar.jpg',
                'source_type' => AttachmentSourceType::Core,
            ]);
        Storage::disk('attachments')->put('attachments/avatars/test-avatar.jpg', 'fake content');

        $response = $this->authRequest()->deleteJson('/api/me');

        $response->assertStatus(200);

        // Attachment 레코드 삭제 확인
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    /**
     * 슈퍼 관리자는 탈퇴 불가
     */
    public function test_withdraw_prevents_super_admin(): void
    {
        $superAdmin = User::factory()->create([
            'email' => 'superadmin@example.com',
            'is_super' => true,
        ]);
        $superToken = $superAdmin->createToken('super-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$superToken,
            'Accept' => 'application/json',
        ])->deleteJson('/api/me');

        $response->assertStatus(500); // Exception 발생

        // 슈퍼 관리자는 변경되지 않음
        $superAdmin->refresh();
        $this->assertEquals('superadmin@example.com', $superAdmin->email);
        $this->assertNull($superAdmin->withdrawn_at);
    }

    /**
     * 관리자는 탈퇴 불가
     */
    public function test_withdraw_prevents_admin(): void
    {
        // 관리자 역할 생성 및 할당
        $role = \App\Models\Role::factory()->create(['identifier' => 'admin']);
        $permission = \App\Models\Permission::factory()->create([
            'type' => \App\Enums\PermissionType::Admin,
        ]);
        $role->permissions()->attach($permission);

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
        ]);
        $admin->roles()->attach($role);
        $adminToken = $admin->createToken('admin-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$adminToken,
            'Accept' => 'application/json',
        ])->deleteJson('/api/me');

        $response->assertStatus(500); // ValidationException 발생

        // 관리자는 변경되지 않음
        $admin->refresh();
        $this->assertEquals('admin@example.com', $admin->email);
        $this->assertNull($admin->withdrawn_at);
    }

    /**
     * 탈퇴 시 닉네임에도 suffix 추가
     */
    public function test_withdraw_adds_suffix_to_nickname(): void
    {
        $this->user->update(['nickname' => '테스트닉네임']);

        $response = $this->authRequest()->deleteJson('/api/me');

        $response->assertStatus(200);

        $this->user->refresh();
        $this->assertStringContainsString('_탈퇴', $this->user->nickname);
    }

    /**
     * 탈퇴 인증 없이 요청 시 401 반환
     */
    public function test_withdraw_returns_401_without_authentication(): void
    {
        $response = $this->jsonRequest()->deleteJson('/api/me');

        $response->assertStatus(401);
    }
}
