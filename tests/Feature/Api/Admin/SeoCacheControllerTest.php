<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\SitemapGenerationMode;
use App\Jobs\GenerateSitemapJob;
use App\Seo\SitemapProgress;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SeoCacheStat;
use App\Models\User;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SitemapManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * SeoCacheController 테스트
 *
 * SEO 캐시 관리 API 엔드포인트를 테스트합니다.
 * - 통계 조회 (stats)
 * - 캐시 삭제 (clearCache)
 * - 캐시 워밍업 (warmup)
 * - 캐시된 URL 목록 (cachedUrls)
 */
class SeoCacheControllerTest extends TestCase
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
     * @return User 생성된 관리자 사용자
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

    // ========================================================================
    // 인증/권한 테스트
    // ========================================================================

    /**
     * 인증 없이 SEO 캐시 통계 조회 시 401 반환
     */
    public function test_stats_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/seo/stats');

        $response->assertStatus(401);
    }

    /**
     * 권한 없이 SEO 캐시 통계 조회 시 403 반환
     */
    public function test_stats_returns_403_without_permission(): void
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

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/seo/stats');

        $response->assertStatus(403);
    }

    /**
     * read 권한만 있는 관리자가 POST 엔드포인트 호출 시 403 반환
     */
    public function test_clear_cache_returns_403_without_update_permission(): void
    {
        // read 권한만 있는 관리자 생성
        $user = $this->createAdminUser(['core.settings.read']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/seo/clear-cache');

        $response->assertStatus(403);
    }

    // ========================================================================
    // 통계 조회 테스트 (stats)
    // ========================================================================

    /**
     * SEO 캐시 통계 조회 성공
     */
    public function test_stats_returns_200_with_stats_data(): void
    {
        // 테스트용 통계 데이터 생성
        SeoCacheStat::create([
            'url' => '/',
            'locale' => 'ko',
            'layout_name' => 'home',
            'module_identifier' => null,
            'type' => 'hit',
        ]);

        SeoCacheStat::create([
            'url' => '/shop/products',
            'locale' => 'ko',
            'layout_name' => 'products',
            'module_identifier' => 'sirsoft-ecommerce',
            'type' => 'miss',
            'response_time_ms' => 150,
        ]);

        $response = $this->withToken($this->token)->getJson('/api/admin/seo/stats');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'overall' => [
                        'total_entries',
                        'hits',
                        'misses',
                        'hit_rate',
                        'avg_response_time_ms',
                    ],
                    'by_layout',
                    'by_module',
                ],
            ]);
    }

    /**
     * 통계 데이터가 없을 때도 정상 응답
     */
    public function test_stats_returns_200_with_empty_data(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/admin/seo/stats');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'overall' => [
                        'total_entries' => 0,
                        'hits' => 0,
                        'misses' => 0,
                        'hit_rate' => 0.0,
                    ],
                    'by_layout' => [],
                    'by_module' => [],
                ],
            ]);
    }

    // ========================================================================
    // 캐시 삭제 테스트 (clearCache)
    // ========================================================================

    /**
     * 파라미터 없이 전체 캐시 삭제 성공
     */
    public function test_clear_cache_clears_all_without_params(): void
    {
        $mock = Mockery::mock(SeoCacheManagerInterface::class);
        $mock->shouldReceive('clearAll')->once();
        $this->app->instance(SeoCacheManagerInterface::class, $mock);

        $response = $this->withToken($this->token)->postJson('/api/admin/seo/clear-cache');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'cleared' => 'all',
                ],
            ]);
    }

    /**
     * layout 파라미터 지정 시 해당 레이아웃 캐시만 삭제 성공
     */
    public function test_clear_cache_clears_specific_layout(): void
    {
        $mock = Mockery::mock(SeoCacheManagerInterface::class);
        $mock->shouldReceive('invalidateByLayout')
            ->with('home')
            ->once()
            ->andReturn(5);
        $this->app->instance(SeoCacheManagerInterface::class, $mock);

        $response = $this->withToken($this->token)->postJson('/api/admin/seo/clear-cache', [
            'layout' => 'home',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'cleared' => 5,
                ],
            ]);
    }

    // ========================================================================
    // 캐시 워밍업 테스트 (warmup)
    // ========================================================================

    /**
     * 캐시 워밍업 요청 시 dispatched 상태 반환
     */
    public function test_warmup_returns_dispatched_status(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/admin/seo/warmup');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'dispatched',
                ],
            ]);
    }

    /**
     * 인증 없이 워밍업 요청 시 401 반환
     */
    public function test_warmup_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/seo/warmup');

        $response->assertStatus(401);
    }

    // ========================================================================
    // 캐시된 URL 목록 테스트 (cachedUrls)
    // ========================================================================

    /**
     * 캐시된 URL 목록 조회 성공
     */
    public function test_cached_urls_returns_url_list(): void
    {
        $expectedUrls = ['/', '/shop/products', '/about'];

        $mock = Mockery::mock(SeoCacheManagerInterface::class);
        $mock->shouldReceive('getCachedUrls')
            ->once()
            ->andReturn($expectedUrls);
        $this->app->instance(SeoCacheManagerInterface::class, $mock);

        $response = $this->withToken($this->token)->getJson('/api/admin/seo/cached-urls');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'urls' => $expectedUrls,
                    'count' => 3,
                ],
            ]);
    }

    /**
     * 캐시된 URL이 없을 때 빈 목록 반환
     */
    public function test_cached_urls_returns_empty_list(): void
    {
        $mock = Mockery::mock(SeoCacheManagerInterface::class);
        $mock->shouldReceive('getCachedUrls')
            ->once()
            ->andReturn([]);
        $this->app->instance(SeoCacheManagerInterface::class, $mock);

        $response = $this->withToken($this->token)->getJson('/api/admin/seo/cached-urls');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'urls' => [],
                    'count' => 0,
                ],
            ]);
    }

    /**
     * 인증 없이 캐시된 URL 목록 조회 시 401 반환
     */
    public function test_cached_urls_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/seo/cached-urls');

        $response->assertStatus(401);
    }

    // ========================================================================
    // Sitemap 수동 재생성 (regenerateSitemap)
    // ========================================================================

    /**
     * 인증 없이 sitemap 재생성 호출 시 401 반환
     */
    public function test_regenerate_sitemap_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/seo/sitemap/regenerate');

        $response->assertStatus(401);
    }

    /**
     * 재생성 요청 시 200 + 진행상황(queued)과 직전 생성 시각을 반환
     */
    public function test_regenerate_sitemap_returns_200_with_queued_progress(): void
    {
        Queue::fake();
        Config::set('g7_settings.core.seo.sitemap_enabled', true);
        Config::set('g7_settings.core.seo.sitemap_last_updated_at', '2026-04-29T10:00:00+00:00');
        // realtime_enabled 판정을 결정적으로: 웹소켓 OFF → false
        Config::set('broadcasting.default', 'null');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/seo/sitemap/regenerate');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertSame('queued', $response->json('data.progress.status'));
        $this->assertSame('full', $response->json('data.progress.mode'));
        $this->assertSame('2026-04-29T10:00:00+00:00', $response->json('data.last_updated_at'));
        $this->assertFalse($response->json('data.realtime_enabled'));
    }

    /**
     * 재생성 요청이 큐 잡을 1건만, 항상 전체(Full) 모드로 디스패치하는지 확인
     *
     * 관리자 수동 재생성은 현 생성 상태와 무관하게 항상 전량 재생성입니다(D7).
     */
    public function test_regenerate_sitemap_dispatches_full_mode_job(): void
    {
        Queue::fake();
        Config::set('g7_settings.core.seo.sitemap_enabled', true);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/seo/sitemap/regenerate')
            ->assertStatus(200);

        Queue::assertPushed(GenerateSitemapJob::class, 1);
        Queue::assertPushed(
            GenerateSitemapJob::class,
            fn (GenerateSitemapJob $job) => $job->mode === SitemapGenerationMode::Full
        );
    }

    /**
     * 재생성 요청이 요청 스레드에서 동기 생성하지 않는지 확인
     *
     * 이 회귀가 무너지면 1.4M 규모에서 관리자 워커가 OOM 으로 죽습니다.
     */
    public function test_regenerate_sitemap_does_not_generate_inline(): void
    {
        Queue::fake();
        Config::set('g7_settings.core.seo.sitemap_enabled', true);

        $mock = Mockery::mock(SitemapManager::class);
        $mock->shouldNotReceive('regenerate');
        $mock->shouldReceive('getStatus')->andReturn(['last_updated_at' => null]);
        $this->app->instance(SitemapManager::class, $mock);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/seo/sitemap/regenerate')
            ->assertStatus(200);
    }

    /**
     * Sitemap 비활성 상태 시 400 반환 + 잡 미디스패치
     */
    public function test_regenerate_sitemap_returns_400_when_disabled(): void
    {
        Queue::fake();
        Config::set('g7_settings.core.seo.sitemap_enabled', false);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/seo/sitemap/regenerate');

        $response->assertStatus(400);
        $this->assertFalse($response->json('success'));
        Queue::assertNotPushed(GenerateSitemapJob::class);
    }

    // ========================================================================
    // Sitemap 진행상황 조회 (sitemapStatus)
    // ========================================================================

    /**
     * 인증 없이 sitemap 상태 조회 시 401 반환
     */
    public function test_sitemap_status_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/seo/sitemap/status');

        $response->assertStatus(401);
    }

    /**
     * read 권한만 있어도 sitemap 상태 조회 가능 (SEO 탭 읽기 권한)
     */
    public function test_sitemap_status_allows_read_permission(): void
    {
        $user = $this->createAdminUser(['core.settings.read']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/seo/sitemap/status');

        $response->assertStatus(200);
    }

    /**
     * 상태 조회 응답이 진행상황 + realtime_enabled 스키마를 포함
     */
    public function test_sitemap_status_returns_progress_and_realtime_flag(): void
    {
        // 진행상황을 미리 기록
        app(SitemapProgress::class)->start(SitemapGenerationMode::Full->value);
        // realtime_enabled 판정을 결정적으로: 웹소켓 OFF → false
        Config::set('broadcasting.default', 'null');

        $response = $this->withToken($this->token)->getJson('/api/admin/seo/sitemap/status');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'last_updated_at',
                    'progress' => ['status', 'mode'],
                    'realtime_enabled',
                ],
            ]);
        $this->assertSame('queued', $response->json('data.progress.status'));
        $this->assertFalse($response->json('data.realtime_enabled'));
    }

    /**
     * 진행상황이 없으면 progress 는 null
     */
    public function test_sitemap_status_returns_null_progress_when_idle(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/admin/seo/sitemap/status');

        $response->assertStatus(200);
        $this->assertNull($response->json('data.progress'));
    }

    /**
     * 재생성 요청이 진행상황을 'queued' 로 즉시 기록 (큐 대기 중에도 UI 표시)
     */
    public function test_regenerate_sitemap_records_queued_progress(): void
    {
        Queue::fake();
        Config::set('g7_settings.core.seo.sitemap_enabled', true);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/seo/sitemap/regenerate')
            ->assertStatus(200);

        $progress = app(SitemapProgress::class)->get();
        $this->assertNotNull($progress);
        $this->assertSame('queued', $progress['status']);
        $this->assertSame('full', $progress['mode']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
