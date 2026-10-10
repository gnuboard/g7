<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\IdentityPolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 환경설정 「본인인증 정책」 탭의 권한 게이트 회귀 테스트 (제품 결정 2026-10-10)
 *
 * 이 탭은 이커머스 모듈 권한 `sirsoft-ecommerce.identity.policies.read`(조회)·`.update`(추가·수정·삭제)로 동작한다.
 * - 목록 API 는 조회 권한이 없으면 403, 있으면 이커머스 몫(source_identifier=sirsoft-ecommerce) 정책만 내린다
 * - 응답 abilities(can_create·can_update·can_delete)는 라우트가 요구하는 수정 권한과 같은 축이다
 * - 추가·수정·삭제 API 는 수정 권한이 없으면 403, 이커머스 몫이 아닌 정책은 찾을 수 없음(404)
 * - 새 정책은 요청 본문과 무관하게 이커머스 몫으로 만든다
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/abilities-gate-710.yaml 참조.
 */
class EcommerceIdentityPolicyPermissionGateTest extends ModuleTestCase
{
    private const BASE = '/api/modules/sirsoft-ecommerce/admin/identity-policies';

    private IdentityPolicy $modulePolicy;

    private IdentityPolicy $corePolicy;

    /**
     * 이커머스 몫 정책 하나와 코어 몫 정책 하나를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = substr(md5(uniqid('', true)), 0, 8);

        $this->modulePolicy = IdentityPolicy::create([
            'key' => 'gate-ecom-'.$suffix,
            'scope' => 'route',
            'target' => 'api.modules.sirsoft-ecommerce.gate.ecom.'.$suffix,
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'admin',
            'source_identifier' => 'sirsoft-ecommerce',
            'applies_to' => 'self',
            'fail_mode' => 'block',
        ]);

        $this->corePolicy = IdentityPolicy::create([
            'key' => 'gate-core-'.$suffix,
            'scope' => 'route',
            'target' => 'gate.core.'.$suffix,
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'admin',
            'source_identifier' => 'core',
            'applies_to' => 'self',
            'fail_mode' => 'block',
        ]);
    }

    /**
     * 주어진 권한만 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $identifiers  부여할 권한 식별자
     * @return User 관리자
     */
    private function adminWith(array $identifiers): User
    {
        $role = Role::create([
            'identifier' => 'idgate_'.uniqid(),
            'name' => ['ko' => '본인인증 게이트 역할', 'en' => 'Identity Gate Role'],
            'is_active' => true,
        ]);

        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $role->permissions()->attach($permission->id, ['scope_type' => null, 'granted_at' => now()]);
        }

        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 새 정책 요청 본문을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 요청 본문
     */
    private function newPolicyPayload(array $overrides = []): array
    {
        $suffix = substr(md5(uniqid('', true)), 0, 8);

        return array_merge([
            'key' => 'gate-new-'.$suffix,
            'scope' => 'route',
            'target' => 'api.modules.sirsoft-ecommerce.gate.new.'.$suffix,
            'purpose' => 'sensitive_action',
            'provider_id' => null,
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'applies_to' => 'self',
            'fail_mode' => 'block',
        ], $overrides);
    }

