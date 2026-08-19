<?php

namespace Tests\Feature\Api\Identity;

use App\Extension\HookManager;
use App\Http\Requests\Identity\CancelChallengeRequest;
use App\Http\Requests\Identity\RequestChallengeRequest;
use App\Http\Requests\Identity\VerifyChallengeRequest;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * IDV 권한 가드의 "데이터 준비" 와 "라우트 계약" 검증.
 *
 * PermissionGuardTest 가 요청 시점의 통과/거부를 다룬다면, 본 테스트는 그 판정이 읽는
 * 시드 데이터(권한 메타·역할별 scope)와 라우트 구성(미들웨어·확장 지점)이 규약대로인지를 본다.
 * 이 둘이 어긋나면 가드는 "정상 동작" 하면서도 잘못된 대상을 통과시킨다.
 */
class IdentityPermissionContractTest extends TestCase
{
    use RefreshDatabase;

    /** IDV 사용자 권한 3종. */
    private const IDV_PERMISSIONS = [
        'core.identity.request',
        'core.identity.verify',
        'core.identity.cancel',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @scenario actor_role=user_with_perm, challenge_owner=self, endpoint=verify
     *
     * @effects role_permission_seeder_syncs_idv_permissions_with_owner_metadata
     */
    public function test_seeder_syncs_idv_permissions_with_owner_metadata(): void
    {
        foreach (self::IDV_PERMISSIONS as $identifier) {
            $permission = Permission::where('identifier', $identifier)->first();
            $this->assertNotNull($permission, "{$identifier} 권한이 시드되지 않았다");
        }

        // verify/cancel 은 소유자 판정 대상이므로 resource_route_key + owner_key 가 있어야 한다.
        // 이 메타가 없으면 PermissionMiddleware 가 scope=self 를 평가할 근거를 잃고 통과시킨다.
        foreach (['core.identity.verify', 'core.identity.cancel'] as $identifier) {
            $permission = Permission::where('identifier', $identifier)->first();
            $this->assertSame('challenge', $permission->resource_route_key, "{$identifier} 의 resource_route_key 불일치");
            $this->assertSame('user_id', $permission->owner_key, "{$identifier} 의 owner_key 불일치");
        }

        // request 는 대상 challenge 가 아직 없으므로 소유자 메타를 갖지 않는다.
        $request = Permission::where('identifier', 'core.identity.request')->first();
        $this->assertNull($request->resource_route_key);
        $this->assertNull($request->owner_key);
    }

    /**
     * @scenario actor_role=user_with_perm, challenge_owner=other, endpoint=cancel
     *
     * @effects role_permission_seeder_assigns_self_scope_to_user_role_idv_perms
     */
    public function test_seeder_assigns_self_scope_to_user_role_idv_permissions(): void
    {
        $userRole = Role::where('identifier', 'user')->first();
        $this->assertNotNull($userRole);

        $scopes = $userRole->permissions()
            ->whereIn('identifier', self::IDV_PERMISSIONS)
            ->pluck('role_permissions.scope_type', 'identifier')
            ->all();

        // verify/cancel 만 self — 이 값이 비면 남의 challenge 도 통과한다
        $this->assertSame('self', $scopes['core.identity.verify'] ?? null);
        $this->assertSame('self', $scopes['core.identity.cancel'] ?? null);

        // request 는 대상이 없어 scope 를 갖지 않는다
        $this->assertNull($scopes['core.identity.request'] ?? null);
    }

    /**
     * @scenario actor_role=guest, challenge_owner=other, endpoint=cancel
     *
     * @effects role_permission_seeder_grants_idv_perms_to_guest_without_scope
     */
    public function test_seeder_grants_idv_permissions_to_guest_role_without_scope(): void
    {
        $guestRole = Role::where('identifier', 'guest')->first();
        $this->assertNotNull($guestRole, 'guest 역할이 시드되지 않았다');

        $scopes = $guestRole->permissions()
            ->whereIn('identifier', self::IDV_PERMISSIONS)
            ->pluck('role_permissions.scope_type', 'identifier')
            ->all();

        // guest 는 IDV 3종 전부 보유 (Mode B 가입 흐름)
        foreach (self::IDV_PERMISSIONS as $identifier) {
            $this->assertArrayHasKey($identifier, $scopes, "guest 역할에 {$identifier} 미부여");
            // scope 가 붙으면 user_id 없는 게스트가 항상 거부되어 가입 흐름이 막힌다
            $this->assertNull($scopes[$identifier], "guest 의 {$identifier} 에 scope 가 붙어 있다");
        }
    }

    /**
     * @scenario actor_role=guest, challenge_owner=other, endpoint=verify
     *
     * @effects guest_role_idv_permission_check_via_guest_role_cache
     */
    public function test_guest_role_idv_permission_is_visible_through_guest_role_lookup(): void
    {
        $guestRole = Role::where('identifier', 'guest')->first();

        // 비로그인 요청의 권한 판정은 이 guest 역할 조회 결과에 전적으로 의존한다.
        // 조회가 비면 게스트의 IDV 흐름 전체가 403 이 된다.
        $identifiers = $guestRole->permissions()->pluck('identifier')->all();

        foreach (self::IDV_PERMISSIONS as $identifier) {
            $this->assertContains($identifier, $identifiers);
        }
    }

    /**
     * @scenario actor_role=guest, challenge_owner=none, endpoint=request
     *
     * @effects cancel_endpoint_via_permission_middleware_does_not_require_legacy_auth_sanctum
     */
    public function test_idv_challenge_routes_use_optional_sanctum_not_auth_sanctum(): void
    {
        $names = [
            'api.identity.challenges.request',
            'api.identity.challenges.verify',
            'api.identity.challenges.cancel',
        ];

        foreach ($names as $name) {
            $route = RouteFacade::getRoutes()->getByName($name);
            $this->assertNotNull($route, "{$name} 라우트가 없다");

            $middleware = $route->gatherMiddleware();

            // auth:sanctum 이 붙으면 비로그인 게스트가 401 로 막혀 Mode B 가입이 불가능해진다.
            $this->assertNotContains('auth:sanctum', $middleware, "{$name} 에 auth:sanctum 이 붙어 있다");
            $this->assertContains('optional.sanctum', $middleware, "{$name} 에 optional.sanctum 이 없다");

            // 권한 판정은 permission 미들웨어가 단독으로 담당한다
            $hasPermission = collect($middleware)->contains(fn ($m) => str_starts_with((string) $m, 'permission:user,core.identity.'));
            $this->assertTrue($hasPermission, "{$name} 에 permission 미들웨어가 없다");
        }
    }

    /**
     * @scenario actor_role=user_with_perm, challenge_owner=none, endpoint=request
     *
     * @effects request_challenge_route_validation_rules_extensible_via_hook_filter
     */
    public function test_request_challenge_rules_are_extensible_via_hook_filter(): void
    {
        HookManager::addFilter('core.identity.request_validation_rules', function (array $rules) {
            $rules['extra_field'] = ['required', 'string'];

            return $rules;
        }, 10, 'test-listener');

        $rules = (new RequestChallengeRequest)->rules();

        $this->assertArrayHasKey('extra_field', $rules, '확장이 추가한 규칙이 반영되지 않았다');
        $this->assertArrayHasKey('purpose', $rules, '기본 규칙이 확장 때문에 사라졌다');
    }

    /**
     * @scenario actor_role=user_with_perm, challenge_owner=self, endpoint=verify
     *
     * @effects verify_challenge_route_validation_rules_extensible_via_hook_filter
     */
    public function test_verify_challenge_rules_are_extensible_via_hook_filter(): void
    {
        HookManager::addFilter('core.identity.verify_validation_rules', function (array $rules) {
            $rules['device_token'] = ['nullable', 'string'];

            return $rules;
        }, 10, 'test-listener');

        $rules = (new VerifyChallengeRequest)->rules();

        $this->assertArrayHasKey('device_token', $rules);
    }

    /**
     * @scenario actor_role=user_with_perm, challenge_owner=other, endpoint=verify
     *
     * @effects cancel_form_request_authorize_returns_true_letting_route_middleware_decide
     */
    public function test_cancel_form_request_authorize_defers_to_route_middleware(): void
    {
        // FormRequest 가 자체 인증/권한 판정을 하면 라우트 미들웨어와 판정이 둘로 갈린다.
        // 코어 규약은 authorize(): true 고정 + permission 미들웨어 단일 게이트다.
        $this->assertTrue((new CancelChallengeRequest)->authorize());
        $this->assertSame([], (new CancelChallengeRequest)->rules());
    }
}
