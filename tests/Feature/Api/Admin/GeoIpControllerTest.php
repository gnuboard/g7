<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\GeoIpDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * POST /api/admin/settings/geoip/update 통합 테스트.
 *
 * GeoIpDatabaseService를 모킹하여 컨트롤러가 상태 코드를
 * 올바른 HTTP 상태/메시지 키로 매핑하는지 검증합니다.
 * 권한 설정 패턴은 SettingsControllerTest 와 동일합니다.
 */
class GeoIpControllerTest extends TestCase
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * core.settings.update 권한을 가진 관리자 사용자를 생성합니다.
     *
     * @return User 생성된 관리자 사용자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.settings.update'],
            [
                'name' => json_encode(['ko' => '설정 업데이트', 'en' => 'Settings Update']),
                'description' => json_encode(['ko' => '설정 업데이트 권한', 'en' => 'Settings Update Permission']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );

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

        $roleIdentifier = 'admin_test_'.uniqid();
        $testRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

        $testRole->permissions()->sync([$permission->id]);

        $user->roles()->attach($adminBaseRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * Bearer 토큰 헤더를 포함하여 API 엔드포인트를 호출합니다.
     *
     * @return \Illuminate\Testing\TestResponse HTTP 응답
     */
    private function postUpdate(): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/settings/geoip/update');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/admin/settings/geoip/update');
        $response->assertStatus(401);
    }

    public function test_missing_license_key_returns_400(): void
    {
        $mockService = Mockery::mock(GeoIpDatabaseService::class);
        $mockService->shouldReceive('updateDatabase')
            ->once()
            ->with(true)
            ->andReturn([
                'success' => false,
                'status' => 'missing_license_key',
                'message' => 'MaxMind 라이선스 키가 설정되지 않았습니다.',
            ]);
        $this->app->instance(GeoIpDatabaseService::class, $mockService);

        $response = $this->postUpdate();

        $response->assertStatus(400);
        $this->assertFalse($response->json('success'));
    }

    public function test_successful_update_returns_200_with_data(): void
    {
        $mockService = Mockery::mock(GeoIpDatabaseService::class);
        $mockService->shouldReceive('updateDatabase')
            ->once()
            ->with(true)
            ->andReturn([
                'success' => true,
                'status' => 'updated',
                'message' => 'GeoIP DB 업데이트가 완료되었습니다.',
                'data' => [
                    'database_path' => '/fake/path/GeoLite2-City.mmdb',
                    'file_size_bytes' => 62_000_000,
                    'elapsed_seconds' => 5.2,
                    'last_updated_at' => '2026-04-16T03:00:00+00:00',
                ],
            ]);
        $this->app->instance(GeoIpDatabaseService::class, $mockService);

        $response = $this->postUpdate();

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertEquals(62_000_000, $response->json('data.file_size_bytes'));
    }

    public function test_unauthorized_status_returns_401_code(): void
    {
        $mockService = Mockery::mock(GeoIpDatabaseService::class);
        $mockService->shouldReceive('updateDatabase')
            ->once()
            ->with(true)
            ->andReturn([
                'success' => false,
                'status' => 'unauthorized',
                'message' => '라이선스 키가 유효하지 않습니다. (HTTP 401)',
            ]);
        $this->app->instance(GeoIpDatabaseService::class, $mockService);

        $response = $this->postUpdate();

        $response->assertStatus(401);
    }

    public function test_download_failed_returns_500_code(): void
    {
        $mockService = Mockery::mock(GeoIpDatabaseService::class);
        $mockService->shouldReceive('updateDatabase')
            ->once()
            ->with(true)
            ->andReturn([
                'success' => false,
                'status' => 'download_failed',
                'message' => '다운로드 실패: HTTP 503',
            ]);
        $this->app->instance(GeoIpDatabaseService::class, $mockService);

        $response = $this->postUpdate();

        $response->assertStatus(500);
    }
}
