<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\ActivityLogRepositoryInterface;
use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\NotificationLogRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\ActivityLogType;
use App\Enums\NotificationLogStatus;
use App\Models\ActivityLog;
use App\Models\NotificationLog;
use App\Services\DashboardService;
use App\Services\LanguagePackService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * DashboardService 단위 테스트
 */
class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;

    private $userRepository;

    private $activityLogRepository;

    private $moduleManager;

    private $pluginManager;

    private $notificationLogRepository;

    private $templateManager;

    private $languagePackRepository;

    private $languagePackService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = Mockery::mock(UserRepositoryInterface::class);
        $this->activityLogRepository = Mockery::mock(ActivityLogRepositoryInterface::class);
        $this->moduleManager = Mockery::mock(ModuleManagerInterface::class);
        $this->pluginManager = Mockery::mock(PluginManagerInterface::class);
        $this->notificationLogRepository = Mockery::mock(NotificationLogRepositoryInterface::class);
        $this->templateManager = Mockery::mock(TemplateManagerInterface::class);
        $this->languagePackRepository = Mockery::mock(LanguagePackRepositoryInterface::class);
        $this->languagePackService = Mockery::mock(LanguagePackService::class);

        $this->service = new DashboardService(
            $this->userRepository,
            $this->activityLogRepository,
            $this->moduleManager,
            $this->pluginManager,
            $this->notificationLogRepository,
            $this->templateManager,
            $this->languagePackRepository,
            $this->languagePackService
        );

        // getStats() 가 항상 호출하는 템플릿/언어팩 의존성의 기본 mock (개별 테스트에서 재정의 가능).
        $this->mockTemplatesAndPacks();
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

    /**
     * 템플릿/언어팩 mock을 설정합니다.
     *
     * @param  array  $allTemplates  전체 템플릿 목록
     * @param  array<string, bool>  $activeByType  타입별 활성 여부 (admin/user)
     * @param  int  $activePacks  활성 언어팩 수
     * @param  int  $uninstalledBundledPacks  미설치 번들 언어팩 수
     */
    private function mockTemplatesAndPacks(
        array $allTemplates = [],
        array $activeByType = ['admin' => false, 'user' => false],
        int $activePacks = 0,
        int $uninstalledBundledPacks = 0,
    ): void {
        // byDefault() — setUp 의 기본 mock 을 개별 테스트가 재호출로 덮어쓸 수 있게 한다.
        $this->templateManager->shouldReceive('getAllTemplates')->andReturn($allTemplates)->byDefault();
        foreach (['admin', 'user'] as $type) {
            $this->templateManager->shouldReceive('getActiveTemplate')->with($type)
                ->andReturn(($activeByType[$type] ?? false) ? ['identifier' => "tpl-{$type}"] : null)->byDefault();
        }
        $this->languagePackRepository->shouldReceive('getActivePacks')
            ->andReturn(new EloquentCollection(array_fill(0, $activePacks, (object) [])))->byDefault();
        $this->languagePackService->shouldReceive('getUninstalledBundledPacks')
            ->andReturn(collect(array_fill(0, $uninstalledBundledPacks, (object) [])))->byDefault();
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
        $this->assertArrayHasKey('installed_templates', $stats);
        $this->assertArrayHasKey('language_packs', $stats);
        $this->assertArrayHasKey('system_status', $stats);
    }

    public function test_get_stats_returns_template_counts(): void
    {
        $this->mockUserStatistics();
        $this->mockModules();
        $this->mockPlugins();
        // 전체 템플릿 2개, admin/user 둘 다 활성 → 2/2
        $this->mockTemplatesAndPacks(
            allTemplates: [['identifier' => 'a'], ['identifier' => 'b']],
            activeByType: ['admin' => true, 'user' => true],
        );

        $stats = $this->service->getStats();

        $this->assertSame(2, $stats['installed_templates']['total']);
        $this->assertSame(2, $stats['installed_templates']['active']);
    }

    public function test_get_stats_returns_language_pack_counts(): void
    {
        $this->mockUserStatistics();
        $this->mockModules();
        $this->mockPlugins();
        // 활성 14, 미설치 번들 4 → 활성 14 / 전체 18
        $this->mockTemplatesAndPacks(activePacks: 14, uninstalledBundledPacks: 4);

        $stats = $this->service->getStats();

        $this->assertSame(14, $stats['language_packs']['active']);
        $this->assertSame(18, $stats['language_packs']['total']);
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
            $this->activityLogRepository,
            $this->moduleManager,
            $this->pluginManager,
            $this->notificationLogRepository,
            $this->templateManager,
            $this->languagePackRepository,
            $this->languagePackService,
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

    /**
     * probe 1개가 예외를 던지고 나머지는 정상값을 반환하는 부분 모킹 서비스를 생성합니다 (공개#40).
     *
     * @param  string  $throwingProbe  예외를 던질 probe 메서드명 (getCpuUsage|getMemoryUsage|getDiskUsage)
     * @return DashboardService 부분 모킹 서비스
     */
    private function createPartiallyFailingResourceService(string $throwingProbe): DashboardService
    {
        $service = Mockery::mock(DashboardService::class, [
            $this->userRepository,
            $this->activityLogRepository,
            $this->moduleManager,
            $this->pluginManager,
            $this->notificationLogRepository,
            $this->templateManager,
            $this->languagePackRepository,
            $this->languagePackService,
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        $normal = [
            'getCpuUsage' => ['percentage' => 45, 'color' => 'green'],
            'getMemoryUsage' => ['percentage' => 60, 'used' => '8.5 GB', 'total' => '16 GB', 'color' => 'blue'],
            'getDiskUsage' => ['percentage' => 75, 'used' => '300 GB', 'total' => '500 GB', 'color' => 'yellow'],
        ];

        foreach ($normal as $probe => $value) {
            if ($probe === $throwingProbe) {
                $service->shouldReceive($probe)->andThrow(new \ErrorException('open_basedir restriction in effect'));
            } else {
                $service->shouldReceive($probe)->andReturn($value);
            }
        }

        return $service;
    }

    public function test_get_system_resources_isolates_failing_cpu_probe(): void
    {
        $service = $this->createPartiallyFailingResourceService('getCpuUsage');

        $resources = $service->getSystemResources();

        // cpu 는 폴백, 나머지는 정상값 — 예외 비전파.
        $this->assertSame(0, $resources['cpu']['percentage']);
        $this->assertSame('gray', $resources['cpu']['color']);
        $this->assertSame(60, $resources['memory']['percentage']);
        $this->assertSame(75, $resources['disk']['percentage']);
    }

    public function test_get_system_resources_isolates_failing_memory_probe(): void
    {
        $service = $this->createPartiallyFailingResourceService('getMemoryUsage');

        $resources = $service->getSystemResources();

        $this->assertSame(0, $resources['memory']['percentage']);
        $this->assertSame('gray', $resources['memory']['color']);
        $this->assertSame(__('common.unknown'), $resources['memory']['used']);
        $this->assertSame(__('common.unknown'), $resources['memory']['total']);
        $this->assertSame(45, $resources['cpu']['percentage']);
        $this->assertSame(75, $resources['disk']['percentage']);
    }

    public function test_get_system_resources_isolates_failing_disk_probe(): void
    {
        $service = $this->createPartiallyFailingResourceService('getDiskUsage');

        $resources = $service->getSystemResources();

        $this->assertSame(0, $resources['disk']['percentage']);
        $this->assertSame('gray', $resources['disk']['color']);
        $this->assertSame(__('common.unknown'), $resources['disk']['used']);
        $this->assertSame(45, $resources['cpu']['percentage']);
        $this->assertSame(60, $resources['memory']['percentage']);
    }

    public function test_get_system_resources_all_probes_fail_returns_fallback_structure(): void
    {
        $service = Mockery::mock(DashboardService::class, [
            $this->userRepository,
            $this->activityLogRepository,
            $this->moduleManager,
            $this->pluginManager,
            $this->notificationLogRepository,
            $this->templateManager,
            $this->languagePackRepository,
            $this->languagePackService,
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        foreach (['getCpuUsage', 'getMemoryUsage', 'getDiskUsage'] as $probe) {
            $service->shouldReceive($probe)->andThrow(new \ErrorException('open_basedir'));
        }

        $resources = $service->getSystemResources();

        // 키 구조 유지 + 폴백값 — 예외 비전파.
        $this->assertArrayHasKey('cpu', $resources);
        $this->assertArrayHasKey('memory', $resources);
        $this->assertArrayHasKey('disk', $resources);
        $this->assertSame('gray', $resources['cpu']['color']);
        $this->assertSame('gray', $resources['memory']['color']);
        $this->assertSame('gray', $resources['disk']['color']);
        $this->assertSame(0, $resources['cpu']['percentage']);
    }

    // ========================================================================
    // getRecentActivities 테스트
    // ========================================================================

    public function test_get_recent_activities_returns_array(): void
    {
        $this->activityLogRepository->shouldReceive('getRecent')
            ->with('core.dashboard.activities', 5)
            ->andReturn(new EloquentCollection([]));

        $activities = $this->service->getRecentActivities();

        $this->assertIsArray($activities);
    }

    public function test_get_recent_activities_formats_user_registration(): void
    {
        $mockLog = Mockery::mock(ActivityLog::class)->makePartial();
        $mockLog->shouldReceive('getAttribute')->with('log_type')->andReturn(ActivityLogType::User);
        $mockLog->shouldReceive('getAttribute')->with('action')->andReturn('register');
        $mockLog->shouldReceive('getAttribute')->with('localized_description')->andReturn('테스트 유저가 가입했습니다');
        $mockLog->shouldReceive('getAttribute')->with('actor_name')->andReturn('Test User');
        $mockLog->shouldReceive('getAttribute')->with('created_at')->andReturn(now()->subMinutes(5));

        $this->activityLogRepository->shouldReceive('getRecent')
            ->with('core.dashboard.activities', 5)
            ->andReturn(new EloquentCollection([$mockLog]));

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

        $this->assertIsArray($alerts);
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

        $this->assertIsArray($alerts);
        foreach ($alerts as $alert) {
            $this->assertContains($alert['type'], $validTypes);
        }
    }

    public function test_get_system_alerts_returns_empty_when_no_listeners(): void
    {
        $alerts = $this->service->getSystemAlerts();

        $this->assertIsArray($alerts);
    }

    public function test_get_recent_notification_logs_maps_display_fields(): void
    {
        $log = new NotificationLog([
            'channel' => 'mail',
            'notification_type' => 'order_confirmed',
            'recipient_name' => '홍길동',
            'recipient_identifier' => 'test@example.com',
            'subject' => '주문이 확인되었습니다',
            'status' => NotificationLogStatus::Sent,
            'sent_at' => now(),
        ]);
        $log->id = 1;
        $log->setRelation('recipientUser', null);

        $this->notificationLogRepository
            ->shouldReceive('getRecent')
            ->once()
            ->with(5)
            ->andReturn(new EloquentCollection([$log]));

        $result = $this->service->getRecentNotificationLogs(5);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('order_confirmed', $result[0]['type']);
        $this->assertSame('mail', $result[0]['channel']);
        $this->assertSame('홍길동', $result[0]['recipient']);
        $this->assertSame('sent', $result[0]['status']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
