<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\IdentityPolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 환경설정 「본인인증 정책」 탭의 권한 게이트 회귀 테스트 (제품 결정 2026-10-10)
 *
 * 이 탭은 게시판 모듈 권한 `sirsoft-board.identity.policies.read`(조회)·`.update`(추가·수정·삭제)로 동작한다.
 * - 목록 API 는 조회 권한이 없으면 403, 있으면 게시판 몫(source_identifier=sirsoft-board) 정책만 내린다
 * - 응답 abilities(can_create·can_update·can_delete)는 라우트가 요구하는 수정 권한과 같은 축이다
 * - 추가·수정·삭제 API 는 수정 권한이 없으면 403, 게시판 몫이 아닌 정책은 찾을 수 없음(404)
 * - 새 정책은 요청 본문과 무관하게 게시판 몫으로 만든다
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-admin-ability-gate.yaml 참조.
 */
class BoardIdentityPolicyPermissionGateTest extends ModuleTestCase
{
    private const BASE = '/api/modules/sirsoft-board/admin/identity-policies';

    private IdentityPolicy $boardPolicy;

    private IdentityPolicy $corePolicy;

    /**
     * 게시판 몫 정책 하나와 코어 몫 정책 하나를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = substr(md5(uniqid('', true)), 0, 8);

        $this->boardPolicy = IdentityPolicy::create([
            'key' => 'gate-board-'.$suffix,
            'scope' => 'route',
            'target' => 'api.modules.sirsoft-board.gate.board.'.$suffix,
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'admin',
            'source_identifier' => 'sirsoft-board',
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
            'target' => 'api.modules.sirsoft-board.gate.new.'.$suffix,
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
     * @scenario case=identity_list_without_read
     *
     * @effects identity_policy_list_requires_module_read
     *
     * @return void 반환값 없음
     */
    public function test_list_is_forbidden_without_identity_read_permission(): void
    {
        $settingsAdmin = $this->adminWith(['sirsoft-board.settings.read', 'sirsoft-board.settings.update']);

        $this->actingAs($settingsAdmin)
            ->getJson(self::BASE)
            ->assertForbidden();
    }

    /**
     * 조회 권한만 있으면 게시판 몫 정책만 받고, 쓰기 abilities 는 모두 false 다.
     *
     * @scenario case=identity_list_read_only
     *
     * @effects identity_policy_list_scoped_to_board
     * @effects identity_policy_abilities_follow_module_update
     *
     * @return void 반환값 없음
     */
    public function test_reader_gets_only_board_policies_with_locked_abilities(): void
    {
        $reader = $this->adminWith(['sirsoft-board.settings.read', 'sirsoft-board.identity.policies.read']);

        // 다른 출처를 요청해도 게시판 몫으로 좁힌다
        $response = $this->actingAs($reader)
            ->getJson(self::BASE.'?per_page=100&source_identifier=core')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_create', false)
            ->assertJsonPath('data.abilities.can_update', false)
            ->assertJsonPath('data.abilities.can_delete', false);

        $rows = collect($response->json('data.data'));
        $this->assertTrue($rows->contains('key', $this->boardPolicy->key));
        $this->assertFalse($rows->contains('key', $this->corePolicy->key));
        $this->assertSame(['sirsoft-board'], $rows->pluck('source_identifier')->unique()->values()->all());

        $row = $rows->firstWhere('key', $this->boardPolicy->key);
        $this->assertFalse($row['abilities']['can_update']);
        $this->assertFalse($row['abilities']['can_delete']);
    }

    /**
     * 수정 권한이 있으면 목록 abilities 가 모두 true 다.
     *
     * @scenario case=identity_list_with_update
     *
     * @effects identity_policy_abilities_follow_module_update
     *
     * @return void 반환값 없음
     */
    public function test_updater_list_reports_write_abilities(): void
    {
        $updater = $this->adminWith(['sirsoft-board.identity.policies.read', 'sirsoft-board.identity.policies.update']);

        $response = $this->actingAs($updater)
            ->getJson(self::BASE.'?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_create', true)
            ->assertJsonPath('data.abilities.can_update', true)
            ->assertJsonPath('data.abilities.can_delete', true);

        $row = collect($response->json('data.data'))->firstWhere('key', $this->boardPolicy->key);
        $this->assertTrue($row['abilities']['can_update']);
    }

    /**
     * 조회 권한만 있으면 추가·수정·삭제가 모두 403 이고 정책은 그대로다.
     *
     * @scenario case=identity_write_read_only
     *
     * @effects identity_policy_write_requires_module_update
     *
     * @return void 반환값 없음
     */
    public function test_writes_are_forbidden_without_update_permission(): void
    {
        $reader = $this->adminWith([
            'sirsoft-board.settings.read',
            'sirsoft-board.settings.update',
            'sirsoft-board.identity.policies.read',
        ]);

        $this->actingAs($reader)
            ->postJson(self::BASE, $this->newPolicyPayload())
            ->assertForbidden();

        $this->actingAs($reader)
            ->putJson(self::BASE.'/'.$this->boardPolicy->id, ['enabled' => false])
            ->assertForbidden();

        $this->actingAs($reader)
            ->deleteJson(self::BASE.'/'.$this->boardPolicy->id)
            ->assertForbidden();

        $this->assertTrue((bool) $this->boardPolicy->fresh()->enabled);
    }

