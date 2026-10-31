<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 권한(ACL) 자기부여 차단 회귀 테스트 (KVE-2026-2367 + 형제)
 *
 * 게시판 권한 저장은 역할을 게시판 권한에 붙이는 조작이고, 역할을 붙이는 것은 그 역할의 회원
 * 전원에게 그 권한을 주는 것이다. 상한 검사가 없어 게시판 수정 권한만 가진 위임 관리자가 자기
 * 역할을 admin.manage·비밀글 열람에 붙여 타인 글을 블라인드했다. 같은 조작을 하는 세 경로 —
 * 게시판 수정(PUT)·게시판 생성(POST)·환경설정 일괄 적용(저장된 기본 권한) — 와 신고 관리 권한의
 * 역할 재할당이 모두 같은 상한(액터가 그 권한을 전체 범위로 가졌는가)을 받는지 본다. 각 차단
 * 케이스는 403 과 함께 매핑·다른 변경이 그대로인지(부분 저장 없음)를 확인하고, 정상 흐름(변경
 * 없는 저장·자기가 가진 권한의 부여·슈퍼관리자)은 그대로 되는지 과차단 축으로 잠근다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-acl-escalation-ceiling.yaml 참조.
 */
class BoardPermissionEscalationTest extends ModuleTestCase
{
    private User $superAdmin;

    private Board $board;

    /**
     * 기본 게시판 유형·슈퍼관리자와 기본 ACL 로 만든 게시판을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        BoardType::firstOrCreate(
            ['slug' => 'basic'],
            ['name' => ['ko' => '기본', 'en' => 'Basic'], 'is_active' => true, 'is_default' => true]
        );

        $this->superAdmin = User::factory()->create(['is_super' => true, 'status' => 'active']);
        $this->superAdmin->roles()->attach(Role::where('identifier', 'admin')->firstOrFail()->id);
        $this->grantAdminRoleBoardPermissions();

        $this->board = $this->createBoardAs($this->superAdmin, 'kve2367-'.substr(md5(uniqid('', true)), 0, 8));
    }

    /**
     * 주어진 액터로 게시판을 서비스 경유로 만듭니다 (기본 ACL + 게시판 관리자 역할 생성).
     *
     * @param  User  $actor  생성 주체
     * @param  string  $slug  게시판 슬러그
     * @return Board 생성된 게시판
     */
    private function createBoardAs(User $actor, string $slug): Board
    {
        $this->actingAs($actor);

        $board = app(BoardService::class)->createBoard([
            'name' => ['ko' => 'KVE 게시판', 'en' => 'KVE Board'],
            'slug' => $slug,
            'type' => 'basic',
            'board_manager_ids' => [$actor->uuid],
        ]);

        auth()->forgetGuards();

        return $board;
    }

    /**
     * 게시판 권한 없이 지정 권한만 가진 위임 관리자와 그 역할을 만듭니다.
     *
     * @param  array<int, string>  $permissionIdentifiers  부여할 권한 식별자
     * @return array{0: User, 1: Role} 위임 관리자와 그 역할
     */
    private function createDelegate(array $permissionIdentifiers): array
    {
        $role = Role::create([
            'identifier' => 'kve2367_delegate_'.uniqid(),
            'name' => ['ko' => '위임 관리자', 'en' => 'Delegate'],
            'is_active' => true,
        ]);

        foreach ($permissionIdentifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $role->permissions()->attach($permission->id, ['scope_type' => null, 'granted_at' => now()]);
        }

        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return [$user->fresh(), $role];
    }

    /**
     * 게시판 권한에 붙은 역할 식별자를 정렬해 돌려줍니다.
     *
     * @param  string  $key  권한 키 (admin.manage 등)
     * @param  Board|null  $board  대상 게시판 (null 이면 기본 게시판)
     * @return array<int, string> 역할 식별자 목록
     */
    private function boardPermissionRoles(string $key, ?Board $board = null): array
    {
        $slug = ($board ?? $this->board)->slug;

        return Permission::where('identifier', "sirsoft-board.{$slug}.{$key}")->firstOrFail()
            ->roles()->pluck('identifier')->sort()->values()->all();
    }

