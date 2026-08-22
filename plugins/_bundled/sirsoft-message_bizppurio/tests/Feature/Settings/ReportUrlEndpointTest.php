<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Settings;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 리포트 수신 주소 조회 엔드포인트 테스트.
 *
 * GET /api/plugins/sirsoft-message_bizppurio/admin/report-url 이 사이트 도메인 기준
 * 절대 URL 을 내려주는지, 그리고 인증/권한 가드가 동작하는지 검증한다.
 *
 * @since 1.0.0
 */
class ReportUrlEndpointTest extends PluginTestCase
{
    private const ENDPOINT = '/api/plugins/sirsoft-message_bizppurio/admin/report-url';

    /**
     * core.plugins.read 권한을 가진 admin 사용자 생성.
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.plugins.read'],
            ['name' => json_encode(['ko' => '플러그인 조회', 'en' => 'Read Plugins']), 'type' => 'admin']
        );

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'type' => 'admin']
        );

        $testRole = Role::create([
            'identifier' => 'bizppurio_report_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'type' => 'admin',
        ]);
        $testRole->permissions()->sync([$permission->id]);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * @scenario auth=admin,app_url=set
     *
     * @effects report_url_returns_absolute_url_built_from_app_url
     */
    public function test_returns_absolute_report_url_based_on_app_url(): void
    {
        config(['app.url' => 'https://shop.example.com']);

        $admin = $this->createAdminUser();
        $token = $admin->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson(self::ENDPOINT);

        $response->assertStatus(200);
        $response->assertJsonPath('data.url', 'https://shop.example.com/api/plugins/sirsoft-message_bizppurio/webhook');
    }

    /**
     * @scenario auth=guest
     *
     * @effects report_url_requires_authentication_returns_401
     */
    public function test_requires_authentication(): void
    {
        $response = $this->getJson(self::ENDPOINT);

        $response->assertStatus(401);
    }
}