    /**
     * 수정 권한이 있으면 게시판 몫 정책을 고치고 지울 수 있다.
     *
     * @scenario case=identity_write_with_update
     *
     * @effects identity_policy_write_requires_module_update
     *
     * @return void 반환값 없음
     */
    public function test_updater_can_update_and_delete_board_policy(): void
    {
        $updater = $this->adminWith(['sirsoft-board.identity.policies.read', 'sirsoft-board.identity.policies.update']);

        $this->actingAs($updater)
            ->putJson(self::BASE.'/'.$this->boardPolicy->id, ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertFalse((bool) $this->boardPolicy->fresh()->enabled);

        $this->actingAs($updater)
            ->deleteJson(self::BASE.'/'.$this->boardPolicy->id)
            ->assertOk();

        $this->assertNull(IdentityPolicy::find($this->boardPolicy->id));
    }

    /**
     * 게시판 몫이 아닌 정책은 수정 권한이 있어도 이 경로로 고치거나 지울 수 없다.
     *
     * @scenario case=identity_write_foreign_policy
     *
     * @effects identity_policy_write_scoped_to_board
     *
     * @return void 반환값 없음
     */
    public function test_foreign_policy_is_not_reachable_through_board_route(): void
    {
        $updater = $this->adminWith(['sirsoft-board.identity.policies.read', 'sirsoft-board.identity.policies.update']);

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
     * 새 정책은 본문이 다른 출처를 적어 보내도 게시판 몫·운영자 정책으로 만든다.
     *
     * @scenario case=identity_store_source
     *
     * @effects identity_policy_write_scoped_to_board
     *
     * @return void 반환값 없음
     */
    public function test_store_always_creates_board_owned_admin_policy(): void
    {
        $updater = $this->adminWith(['sirsoft-board.identity.policies.read', 'sirsoft-board.identity.policies.update']);
        $payload = $this->newPolicyPayload(['source_identifier' => 'core']);

        $this->actingAs($updater)
            ->postJson(self::BASE, $payload)
            ->assertCreated()
            ->assertJsonPath('data.source_identifier', 'sirsoft-board');

        $created = IdentityPolicy::where('key', $payload['key'])->first();
        $this->assertNotNull($created);
        $this->assertSame('sirsoft-board', $created->source_identifier);
        $this->assertSame('admin', $created->source_type instanceof \BackedEnum ? $created->source_type->value : $created->source_type);
    }

    /**
     * 수정 권한만으로는 게시판 밖의 지점(코어 로그인 경로·코어 훅)을 겨누는 정책을 만들 수 없다.
     *
     * @scenario case=identity_target_outside_module
     *
     * @effects identity_policy_target_limited_to_board
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_target_outside_board(): void
    {
        $updater = $this->adminWith(['sirsoft-board.identity.policies.read', 'sirsoft-board.identity.policies.update']);

        foreach ([
            ['scope' => 'route', 'target' => 'api.auth.login'],
            ['scope' => 'hook', 'target' => 'core.auth.before_login'],
            ['scope' => 'custom', 'target' => 'core.custom.anything'],
            ['scope' => 'route', 'target' => 'api.modules.sirsoft-board-evil.admin.x'],
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
     * 게시판 훅·경로를 겨누는 정책은 수정 권한으로 만들 수 있다.
     *
     * @scenario case=identity_target_inside_module
     *
     * @effects identity_policy_target_limited_to_board
     *
     * @return void 반환값 없음
     */
    public function test_store_accepts_board_owned_targets(): void
    {
        $updater = $this->adminWith(['sirsoft-board.identity.policies.read', 'sirsoft-board.identity.policies.update']);

        foreach ([
            ['scope' => 'hook', 'target' => 'sirsoft-board.post.before_delete'],
            ['scope' => 'route', 'target' => 'api.modules.sirsoft-board.admin.example'],
            ['scope' => 'custom', 'target' => 'sirsoft-board.custom.example'],
        ] as $own) {
            $payload = $this->newPolicyPayload($own);

            $this->actingAs($updater)
                ->postJson(self::BASE, $payload)
                ->assertCreated()
                ->assertJsonPath('data.target', $own['target']);
        }
    }

    /**
     * 운영자 정책의 범위·대상을 게시판 밖으로 바꾸는 수정은 거부되고 정책은 그대로다.
     *
     * @scenario case=identity_target_outside_module
     *
     * @effects identity_policy_target_limited_to_board
     *
     * @return void 반환값 없음
     */
    public function test_update_rejects_moving_target_outside_board(): void
    {
        $updater = $this->adminWith(['sirsoft-board.identity.policies.read', 'sirsoft-board.identity.policies.update']);
        $originalTarget = $this->boardPolicy->target;

        $this->actingAs($updater)
            ->putJson(self::BASE.'/'.$this->boardPolicy->id, ['target' => 'api.auth.login'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');

        // 대상은 그대로 두고 범위만 바꿔 기존 대상이 새 범위의 게시판 몫이 아니게 되는 경우
        $this->actingAs($updater)
            ->putJson(self::BASE.'/'.$this->boardPolicy->id, ['scope' => 'hook'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');

        $fresh = $this->boardPolicy->fresh();
        $this->assertSame($originalTarget, $fresh->target);
        $this->assertSame('route', $fresh->scope instanceof \BackedEnum ? $fresh->scope->value : $fresh->scope);
    }

    /**
     * 7.0.11 에서 자유 입력으로 만든, 게시판 밖 지점을 겨누는 게시판 몫 운영자 정책을 만듭니다.
     *
     * @return IdentityPolicy 게시판 밖 대상을 가진 기존 정책
     */
    private function legacyForeignTargetPolicy(): IdentityPolicy
    {
        $suffix = substr(md5(uniqid('', true)), 0, 8);

        return IdentityPolicy::create([
            'key' => 'gate-legacy-'.$suffix,
            'scope' => 'route',
            'target' => 'api.legacy.foreign.'.$suffix,
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'admin',
            'source_identifier' => 'sirsoft-board',
            'applies_to' => 'self',
            'fail_mode' => 'block',
        ]);
    }

    /**
     * 대상을 바꾸지 않는 수정(사용 토글·다른 칸 수정)은 기존 정책의 대상이 게시판 밖이어도 된다 — 7.0.11 에서 만든
     * 정책이 업데이트 뒤 토글조차 422 가 되던 결함(B7-1)의 회귀 잠금. 편집 모달처럼 같은 범위·대상을 다시 보내도 같다.
     *
     * @scenario case=identity_legacy_target_unchanged
     *
     * @effects identity_policy_unchanged_target_skips_ownership
     *
     * @return void 반환값 없음
     */
    public function test_update_without_target_change_is_allowed_for_legacy_foreign_target(): void
    {
        $updater = $this->adminWith(['sirsoft-board.identity.policies.read', 'sirsoft-board.identity.policies.update']);
        $legacy = $this->legacyForeignTargetPolicy();

        // 목록의 사용 토글 — 본문은 enabled 하나뿐
        $this->actingAs($updater)
            ->putJson(self::BASE.'/'.$legacy->id, ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        // 편집 모달 저장 — 범위·대상을 그대로 다시 보내며 다른 칸만 고친다
        $this->actingAs($updater)
            ->putJson(self::BASE.'/'.$legacy->id, [
                'scope' => 'route',
                'target' => $legacy->target,
                'grace_minutes' => 30,
                'enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.grace_minutes', 30);

        $fresh = $legacy->fresh();
        $this->assertTrue((bool) $fresh->enabled);
        $this->assertSame(30, (int) $fresh->grace_minutes);
        $this->assertSame($legacy->target, $fresh->target);
    }

    /**
     * 게시판 밖 대상을 가진 기존 정책이라도 대상·범위를 새로 지정하면 소유 검사를 받는다 — 다른 게시판 밖 지점으로
     * 옮기거나 범위만 바꾸는 것은 422, 게시판 지점으로 옮기는 것은 허용.
     *
     * @scenario case=identity_legacy_target_changed
     *
     * @effects identity_policy_target_limited_to_board
     *
     * @return void 반환값 없음
     */
    public function test_update_changing_target_of_legacy_policy_is_checked(): void
    {
        $updater = $this->adminWith(['sirsoft-board.identity.policies.read', 'sirsoft-board.identity.policies.update']);
        $legacy = $this->legacyForeignTargetPolicy();
        $originalTarget = $legacy->target;

        $this->actingAs($updater)
            ->putJson(self::BASE.'/'.$legacy->id, ['target' => 'api.auth.login'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');

        $this->actingAs($updater)
            ->putJson(self::BASE.'/'.$legacy->id, ['scope' => 'hook'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');

        $fresh = $legacy->fresh();
        $this->assertSame($originalTarget, $fresh->target);
        $this->assertSame('route', $fresh->scope instanceof \BackedEnum ? $fresh->scope->value : $fresh->scope);

        $this->actingAs($updater)
            ->putJson(self::BASE.'/'.$legacy->id, ['target' => 'api.modules.sirsoft-board.legacy.moved'])
            ->assertOk()
            ->assertJsonPath('data.target', 'api.modules.sirsoft-board.legacy.moved');
    }

    /**
     * 모듈은 본인인증 정책 조회·수정 권한을 선언하고 기본 관리자 역할(admin)에 준다.
     *
     * @scenario case=identity_permission_declared
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
