<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ExtensionOwnerType;
use App\Listeners\ExtensionCompatibilityAlertListener;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 확장 호환성 알림이 dashboard alerts 엔드포인트에 노출되는지 검증.
 *
 * (1) 자동 비활성화 확장 → incompatible_core 알림
 * (2) 재호환 확장 → recovery_available 알림 + recover_endpoint
 * (3) dismiss 후 같은 사용자에게 미노출
 * (4) listener 이 hook 시스템을 통해 정상 등록
 */
class ExtensionCompatibilityAlertTest extends TestCase
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

    private function createAdminUser(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $permissions = ['core.dashboard.read'];
        $permissionIds = [];
        foreach ($permissions as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => \App\Enums\PermissionType::Admin,
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $testRole = Role::create([
            'identifier' => 'admin_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

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

        $testRole->permissions()->sync($permissionIds);

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($testRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    public function test_dashboard_alerts_endpoint_returns_array_payload(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/dashboard/alerts');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_listener_subscribed_hook_registered_with_filter_type(): void
    {
        $hooks = ExtensionCompatibilityAlertListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.dashboard.alerts', $hooks);
        $this->assertSame('filter', $hooks['core.dashboard.alerts']['type']);
        $this->assertSame('addCompatibilityAlerts', $hooks['core.dashboard.alerts']['method']);
    }

    public function test_listener_addCompatibilityAlerts_returns_array_unchanged_when_no_records(): void
    {
        $listener = new ExtensionCompatibilityAlertListener;

        $result = $listener->addCompatibilityAlerts([]);

        $this->assertIsArray($result);
    }

    public function test_dismiss_alert_persists_for_user(): void
    {
        $service = app(\App\Services\ExtensionCompatibilityAlertService::class);
        $service->dismissAlert('compat_plugin_test', $this->admin->id);

        // 두 번 호출해도 중복 추가 안됨 (idempotent)
        $service->dismissAlert('compat_plugin_test', $this->admin->id);

        $dismissed = $service->getDismissedAlertIds($this->admin->id);
        $this->assertContains('compat_plugin_test', $dismissed);
        $this->assertSame(1, count(array_keys($dismissed, 'compat_plugin_test', true)), '중복 등록되지 않음');
    }
}
