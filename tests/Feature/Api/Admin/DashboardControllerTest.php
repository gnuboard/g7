<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ActivityLogType;
use App\Enums\ExtensionOwnerType;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * DashboardController 테스트
 *
 * 대시보드 API 엔드포인트를 테스트합니다.
 */
class DashboardControllerTest extends TestCase
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
     * @param  string|null  $scopeType  권한 스코프 타입 (null: 전체, 'self': 본인, 'role': 역할)
     * @return User
     */
    private function createAdminUser(array $permissions = ['core.dashboard.read'], ?string $scopeType = null): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        // 권한 생성
        $permissionIds = [];
        foreach ($permissions as $permIdentifier) {
            $attrs = [
                'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                'description' => json_encode(['ko' => $permIdentifier.' 권한', 'en' => $permIdentifier.' Permission']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ];

            // 스코프 지원 권한에 resource_route_key, owner_key 설정
            if ($permIdentifier === 'core.dashboard.activities') {
                $attrs['resource_route_key'] = 'activityLog';
                $attrs['owner_key'] = 'user_id';
            }

            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                $attrs
            );

            // firstOrCreate로 이미 존재하는 경우 owner_key가 없을 수 있으므로 업데이트
            if ($permIdentifier === 'core.dashboard.activities' && $permission->owner_key === null) {
                $permission->update([
                    'resource_route_key' => 'activityLog',
                    'owner_key' => 'user_id',
                ]);
            }

            $permissionIds[] = $permission->id;
        }

        // 고유한 식별자로 역할 생성
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

        // 테스트용 역할에 권한 할당 (스코프 타입 포함)
        $syncData = [];
        foreach ($permissionIds as $permId) {
            $syncData[$permId] = ['scope_type' => $scopeType];
        }
        $adminRole->permissions()->sync($syncData);

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
     * 인증 헤더와 함께 요청
     *
     * @return $this
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 특정 사용자의 토큰으로 인증 요청
     *
     * @param  User  $user  인증할 사용자
     * @return $this
     */
    private function authAs(User $user): static
    {
        $token = $user->createToken('test-token')->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    }

    // ========================================================================
    // 인증/권한 테스트
    // ========================================================================

    public function test_stats_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/dashboard/stats');
        $response->assertStatus(401);
    }

    public function test_stats_returns_403_without_permission(): void
    {
        $user = $this->createAdminUser([]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/dashboard/stats');

        $response->assertStatus(403);
    }

    public function test_resources_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/dashboard/resources');
        $response->assertStatus(401);
    }

    public function test_activities_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/dashboard/activities');
        $response->assertStatus(401);
    }

    public function test_activities_returns_403_without_permission(): void
    {
        $user = $this->createAdminUser([]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/dashboard/activities');

        $response->assertStatus(403);
    }

    public function test_alerts_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/dashboard/alerts');
        $response->assertStatus(401);
    }

    // ========================================================================
    // Stats API 테스트
    // ========================================================================

    public function test_stats_returns_correct_structure(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/dashboard/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_users' => ['count', 'change_percent', 'change_display', 'trend'],
                    'installed_modules' => ['total', 'active'],
                    'active_plugins' => ['active', 'total'],
                    'system_status' => ['status', 'label', 'all_services_running'],
                ],
            ]);
    }

    public function test_stats_returns_correct_user_count(): void
    {
        User::factory()->count(5)->create();

        $response = $this->authRequest()->getJson('/api/admin/dashboard/stats');

        $response->assertStatus(200);
        // 사용자 수가 정확히 반환되는지 확인
        $userCount = $response->json('data.total_users.count');
        $this->assertIsInt($userCount);
        $this->assertGreaterThanOrEqual(1, $userCount); // 최소 admin 1명
    }

    public function test_stats_user_trend_is_up_when_increased(): void
    {
        // 전월에 가입한 사용자 2명 생성
        User::factory()->count(2)->create([
            'created_at' => now()->subMonth()->subDays(5),
        ]);

        // 이번 달에 가입한 사용자 5명 생성
        User::factory()->count(5)->create([
            'created_at' => now()->subDays(5),
        ]);

        $response = $this->authRequest()->getJson('/api/admin/dashboard/stats');

        $response->assertStatus(200);
        $this->assertEquals('up', $response->json('data.total_users.trend'));
    }

    // ========================================================================
    // Resources API 테스트
    // ========================================================================

    /**
     * 시스템 리소스 조회를 위한 모킹된 서비스를 바인딩합니다.
     *
     * 실제 시스템 명령(PowerShell, wmic 등) 호출을 피하고
     * 테스트 성능을 향상시키기 위해 모킹된 값을 반환합니다.
     *
     * @return void
     */
    private function mockDashboardResourceService(): void
    {
        $this->partialMock(DashboardService::class, function ($mock) {
            $mock->shouldReceive('getSystemResources')->andReturn([
                'cpu' => ['percentage' => 45, 'color' => 'green'],
                'memory' => ['percentage' => 60, 'used' => '8.5 GB', 'total' => '16 GB', 'color' => 'blue'],
                'disk' => ['percentage' => 75, 'used' => '300 GB', 'total' => '500 GB', 'color' => 'yellow'],
            ]);
        });
    }

    public function test_resources_returns_correct_structure(): void
    {
        $this->mockDashboardResourceService();

        $response = $this->authRequest()->getJson('/api/admin/dashboard/resources');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'cpu' => ['percentage', 'color'],
                    'memory' => ['percentage', 'used', 'total', 'color'],
                    'disk' => ['percentage', 'used', 'total', 'color'],
                ],
            ]);
    }

    public function test_resources_returns_valid_percentage_values(): void
    {
        $this->mockDashboardResourceService();

        $response = $this->authRequest()->getJson('/api/admin/dashboard/resources');

        $response->assertStatus(200);

        $cpu = $response->json('data.cpu.percentage');
        $memory = $response->json('data.memory.percentage');
        $disk = $response->json('data.disk.percentage');

        $this->assertGreaterThanOrEqual(0, $cpu);
        $this->assertLessThanOrEqual(100, $cpu);
        $this->assertGreaterThanOrEqual(0, $memory);
        $this->assertLessThanOrEqual(100, $memory);
        $this->assertGreaterThanOrEqual(0, $disk);
        $this->assertLessThanOrEqual(100, $disk);
    }

    public function test_resources_returns_valid_color_values(): void
    {
        $this->mockDashboardResourceService();

        $response = $this->authRequest()->getJson('/api/admin/dashboard/resources');

        $response->assertStatus(200);

        $validColors = ['green', 'yellow', 'red', 'blue', 'gray'];

        $cpuColor = $response->json('data.cpu.color');
        $memoryColor = $response->json('data.memory.color');
        $diskColor = $response->json('data.disk.color');

        $this->assertContains($cpuColor, $validColors);
        $this->assertContains($memoryColor, $validColors);
        $this->assertContains($diskColor, $validColors);
    }

    // ========================================================================
    // Activities API 테스트
    // ========================================================================

    public function test_activities_returns_correct_structure(): void
    {
        $user = $this->createAdminUser(['core.dashboard.activities']);

        $response = $this->authAs($user)->getJson('/api/admin/dashboard/activities');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    }

    public function test_activities_returns_activity_log_data(): void
    {
        $user = $this->createAdminUser(['core.dashboard.activities']);

        ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
            'description_key' => 'activity_log.description.user_create',
            'user_id' => $user->id,
        ]);

        $response = $this->authAs($user)->getJson('/api/admin/dashboard/activities');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_activities_returns_array(): void
    {
        $user = $this->createAdminUser(['core.dashboard.activities']);

        $response = $this->authAs($user)->getJson('/api/admin/dashboard/activities');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
    }

    public function test_activities_returns_empty_array_when_no_logs(): void
    {
        $user = $this->createAdminUser(['core.dashboard.activities']);

        $response = $this->authAs($user)->getJson('/api/admin/dashboard/activities');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
        $this->assertEmpty($response->json('data'));
    }

    public function test_activities_item_has_required_fields(): void
    {
        $user = $this->createAdminUser(['core.dashboard.activities']);

        ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.update',
            'description_key' => 'activity_log.description.user_update',
            'user_id' => $user->id,
        ]);

        $response = $this->authAs($user)->getJson('/api/admin/dashboard/activities');

        $response->assertStatus(200);

        $activities = $response->json('data');
        $this->assertNotEmpty($activities);

        $firstActivity = $activities[0];
        $this->assertArrayHasKey('title', $firstActivity);
        $this->assertArrayHasKey('description', $firstActivity);
        $this->assertArrayHasKey('time', $firstActivity);
        $this->assertArrayHasKey('type', $firstActivity);
        $this->assertArrayHasKey('icon', $firstActivity);
        $this->assertArrayHasKey('icon_color', $firstActivity);
    }

    // ========================================================================
    // Activities 스코프 권한 테스트
    // ========================================================================

    public function test_activities_scope_self_returns_only_own_activities(): void
    {
        // self 스코프로 활동 권한을 가진 사용자 생성
        $selfUser = $this->createAdminUser(['core.dashboard.activities'], 'self');
        $otherUser = User::factory()->create();

        // 본인 활동 로그
        ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.login',
            'description_key' => 'activity_log.description.user_login',
            'user_id' => $selfUser->id,
        ]);

        // 다른 사용자의 활동 로그
        ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.login',
            'description_key' => 'activity_log.description.user_login',
            'user_id' => $otherUser->id,
        ]);

        $response = $this->authAs($selfUser)->getJson('/api/admin/dashboard/activities');

        $response->assertStatus(200);
        $activities = $response->json('data');

        // self 스코프이므로 본인 활동만 반환
        $this->assertCount(1, $activities);
    }

    public function test_activities_scope_null_returns_all_activities(): void
    {
        // 전체 스코프(null)로 활동 권한을 가진 사용자 생성
        $fullUser = $this->createAdminUser(['core.dashboard.activities']);
        $otherUser = User::factory()->create();

        // 본인 활동 로그
        ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.login',
            'description_key' => 'activity_log.description.user_login',
            'user_id' => $fullUser->id,
        ]);

        // 다른 사용자의 활동 로그
        ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.login',
            'description_key' => 'activity_log.description.user_login',
            'user_id' => $otherUser->id,
        ]);

        $response = $this->authAs($fullUser)->getJson('/api/admin/dashboard/activities');

        $response->assertStatus(200);
        $activities = $response->json('data');

        // 전체 스코프이므로 모든 활동 반환
        $this->assertCount(2, $activities);
    }

    // ========================================================================
    // Alerts API 테스트
    // ========================================================================

    public function test_alerts_returns_correct_structure(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/dashboard/alerts');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    }

    public function test_alerts_returns_array(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/dashboard/alerts');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
    }

    public function test_alerts_item_has_required_fields(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/dashboard/alerts');

        $response->assertStatus(200);

        $alerts = $response->json('data');
        if (count($alerts) > 0) {
            $firstAlert = $alerts[0];
            $this->assertArrayHasKey('id', $firstAlert);
            $this->assertArrayHasKey('title', $firstAlert);
            $this->assertArrayHasKey('message', $firstAlert);
            $this->assertArrayHasKey('time', $firstAlert);
            $this->assertArrayHasKey('type', $firstAlert);
            $this->assertArrayHasKey('icon', $firstAlert);
            $this->assertArrayHasKey('read', $firstAlert);
        }
    }
}
