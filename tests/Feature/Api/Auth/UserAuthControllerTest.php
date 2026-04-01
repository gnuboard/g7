<?php

namespace Tests\Feature\Api\Auth;

use App\Models\PasswordResetToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserConsent;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * UserAuthController 테스트
 *
 * 사용자 인증 API 엔드포인트를 테스트합니다.
 */
class UserAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 권한이 부여된 사용자 생성 헬퍼
     */
    private function createUserWithPermissions(array $attributes = [], array $permissions = []): User
    {
        $user = User::factory()->create($attributes);

        if (! empty($permissions)) {
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
                'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
                'is_active' => true,
            ]);

            $testRole->permissions()->sync($permissionIds);
            $user->roles()->attach($testRole->id, ['assigned_at' => now()]);
            $user = $user->fresh();
        }

        return $user;
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

    /**
     * 인증된 요청 헬퍼 메서드
     */
    private function authRequest(User $user): static
    {
        $token = $user->createToken('test-token')->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    }

    // ========================================================================
    // 로그인 테스트 (login)
    // ========================================================================

    /**
     * 유효한 자격 증명으로 로그인 성공
     */
    public function test_login_succeeds_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 로그인 성공 시 토큰과 사용자 정보 반환
     */
    public function test_login_returns_token_and_user(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'token',
                    'user' => [
                        'id',
                        'name',
                        'email',
                    ],
                ],
            ]);
    }

    /**
     * 잘못된 이메일로 로그인 실패
     */
    public function test_login_fails_with_invalid_email(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/login', [
            'email' => 'wrong@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401);
    }

    /**
     * 잘못된 비밀번호로 로그인 실패
     */
    public function test_login_fails_with_invalid_password(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);
    }

    /**
     * 이메일 필수 검증
     */
    public function test_login_validates_email_required(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/login', [
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * 비밀번호 필수 검증
     */
    public function test_login_validates_password_required(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/login', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 이메일 형식 검증
     */
    public function test_login_validates_email_format(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/login', [
            'email' => 'invalid-email',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ========================================================================
    // 회원가입 테스트 (register)
    // ========================================================================

    /**
     * 회원가입 성공
     */
    public function test_register_creates_user_successfully(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
        ]);
    }

    /**
     * 회원가입 시 user_consents 테이블에 동의 이력이 기록되는지 확인
     */
    public function test_register_stores_consent_records(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'agreement@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
            'terms_version' => 3,
            'privacy_version' => 2,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'agreement@example.com')->first();
        $this->assertNotNull($user);

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'consent_type' => 'terms',
        ]);

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'consent_type' => 'privacy',
        ]);

        // users 테이블에 agreed_terms_at 컬럼 없음 확인
        $this->assertArrayNotHasKey('agreed_terms_at', $user->toArray());
        $this->assertArrayNotHasKey('agreed_privacy_at', $user->toArray());
    }

    /**
     * 버전 미전달 시 version=0으로 동의 이력이 기록되는지 확인
     */
    public function test_register_stores_consent_without_version(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'noversion@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'noversion@example.com')->first();
        $this->assertNotNull($user);

        // 버전 파라미터 없이도 동의 이력이 정상 기록되는지 확인
        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'consent_type' => 'terms',
        ]);

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'consent_type' => 'privacy',
        ]);
    }

    /**
     * 회원가입 시 동의 이력에 IP 주소가 기록되는지 확인
     */
    public function test_register_stores_ip_address_in_consent(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'consentip@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'consentip@example.com')->first();
        $this->assertNotNull($user);

        $consent = UserConsent::where('user_id', $user->id)
            ->where('consent_type', 'terms')
            ->first();

        $this->assertNotNull($consent);
        $this->assertNotNull($consent->ip_address);
        $this->assertNotNull($consent->agreed_at);
    }

    /**
     * 회원가입 시 닉네임이 선택 사항인지 확인
     */
    public function test_register_nickname_is_optional(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'nickname' => '테스트닉네임',
            'email' => 'withnickname@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'withnickname@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('테스트닉네임', $user->nickname);
    }

    /**
     * 회원가입 시 언어 설정이 저장되는지 확인
     */
    public function test_register_stores_language_preference(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'english@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'language' => 'en',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'english@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('en', $user->language);
    }

    /**
     * 회원가입 시 'user' 역할 자동 할당
     */
    public function test_register_assigns_user_role_automatically(): void
    {
        // 'user' 역할이 존재해야 함 (RolePermissionSeeder에서 생성)
        $this->artisan('db:seed', ['--class' => 'RolePermissionSeeder']);

        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'roletest@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'roletest@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->roles()->where('identifier', 'user')->exists());
    }

    /**
     * 이름 필수 검증
     */
    public function test_register_validates_name_required(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * 이용약관 동의 필수 검증
     */
    public function test_register_validates_agree_terms_required(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_privacy' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['agree_terms']);
    }

    /**
     * 개인정보처리방침 동의 필수 검증
     */
    public function test_register_validates_agree_privacy_required(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['agree_privacy']);
    }

    /**
     * 이메일 고유성 검증
     */
    public function test_register_validates_email_unique(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * 비밀번호 확인 검증
     */
    public function test_register_validates_password_confirmed(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'differentpassword',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 비밀번호 최소 길이 검증 (8자 미만 실패)
     */
    public function test_register_validates_password_min_length(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'newuser@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 비밀번호 7자는 실패 (최소 8자 경계값 테스트)
     */
    public function test_register_validates_password_min_8_characters(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'newuser@example.com',
            'password' => 'pass123',  // 7자
            'password_confirmation' => 'pass123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 비밀번호 8자는 성공 (최소 8자 경계값 테스트)
     */
    public function test_register_accepts_password_with_exactly_8_characters(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'newuser@example.com',
            'password' => 'pass1234',  // 8자
            'password_confirmation' => 'pass1234',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201);
    }

    /**
     * 회원가입 시 country 필드가 Accept-Language 헤더로부터 자동 저장되는지 확인
     */
    public function test_register_stores_country_from_accept_language(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'ko-KR,ko;q=0.9,en-US;q=0.8',
        ])->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'country@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'country@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('KR', $user->country);
    }

    /**
     * 회원가입 시 ip_address가 자동 저장되는지 확인
     */
    public function test_register_stores_ip_address(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'iptest@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'iptest@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->ip_address);
    }

    /**
     * 회원가입 시 환영 알림이 발송되는지 확인
     */
    public function test_register_sends_welcome_notification(): void
    {
        Notification::fake();

        $response = $this->jsonRequest()->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'welcome@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'welcome@example.com')->first();
        $this->assertNotNull($user);

        // 환영 알림 발송 확인
        Notification::assertSentTo($user, WelcomeNotification::class);
    }

    /**
     * Accept-Language 헤더가 빈 문자열일 때 country가 null인지 확인
     */
    public function test_register_stores_null_country_with_empty_accept_language(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => '',
        ])->postJson('/api/auth/register', [
            'name' => '테스트 사용자',
            'email' => 'nocountry@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'nocountry@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->country);
    }

    // ========================================================================
    // 로그아웃 테스트 (logout)
    // ========================================================================

    /**
     * 현재 토큰만 무효화하여 로그아웃
     */
    public function test_logout_invalidates_current_token(): void
    {
        $user = $this->createUserWithPermissions([], ['core.auth.logout']);

        $response = $this->authRequest($user)->postJson('/api/user/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 인증 없이 로그아웃 시 401 반환
     */
    public function test_logout_returns_401_without_authentication(): void
    {
        $response = $this->jsonRequest()->postJson('/api/user/auth/logout');

        $response->assertStatus(401);
    }

    // ========================================================================
    // 전체 디바이스 로그아웃 테스트 (logoutFromAllDevices)
    // ========================================================================

    /**
     * 모든 디바이스에서 로그아웃
     */
    public function test_logout_all_devices_invalidates_all_tokens(): void
    {
        $user = $this->createUserWithPermissions([], ['core.auth.logout']);

        // 여러 토큰 생성
        $user->createToken('device-1');
        $user->createToken('device-2');
        $token = $user->createToken('current-device')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/user/auth/logout-all-devices');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // 모든 토큰이 삭제되었는지 확인
        $user->refresh();
        $this->assertCount(0, $user->tokens);
    }

    // ========================================================================
    // 토큰 갱신 테스트 (refresh)
    // ========================================================================

    /**
     * 토큰 갱신 시 새 토큰 반환
     */
    public function test_refresh_returns_new_token(): void
    {
        $user = $this->createUserWithPermissions([], ['core.auth.refresh']);

        $response = $this->authRequest($user)->postJson('/api/user/auth/refresh');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'token',
                ],
            ]);
    }

    /**
     * 인증 없이 토큰 갱신 시 401 반환
     */
    public function test_refresh_returns_401_without_authentication(): void
    {
        $response = $this->jsonRequest()->postJson('/api/user/auth/refresh');

        $response->assertStatus(401);
    }

    // ========================================================================
    // 현재 사용자 정보 테스트 (user)
    // ========================================================================

    /**
     * 인증된 사용자 정보 반환
     */
    public function test_user_returns_authenticated_user_info(): void
    {
        $user = $this->createUserWithPermissions([
            'name' => '테스트 사용자',
            'email' => 'test@example.com',
        ], ['core.auth.user']);

        $response = $this->authRequest($user)->getJson('/api/user/auth/user');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.email', 'test@example.com');
    }

    /**
     * 인증 없이 사용자 정보 조회 시 401 반환
     */
    public function test_user_returns_401_without_authentication(): void
    {
        $response = $this->jsonRequest()->getJson('/api/user/auth/user');

        $response->assertStatus(401);
    }

    // ========================================================================
    // 공용 인증 라우트 테스트 (/api/auth/*)
    // ========================================================================

    /**
     * /api/auth/user 공용 라우트로 인증된 사용자 정보 반환
     */
    public function test_auth_user_returns_authenticated_user_info(): void
    {
        $user = User::factory()->create([
            'name' => '테스트 사용자',
            'email' => 'test@example.com',
        ]);

        $response = $this->authRequest($user)->getJson('/api/auth/user');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.email', 'test@example.com');
    }

    /**
     * /api/auth/user 공용 라우트 - 인증 없이 접근 시 401 반환
     */
    public function test_auth_user_returns_401_without_authentication(): void
    {
        $response = $this->jsonRequest()->getJson('/api/auth/user');

        $response->assertStatus(401);
    }

    /**
     * /api/auth/logout 공용 라우트로 로그아웃 성공
     */
    public function test_auth_logout_invalidates_token(): void
    {
        $user = User::factory()->create();

        $response = $this->authRequest($user)->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * /api/auth/logout 공용 라우트 - 인증 없이 접근 시 401 반환
     */
    public function test_auth_logout_returns_401_without_authentication(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/logout');

        $response->assertStatus(401);
    }

    /**
     * /api/auth/user 응답에 is_admin 필드 포함 확인 (일반 사용자)
     */
    public function test_auth_user_returns_is_admin_false_for_regular_user(): void
    {
        $user = User::factory()->create();

        $response = $this->authRequest($user)->getJson('/api/auth/user');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_admin', false);
    }

    /**
     * /api/auth/user 응답에 is_admin 필드 포함 확인 (관리자)
     */
    public function test_auth_user_returns_is_admin_true_for_admin_user(): void
    {
        // 권한 시더 실행
        $this->artisan('db:seed', ['--class' => 'RolePermissionSeeder']);

        $user = User::factory()->create();

        // admin 역할 할당
        $adminRole = \App\Models\Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $user->roles()->sync([$adminRole->id]);
        }

        $response = $this->authRequest($user)->getJson('/api/auth/user');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_admin', true);
    }

    // ========================================================================
    // 비밀번호 찾기 테스트 (forgotPassword)
    // ========================================================================

    /**
     * 비밀번호 찾기 요청 시 이메일 발송 성공
     */
    public function test_forgot_password_sends_email(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // 토큰이 DB에 저장되었는지 확인
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'test@example.com',
        ]);

        // 알림 발송 확인
        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /**
     * 미등록 이메일로 비밀번호 찾기 요청 시 실패
     */
    public function test_forgot_password_fails_with_unregistered_email(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * 비밀번호 찾기 요청 시 이메일 형식 검증
     */
    public function test_forgot_password_validates_email_format(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/forgot-password', [
            'email' => 'invalid-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * 중복 비밀번호 찾기 요청 시 토큰 갱신
     */
    public function test_forgot_password_updates_existing_token(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        // 첫 번째 요청
        $this->jsonRequest()->postJson('/api/auth/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $firstToken = PasswordResetToken::where('email', 'test@example.com')->first();
        $firstCreatedAt = $firstToken->created_at;

        // 시간을 약간 앞으로
        $this->travel(1)->minutes();

        // 두 번째 요청
        $this->jsonRequest()->postJson('/api/auth/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $secondToken = PasswordResetToken::where('email', 'test@example.com')->first();

        // 토큰이 갱신되었는지 확인 (created_at이 다름)
        $this->assertNotEquals($firstCreatedAt->timestamp, $secondToken->created_at->timestamp);

        // 알림이 2번 발송됨 확인
        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 2);
    }

    /**
     * 비밀번호 찾기 요청 시 redirect_prefix=admin 전달 성공
     */
    public function test_forgot_password_accepts_redirect_prefix_admin(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/forgot-password', [
            'email' => 'admin@example.com',
            'redirect_prefix' => 'admin',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /**
     * 비밀번호 찾기 요청 시 잘못된 redirect_prefix 거부
     */
    public function test_forgot_password_rejects_invalid_redirect_prefix(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/forgot-password', [
            'email' => 'test@example.com',
            'redirect_prefix' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['redirect_prefix']);
    }

    /**
     * 비밀번호 찾기 요청 시 redirect_prefix 없이도 정상 동작 (하위 호환성)
     */
    public function test_forgot_password_works_without_redirect_prefix(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    // ========================================================================
    // 비밀번호 재설정 테스트 (resetPassword)
    // ========================================================================

    /**
     * 비밀번호 재설정 성공
     */
    public function test_reset_password_succeeds(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $token = 'valid-reset-token-12345678901234567890123456789012345678901234';

        // 토큰 저장 (해시로)
        PasswordResetToken::create([
            'email' => 'test@example.com',
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'test@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // 비밀번호가 변경되었는지 확인
        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));

        // 토큰이 삭제되었는지 확인
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * 잘못된 토큰으로 비밀번호 재설정 실패
     */
    public function test_reset_password_fails_with_invalid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $token = 'valid-reset-token-12345678901234567890123456789012345678901234';

        PasswordResetToken::create([
            'email' => 'test@example.com',
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/reset-password', [
            'token' => 'wrong-token',
            'email' => 'test@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    /**
     * 만료된 토큰으로 비밀번호 재설정 실패
     */
    public function test_reset_password_fails_with_expired_token(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $token = 'valid-reset-token-12345678901234567890123456789012345678901234';

        // 61분 전에 생성된 토큰 (만료됨)
        PasswordResetToken::create([
            'email' => 'test@example.com',
            'token' => Hash::make($token),
            'created_at' => now()->subMinutes(61),
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'test@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);

        // 만료된 토큰이 삭제되었는지 확인
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * 미등록 이메일로 비밀번호 재설정 실패
     */
    public function test_reset_password_fails_with_unregistered_email(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/reset-password', [
            'token' => 'some-token',
            'email' => 'nonexistent@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * 비밀번호 확인 불일치 시 실패
     */
    public function test_reset_password_fails_with_password_mismatch(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/reset-password', [
            'token' => 'some-token',
            'email' => 'test@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 토큰이 존재하지 않을 때 비밀번호 재설정 실패
     */
    public function test_reset_password_fails_when_no_token_exists(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        // 토큰을 생성하지 않음

        $response = $this->jsonRequest()->postJson('/api/auth/reset-password', [
            'token' => 'some-token',
            'email' => 'test@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    // ========================================================================
    // 비밀번호 재설정 토큰 검증 테스트 (validateResetToken)
    // ========================================================================

    /**
     * 유효한 토큰으로 검증 성공
     */
    public function test_validate_reset_token_succeeds_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $token = 'valid-reset-token-12345678901234567890123456789012345678901234';

        PasswordResetToken::create([
            'email' => 'test@example.com',
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/validate-reset-token', [
            'token' => $token,
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'valid' => true,
                ],
            ]);
    }

    /**
     * 잘못된 토큰으로 검증 실패
     */
    public function test_validate_reset_token_fails_with_invalid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $token = 'valid-reset-token-12345678901234567890123456789012345678901234';

        PasswordResetToken::create([
            'email' => 'test@example.com',
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/validate-reset-token', [
            'token' => 'wrong-token',
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    /**
     * 만료된 토큰으로 검증 실패
     */
    public function test_validate_reset_token_fails_with_expired_token(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $token = 'valid-reset-token-12345678901234567890123456789012345678901234';

        // 61분 전에 생성된 토큰 (만료됨)
        PasswordResetToken::create([
            'email' => 'test@example.com',
            'token' => Hash::make($token),
            'created_at' => now()->subMinutes(61),
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/validate-reset-token', [
            'token' => $token,
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);

        // 만료된 토큰이 삭제되었는지 확인
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * 미등록 이메일로 토큰 검증 실패
     */
    public function test_validate_reset_token_fails_with_unregistered_email(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/validate-reset-token', [
            'token' => 'some-token',
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    /**
     * 토큰이 존재하지 않을 때 검증 실패
     */
    public function test_validate_reset_token_fails_when_no_token_exists(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        // 토큰을 생성하지 않음

        $response = $this->jsonRequest()->postJson('/api/auth/validate-reset-token', [
            'token' => 'some-token',
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    /**
     * 토큰 필수 검증
     */
    public function test_validate_reset_token_validates_token_required(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/validate-reset-token', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    /**
     * 이메일 필수 검증
     */
    public function test_validate_reset_token_validates_email_required(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/validate-reset-token', [
            'token' => 'some-token',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * 이메일 형식 검증
     */
    public function test_validate_reset_token_validates_email_format(): void
    {
        $response = $this->jsonRequest()->postJson('/api/auth/validate-reset-token', [
            'token' => 'some-token',
            'email' => 'invalid-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