    /**
     * 환경설정 권한만 있고 본인인증 정책 조회 권한이 없으면 목록을 받지 못한다.
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects identity_policy_list_requires_module_read
     *
     * @return void 반환값 없음
     */
    public function test_list_is_forbidden_without_identity_read_permission(): void
    {
        $settingsAdmin = $this->adminWith(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update']);

        $this->actingAs($settingsAdmin)
            ->getJson(self::BASE)
            ->assertForbidden();
    }

    /**
     * 조회 권한만 있으면 이커머스 몫 정책만 받고, 쓰기 abilities 는 모두 false 다.
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects identity_policy_list_scoped_to_module
     * @effects identity_policy_abilities_follow_module_update
     *
     * @return void 반환값 없음
     */
    public function test_reader_gets_only_ecommerce_policies_with_locked_abilities(): void
    {
        $reader = $this->adminWith(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.identity.policies.read']);

        // 다른 출처를 요청해도 이커머스 몫으로 좁힌다
        $response = $this->actingAs($reader)
            ->getJson(self::BASE.'?per_page=100&source_identifier=core')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_create', false)
            ->assertJsonPath('data.abilities.can_update', false)
            ->assertJsonPath('data.abilities.can_delete', false);

        $rows = collect($response->json('data.data'));
        $this->assertTrue($rows->contains('key', $this->modulePolicy->key));
        $this->assertFalse($rows->contains('key', $this->corePolicy->key));
        $this->assertSame(['sirsoft-ecommerce'], $rows->pluck('source_identifier')->unique()->values()->all());

        $row = $rows->firstWhere('key', $this->modulePolicy->key);
        $this->assertFalse($row['abilities']['can_update']);
        $this->assertFalse($row['abilities']['can_delete']);
    }

    /**
     * 수정 권한이 있으면 목록 abilities 가 모두 true 다.
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects identity_policy_abilities_follow_module_update
     *
     * @return void 반환값 없음
     */
    public function test_updater_list_reports_write_abilities(): void
    {
        $updater = $this->adminWith(['sirsoft-ecommerce.identity.policies.read', 'sirsoft-ecommerce.identity.policies.update']);

        $response = $this->actingAs($updater)
            ->getJson(self::BASE.'?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_create', true)
            ->assertJsonPath('data.abilities.can_update', true)
            ->assertJsonPath('data.abilities.can_delete', true);

        $row = collect($response->json('data.data'))->firstWhere('key', $this->modulePolicy->key);
        $this->assertTrue($row['abilities']['can_update']);
    }

    /**
     * 조회 권한만 있으면 추가·수정·삭제가 모두 403 이고 정책은 그대로다.
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects identity_policy_write_requires_module_update
     *
     * @return void 반환값 없음
     */
    public function test_writes_are_forbidden_without_update_permission(): void
    {
        $reader = $this->adminWith([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
            'sirsoft-ecommerce.identity.policies.read',
        ]);

        $this->actingAs($reader)
            ->postJson(self::BASE, $this->newPolicyPayload())
            ->assertForbidden();

        $this->actingAs($reader)
            ->putJson(self::BASE.'/'.$this->modulePolicy->id, ['enabled' => false])
            ->assertForbidden();

        $this->actingAs($reader)
            ->deleteJson(self::BASE.'/'.$this->modulePolicy->id)
            ->assertForbidden();

        $this->assertTrue((bool) $this->modulePolicy->fresh()->enabled);
    }

    /**
     * 수정 권한이 있으면 이커머스 몫 정책을 고치고 지울 수 있다.
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects identity_policy_write_requires_module_update
     *
     * @return void 반환값 없음
     */
    public function test_updater_can_update_and_delete_ecommerce_policy(): void
    {
        $updater = $this->adminWith(['sirsoft-ecommerce.identity.policies.read', 'sirsoft-ecommerce.identity.policies.update']);

        $this->actingAs($updater)
            ->putJson(self::BASE.'/'.$this->modulePolicy->id, ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertFalse((bool) $this->modulePolicy->fresh()->enabled);

        $this->actingAs($updater)
            ->deleteJson(self::BASE.'/'.$this->modulePolicy->id)
            ->assertOk();

        $this->assertNull(IdentityPolicy::find($this->modulePolicy->id));
    }

    /**
     * 이커머스 몫이 아닌 정책은 수정 권한이 있어도 이 경로로 고치거나 지울 수 없다.
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects identity_policy_write_scoped_to_module
     *
     * @return void 반환값 없음
     */
    public function test_foreign_policy_is_not_reachable_through_ecommerce_route(): void
    {
        $updater = $this->adminWith(['sirsoft-ecommerce.identity.policies.read', 'sirsoft-ecommerce.identity.policies.update']);

        $this->actingAs($updater)
            ->putJson(self::BASE.'/'.$this->corePolicy->id, ['enabled' => false])
            ->assertNotFound();

        $this->actingAs($updater)
            ->deleteJson(self::BASE.'/'.$this->corePolicy->id)
            ->assertNotFound();

        $this->assertNotNull(IdentityPolicy::find($this->corePolicy->id));
        $this->assertTrue((bool) $this->corePolicy->fresh()->enabled);
    }

    /**
     * 새 정책은 본문이 다른 출처를 적어 보내도 이커머스 몫·운영자 정책으로 만든다.
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects identity_policy_write_scoped_to_module
     *
     * @return void 반환값 없음
     */
    public function test_store_always_creates_ecommerce_owned_admin_policy(): void
    {
        $updater = $this->adminWith(['sirsoft-ecommerce.identity.policies.read', 'sirsoft-ecommerce.identity.policies.update']);
        $payload = $this->newPolicyPayload(['source_identifier' => 'core']);

        $this->actingAs($updater)
            ->postJson(self::BASE, $payload)
            ->assertCreated()
            ->assertJsonPath('data.source_identifier', 'sirsoft-ecommerce');

        $created = IdentityPolicy::where('key', $payload['key'])->first();
        $this->assertNotNull($created);
        $this->assertSame('sirsoft-ecommerce', $created->source_identifier);
        $this->assertSame('admin', $created->source_type instanceof \BackedEnum ? $created->source_type->value : $created->source_type);
    }

    /**
     * 수정 권한만으로는 이커머스 밖의 지점(코어 로그인 경로·코어 훅)을 겨누는 정책을 만들 수 없다.
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects identity_policy_target_limited_to_module
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_target_outside_ecommerce(): void
    {
        $updater = $this->adminWith(['sirsoft-ecommerce.identity.policies.read', 'sirsoft-ecommerce.identity.policies.update']);

        foreach ([
            ['scope' => 'route', 'target' => 'api.auth.login'],
            ['scope' => 'hook', 'target' => 'core.auth.before_login'],
            ['scope' => 'custom', 'target' => 'core.custom.anything'],
            ['scope' => 'route', 'target' => 'api.modules.sirsoft-ecommerce-evil.admin.x'],
        ] as $foreign) {
            $payload = $this->newPolicyPayload($foreign);

            $this->actingAs($updater)
                ->postJson(self::BASE, $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('target');

            $this->assertNull(IdentityPolicy::where('key', $payload['key'])->first());
        }
    }

    /**
     * 이커머스 훅·경로를 겨누는 정책은 수정 권한으로 만들 수 있다.
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects identity_policy_target_limited_to_module
     *
     * @return void 반환값 없음
     */
    public function test_store_accepts_ecommerce_owned_targets(): void
    {
        $updater = $this->adminWith(['sirsoft-ecommerce.identity.policies.read', 'sirsoft-ecommerce.identity.policies.update']);

        foreach ([
            ['scope' => 'hook', 'target' => 'sirsoft-ecommerce.payment.before_cancel'],
            ['scope' => 'route', 'target' => 'api.modules.sirsoft-ecommerce.admin.example'],
            ['scope' => 'custom', 'target' => 'sirsoft-ecommerce.custom.example'],
        ] as $own) {
            $payload = $this->newPolicyPayload($own);

            $this->actingAs($updater)
                ->postJson(self::BASE, $payload)
                ->assertCreated()
                ->assertJsonPath('data.target', $own['target']);
        }
    }

    /**
     * 운영자 정책의 범위·대상을 이커머스 밖으로 바꾸는 수정은 거부되고 정책은 그대로다.
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects identity_policy_target_limited_to_module
     *
     * @return void 반환값 없음
     */
    public function test_update_rejects_moving_target_outside_ecommerce(): void
    {
        $updater = $this->adminWith(['sirsoft-ecommerce.identity.policies.read', 'sirsoft-ecommerce.identity.policies.update']);
        $originalTarget = $this->modulePolicy->target;

        $this->actingAs($updater)
            ->putJson(self::BASE.'/'.$this->modulePolicy->id, ['target' => 'api.auth.login'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');

        // 대상은 그대로 두고 범위만 바꿔 기존 대상이 새 범위의 이커머스 몫이 아니게 되는 경우
        $this->actingAs($updater)
            ->putJson(self::BASE.'/'.$this->modulePolicy->id, ['scope' => 'hook'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');

        $fresh = $this->modulePolicy->fresh();
        $this->assertSame($originalTarget, $fresh->target);
        $this->assertSame('route', $fresh->scope instanceof \BackedEnum ? $fresh->scope->value : $fresh->scope);
    }

    /**
     * 모듈은 본인인증 정책 조회·수정 권한을 선언하고 기본 관리자 역할(admin)에 준다.
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects identity_permission_granted_to_admin_by_default
     *
     * @return void 반환값 없음
     */
    public function test_module_declares_identity_policy_permissions_for_admin(): void
    {
        $categories = collect((new Module)->getPermissions()['categories'] ?? []);
        $category = $categories->firstWhere('identifier', 'identity.policies');

        $this->assertNotNull($category);

        $actions = collect($category['permissions'])->keyBy('action');
        $this->assertSame(['read', 'update'], $actions->keys()->sort()->values()->all());
        $this->assertContains('admin', $actions['read']['roles']);
        $this->assertContains('admin', $actions['update']['roles']);
    }
}
