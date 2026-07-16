<?php

namespace Tests\Feature\Seo;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * SaveSettingsRequest SEO 필드 검증 테스트
 *
 * 코어 SEO 설정 필드(bot_user_agents, cache_ttl, sitemap_schedule 등)의
 * 유효성 검증 규칙이 올바르게 동작하는지 API 요청을 통해 검증합니다.
 */
class SaveSettingsRequestSeoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 역할 및 권한을 가진 사용자를 생성합니다.
     *
     * @param  array  $permissions  부여할 권한 식별자 목록
     */
    private function createAdminUser(array $permissions = ['core.settings.read', 'core.settings.update']): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

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

        $roleIdentifier = 'admin_test_'.uniqid();
        $adminRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

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

        $adminRole->permissions()->sync($permissionIds);

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
     * SEO 탭 설정 저장 요청을 전송합니다.
     *
     * @param  array  $seoData  SEO 필드 데이터
     */
    private function postSeoSettings(array $seoData): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson('/api/admin/settings', [
                '_tab' => 'seo',
                'seo' => $seoData,
            ]);
    }

    // ========================================
    // bot_user_agents 검증 테스트
    // ========================================

    /**
     * bot_user_agents 배열 전송 시 유효성 검증 통과
     */
    public function test_bot_user_agents_array_passes_validation(): void
    {
        $response = $this->postSeoSettings([
            'bot_user_agents' => ['Googlebot', 'Bingbot', 'Yandex'],
        ]);

        $response->assertStatus(200);
    }

    /**
     * bot_user_agents 빈 배열 전송 시 유효성 검증 통과
     */
    public function test_bot_user_agents_empty_array_passes_validation(): void
    {
        $response = $this->postSeoSettings([
            'bot_user_agents' => [],
        ]);

        $response->assertStatus(200);
    }

    /**
     * bot_user_agents 비배열 전송 시 422 응답
     */
    public function test_bot_user_agents_non_array_fails_validation(): void
    {
        $response = $this->postSeoSettings([
            'bot_user_agents' => 'Googlebot',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.bot_user_agents']);
    }

    /**
     * bot_user_agents 요소가 100자 초과 시 422 응답
     */
    public function test_bot_user_agents_element_exceeding_100_chars_fails(): void
    {
        $response = $this->postSeoSettings([
            'bot_user_agents' => [str_repeat('a', 101)],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.bot_user_agents.0']);
    }

    // ========================================
    // cache 관련 검증 테스트
    // ========================================

    /**
     * cache_enabled boolean 전송 시 유효성 검증 통과
     */
    public function test_cache_enabled_boolean_passes_validation(): void
    {
        $response = $this->postSeoSettings([
            'cache_enabled' => true,
        ]);

        $response->assertStatus(200);
    }

    /**
     * cache_ttl 범위 내 값 전송 시 유효성 검증 통과
     */
    public function test_cache_ttl_in_range_passes_validation(): void
    {
        $response = $this->postSeoSettings([
            'cache_ttl' => 3600,
        ]);

        $response->assertStatus(200);
    }

    /**
     * cache_ttl 최소값 미만 시 422 응답
     */
    public function test_cache_ttl_below_minimum_fails_validation(): void
    {
        $response = $this->postSeoSettings([
            'cache_ttl' => 59,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.cache_ttl']);
    }

    /**
     * cache_ttl 최대값 초과 시 422 응답
     */
    public function test_cache_ttl_above_maximum_fails_validation(): void
    {
        $response = $this->postSeoSettings([
            'cache_ttl' => 86401,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.cache_ttl']);
    }

    // ========================================
    // sitemap 관련 검증 테스트
    // ========================================

    /**
     * sitemap_schedule 유효 값(daily) 전송 시 검증 통과
     */
    public function test_sitemap_schedule_valid_value_passes_validation(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_schedule' => 'daily',
        ]);

        $response->assertStatus(200);
    }

    /**
     * sitemap_schedule 유효하지 않은 값(monthly) 전송 시 422 응답
     */
    public function test_sitemap_schedule_invalid_value_fails_validation(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_schedule' => 'monthly',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.sitemap_schedule']);
    }

    /**
     * sitemap_schedule_time 유효 형식(02:00) 전송 시 검증 통과
     */
    public function test_sitemap_schedule_time_valid_format_passes(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_schedule_time' => '02:00',
        ]);

        $response->assertStatus(200);
    }

    /**
     * sitemap_schedule_time 유효하지 않은 형식(2:00) 전송 시 422 응답
     */
    public function test_sitemap_schedule_time_invalid_format_fails(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_schedule_time' => '2:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.sitemap_schedule_time']);
    }

    // ========================================
    // sitemap_cache_ttl 검증 테스트
    // ========================================

    /**
     * sitemap_cache_ttl 범위 내 값 전송 시 유효성 검증 통과
     */
    public function test_sitemap_cache_ttl_in_range_passes_validation(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_cache_ttl' => 86400,
        ]);

        $response->assertStatus(200);
    }

    /**
     * sitemap_cache_ttl 최소값 미만 시 422 응답
     */
    public function test_sitemap_cache_ttl_below_minimum_fails_validation(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_cache_ttl' => 3599,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.sitemap_cache_ttl']);
    }

    /**
     * sitemap_cache_ttl 최대값 초과 시 422 응답
     */
    public function test_sitemap_cache_ttl_above_maximum_fails_validation(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_cache_ttl' => 604801,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.sitemap_cache_ttl']);
    }

    // ========================================
    // bot_detection_enabled 검증 테스트
    // ========================================

    /**
     * bot_detection_enabled boolean 전송 시 유효성 검증 통과
     */
    public function test_bot_detection_enabled_boolean_passes_validation(): void
    {
        $response = $this->postSeoSettings([
            'bot_detection_enabled' => false,
        ]);

        $response->assertStatus(200);
    }

    // ========================================
    // sitemap_enabled 검증 테스트
    // ========================================

    /**
     * sitemap_enabled boolean 전송 시 유효성 검증 통과
     */
    public function test_sitemap_enabled_boolean_passes_validation(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_enabled' => true,
        ]);

        $response->assertStatus(200);
    }

    // ========================================
    // generator 메타 태그 검증 테스트
    // ========================================

    /**
     * generator_enabled boolean 전송 시 유효성 검증 통과
     */
    public function test_generator_enabled_boolean_passes_validation(): void
    {
        $response = $this->postSeoSettings([
            'generator_enabled' => false,
        ]);

        $response->assertStatus(200);
    }

    /**
     * generator_content 200자 이내 전송 시 유효성 검증 통과
     */
    public function test_generator_content_within_limit_passes_validation(): void
    {
        $response = $this->postSeoSettings([
            'generator_enabled' => true,
            'generator_content' => 'GnuBoard7',
        ]);

        $response->assertStatus(200);
    }

    /**
     * generator_content 200자 초과 전송 시 422 응답
     */
    public function test_generator_content_exceeding_limit_fails_validation(): void
    {
        $response = $this->postSeoSettings([
            'generator_content' => str_repeat('a', 201),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.generator_content']);
    }
}
