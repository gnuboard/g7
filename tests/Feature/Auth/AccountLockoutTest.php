<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 영구 계정 잠금 + 관리자 수동 해제 통합 테스트 (C1)
 *
 * `login_lockout_time = 0` 은 관리자 UI 가 "0을 입력하면 무한대로 설정됩니다" 라고 안내하는
 * 값입니다. 수정 전에는 1 분 잠금으로 뭉개져 무차별 대입 공격자를 영구 차단할 수 없었고,
 * 관리자가 그 사실을 인지할 방법도 없었습니다.
 *
 * 영구 잠금을 도입하면 해제 수단이 "성공 로그인" 뿐이던 기존 구조에서는 잠긴 계정이
 * 복구 불가가 되므로, 관리자 수동 해제 엔드포인트를 같은 변경에 포함합니다.
 */
class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSecuritySettings([
            'login_attempt_enabled' => true,
            'max_login_attempts' => 3,
            'login_lockout_time' => 0,
        ]);

        Cache::flush();
    }

    /**
     * @test
     */
    public function 잠금시간_0_설정에서_임계값_도달_시_영구_잠금된다(): void
    {
        $user = User::factory()->create([
            'email' => 'permlock@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'permlock@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $user->refresh();
        $this->assertTrue((bool) $user->locked_permanently);
        $this->assertNull($user->locked_until);

        // 하루가 지나도 여전히 잠금 (수정 전: 1분 뒤 자동 해제)
        $this->travel(1)->day();

        $this->postJson('/api/auth/login', [
            'email' => 'permlock@example.com',
            'password' => 'correct-password',
        ])->assertStatus(423);
    }

    /**
     * @test
     */
    public function 영구_잠금_응답은_locked_until_이_null_이어도_직렬화된다(): void
    {
        $user = User::factory()->create([
            'email' => 'permlock2@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        app(UserRepositoryInterface::class)->lockAccount($user, 0);

        // 수정 전: $e->lockedUntil->toIso8601String() 가 null 에서 fatal
        $response = $this->postJson('/api/auth/login', [
            'email' => 'permlock2@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(423);
        $this->assertNull($response->json('errors.locked_until'));
    }

    /**
     * @test
     */
    public function 관리자_해제_ap_i_로_영구_잠금을_풀_수_있다(): void
    {
        $locked = User::factory()->create([
            'email' => 'unlockme@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);
        app(UserRepositoryInterface::class)->lockAccount($locked, 0);

        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$locked->uuid}/unlock")
            ->assertStatus(200);

        $locked->refresh();
        $this->assertFalse((bool) $locked->locked_permanently);
        $this->assertNull($locked->locked_until);

        // actingAs(sanctum) 이 기본 guard 까지 sanctum 으로 고정하므로 되돌린 뒤
        // 실제 로그인(Auth::attempt) 가능 여부를 확인한다.
        // (actingAs 는 config('auth.defaults.guard') 자체를 sanctum 으로 덮어쓰므로 config 를 다시 읽으면 안 된다)
        $this->app['auth']->shouldUse('web');
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', [
            'email' => 'unlockme@example.com',
            'password' => 'correct-password',
        ])->assertStatus(200);
    }

    /**
     * @test
     */
    public function 권한_없는_사용자는_해제_ap_i_를_호출할_수_없다(): void
    {
        $locked = User::factory()->create(['status' => 'active']);
        app(UserRepositoryInterface::class)->lockAccount($locked, 0);

        $member = User::factory()->create(['status' => 'active']);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/admin/users/{$locked->uuid}/unlock")
            ->assertStatus(403);

        $this->assertTrue((bool) $locked->fresh()->locked_permanently);
    }

    /**
     * self 스코프 역할은 남의 계정을 해제할 수 없다.
     *
     * 해제 엔드포인트는 신규 권한을 만들지 않고 `core.users.update` 를 재사용한다.
     * 기본 manager 역할이 그 권한을 self 스코프로 갖고 있으므로, 스코프가 이 경로에
     * 적용되지 않으면 매니저가 임의 계정의 잠금을 푸는 우회로가 된다.
     *
     * @test
     */
    public function self_스코프_역할은_남의_계정_잠금을_해제할_수_없다(): void
    {
        $locked = User::factory()->create(['status' => 'active']);
        app(UserRepositoryInterface::class)->lockAccount($locked, 0);

        $manager = $this->createSelfScopedManager();

        // 전제 확인 — 이 사용자의 실효 스코프가 정말 self 인가
        $this->assertSame('self', $manager->getEffectiveScopeForPermission('core.users.update'));
        $this->assertSame('user', PermissionHelper::getResourceRouteKey('core.users.update'));

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/admin/users/{$locked->uuid}/unlock")
            ->assertStatus(403);

        $this->assertTrue(
            (bool) $locked->fresh()->locked_permanently,
            'self 스코프 역할이 남의 계정 잠금을 해제했습니다.'
        );
    }

    /**
     * @test
     */
    public function 유한_잠금_설정은_기존과_동일하게_만료된다(): void
    {
        $this->setSecuritySettings(['login_lockout_time' => 5]);

        $user = User::factory()->create([
            'email' => 'tmplock@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'tmplock@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->assertFalse((bool) $user->fresh()->locked_permanently);

        $this->travel(6)->minutes();
        Cache::flush();

        $this->postJson('/api/auth/login', [
            'email' => 'tmplock@example.com',
            'password' => 'correct-password',
        ])->assertStatus(200);
    }

    /**
     * 보안 환경설정 값을 테스트용으로 덮어씁니다.
     *
     * @param  array  $values  덮어쓸 보안 설정 값
     */
    private function setSecuritySettings(array $values): void
    {
        $existing = (array) config('g7_settings.core.security', []);
        config(['g7_settings.core.security' => array_merge($existing, $values)]);
    }

    /**
     * 사용자 수정 권한을 가진 관리자를 생성합니다.
     *
     * @return User 관리자 사용자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create(['is_super' => true, 'status' => 'active']);

        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.update'],
            [
                'name' => json_encode(['ko' => '사용자 수정', 'en' => 'Update Users']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => PermissionType::Admin,
            ]
        );

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        if (! $adminRole->permissions()->where('permissions.id', $permission->id)->exists()) {
            $adminRole->permissions()->attach($permission->id);
        }

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * `core.users.update` 를 self 스코프로만 가진 사용자를 만듭니다 (기본 manager 역할 구성).
     *
     * @return User 생성된 사용자
     */
    private function createSelfScopedManager(): User
    {
        $user = User::factory()->create(['is_super' => false, 'status' => 'active']);

        // resource_route_key/owner_key 는 config/core.php 의 선언과 같아야 한다 —
        // 비어 있으면 미들웨어가 스코프 검사를 건너뛰어 이 테스트가 무의미해진다.
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.update'],
            [
                'name' => json_encode(['ko' => '사용자 수정', 'en' => 'Update Users']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => PermissionType::Admin,
            ]
        );
        $permission->forceFill(['resource_route_key' => 'user', 'owner_key' => 'id'])->save();

        $role = Role::firstOrCreate(
            ['identifier' => 'manager'],
            [
                'name' => json_encode(['ko' => '매니저', 'en' => 'Manager']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $role->permissions()->syncWithoutDetaching([
            $permission->id => ['scope_type' => 'self'],
        ]);

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }
}
