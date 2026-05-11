<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 모듈 업데이트 호환성 응답 페이로드 검증.
 */
class ModuleUpdateVersionCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdminUser([
            'core.modules.read',
            'core.modules.activate',
            'core.modules.update',
            'core.modules.install',
        ]);
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    private function createAdminUser(array $permissions): User
    {
        $user = User::factory()->create();

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

    public function test_check_updates_response_includes_compatibility_fields(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('checkForUpdates')
            ->once()
            ->andReturn([
                'updated_count' => 1,
                'details' => [
                    'sirsoft-ecommerce' => [
                        'update_available' => true,
                        'update_source' => 'bundled',
                        'latest_version' => '2.0.0',
                        'current_version' => '1.0.0',
                        'required_core_version' => '>=7.0.0',
                        'is_compatible' => true,
                        'current_core_version' => '7.0.0-beta.1',
                    ],
                ],
            ]);
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/check-updates');

        $response->assertStatus(200)
            ->assertJsonPath('data.details.sirsoft-ecommerce.is_compatible', true)
            ->assertJsonPath('data.details.sirsoft-ecommerce.required_core_version', '>=7.0.0');
    }

    public function test_perform_update_request_accepts_force_flag(): void
    {
        $request = new \App\Http\Requests\Module\PerformModuleUpdateRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('force', $rules);
        $this->assertContains('boolean', $rules['force']);
    }
}
