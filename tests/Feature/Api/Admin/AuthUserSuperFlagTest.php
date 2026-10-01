<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 로그인 사용자 응답이 본인의 최고관리자 여부(is_super)를 싣는지 검증합니다 (#703 브라우저 실측).
 *
 * 모듈·플러그인·템플릿 목록의 「숨김 포함」 토글은 최고관리자에게만 보이도록 되어 있었지만, 조건이 읽는
 * 값을 로그인 사용자 응답이 주지 않아 최고관리자에게도 토글이 나타나지 않았다(화면이 받는 값을 서버가
 * 주지 않는 어긋남). 남의 계정이 담기는 목록 응답에는 이 값을 싣지 않는다.
 *
 * 시나리오 매니페스트: tests/scenarios/auth-user-super-flag.yaml
 */
class AuthUserSuperFlagTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 관리자 화면에 들어갈 수 있는 사용자를 만듭니다.
     *
     * AdminMiddleware 는 `admin` 역할과 type=admin 권한 하나 이상을 본다.
     *
     * @param  bool  $isSuper  최고관리자 여부
     * @return User 생성된 사용자
     */
    private function makeAdmin(bool $isSuper): User
    {
        $user = User::factory()->create(['is_super' => $isSuper]);

        $permission = Permission::firstOrCreate(
            ['identifier' => 'admin.super_flag_test'],
            [
                'name' => json_encode(['ko' => 'super flag test', 'en' => 'super flag test']),
                'description' => json_encode(['ko' => '', 'en' => '']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );

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
        $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user;
    }

    /**
     * 최고관리자와 일반 관리자의 로그인 사용자 응답에 각자의 is_super 가 실린다.
     *
     * @scenario account=super_admin
     *
     * @effects auth_user_exposes_own_super_flag
     *
     * @return void 반환값 없음
     */
    public function test_auth_user_response_exposes_own_super_flag(): void
    {
        foreach ([true, false] as $isSuper) {
            $user = $this->makeAdmin($isSuper);

            $response = $this->withHeaders([
                'Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken,
                'Accept' => 'application/json',
            ])->getJson('/api/admin/auth/user');

            $response->assertOk();
            $this->assertSame($isSuper, $response->json('data.is_super'), $isSuper ? '최고관리자' : '일반 관리자');
            $this->app['auth']->forgetGuards();
        }
    }
}
