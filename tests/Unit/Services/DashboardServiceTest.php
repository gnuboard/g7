<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Services\DashboardService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Mockery;
use Tests\TestCase;

/**
 * DashboardService 단위 테스트
 */
class DashboardServiceTest extends TestCase
{
    private DashboardService $service;

    private $userRepository;

    private $moduleManager;

    private $pluginManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = Mockery::mock(UserRepositoryInterface::class);
        $this->moduleManager = Mockery::mock(ModuleManagerInterface::class);
        $this->pluginManager = Mockery::mock(PluginManagerInterface::class);

        $this->service = new DashboardService(
            $this->userRepository,
            $this->moduleManager,
            $this->pluginManager
        );
    }

    /**
     * 기본 사용자 통계 mock을 설정합니다.
     */
    private function mockUserStatistics(int $totalUsers = 100, int $usersThisMonth = 10): void
    {
        $this->userRepository->shouldReceive('getStatistics')->andReturn([
            'total_users' => $totalUsers,
            'users_this_month' => $usersThisMonth,
            'users_this_week' => 5,
            'users_today' => 1,
            'active_users_this_week' => 50,
        ]);
    }

    /**
     * 기본 모듈 mock을 설정합니다.
     */
    private function mockModules(array $all = [], array $active = []): void
    {
        $this->moduleManager->shouldReceive('getAllModules')->andReturn($all);
        $this->moduleManager->shouldReceive('getActiveModules')->andReturn($active);
    }

    /**
     * 기본 플러그인 mock을 설정합니다.
     */
    private function mockPlugins(array $all = [], array $active = []): void
    {
        $this->pluginManager->shouldReceive('getAllPlugins')->andReturn($all);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn($active);
    }

    // ========================================================================
    // getStats 테스트
    // ========================================================================

    public function test_get_stats_returns_correct_structure(): void
    {
        $this->mockUserStatistics(100, 90);
        $this->mockModules(
            [(object) ['identifier' => 'module1'], (object) ['identifier' => 'module2']],
            [(object) ['identifier' => 'module1']]
        );
        $this->mockPlugins(
            [(object) ['identifier' => 'plugin1'], (object) ['identifier' => 'plugin2'], (object) ['identifier' => 'plugin3']],
            [(object) ['identifier' => 'plugin1'], (object) ['identifier' => 'plugin2']]
        );

        $stats = $this->service->getStats();

        $this->assertArrayHasKey('total_users', $stats);
        $this->assertArrayHasKey('installed_modules', $stats);
        $this->assertArrayHasKey('active_plugins', $stats);
        $this->assertArrayHasKey('system_status', $stats);
    }

    public function test_get_stats_calculates_change_percent_correctly_when_increased(): void
    {
        // 전체 100명, 이번달 20명 가입 → 전월 80명 대비 25% 증가
        $this->mockUserStatistics(100, 20);
        $this->mockModules();
        $this->mockPlugins();

        $stats = $this->service->getStats();

        $this->assertEquals(25, $stats['total_users']['change_percent']);
        $this->assertEquals('up', $stats['total_users']['trend']);
    }

    public function test_get_stats_shows_up_trend_when_new_users_exist(): void
    {
        // 전체 80명, 이번달 10명 가입
        $this->mockUserStatistics(80, 10);
        $this->mockModules();
        $this->mockPlugins();

        $stats = $this->service->getStats();

        $this->assertEquals('up', $stats['total_users']['trend']);
        $this->assertEquals('+10', $stats['total_users']['change_display']);
    }

    public function test_get_stats_handles_zero_new_users(): void
    {
        // 이번달 신규 가입자 0명일 때
        $this->mockUserStatistics(100, 0);
        $this->mockModules();
        $this->mockPlugins();

        $stats = $this->service->getStats();

        // 신규 가입자 0명이면 0%
        $this->assertEquals(0, $stats['total_users']['change_percent']);
        $this->assertEquals('up', $stats['total_users']['trend']);
    }

    public function test_get_stats_returns_module_counts(): void
    {
        $this->mockUserStatistics(50, 50);
        $this->mockModules(
            [(object) ['identifier' => 'module1'], (object) ['identifier' => 'module2'], (object) ['identifier' => 'module3']],
            [(object) ['identifier' => 'module1'], (object) ['identifier' => 'module2']]
        );
        $this->mockPlugins();

        $stats = $this->service->getStats();

        $this->assertEquals(3, $stats['installed_modules']['total']);
        $this->assertEquals(2, $stats['installed_modules']['active']);
    }

    public function test_get_stats_returns_plugin_counts(): void
    {
        $this->mockUserStatistics(50, 50);
        $this->mockModules();
        $this->mockPlugins(
            [(object) ['identifier' => 'plugin1'], (object) ['identifier' => 'plugin2'], (object) ['identifier' => 'plugin3'], (object) ['identifier' => 'plugin4']],
            [(object) ['identifier' => 'plugin1'], (object) ['identifier' => 'plugin2'], (object) ['identifier' => 'plugin3']]
        );

        $stats = $this->service->getStats();

        $this->assertEquals(4, $stats['active_plugins']['total']);
        $this->assertEquals(3, $stats['active_plugins']['active']);
    }

    // ========================================================================
    // getSystemResources 테스트
    // ========================================================================

    /**
     * 시스템 리소스 조회를 위한 부분 모킹된 서비스를 생성합니다.
     *
     * 실제 시스템 명령(PowerShell, wmic 등) 호출을 피하고
     * 테스트 성능을 향상시키기 위해 모킹된 값을 반환합니다.
     *
     * @return DashboardService 모킹된 서비스 인스턴스
     */
    private function createMockedResourceService(): DashboardService
    {
        $service = Mockery::mock(DashboardService::class, [
            $this->userRepository,
            $this->moduleManager,
            $this->pluginManager,
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        // CPU 조회 모킹 (실제 시스템 명령 호출 방지)
        $service->shouldReceive('getCpuUsage')->andReturn([
            'percentage' => 45,
            'color' => 'green',
        ]);

        // 메모리 조회 모킹 (실제 시스템 명령 호출 방지)
        $service->shouldReceive('getMemoryUsage')->andReturn([
            'percentage' => 60,
            'used' => '8.5 GB',
            'total' => '16 GB',
            'color' => 'blue',
        ]);

        // 디스크 조회 모킹 (disk_total_space, disk_free_space는 빠르지만 일관성을 위해 모킹)
        $service->shouldReceive('getDiskUsage')->andReturn([
            'percentage' => 75,
            'used' => '300 GB',
            'total' => '500 GB',
            'color' => 'yellow',
        ]);

        return $service;
    }

    public function test_get_system_resources_returns_correct_structure(): void
    {
        $service = $this->createMockedResourceService();
        $resources = $service->getSystemResources();

        $this->assertArrayHasKey('cpu', $resources);
        $this->assertArrayHasKey('memory', $resources);
        $this->assertArrayHasKey('disk', $resources);
    }

    public function test_get_system_resources_returns_valid_cpu_data(): void
    {
        $service = $this->createMockedResourceService();
        $resources = $service->getSystemResources();

        $this->assertArrayHasKey('percentage', $resources['cpu']);
        $this->assertArrayHasKey('color', $resources['cpu']);
        $this->assertGreaterThanOrEqual(0, $resources['cpu']['percentage']);
        $this->assertLessThanOrEqual(100, $resources['cpu']['percentage']);
    }

    public function test_get_system_resources_returns_valid_memory_data(): void
    {
        $service = $this->createMockedResourceService();
        $resources = $service->getSystemResources();

        $this->assertArrayHasKey('percentage', $resources['memory']);
        $this->assertArrayHasKey('used', $resources['memory']);
        $this->assertArrayHasKey('total', $resources['memory']);
        $this->assertArrayHasKey('color', $resources['memory']);
    }

    public function test_get_system_resources_returns_valid_disk_data(): void
    {
        $service = $this->createMockedResourceService();
        $resources = $service->getSystemResources();

        $this->assertArrayHasKey('percentage', $resources['disk']);
        $this->assertArrayHasKey('used', $resources['disk']);
        $this->assertArrayHasKey('total', $resources['disk']);
        $this->assertArrayHasKey('color', $resources['disk']);
    }

    // ========================================================================
    // getRecentActivities 테스트
    // ========================================================================

    public function test_get_recent_activities_returns_array(): void
    {
        $this->userRepository->shouldReceive('getRecentUsers')
            ->with(10)
            ->andReturn(new EloquentCollection([]));

        $activities = $this->service->getRecentActivities();

        $this->assertIsArray($activities);
    }

    public function test_get_recent_activities_formats_user_registration(): void
    {
        $mockUser = Mockery::mock();
        $mockUser->name = 'Test User';
        $mockUser->email = 'test@example.com';
        $mockUser->created_at = now()->subMinutes(5);

        $this->userRepository->shouldReceive('getRecentUsers')
            ->with(10)
            ->andReturn(new EloquentCollection([$mockUser]));

        $activities = $this->service->getRecentActivities();

        $this->assertCount(1, $activities);
        $this->assertArrayHasKey('title', $activities[0]);
        $this->assertArrayHasKey('description', $activities[0]);
        $this->assertArrayHasKey('time', $activities[0]);
        $this->assertArrayHasKey('type', $activities[0]);
    }

    // ========================================================================
    // getSystemAlerts 테스트
    // ========================================================================

    public function test_get_system_alerts_returns_array(): void
    {
        $alerts = $this->service->getSystemAlerts();

        $this->assertIsArray($alerts);
    }

    public function test_get_system_alerts_items_have_required_fields(): void
    {
        $alerts = $this->service->getSystemAlerts();

        foreach ($alerts as $alert) {
            $this->assertArrayHasKey('id', $alert);
            $this->assertArrayHasKey('title', $alert);
            $this->assertArrayHasKey('message', $alert);
            $this->assertArrayHasKey('time', $alert);
            $this->assertArrayHasKey('type', $alert);
            $this->assertArrayHasKey('icon', $alert);
            $this->assertArrayHasKey('read', $alert);
        }
    }

    public function test_get_system_alerts_returns_valid_types(): void
    {
        $alerts = $this->service->getSystemAlerts();
        $validTypes = ['info', 'warning', 'success', 'error'];

        foreach ($alerts as $alert) {
            $this->assertContains($alert['type'], $validTypes);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
