<?php

namespace Tests\Feature\Seo;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
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
     * 저장된 설정 카테고리를 디스크에서 다시 읽습니다.
     *
     * g7_core_settings() 는 부팅 시점에 로드된 Config 를 보므로, 같은 프로세스에서
     * 저장 직후 값을 확인할 때는 저장소를 직접 읽어야 합니다.
     *
     * @param  string  $category  설정 카테고리
     * @return array 저장된 설정 배열
     */
    private function storedSettings(string $category): array
    {
        return app(ConfigRepositoryInterface::class)->getCategory($category);
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
    private function postSeoSettings(array $seoData): TestResponse
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

    // ========================================
    // sitemap 분할/압축/서빙 설정 검증 테스트
    // ========================================

    /**
     * sitemap 분할/압축/서빙 설정이 저장되어 설정값에 반영되는지 확인
     */
    public function test_sitemap_generation_settings_are_saved(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_urls_per_file' => 20000,
            'sitemap_gzip' => true,
            'sitemap_serve_stale_on_miss' => false,
            'sitemap_max_urls_per_contributor' => 100000,
        ]);

        $response->assertStatus(200);

        $seo = $this->storedSettings('seo');
        $this->assertSame(20000, $seo['sitemap_urls_per_file']);
        $this->assertTrue((bool) $seo['sitemap_gzip']);
        $this->assertFalse((bool) $seo['sitemap_serve_stale_on_miss']);
        $this->assertSame(100000, $seo['sitemap_max_urls_per_contributor']);
    }

    /**
     * 파일당 URL 수 상한(50000)을 넘기면 422 응답
     *
     * 50000 은 sitemaps.org 프로토콜 상한이므로 저장 자체를 막습니다.
     */
    public function test_sitemap_urls_per_file_exceeding_protocol_limit_fails(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_urls_per_file' => 50001,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.sitemap_urls_per_file']);
    }

    /**
     * 파일당 URL 수 하한(1000) 미만이면 422 응답
     */
    public function test_sitemap_urls_per_file_below_minimum_fails(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_urls_per_file' => 999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.sitemap_urls_per_file']);
    }

    /**
     * 파일당 URL 수 경계값(1000/50000)은 통과하는지 확인
     */
    public function test_sitemap_urls_per_file_boundary_values_pass(): void
    {
        $this->postSeoSettings(['sitemap_urls_per_file' => 1000])->assertStatus(200);
        $this->postSeoSettings(['sitemap_urls_per_file' => 50000])->assertStatus(200);
    }

    /**
     * 수집기당 최대 URL 수는 0(무제한)을 허용하고 음수는 거부하는지 확인
     */
    public function test_sitemap_max_urls_per_contributor_allows_zero_and_rejects_negative(): void
    {
        $this->postSeoSettings(['sitemap_max_urls_per_contributor' => 0])->assertStatus(200);

        $this->postSeoSettings(['sitemap_max_urls_per_contributor' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['seo.sitemap_max_urls_per_contributor']);
    }

    /**
     * gzip 이 boolean 이 아니면 422 응답
     */
    public function test_sitemap_gzip_non_boolean_fails_validation(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_gzip' => 'yes-please',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.sitemap_gzip']);
    }

    /**
     * hreflang 사용 여부가 boolean 으로 저장되는지 확인
     */
    public function test_sitemap_hreflang_enabled_is_saved(): void
    {
        $this->postSeoSettings(['sitemap_hreflang_enabled' => false])->assertStatus(200);

        $seo = $this->storedSettings('seo');
        $this->assertFalse((bool) $seo['sitemap_hreflang_enabled']);
    }

    /**
     * hreflang 사용 여부가 boolean 이 아니면 422 응답
     */
    public function test_sitemap_hreflang_enabled_non_boolean_fails_validation(): void
    {
        $response = $this->postSeoSettings([
            'sitemap_hreflang_enabled' => 'maybe',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seo.sitemap_hreflang_enabled']);
    }

    // ========================================
    // 고급 탭 Sitemap 캐시 기준값 (D19 의 메인 값)
    // ========================================

    /**
     * 고급 탭 설정 저장 요청을 전송합니다.
     *
     * @param  array  $advancedData  고급 필드 데이터
     */
    private function postAdvancedSettings(array $advancedData): TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson('/api/admin/settings', [
                '_tab' => 'advanced',
                'advanced' => $advancedData,
            ]);
    }

    /**
     * 고급 탭의 Sitemap 캐시 기준값이 저장되어 cache 카테고리에 반영되는지 확인
     */
    public function test_advanced_sitemap_cache_ttl_is_saved_to_cache_category(): void
    {
        $response = $this->postAdvancedSettings([
            'cache_enabled' => true,
            'layout_cache_enabled' => true,
            'layout_cache_ttl' => 3600,
            'stats_cache_enabled' => true,
            'stats_cache_ttl' => 3600,
            'seo_cache_enabled' => true,
            'seo_cache_ttl' => 3600,
            'seo_sitemap_cache_ttl' => 43200,
            'debug_mode' => false,
            'sql_query_log' => false,
        ]);

        $response->assertStatus(200);
        $this->assertSame(43200, $this->storedSettings('cache')['seo_sitemap_ttl']);
    }

    /**
     * 고급 탭 Sitemap 캐시 기준값이 기본값(86400)을 허용하는지 확인
     *
     * 형제 캐시 TTL 규칙(최대 14400)을 그대로 재사용하면 화면 기본값조차 저장할 수 없게 됩니다.
     */
    public function test_advanced_sitemap_cache_ttl_accepts_default_value(): void
    {
        $this->postAdvancedSettings([
            'cache_enabled' => true,
            'layout_cache_enabled' => true,
            'layout_cache_ttl' => 3600,
            'stats_cache_enabled' => true,
            'stats_cache_ttl' => 3600,
            'seo_cache_enabled' => true,
            'seo_cache_ttl' => 3600,
            'seo_sitemap_cache_ttl' => 86400,
            'debug_mode' => false,
            'sql_query_log' => false,
        ])->assertStatus(200);

        $this->assertSame(86400, $this->storedSettings('cache')['seo_sitemap_ttl']);
    }

    /**
     * 고급 탭 Sitemap 캐시 기준값의 허용 범위를 벗어나면 422 응답
     */
    public function test_advanced_sitemap_cache_ttl_out_of_range_fails(): void
    {
        $this->postAdvancedSettings(['seo_sitemap_cache_ttl' => 604801])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['advanced.seo_sitemap_cache_ttl']);

        $this->postAdvancedSettings(['seo_sitemap_cache_ttl' => 3599])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['advanced.seo_sitemap_cache_ttl']);
    }

    /**
     * 신규 키를 보내지 않는 기존 클라이언트의 고급 탭 저장이 계속 동작하는지 확인
     *
     * 형제 키처럼 required 로 두면 이전 화면/외부 클라이언트의 저장이 422 로 깨집니다.
     */
    public function test_advanced_tab_saves_without_new_sitemap_cache_key(): void
    {
        $this->postAdvancedSettings([
            'cache_enabled' => true,
            'layout_cache_enabled' => true,
            'layout_cache_ttl' => 3600,
            'stats_cache_enabled' => true,
            'stats_cache_ttl' => 3600,
            'seo_cache_enabled' => true,
            'seo_cache_ttl' => 3600,
            'debug_mode' => false,
            'sql_query_log' => false,
        ])->assertStatus(200);
    }

    /**
     * 두 탭의 SEO 캐시 TTL 안내 문구가 각자의 실제 한도를 알려주는지 확인
     *
     * 두 탭이 같은 다국어 키를 공유하면 PHP 배열의 나중 정의가 이겨서
     * 한쪽 탭이 반드시 틀린 한도를 안내하게 됩니다(ko/en 이 서로 반대로 틀어졌던 사례).
     */
    public function test_seo_cache_ttl_messages_state_each_tabs_own_limit(): void
    {
        $seoTabErrors = $this->postSeoSettings(['cache_ttl' => 86401])
            ->assertStatus(422)
            ->json('errors');

        $advancedTabErrors = $this->postAdvancedSettings(['seo_cache_ttl' => 14401])
            ->assertStatus(422)
            ->json('errors');

        $this->assertStringContainsString('86400', $seoTabErrors['seo.cache_ttl'][0]);
        $this->assertStringContainsString('14400', $advancedTabErrors['advanced.seo_cache_ttl'][0]);
    }

    // ========================================
    // 캐시 오버라이드 칸 비우기 (D19)
    // ========================================

    /**
     * 캐시 오버라이드 칸을 비워서 저장하면 null(미설정)로 정규화되는지 확인
     *
     * 폼에서 칸을 비우면 빈 문자열로 전송될 수 있는데, 이것이 null 로 정규화되지 않으면
     * "미설정" 으로 판정되지 않아 고급 설정 값을 따르지 못하고 오버라이드가 계속 발동합니다.
     */
    public function test_emptied_cache_override_fields_are_normalized_to_null(): void
    {
        $this->postSeoSettings([
            'cache_ttl' => 3600,
            'sitemap_cache_ttl' => 7200,
        ])->assertStatus(200);

        $seo = $this->storedSettings('seo');
        $this->assertSame(3600, $seo['cache_ttl']);
        $this->assertSame(7200, $seo['sitemap_cache_ttl']);

        $this->postSeoSettings([
            'cache_ttl' => '',
            'sitemap_cache_ttl' => '',
        ])->assertStatus(200);

        $seo = $this->storedSettings('seo');
        $this->assertNull($seo['cache_ttl']);
        $this->assertNull($seo['sitemap_cache_ttl']);
    }
}