    /**
     * 슈퍼관리자가 쓰는 admin 역할에 게시판 관리·환경설정 권한을 붙입니다.
     *
     * 권한 미들웨어는 슈퍼관리자도 역할이 가진 권한으로 판정한다 — 테스트의 admin 역할은 빈
     * 역할로 만들어지므로 라우트 권한을 명시적으로 붙인다.
     *
     * @return void 반환값 없음
     */
    private function grantAdminRoleBoardPermissions(): void
    {
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();

        foreach ([
            'sirsoft-board.boards.read',
            'sirsoft-board.boards.create',
            'sirsoft-board.boards.update',
            'sirsoft-board.settings.read',
            'sirsoft-board.settings.update',
        ] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    /**
     * 게시판 생성 요청 본문을 만듭니다 (StoreBoardRequest 필수 항목 포함).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int, string>  $managerUuids  게시판 관리자 UUID 목록
     * @param  array<string, mixed>  $extra  덧붙일 항목
     * @return array<string, mixed> 요청 본문
     */
    private function createPayload(string $slug, array $managerUuids, array $extra = []): array
    {
        return array_merge([
            'name' => ['ko' => '새 게시판', 'en' => 'New Board'],
            'slug' => $slug,
            'type' => 'basic',
            'description' => ['ko' => '설명', 'en' => 'Description'],
            'show_view_count' => true,
            'use_report' => false,
            'board_manager_ids' => $managerUuids,
        ], $extra);
    }

    /**
     * 응답이 권한 부여 상한 위반(403 + 상한 메시지)인지 확인합니다 — 라우트 권한 부족 같은 다른
     * 403 과 구분해, 차단이 상한 검사에서 났음을 증명한다.
     *
     * @param  TestResponse  $response  응답
     * @return void 반환값 없음
     */
    private function assertEscalationRejected(TestResponse $response): void
    {
        $response->assertStatus(403)
            ->assertJsonPath('message', __('exceptions.cannot_grant_unheld_permission'));
    }

    /**
     * 게시판 수정 API 경로를 돌려줍니다.
     *
     * @return string 게시판 수정 API URL
     */
    private function updateUrl(): string
    {
        return "/api/modules/sirsoft-board/admin/boards/{$this->board->id}";
    }

    /**
     * 게시판 수정 권한만 가진 위임 관리자가 자기 역할을 admin.manage·비밀글 열람에 붙이면 403
     * 이고, 매핑도 같은 요청의 이름 변경도 저장되지 않는다.
     *
     * @scenario case=update_self_grant_blocked
     *
     * @effects update_acl_self_grant_rejected, rejected_update_leaves_no_partial_state
     *
     * @return void 반환값 없음
     */
    public function test_delegate_cannot_attach_own_role_to_board_admin_permissions(): void
    {
        [$delegate, $role] = $this->createDelegate(['sirsoft-board.boards.read', 'sirsoft-board.boards.update']);
        $manageBefore = $this->boardPermissionRoles('admin.manage');
        $secretBefore = $this->boardPermissionRoles('posts.read-secret');

        $this->assertEscalationRejected($this->actingAs($delegate)->putJson($this->updateUrl(), [
            'name' => ['ko' => '바뀐 이름', 'en' => 'Renamed'],
            'permissions' => [
                'admin_manage' => ['roles' => array_merge($manageBefore, [$role->identifier])],
                'posts_read-secret' => ['roles' => array_merge($secretBefore, [$role->identifier])],
            ],
        ]));

        $this->assertSame($manageBefore, $this->boardPermissionRoles('admin.manage'));
        $this->assertSame($secretBefore, $this->boardPermissionRoles('posts.read-secret'));
        $this->assertSame('KVE 게시판', $this->board->fresh()->name['ko']);
    }

    /**
     * 과차단 회귀 축 — 권한 매핑을 그대로 다시 보내는 저장(관리자 폼의 평소 저장)은 된다.
     *
     * @scenario case=update_without_acl_change_allowed
     *
     * @effects unchanged_acl_resubmission_allowed
     *
     * @return void 반환값 없음
     */
    public function test_delegate_update_without_acl_change_still_succeeds(): void
    {
        [$delegate] = $this->createDelegate(['sirsoft-board.boards.read', 'sirsoft-board.boards.update']);

        $this->actingAs($delegate)->putJson($this->updateUrl(), [
            'name' => ['ko' => '바뀐 이름', 'en' => 'Renamed'],
            'permissions' => [
                'admin_manage' => ['roles' => $this->boardPermissionRoles('admin.manage')],
            ],
        ])->assertOk();

        $this->assertSame('바뀐 이름', $this->board->fresh()->name['ko']);
    }

    /**
     * 과차단 회귀 축 — 그 게시판 권한을 가진 액터(게시판 관리자)는 자기가 가진 권한을 다른 역할에
     * 붙일 수 있다(상한 = 보유 권한).
     *
     * @scenario case=update_holder_grant_allowed
     *
     * @effects holder_can_grant_held_board_permission
     *
     * @return void 반환값 없음
     */
    public function test_board_manager_can_grant_board_permission_they_hold(): void
    {
        [$holder] = $this->createDelegate(['sirsoft-board.boards.read', 'sirsoft-board.boards.update']);
        $holder->roles()->attach(Role::where('identifier', "sirsoft-board.{$this->board->slug}.manager")->firstOrFail()->id);
        $grantee = Role::create(['identifier' => 'kve2367_grantee_'.uniqid(), 'name' => ['ko' => '대상', 'en' => 'Grantee'], 'is_active' => true]);

        $this->actingAs($holder->fresh())->putJson($this->updateUrl(), [
            'permissions' => [
                'admin_manage' => ['roles' => array_merge($this->boardPermissionRoles('admin.manage'), [$grantee->identifier])],
            ],
        ])->assertOk();

        $this->assertContains($grantee->identifier, $this->boardPermissionRoles('admin.manage'));
    }

    /**
     * 슈퍼관리자는 어떤 역할이든 게시판 권한에 붙일 수 있다(무회귀).
     *
     * @scenario case=update_super_admin_allowed
     *
     * @effects super_admin_edits_board_acl_freely
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_can_attach_any_role_to_board_permissions(): void
    {
        [, $role] = $this->createDelegate(['sirsoft-board.boards.read']);

        $this->actingAs($this->superAdmin)->putJson($this->updateUrl(), [
            'permissions' => [
                'admin_manage' => ['roles' => array_merge($this->boardPermissionRoles('admin.manage'), [$role->identifier])],
            ],
        ])->assertOk();

        $this->assertContains($role->identifier, $this->boardPermissionRoles('admin.manage'));
    }

    /**
     * 게시판을 만들며 기본 ACL 위에 자기 역할을 admin.manage 에 얹으면 403 이고, 게시판·게시판
     * 권한·역할이 하나도 남지 않는다.
     *
     * @scenario case=create_extra_grant_blocked
     *
     * @effects create_acl_self_grant_rejected, rejected_create_leaves_no_board
     *
     * @return void 반환값 없음
     */
    public function test_delegate_cannot_create_board_granting_own_role_extra_acl(): void
    {
        [$delegate, $role] = $this->createDelegate(['sirsoft-board.boards.read', 'sirsoft-board.boards.create']);
        $slug = 'kve2367-new-'.substr(md5(uniqid('', true)), 0, 8);

        $this->assertEscalationRejected($this->actingAs($delegate)->postJson(
            '/api/modules/sirsoft-board/admin/boards',
            $this->createPayload($slug, [$this->superAdmin->uuid], [
                'permissions' => ['admin_manage' => ['roles' => ['admin', $role->identifier]]],
            ])
        ));

        $this->assertFalse(Board::where('slug', $slug)->exists());
        $this->assertSame(0, Permission::where('identifier', 'like', "sirsoft-board.{$slug}%")->count());
        $this->assertSame(0, Role::where('identifier', 'like', "sirsoft-board.{$slug}.%")->count());
    }

    /**
     * 과차단 회귀 축 — 기본 ACL 로 새 게시판 권한을 갖게 되는 액터(admin 역할)는 생성과 동시에
     * 역할을 더 얹을 수 있다. 기본 ACL 을 먼저 붙인 뒤의 권한으로 판정하기 때문이다.
     *
     * @scenario case=create_extra_grant_by_default_acl_holder_allowed
     *
     * @effects default_acl_holder_can_add_roles_on_create
     *
     * @return void 반환값 없음
     */
    public function test_admin_role_actor_can_add_extra_role_when_creating_board(): void
    {
        $staff = User::factory()->create(['status' => 'active']);
        $staff->roles()->attach(Role::where('identifier', 'admin')->firstOrFail()->id);
        foreach (['sirsoft-board.boards.read', 'sirsoft-board.boards.create'] as $identifier) {
            $permission = Permission::firstOrCreate(['identifier' => $identifier], ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']);
            Role::where('identifier', 'admin')->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $extra = Role::create(['identifier' => 'kve2367_extra_'.uniqid(), 'name' => ['ko' => '추가', 'en' => 'Extra'], 'is_active' => true]);
        $slug = 'kve2367-staff-'.substr(md5(uniqid('', true)), 0, 8);

        $this->actingAs($staff->fresh())->postJson(
            '/api/modules/sirsoft-board/admin/boards',
            $this->createPayload($slug, [$staff->uuid], [
                'permissions' => ['posts_read' => ['roles' => ['admin', 'user', 'guest', $extra->identifier]]],
            ])
        )->assertStatus(201);

        $this->assertContains($extra->identifier, $this->boardPermissionRoles('posts.read', Board::where('slug', $slug)->firstOrFail()));
    }

    /**
     * 과차단 회귀 축 — 환경설정의 게시판 기본 권한을 읽지 못해도(설정 미러가 빈 상태) 새 게시판에
     * 자기 관리자·스텝 역할이 붙는 것은 시스템 기준이라 상한 검사를 받지 않는다. 게시판 생성 쪽은
     * 권한 정의 키로 대체값을 만들어 두 역할을 싣는데 권한 쪽이 빈 기본값으로 판정하면, 두 역할이
     * 「요청이 더 얹은 역할」로 분류돼 게시판 권한이 없는 액터의 생성이 403 으로 막혔다.
     *
     * @scenario case=create_without_default_settings_board_roles_allowed
     *
     * @effects board_own_roles_attach_without_default_settings
     *
     * @return void 반환값 없음
     */
    public function test_board_own_roles_attach_on_create_when_default_permissions_missing(): void
    {
        Config::set('g7_settings.modules.sirsoft-board.basic_defaults', []);
        [$delegate] = $this->createDelegate(['sirsoft-board.boards.read', 'sirsoft-board.boards.create']);
        $slug = 'kve2367-nodef-'.substr(md5(uniqid('', true)), 0, 8);

        $this->actingAs($delegate);
        $board = app(BoardService::class)->createBoard([
            'name' => ['ko' => '기본값 없는 게시판', 'en' => 'No Default Board'],
            'slug' => $slug,
            'type' => 'basic',
        ]);

        $this->assertSame(
            ["sirsoft-board.{$slug}.manager", "sirsoft-board.{$slug}.step"],
            $this->boardPermissionRoles('posts.read', $board)
        );
        $this->assertSame(["sirsoft-board.{$slug}.manager"], $this->boardPermissionRoles('admin.manage', $board));
    }

    /**
     * 환경설정 기본 권한에 자기 역할을 넣어 두고(설정 수정 권한으로 가능) 일괄 적용으로 기존
     * 게시판의 admin.manage 에 붙이면 403 이고 전체가 되돌려진다(「일괄 적용 중단」 200 으로
     * 감싸지지 않는다).
     *
     * @scenario case=bulk_apply_self_grant_blocked
     *
     * @effects bulk_apply_acl_self_grant_rejected
     *
     * @return void 반환값 없음
     */
    public function test_delegate_cannot_self_grant_through_bulk_apply(): void
    {
        // 일괄 적용 라우트는 환경설정 수정 + 게시판 수정 권한을 둘 다 요구한다(제품 결정 2026-10-09) — 라우트를 통과한 뒤 상한 검사에서 막히는지 본다
        [$delegate, $role] = $this->createDelegate(['sirsoft-board.settings.read', 'sirsoft-board.settings.update', 'sirsoft-board.boards.update']);
        $manageBefore = $this->boardPermissionRoles('admin.manage');
        $this->storeDefaultBoardPermission('admin.manage', ['admin', $role->identifier]);

        $this->assertEscalationRejected($this->actingAs($delegate)->postJson('/api/modules/sirsoft-board/admin/settings/bulk-apply', [
            'fields' => ['admin.manage'],
            'apply_all' => false,
            'board_ids' => [$this->board->id],
        ]));

        $this->assertSame($manageBefore, $this->boardPermissionRoles('admin.manage'));
    }

    /**
     * 과차단 회귀 축 — 슈퍼관리자의 같은 일괄 적용은 그대로 반영된다.
     *
     * @scenario case=bulk_apply_super_admin_allowed
     *
     * @effects super_admin_bulk_apply_acl_applies
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_bulk_apply_still_applies(): void
    {
        [, $role] = $this->createDelegate(['sirsoft-board.settings.read']);
        $this->storeDefaultBoardPermission('admin.manage', ['admin', $role->identifier]);

        $this->actingAs($this->superAdmin)->postJson('/api/modules/sirsoft-board/admin/settings/bulk-apply', [
            'fields' => ['admin.manage'],
            'apply_all' => false,
            'board_ids' => [$this->board->id],
        ])->assertOk()->assertJsonPath('data.rolled_back', false);

        $this->assertContains($role->identifier, $this->boardPermissionRoles('admin.manage'));
    }

    /**
     * 환경설정에 저장된 게시판 기본 권한 한 키를 바꿔 둡니다 (설정 저장을 거친 상태를 흉내).
     *
     * 일괄 적용은 요청의 덮어쓰기 값 중 검증 규칙이 있는 컬럼 값만 받으므로 기본 권한은 저장된
     * 설정에서 읽는다 — 설정 수정 권한만 있으면 저장할 수 있는 값이다.
     *
     * @param  string  $key  권한 키 (admin.manage 등)
     * @param  array<int, string>  $roles  역할 식별자 목록
     * @return void 반환값 없음
     */
    private function storeDefaultBoardPermission(string $key, array $roles): void
    {
        $defaults = config('g7_settings.modules.sirsoft-board.basic_defaults.default_board_permissions', []);
        $defaults[$key] = $roles;
        Config::set('g7_settings.modules.sirsoft-board.basic_defaults.default_board_permissions', $defaults);
    }

    /**
     * 신고 관리 권한에 자기 역할을 붙이는 환경설정 저장은 403 이고, 설정 파일을 쓰기 전에 거부돼
     * 권한 매핑이 그대로다.
     *
     * @scenario case=report_permission_self_grant_blocked
     *
     * @effects report_permission_self_grant_rejected
     *
     * @return void 반환값 없음
     */
    public function test_delegate_cannot_grant_self_report_management(): void
    {
        $this->seedReportPermissions();
        [$delegate, $role] = $this->createDelegate(['sirsoft-board.settings.read', 'sirsoft-board.settings.update']);

        $this->assertEscalationRejected($this->actingAs($delegate)->putJson('/api/modules/sirsoft-board/admin/settings', [
            'report_permissions' => [
                'view_roles' => ['admin'],
                'manage_roles' => ['admin', $role->identifier],
            ],
        ]));

        $this->assertSame(['admin'], Permission::where('identifier', 'sirsoft-board.reports.manage')->firstOrFail()
            ->roles()->pluck('identifier')->all());
    }

    /**
     * 과차단 회귀 축 — 신고 관리 권한 역할이 바뀌지 않는 저장은 검사하지 않고, 슈퍼관리자의
     * 변경은 반영된다(서비스 경로 — HTTP 저장은 설정 파일을 쓰므로 여기서는 부르지 않는다).
     *
     * @scenario case=report_permission_unchanged_or_super_allowed
     *
     * @effects report_permission_unchanged_or_super_admin_allowed
     *
     * @return void 반환값 없음
     */
    public function test_report_permission_unchanged_or_super_admin_change_is_allowed(): void
    {
        $this->seedReportPermissions();
        [$delegate, $role] = $this->createDelegate(['sirsoft-board.settings.update']);
        $service = app(BoardSettingsService::class);

        $this->actingAs($delegate);
        $service->assertReportPermissionRolesChangeAllowed(['view_roles' => ['admin'], 'manage_roles' => ['admin']]);

        $this->actingAs($this->superAdmin);
        $service->syncReportPermissionRoles(['view_roles' => ['admin'], 'manage_roles' => ['admin', $role->identifier]]);

        $this->assertContains($role->identifier, Permission::where('identifier', 'sirsoft-board.reports.manage')->firstOrFail()
            ->roles()->pluck('identifier')->all());
    }

    /**
     * 게시판 기본 권한의 「게시판 관리」 에 자기 역할을 넣는 환경설정 저장은 403 이고, 설정 파일을
     * 쓰기 전에 거부돼 저장된 기본 권한이 그대로다 — 막지 않으면 이 기본값으로 새 게시판을 만들어
     * 관리 권한을 얻는다(게시판 생성 시 기본 권한은 검사하지 않는다).
     *
     * @scenario case=default_permissions_self_grant_blocked
     *
     * @effects default_permissions_self_grant_rejected
     *
     * @return void 반환값 없음
     */
    public function test_delegate_cannot_put_own_role_into_default_board_permissions(): void
    {
        [$delegate, $role] = $this->createDelegate(['sirsoft-board.settings.read', 'sirsoft-board.settings.update']);
        $service = app(BoardSettingsService::class);
        $before = $service->getSettings('basic_defaults')['default_board_permissions'] ?? [];
        $next = $before;
        $next['admin.manage'] = array_merge($before['admin.manage'] ?? [], [$role->identifier]);

        $this->assertEscalationRejected($this->actingAs($delegate)->putJson('/api/modules/sirsoft-board/admin/settings', [
            'basic_defaults' => ['default_board_permissions' => $next],
        ]));

        $service->clearCache();
        $this->assertSame($before, $service->getSettings('basic_defaults')['default_board_permissions'] ?? []);
    }

    /**
     * 과차단 회귀 축 — 기본 권한을 그대로 다시 보내는 저장은 검사하지 않고, 모든 게시판에서 그
     * 권한을 가진 액터(admin 역할, 슈퍼 아님)는 기본 권한을 바꿀 수 있다(서비스 경로 — HTTP 저장은
     * 설정 파일을 쓰므로 여기서는 부르지 않는다).
     *
     * @scenario case=default_permissions_unchanged_or_holder_allowed
     *
     * @effects default_permissions_unchanged_or_holder_allowed
     *
     * @return void 반환값 없음
     */
    public function test_default_board_permissions_unchanged_or_holder_change_is_allowed(): void
    {
        [$delegate, $role] = $this->createDelegate(['sirsoft-board.settings.update']);
        $service = app(BoardSettingsService::class);
        $current = $service->getSettings('basic_defaults')['default_board_permissions'] ?? [];

        $this->actingAs($delegate);
        $service->assertDefaultBoardPermissionsChangeAllowed($current);

        // 「모든 게시판에서 보유」 전제를 이 테스트가 직접 만든다 — 같은 프로세스의 다른 테스트가
        // 남긴 게시판(프로세스 첫 테스트는 migrate:fresh 로 트랜잭션이 끊겨 커밋된다)도 판정 대상이라,
        // 기본 ACL 에 기대면 실행 순서에 따라 결과가 달라진다.
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        // BoardTestCase 기반 테스트가 DB 로 직접 넣은 게시판은 권한 행이 없을 수 있어 없으면 만든다(실행 순서 무관)
        foreach (Board::all() as $existing) {
            $identifier = "sirsoft-board.{$existing->slug}.admin.manage";
            Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            )->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        $staff = User::factory()->create(['status' => 'active']);
        $staff->roles()->attach($adminRole->id);
        $next = $current;
        $next['admin.manage'] = array_merge($current['admin.manage'] ?? [], [$role->identifier]);

        $this->actingAs($staff->fresh());
        $service->assertDefaultBoardPermissionsChangeAllowed($next);

        $this->addToAssertionCount(1);
    }

    /**
     * 신고 관리 모듈 권한을 admin 역할에만 붙인 상태로 준비합니다.
     *
     * @return void 반환값 없음
     */
    private function seedReportPermissions(): void
    {
        $admin = Role::where('identifier', 'admin')->firstOrFail();

        foreach (['sirsoft-board.reports.view', 'sirsoft-board.reports.manage'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $permission->roles()->sync([$admin->id]);
        }
    }
}
