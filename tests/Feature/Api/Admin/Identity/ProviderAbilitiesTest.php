<?php

namespace Tests\Feature\Api\Admin\Identity;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 본인인증 프로바이더 행 abilities (core-backend #5).
 */
class ProviderAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 주어진 권한만 가진 운영자와 토큰을 만듭니다.
     *
     * @param  array<int, string>  $identifiers  권한 식별자 목록
     * @return array{0: User, 1: string} 운영자와 액세스 토큰
     */
    private function makeUserWithPermissions(array $identifiers): array
    {
        $user = User::factory()->create(['is_super' => false]);

        $role = Role::create([
            'identifier' => 'test-idv-ability-'.uniqid(),
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'description' => ['ko' => '', 'en' => ''],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);

        foreach ($identifiers as $identifier) {
            $perm = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => '', 'en' => ''],
                    'type' => PermissionType::Admin,
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'order' => 0,
                ]
            );
            $role->permissions()->attach($perm->id, ['granted_at' => now(), 'granted_by' => null]);
        }

        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return [$user->fresh(), $user->createToken('test')->plainTextToken];
    }

    /**
     * 토큰 인증 헤더를 만듭니다.
     *
     * @param  string  $token  액세스 토큰
     * @return array<string, string> 요청 헤더
     */
    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /**
     * 프로바이더 행 can_update 는 실제 저장 경로(환경설정 저장 = core.settings.update)로 판정된다 (core-backend #5).
     *
     * 프로바이더 설정은 환경설정 저장으로 바뀐다. 쓰는 라우트가 없는 권한(providers.update)으로 판정하면
     * 그 권한만 받은 운영자는 true 를 받고도 저장이 403 이고, 설정 수정 권한 보유자는 false 를 받았다.
     *
     * @scenario endpoint=admin.identity.providers.index,permission=providers.update|settings.update
     *
     * @effects provider_can_update_follows_settings_update
     *
     * @return void 반환값 없음
     */
    public function test_provider_can_update_follows_settings_update(): void
    {
        [, $updateOnly] = $this->makeUserWithPermissions([
            'core.admin.identity.providers.read',
            'core.admin.identity.providers.update',
        ]);
        $rows = $this->withHeaders($this->auth($updateOnly))->getJson('/api/admin/identity/providers')->assertOk()->json('data');
        foreach ((array) $rows as $row) {
            $this->assertFalse($row['abilities']['can_update'] ?? null);
        }

        // 같은 테스트 안에서 다른 토큰으로 다시 부르므로 인증 가드가 붙든 첫 사용자를 비운다.
        $this->app['auth']->forgetGuards();

        [, $settings] = $this->makeUserWithPermissions([
            'core.admin.identity.providers.read',
            'core.settings.update',
        ]);
        $rows = $this->withHeaders($this->auth($settings))->getJson('/api/admin/identity/providers')->assertOk()->json('data');
        $this->assertNotEmpty($rows, '등록된 본인인증 프로바이더가 없다');
        foreach ($rows as $row) {
            $this->assertTrue($row['abilities']['can_update'] ?? null);
        }
    }
}
