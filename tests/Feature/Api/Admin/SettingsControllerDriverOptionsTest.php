<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Settings API에서 available_drivers 필드 포함 검증
 *
 * DriverRegistryService를 통해 코어 + 플러그인 드라이버 목록이
 * settings API 응답에 포함되는지 검증합니다.
 */
class SettingsControllerDriverOptionsTest extends TestCase
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
     * 관리자 사용자 생성
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $roleIdentifier = 'admin_test_' . uniqid();
        $adminRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
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
     * 인증된 요청 헬퍼 메서드
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * settings index 응답에 available_drivers가 포함되는지 검증합니다.
     */
    #[Test]
    public function index_response_includes_available_drivers(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'available_drivers' => [
                        'storage',
                        'cache',
                        'session',
                        'queue',
                        'log',
                        'websocket',
                        'mail',
                    ],
                ],
            ]);
    }

    /**
     * available_drivers의 각 드라이버에 id와 label 필드가 있는지 검증합니다.
     */
    #[Test]
    public function available_drivers_have_correct_structure(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $response->assertStatus(200);

        $data = $response->json('data.available_drivers');

        // 7개 카테고리 존재
        $this->assertCount(7, $data);

        // 각 카테고리에 최소 1개 이상의 드라이버 존재
        foreach ($data as $category => $drivers) {
            $this->assertNotEmpty($drivers, "{$category} 카테고리에 드라이버가 없습니다.");

            // 첫 번째 드라이버 구조 검증
            $this->assertArrayHasKey('id', $drivers[0]);
            $this->assertArrayHasKey('label', $drivers[0]);
            $this->assertArrayHasKey('ko', $drivers[0]['label']);
            $this->assertArrayHasKey('en', $drivers[0]['label']);
        }
    }

    /**
     * storage 카테고리에 코어 드라이버가 포함되는지 검증합니다.
     */
    #[Test]
    public function storage_drivers_include_core_options(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $storageDrivers = $response->json('data.available_drivers.storage');
        $ids = array_column($storageDrivers, 'id');

        $this->assertContains('local', $ids);
        $this->assertContains('s3', $ids);
    }

    /**
     * mail 카테고리에 코어 드라이버가 포함되는지 검증합니다.
     */
    #[Test]
    public function mail_drivers_include_core_options(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $mailDrivers = $response->json('data.available_drivers.mail');
        $ids = array_column($mailDrivers, 'id');

        $this->assertContains('smtp', $ids);
        $this->assertContains('mailgun', $ids);
        $this->assertContains('ses', $ids);
    }
}
