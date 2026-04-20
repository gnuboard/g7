<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Attachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AdminSettingsController 테스트
 *
 * 시스템 설정 관리 API 엔드포인트를 테스트합니다.
 */
class SettingsControllerTest extends TestCase
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
     * @param  array  $permissions  사용자에게 부여할 권한 식별자 목록
     */
    private function createAdminUser(array $permissions = ['core.settings.read', 'core.settings.update']): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

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
        // admin 미들웨어가 hasRole('admin')을 체크하므로 'admin'으로 시작하는 역할 사용
        $roleIdentifier = 'admin_test_'.uniqid();
        $adminRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

        // admin 역할도 추가 (admin 미들웨어 통과용)
        $adminBaseRole = Role::firstOrCreate(
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
        $adminRole->permissions()->sync($permissionIds);

        // 사용자에게 admin 역할과 테스트용 역할 모두 할당
        $user->roles()->attach($adminBaseRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * super_admin 역할을 가진 사용자 생성 (필요한 권한 포함)
     * admin 미들웨어를 통과하기 위해 admin 역할도 함께 할당
     */
    private function createSuperAdminUser(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        // admin 역할 생성 (admin 미들웨어 통과용)
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

        // super_admin 역할 생성
        $superAdminRole = Role::firstOrCreate(
            ['identifier' => 'super_admin'],
            [
                'name' => json_encode(['ko' => '최고관리자', 'en' => 'Super Administrator']),
                'description' => json_encode(['ko' => '최고 권한 관리자', 'en' => 'Super Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        // core.settings.update 권한 생성 및 역할에 할당
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.settings.update'],
            [
                'name' => json_encode(['ko' => '설정 수정', 'en' => 'Update Settings']),
                'description' => json_encode(['ko' => '설정 수정 권한', 'en' => 'Update Settings Permission']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );

        if (! $superAdminRole->permissions()->where('permissions.id', $permission->id)->exists()) {
            $superAdminRole->permissions()->attach($permission->id);
        }

        // 사용자에게 admin 역할과 super_admin 역할 모두 할당
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($superAdminRole->id, [
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
    // 인증/권한 테스트
    // ========================================================================

    /**
     * 인증 없이 설정 목록 조회 시 401 반환
     */
    public function test_index_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/settings');

        $response->assertStatus(401);
    }

    /**
     * 권한 없이 설정 목록 조회 시 403 반환
     */
    public function test_index_returns_403_without_permission(): void
    {
        // 권한 없는 관리자 생성
        $user = User::factory()->create();
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

        // 기존 권한 분리 (테스트용)
        $readPermission = Permission::where('identifier', 'core.settings.read')->first();
        if ($adminRole && $readPermission) {
            $adminRole->permissions()->detach($readPermission->id);
        }

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/settings');

        $response->assertStatus(403);
    }

    /**
     * 권한 없이 설정 저장 시 403 반환
     */
    public function test_store_returns_403_without_update_permission(): void
    {
        // read 권한만 있는 관리자 생성
        $user = $this->createAdminUser(['core.settings.read']);
        $token = $user->createToken('test-token')->plainTextToken;

        // 전체 필수 필드를 포함하여 권한 테스트
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'site_name' => 'Test Site',
            'site_url' => 'https://test.example.com',
            'admin_email' => 'admin@example.com',
            'timezone' => 'Asia/Seoul',
            'language' => 'ko',
        ]);

        $response->assertStatus(403);
    }

    // ========================================================================
    // 설정 조회 테스트 (index)
    // ========================================================================

    /**
     * 설정 목록 조회 성공
     */
    public function test_index_returns_all_settings(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 설정 목록 응답 구조 검증
     */
    public function test_index_returns_correct_structure(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);
    }

    // ========================================================================
    // abilities 응답 테스트
    // ========================================================================

    /**
     * read+update 권한 사용자 → abilities.can_update: true
     */
    public function test_index_returns_can_update_true_with_update_permission(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'abilities' => [
                        'can_update' => true,
                    ],
                ],
            ]);
    }

    /**
     * read 권한만 사용자 → abilities.can_update: false
     */
    public function test_index_returns_can_update_false_without_update_permission(): void
    {
        // read 권한만 있는 관리자 생성
        $readOnlyUser = $this->createAdminUser(['core.settings.read']);
        $readOnlyToken = $readOnlyUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readOnlyToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/settings');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'abilities' => [
                        'can_update' => false,
                    ],
                ],
            ]);
    }

    // ========================================================================
    // 설정 저장 테스트 (store)
    // ========================================================================

    /**
     * 일반 탭 설정 저장 성공
     */
    public function test_store_saves_general_tab_settings(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => [
                'site_name' => 'Test Site',
                'site_url' => 'https://test.example.com',
                'admin_email' => 'admin@example.com',
                'timezone' => 'Asia/Seoul',
                'language' => 'ko',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 보안 탭 설정 저장 성공
     */
    public function test_store_saves_security_tab_settings(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'security',
            'security' => [
                'force_https' => true,
                'login_attempt_enabled' => true,
                'auth_token_lifetime' => 60,
                'max_login_attempts' => 5,
                'login_lockout_time' => 30,
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 고급 탭 설정 저장 성공
     */
    public function test_store_saves_advanced_tab_settings(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'advanced',
            'advanced' => [
                'cache_enabled' => true,
                'layout_cache_enabled' => true,
                'layout_cache_ttl' => 3600,
                'stats_cache_enabled' => true,
                'stats_cache_ttl' => 3600,
                'seo_cache_enabled' => true,
                'seo_cache_ttl' => 3600,
                'debug_mode' => false,
                'sql_query_log' => false,
                'core_update_github_url' => 'https://github.com/test/repo',
                'core_update_github_token' => 'ghp_test_token_12345',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 일반 탭에서 site_name 필수 검증
     */
    public function test_store_validates_site_name_required_for_general_tab(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => [
                // site_name 누락
                'site_url' => 'https://test.example.com',
                'admin_email' => 'admin@example.com',
                'timezone' => 'Asia/Seoul',
                'language' => 'ko',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['general.site_name']);
    }

    /**
     * site_url 형식 검증
     */
    public function test_store_validates_site_url_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => [
                'site_name' => 'Test Site',
                'site_url' => 'not-a-valid-url',
                'admin_email' => 'admin@example.com',
                'timezone' => 'Asia/Seoul',
                'language' => 'ko',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['general.site_url']);
    }

    /**
     * admin_email 형식 검증
     */
    public function test_store_validates_admin_email_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => [
                'site_name' => 'Test Site',
                'site_url' => 'https://test.example.com',
                'admin_email' => 'not-a-valid-email',
                'timezone' => 'Asia/Seoul',
                'language' => 'ko',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['general.admin_email']);
    }

    /**
     * timezone 지원 목록 검증
     */
    public function test_store_validates_timezone_in_supported_list(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => [
                'site_name' => 'Test Site',
                'site_url' => 'https://test.example.com',
                'admin_email' => 'admin@example.com',
                'timezone' => 'Invalid/Timezone',
                'language' => 'ko',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['general.timezone']);
    }

    /**
     * auth_token_lifetime 범위 검증 (30-3600 또는 0)
     */
    public function test_store_validates_auth_token_lifetime_range(): void
    {
        // 범위 벗어난 값 (1-29)
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'security',
            'security' => [
                'force_https' => true,
                'login_attempt_enabled' => true,
                'auth_token_lifetime' => 15, // 30 미만, 0이 아님
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['security.auth_token_lifetime']);
    }

    /**
     * 캐시 TTL 범위 검증
     */
    public function test_store_validates_cache_ttl_range(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'advanced',
            'advanced' => [
                'cache_enabled' => true,
                'layout_cache_enabled' => true,
                'layout_cache_ttl' => 99999, // 최대 14400 초과
                'stats_cache_enabled' => true,
                'stats_cache_ttl' => 3600,
                'seo_cache_enabled' => true,
                'seo_cache_ttl' => 3600,
                'debug_mode' => false,
                'sql_query_log' => false,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['advanced.layout_cache_ttl']);
    }

    /**
     * 잘못된 탭 값 검증
     */
    public function test_store_returns_422_for_invalid_tab(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'invalid_tab',
            'site_name' => 'Test Site',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['_tab']);
    }

    // ========================================================================
    // 개별 설정 테스트 (show/update)
    // ========================================================================

    /**
     * 단일 설정 조회 성공
     */
    public function test_show_returns_single_setting(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings/site_name');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'key',
                    'value',
                ],
            ]);
    }

    /**
     * 존재하지 않는 설정 키 조회 시 기본값 반환
     */
    public function test_show_returns_default_for_nonexistent_key(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings/nonexistent_key');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'key' => 'nonexistent_key',
                ],
            ]);
    }

    /**
     * 단일 설정 업데이트 성공
     */
    public function test_update_modifies_single_setting(): void
    {
        // set() 메서드는 category.key 형식을 요구함 (예: general.site_name)
        $response = $this->authRequest()->putJson('/api/admin/settings/general.site_name', [
            'value' => 'Updated Site Name',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 설정 업데이트 시 value 필수 검증
     */
    public function test_update_validates_value_required(): void
    {
        $response = $this->authRequest()->putJson('/api/admin/settings/general.site_name', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['value']);
    }

    // ========================================================================
    // 시스템 정보 테스트 (systemInfo)
    // ========================================================================

    /**
     * 시스템 정보 응답 구조 검증
     */
    public function test_system_info_returns_correct_structure(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings/system-info');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);
    }

    // ========================================================================
    // 캐시 관리 테스트 (clearCache)
    // ========================================================================

    /**
     * 캐시 정리 성공
     */
    public function test_clear_cache_succeeds(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings/clear-cache');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========================================================================
    // 앱 키 테스트 (getAppKey/regenerateAppKey)
    // ========================================================================

    /**
     * 앱 키 조회 시 마스킹된 키 반환
     */
    public function test_get_app_key_returns_masked_key(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings/app-key');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'app_key',
                ],
            ]);

        // 마스킹 확인 (키의 일부가 *로 대체되어 있어야 함)
        $appKey = $response->json('data.app_key');
        $this->assertStringContainsString('*', $appKey);
    }

    /**
     * 앱 키 재생성 시 비밀번호 필수
     */
    public function test_regenerate_app_key_requires_password(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $token = $superAdmin->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/settings/regenerate-app-key', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 앱 키 재생성 시 잘못된 비밀번호 검증
     */
    public function test_regenerate_app_key_validates_password(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $token = $superAdmin->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/settings/regenerate-app-key', [
            'password' => 'wrong_password',
        ]);

        // 비밀번호 검증 실패 시 401 반환
        $response->assertStatus(401);
    }

    /**
     * 앱 키 재생성 시 super_admin 역할 필수
     */
    public function test_regenerate_app_key_requires_super_admin_role(): void
    {
        // 일반 admin 역할로 요청
        $response = $this->authRequest()->postJson('/api/admin/settings/regenerate-app-key', [
            'password' => 'password123',
        ]);

        // authorize 실패로 403 반환
        $response->assertStatus(403);
    }

    /**
     * super_admin 역할로 앱 키 재생성 성공
     */
    public function test_regenerate_app_key_succeeds_with_super_admin(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $token = $superAdmin->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/settings/regenerate-app-key', [
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'app_key',
                ],
            ]);
    }

    // ========================================================================
    // 테스트 메일 발송 테스트 (testMail)
    // ========================================================================

    /**
     * 인증 없이 테스트 메일 발송 시 401 반환
     */
    public function test_test_mail_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/settings/test-mail', [
            'to_email' => 'test@example.com',
        ]);

        $response->assertStatus(401);
    }

    /**
     * 이메일 없이 테스트 메일 발송 시 422 반환
     */
    public function test_test_mail_returns_422_without_email(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings/test-mail', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['to_email']);
    }

    /**
     * 잘못된 이메일 형식으로 테스트 메일 발송 시 422 반환
     */
    public function test_test_mail_returns_422_with_invalid_email(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings/test-mail', [
            'to_email' => 'not-a-valid-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['to_email']);
    }

    /**
     * 올바른 이메일로 테스트 메일 발송 성공
     */
    public function test_test_mail_succeeds_with_valid_email(): void
    {
        Mail::fake();

        $response = $this->authRequest()->postJson('/api/admin/settings/test-mail', [
            'to_email' => 'test@example.com',
            'mailer' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => 587,
            'from_address' => 'noreply@example.com',
            'from_name' => 'G7 Test',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========================================================================
    // 타임존 설정 테스트
    // ========================================================================

    /**
     * Europe/Paris 타임존이 유효성 검사를 통과하는지 테스트합니다.
     */
    public function test_store_accepts_europe_paris_timezone(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => [
                'site_name' => 'Test Site',
                'site_url' => 'https://test.example.com',
                'admin_email' => 'admin@example.com',
                'timezone' => 'Europe/Paris',
                'language' => 'ko',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 타임존 저장 후 app.timezone이 UTC를 유지하는지 테스트합니다.
     */
    public function test_store_timezone_does_not_change_app_timezone(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => [
                'site_name' => 'Test Site',
                'site_url' => 'https://test.example.com',
                'admin_email' => 'admin@example.com',
                'timezone' => 'America/New_York',
                'language' => 'ko',
            ],
        ]);

        $response->assertStatus(200);

        // app.timezone은 항상 UTC 유지
        $this->assertEquals('UTC', config('app.timezone'));
    }

    /**
     * 고급 탭에서 코어 업데이트 설정(GitHub URL, 토큰) 저장 성공
     */
    public function test_store_saves_core_update_settings_in_advanced_tab(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'advanced',
            'advanced' => [
                'cache_enabled' => true,
                'layout_cache_enabled' => true,
                'layout_cache_ttl' => 3600,
                'stats_cache_enabled' => true,
                'stats_cache_ttl' => 1800,
                'seo_cache_enabled' => true,
                'seo_cache_ttl' => 7200,
                'debug_mode' => false,
                'sql_query_log' => false,
                'core_update_github_url' => 'https://github.com/gnuboard/g7',
                'core_update_github_token' => 'ghp_test_token_abcdef123456',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // 저장된 설정이 조회 시 반영되는지 확인
        $getResponse = $this->authRequest()->getJson('/api/admin/settings');
        $getResponse->assertStatus(200);

        $settings = $getResponse->json('data.advanced');
        $this->assertNotNull($settings, 'advanced 카테고리가 응답에 포함되어야 합니다');
        $this->assertEquals('https://github.com/gnuboard/g7', $settings['core_update_github_url']);
        $this->assertEquals('ghp_test_token_abcdef123456', $settings['core_update_github_token']);
    }

    /**
     * 코어 업데이트 GitHub URL 형식 검증
     */
    public function test_store_validates_core_update_github_url_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'advanced',
            'advanced' => [
                'cache_enabled' => true,
                'layout_cache_enabled' => true,
                'layout_cache_ttl' => 3600,
                'stats_cache_enabled' => true,
                'stats_cache_ttl' => 1800,
                'seo_cache_enabled' => true,
                'seo_cache_ttl' => 7200,
                'debug_mode' => false,
                'sql_query_log' => false,
                'core_update_github_url' => 'not-a-valid-url',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['advanced.core_update_github_url']);
    }

    /**
     * 코어 업데이트 GitHub 토큰 최대 길이 검증
     */
    public function test_store_validates_core_update_github_token_max_length(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'advanced',
            'advanced' => [
                'cache_enabled' => true,
                'layout_cache_enabled' => true,
                'layout_cache_ttl' => 3600,
                'stats_cache_enabled' => true,
                'stats_cache_ttl' => 1800,
                'seo_cache_enabled' => true,
                'seo_cache_ttl' => 7200,
                'debug_mode' => false,
                'sql_query_log' => false,
                'core_update_github_token' => str_repeat('a', 501),
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['advanced.core_update_github_token']);
    }

    /**
     * 코어 업데이트 설정이 비어있어도 저장 성공 (nullable)
     */
    public function test_store_allows_empty_core_update_settings(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'advanced',
            'advanced' => [
                'cache_enabled' => true,
                'layout_cache_enabled' => true,
                'layout_cache_ttl' => 3600,
                'stats_cache_enabled' => true,
                'stats_cache_ttl' => 1800,
                'seo_cache_enabled' => true,
                'seo_cache_ttl' => 7200,
                'debug_mode' => false,
                'sql_query_log' => false,
                'core_update_github_url' => '',
                'core_update_github_token' => '',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 코어 업데이트 설정 저장 후 config()에 반영되는지 확인
     */
    public function test_core_update_settings_override_config(): void
    {
        // 기본 config 값 확인
        $originalUrl = config('app.update.github_url');

        // settings로 저장
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'advanced',
            'advanced' => [
                'cache_enabled' => true,
                'layout_cache_enabled' => true,
                'layout_cache_ttl' => 3600,
                'stats_cache_enabled' => true,
                'stats_cache_ttl' => 1800,
                'seo_cache_enabled' => true,
                'seo_cache_ttl' => 7200,
                'debug_mode' => false,
                'sql_query_log' => false,
                'core_update_github_url' => 'https://github.com/custom/repo',
                'core_update_github_token' => 'ghp_custom_token',
            ],
        ]);

        $response->assertStatus(200);

        // SettingsServiceProvider가 다음 요청에서 config를 오버라이드하는지 확인
        // JsonConfigRepository에서 직접 읽어서 확인
        $configRepo = app(ConfigRepositoryInterface::class);
        $coreUpdateSettings = $configRepo->getCategory('core_update');

        $this->assertEquals('https://github.com/custom/repo', $coreUpdateSettings['github_url']);
        $this->assertEquals('ghp_custom_token', $coreUpdateSettings['github_token']);
    }

    /**
     * site_logo가 Attachment 객체 배열로 전송되어도 검증 통과
     *
     * initLocal로 복사된 Attachment 객체가 폼 데이터에 포함되어
     * 정수 검증 실패하는 버그 회귀 방지 (#225)
     */
    public function test_store_general_accepts_site_logo_as_attachment_objects(): void
    {
        // Attachment 레코드 생성
        $attachment = Attachment::factory()->create([
            'collection' => 'site_logo',
        ]);

        // 프론트엔드에서 전송되는 형태: Attachment 객체 배열
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => [
                'site_name' => 'Test Site',
                'site_url' => 'https://test.example.com',
                'admin_email' => 'admin@example.com',
                'timezone' => 'Asia/Seoul',
                'language' => 'ko',
                'site_logo' => [
                    [
                        'id' => $attachment->id,
                        'hash' => $attachment->hash,
                        'original_filename' => $attachment->original_filename,
                        'mime_type' => $attachment->mime_type,
                        'size' => $attachment->size,
                        'download_url' => '/attachments/'.$attachment->hash,
                        'order' => 0,
                        'is_image' => true,
                    ],
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * site_logo가 정수 ID 배열로 전송되어도 정상 동작
     */
    public function test_store_general_accepts_site_logo_as_integer_ids(): void
    {
        $attachment = Attachment::factory()->create([
            'collection' => 'site_logo',
        ]);

        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => [
                'site_name' => 'Test Site',
                'site_url' => 'https://test.example.com',
                'admin_email' => 'admin@example.com',
                'timezone' => 'Asia/Seoul',
                'language' => 'ko',
                'site_logo' => [$attachment->id],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========================================================================
    // 드라이버 탭 - 웹소켓 필수 필드 검증 테스트
    // ========================================================================

    /**
     * 웹소켓 활성화 시 앱 ID, 앱 키, 앱 시크릿 필수 검증
     */
    public function test_store_validates_websocket_required_fields_when_enabled(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => [
                'websocket_enabled' => true,
                'websocket_app_id' => '',
                'websocket_app_key' => '',
                'websocket_app_secret' => '',
                'websocket_host' => 'localhost',
                'websocket_port' => 8080,
                'websocket_scheme' => 'https',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'drivers.websocket_app_id',
                'drivers.websocket_app_key',
                'drivers.websocket_app_secret',
            ]);
    }

    /**
     * 웹소켓 비활성화 시 앱 ID, 앱 키, 앱 시크릿 비필수
     */
    public function test_store_allows_empty_websocket_fields_when_disabled(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => [
                'storage_driver' => 'local',
                'cache_driver' => 'file',
                'session_driver' => 'file',
                'queue_driver' => 'database',
                'log_driver' => 'daily',
                'log_level' => 'error',
                'websocket_enabled' => false,
                'websocket_app_id' => '',
                'websocket_app_key' => '',
                'websocket_app_secret' => '',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 웹소켓 활성화 + 모든 필수 필드 입력 시 저장 성공
     */
    public function test_store_saves_websocket_settings_with_all_required_fields(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => [
                'storage_driver' => 'local',
                'cache_driver' => 'file',
                'session_driver' => 'file',
                'queue_driver' => 'database',
                'log_driver' => 'daily',
                'log_level' => 'error',
                'websocket_enabled' => true,
                'websocket_app_id' => 'test-app-id',
                'websocket_app_key' => 'test-app-key',
                'websocket_app_secret' => 'test-app-secret',
                'websocket_host' => 'localhost',
                'websocket_port' => 8080,
                'websocket_scheme' => 'https',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }
}
