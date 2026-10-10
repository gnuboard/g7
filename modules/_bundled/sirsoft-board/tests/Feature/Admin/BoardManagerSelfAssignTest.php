<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 담당자(관리자·스텝) 지정 자기배정 차단 회귀 테스트 (KVE-2026-2367 형제)
 *
 * 게시판 관리자·스텝 역할은 그 게시판의 admin.manage·비밀글 열람 등을 담고 있어, 회원을 그
 * 역할에 넣는 것은 그 권한을 통째로 주는 것이다. 권한 저장(ACL)만 막으면 게시판 수정 권한만 가진
 * 위임 관리자가 `board_manager_ids` 에 자기를 넣어 같은 권한을 얻는다 — 두 번째 우회로다.
 * 담당자 명단 변경은 회원 역할 배정과 같은 상한(그 역할의 권한을 액터가 모두 가졌는가)을 받고,
 * 명단이 그대로인 저장·게시판 기존 관리자의 지정·슈퍼관리자는 그대로 된다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-acl-escalation-ceiling.yaml 참조.
 */
class BoardManagerSelfAssignTest extends ModuleTestCase
{
    private User $superAdmin;

    private Board $board;

    /**
     * 기본 게시판 유형·슈퍼관리자와 슈퍼관리자가 관리자인 게시판을 준비합니다.
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

        $this->actingAs($this->superAdmin);
        $this->board = app(BoardService::class)->createBoard([
            'name' => ['ko' => '담당자 게시판', 'en' => 'Manager Board'],
            'slug' => 'kve2367-mgr-'.substr(md5(uniqid('', true)), 0, 8),
            'type' => 'basic',
            'board_manager_ids' => [$this->superAdmin->uuid],
        ]);
        auth()->forgetGuards();
    }

    /**
     * 지정 권한만 가진 위임 관리자를 만듭니다.
     *
     * @param  array<int, string>  $permissionIdentifiers  부여할 권한 식별자
     * @return User 위임 관리자
     */
    private function createDelegate(array $permissionIdentifiers): User
    {
        $role = Role::create([
            'identifier' => 'kve2367_mgr_delegate_'.uniqid(),
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

        return $user->fresh();
    }

    /**
     * 게시판 수정 권한만 가진 위임 관리자.
     *
     * @return User 위임 관리자
     */
    private function createBoardEditor(): User
    {
        return $this->createDelegate(['sirsoft-board.boards.read', 'sirsoft-board.boards.update']);
    }

    /**
     * 게시판 역할(manager/step)에 속한 회원 id 목록을 정렬해 돌려줍니다.
     *
     * @param  string  $kind  manager 또는 step
     * @return array<int, int> 회원 id 목록
     */
    private function roleMemberIds(string $kind): array
    {
        return Role::where('identifier', "sirsoft-board.{$this->board->slug}.{$kind}")->firstOrFail()
            ->users()->pluck('users.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
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
     * 위임 관리자가 자기를 게시판 관리자로 넣으면 403 이고 관리자 명단·같은 요청의 이름 변경이
     * 그대로다.
     *
     * @scenario case=update_manager_self_assign_blocked
     *
     * @effects manager_self_assign_rejected, rejected_assignment_leaves_no_partial_state
     *
     * @return void 반환값 없음
     */
    public function test_delegate_cannot_assign_self_as_board_manager(): void
    {
        $delegate = $this->createBoardEditor();
        $before = $this->roleMemberIds('manager');

        $this->assertEscalationRejected($this->actingAs($delegate)->putJson($this->updateUrl(), [
            'name' => ['ko' => '바뀐 이름', 'en' => 'Renamed'],
            'board_manager_ids' => [$this->superAdmin->uuid, $delegate->uuid],
        ]));

        $this->assertSame($before, $this->roleMemberIds('manager'));
        $this->assertSame('담당자 게시판', $this->board->fresh()->name['ko']);
    }

    /**
     * 자기가 아닌 다른 회원을 넣어도 같다 — 담당자 지정은 넣는 대상이 아니라 액터가 그 역할의
     * 권한을 가졌는지로 판정하므로, 공모 계정·부계정을 담당자로 넣는 우회도 403 이다.
     *
     * @scenario case=update_manager_other_assign_blocked
     *
     * @effects manager_other_assign_rejected
     *
     * @return void 반환값 없음
     */
    public function test_delegate_cannot_assign_other_member_as_board_manager(): void
    {
        $delegate = $this->createBoardEditor();
        $accomplice = User::factory()->create(['status' => 'active']);
        $before = $this->roleMemberIds('manager');

        $this->assertEscalationRejected($this->actingAs($delegate)->putJson($this->updateUrl(), [
            'board_manager_ids' => [$this->superAdmin->uuid, $accomplice->uuid],
        ]));

        $this->assertSame($before, $this->roleMemberIds('manager'));
        $this->assertNotContains($accomplice->id, $this->roleMemberIds('manager'));
    }

    /**
     * 스텝 역할도 같은 상한을 받는다.
     *
     * @scenario case=update_step_self_assign_blocked
     *
     * @effects step_self_assign_rejected
     *
     * @return void 반환값 없음
     */
    public function test_delegate_cannot_assign_self_as_board_step(): void
    {
        $delegate = $this->createBoardEditor();

        $this->assertEscalationRejected($this->actingAs($delegate)->putJson($this->updateUrl(), [
            'board_step_ids' => [$delegate->uuid],
        ]));

        $this->assertSame([], $this->roleMemberIds('step'));
    }

    /**
     * 과차단 회귀 축 — 명단을 그대로 다시 보내는 저장(관리자 폼의 평소 저장)은 된다.
     *
     * @scenario case=update_unchanged_members_allowed
     *
     * @effects unchanged_member_resubmission_allowed
     *
     * @return void 반환값 없음
     */
    public function test_delegate_update_with_unchanged_members_still_succeeds(): void
    {
        $delegate = $this->createBoardEditor();

        $this->actingAs($delegate)->putJson($this->updateUrl(), [
            'name' => ['ko' => '바뀐 이름', 'en' => 'Renamed'],
            'board_manager_ids' => [$this->superAdmin->uuid],
            'board_step_ids' => [],
        ])->assertOk();

        $this->assertSame('바뀐 이름', $this->board->fresh()->name['ko']);
    }

    /**
     * 과차단 회귀 축 — 그 게시판의 기존 관리자는 다른 회원을 관리자로 지정할 수 있다.
     *
     * @scenario case=existing_manager_assigns_allowed
     *
     * @effects existing_board_manager_can_assign_members
     *
     * @return void 반환값 없음
     */
    public function test_existing_board_manager_can_assign_another_manager(): void
    {
        $manager = $this->createBoardEditor();
        Role::where('identifier', "sirsoft-board.{$this->board->slug}.manager")->firstOrFail()->users()->attach($manager->id);
        $newcomer = User::factory()->create(['status' => 'active']);

        $this->actingAs($manager->fresh())->putJson($this->updateUrl(), [
            'board_manager_ids' => [$this->superAdmin->uuid, $manager->uuid, $newcomer->uuid],
        ])->assertOk();

        $this->assertContains($newcomer->id, $this->roleMemberIds('manager'));
    }

    /**
     * 슈퍼관리자는 누구든 담당자로 지정할 수 있다(무회귀).
     *
     * @scenario case=super_admin_assigns_allowed
     *
     * @effects super_admin_assigns_members_freely
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_can_assign_any_manager(): void
    {
        $member = User::factory()->create(['status' => 'active']);

        $this->actingAs($this->superAdmin)->putJson($this->updateUrl(), [
            'board_manager_ids' => [$this->superAdmin->uuid, $member->uuid],
        ])->assertOk();

        $this->assertContains($member->id, $this->roleMemberIds('manager'));
    }

    /**
     * 기본 ACL 밖 위임 관리자도 새 게시판을 만들며 **자기 한 명만** 관리자로 둘 수 있다 — 새로 만드는 게시판의
     * 관리자가 되는 것은 기존 게시판에 대한 권한 상승이 아니다(7.1.0 실측 K4F-2, 결정 2026-10-08). 종전에는
     * 누구를 지정해도 403, 비우면 422 라 게시판을 아예 만들 수 없었다.
     *
     * @scenario case=create_manager_self_only_allowed
     *
     * @effects create_board_creator_self_manager_allowed
     *
     * @return void 반환값 없음
     */
    public function test_delegate_can_create_board_naming_only_self_manager(): void
    {
        $delegate = $this->createDelegate(['sirsoft-board.boards.read', 'sirsoft-board.boards.create']);
        $slug = 'kve2367-mgr-new-'.substr(md5(uniqid('', true)), 0, 8);

        $this->actingAs($delegate)->postJson(
            '/api/modules/sirsoft-board/admin/boards',
            $this->createPayload($slug, [$delegate->uuid])
        )->assertStatus(201);

        $board = Board::where('slug', $slug)->firstOrFail();
        $this->assertSame($delegate->id, (int) $board->created_by);
        $managerIds = Role::where('identifier', "sirsoft-board.{$slug}.manager")->firstOrFail()
            ->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([$delegate->id], $managerIds);
    }

    /**
     * 새 게시판에서도 다른 회원을 관리자로 넣으면(자기와 함께여도) 403 이고 게시판·역할·권한이 남지 않는다 —
     * 허용은 생성자 본인 한 명뿐이다.
     *
     * @scenario case=create_manager_self_assign_blocked
     *
     * @effects create_manager_self_assign_rejected
     *
     * @return void 반환값 없음
     */
    public function test_delegate_cannot_create_board_naming_other_manager(): void
    {
        $delegate = $this->createDelegate(['sirsoft-board.boards.read', 'sirsoft-board.boards.create']);
        $accomplice = User::factory()->create(['status' => 'active']);

        foreach ([[$accomplice->uuid], [$delegate->uuid, $accomplice->uuid]] as $managers) {
            $slug = 'kve2367-mgr-oth-'.substr(md5(uniqid('', true)), 0, 8);

            $this->assertEscalationRejected($this->actingAs($delegate)->postJson(
                '/api/modules/sirsoft-board/admin/boards',
                $this->createPayload($slug, $managers)
            ));

            $this->assertFalse(Board::where('slug', $slug)->exists());
            $this->assertSame(0, Role::where('identifier', 'like', "sirsoft-board.{$slug}.%")->count());
            $this->assertSame(0, Permission::where('identifier', 'like', "sirsoft-board.{$slug}%")->count());
        }
    }

    /**
     * 새 게시판의 스텝 지정은 본인이든 다른 회원이든 그대로 상한을 받는다 — 허용은 관리자 본인 한 명뿐이다.
     * 같은 요청에서 방금 얻은 관리자 역할로 스텝 지정이 통과하지 않도록 스텝을 먼저 판정한다.
     *
     * @scenario case=create_manager_self_assign_blocked
     *
     * @effects create_manager_self_assign_rejected
     *
     * @return void 반환값 없음
     */
    public function test_delegate_cannot_create_board_naming_self_step(): void
    {
        $delegate = $this->createDelegate(['sirsoft-board.boards.read', 'sirsoft-board.boards.create']);
        $other = User::factory()->create(['status' => 'active']);

        foreach ([[$delegate->uuid], [$other->uuid]] as $steps) {
            $slug = 'kve2367-mgr-stp-'.substr(md5(uniqid('', true)), 0, 8);

            $this->assertEscalationRejected($this->actingAs($delegate)->postJson(
                '/api/modules/sirsoft-board/admin/boards',
                $this->createPayload($slug, [$delegate->uuid], ['board_step_ids' => $steps])
            ));

            $this->assertFalse(Board::where('slug', $slug)->exists());
        }
    }

    /**
     * 과차단 회귀 축 — 기본 ACL 로 새 게시판 권한을 갖게 되는 액터(admin 역할, 슈퍼 아님)는
     * 게시판을 만들며 자기를 관리자로 넣을 수 있다. 같은 요청에서 방금 붙은 권한으로 판정한다.
     *
     * @scenario case=create_manager_by_default_acl_holder_allowed
     *
     * @effects default_acl_holder_can_name_manager_on_create
     *
     * @return void 반환값 없음
     */
    public function test_admin_role_actor_can_create_board_naming_self_manager(): void
    {
        $staff = User::factory()->create(['status' => 'active']);
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        $staff->roles()->attach($adminRole->id);
        foreach (['sirsoft-board.boards.read', 'sirsoft-board.boards.create'] as $identifier) {
            $permission = Permission::firstOrCreate(['identifier' => $identifier], ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']);
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $slug = 'kve2367-mgr-staff-'.substr(md5(uniqid('', true)), 0, 8);

        $this->actingAs($staff->fresh())->postJson(
            '/api/modules/sirsoft-board/admin/boards',
            $this->createPayload($slug, [$staff->uuid])
        )->assertStatus(201);

        $this->assertTrue(
            Role::where('identifier', "sirsoft-board.{$slug}.manager")->firstOrFail()->users()->where('users.id', $staff->id)->exists()
        );
    }

    /**
     * 게시판 생성·복사 폼 데이터 API 경로를 돌려줍니다.
     *
     * @param  array<string, mixed>  $query  쿼리 (copy_id 등)
     * @return string 폼 데이터 API URL
     */
    private function formDataUrl(array $query = []): string
    {
        $url = '/api/modules/sirsoft-board/admin/boards/form-data';

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * 새 게시판의 기본 ACL 을 받지 못하는 위임 관리자에게는 생성 폼이 **본인 한 명만** 관리자로 미리 채운다 —
     * 그대로 저장할 수 있는 값이다. 다른 담당자는 지정할 수 없다는 사실을 `_meta.can_assign_board_members=false`
     * 로 알려 화면이 이유를 보이고 명단 칸을 잠근다(K4F-2, 결정 2026-10-08).
     *
     * @scenario case=create_form_prefill_skipped_for_non_holder
     *
     * @effects create_form_does_not_prefill_unassignable_manager
     *
     * @return void 반환값 없음
     */
    public function test_create_form_prefills_only_self_for_delegate(): void
    {
        $delegate = $this->createDelegate(['sirsoft-board.boards.read', 'sirsoft-board.boards.create']);

        $response = $this->actingAs($delegate)->getJson($this->formDataUrl())->assertOk();

        $this->assertSame([$delegate->uuid], $response->json('data.board_manager_ids'));
        $this->assertSame([$delegate->uuid], array_column($response->json('data.board_managers'), 'uuid'));
        $this->assertSame([], $response->json('data.board_step_ids'));
        $this->assertFalse($response->json('data._meta.can_assign_board_members'));
    }

    /**
     * 복사 폼도 같다 — 원본 게시판의 관리자·스텝 명단 대신 본인 한 명을 관리자로 채우고 스텝은 비운다. 원본
     * 명단을 그대로 채우면 위임 관리자가 저장할 때 403 이다.
     *
     * @scenario case=copy_form_prefill_skipped_for_non_holder
     *
     * @effects copy_form_does_not_prefill_unassignable_members
     *
     * @return void 반환값 없음
     */
    public function test_copy_form_prefills_only_self_for_delegate(): void
    {
        $delegate = $this->createDelegate(['sirsoft-board.boards.read', 'sirsoft-board.boards.create']);

        $response = $this->actingAs($delegate)->getJson($this->formDataUrl(['copy_id' => $this->board->id]))->assertOk();

        $this->assertSame([$delegate->uuid], $response->json('data.board_manager_ids'));
        $this->assertSame([$delegate->uuid], array_column($response->json('data.board_managers'), 'uuid'));
        $this->assertSame([], $response->json('data.board_step_ids'));
        $this->assertSame([], $response->json('data.board_steps'));
        $this->assertFalse($response->json('data._meta.can_assign_board_members'));
    }

    /**
     * 복사 폼이 채운 값을 그대로 저장하면 게시판이 만들어지고 위임 관리자가 그 관리자가 된다.
     *
     * @scenario case=copy_form_prefill_skipped_for_non_holder
     *
     * @effects create_board_creator_self_manager_allowed
     *
     * @return void 반환값 없음
     */
    public function test_delegate_can_save_copy_form_as_is(): void
    {
        $delegate = $this->createDelegate(['sirsoft-board.boards.read', 'sirsoft-board.boards.create']);
        $form = $this->actingAs($delegate)->getJson($this->formDataUrl(['copy_id' => $this->board->id]))->assertOk()->json('data');
        $slug = 'kve2367-mgr-cpy-'.substr(md5(uniqid('', true)), 0, 8);

        $this->actingAs($delegate)->postJson('/api/modules/sirsoft-board/admin/boards', array_merge(
            $this->createPayload($slug, $form['board_manager_ids']),
            ['board_step_ids' => $form['board_step_ids'], 'permissions' => $form['permissions']]
        ))->assertStatus(201);

        $this->assertTrue(
            Role::where('identifier', "sirsoft-board.{$slug}.manager")->firstOrFail()
                ->users()->where('users.id', $delegate->id)->exists()
        );
    }

    /**
     * 과차단 회귀 축 — 기본 ACL 로 새 게시판 권한을 받는 관리자(admin 역할, 슈퍼 아님)와
     * 슈퍼관리자에게는 생성 폼이 본인을 관리자로 그대로 미리 채운다.
     *
     * @scenario case=create_form_prefill_kept_for_holder
     *
     * @effects create_form_prefills_self_for_assignable_actor
     *
     * @return void 반환값 없음
     */
    public function test_create_form_prefills_self_for_default_acl_holder_and_super_admin(): void
    {
        $staff = User::factory()->create(['status' => 'active']);
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        $staff->roles()->attach($adminRole->id);

        foreach ([$staff->fresh(), $this->superAdmin] as $actor) {
            $response = $this->actingAs($actor)->getJson($this->formDataUrl())->assertOk();

            $this->assertSame([$actor->uuid], $response->json('data.board_manager_ids'));
            $this->assertTrue($response->json('data._meta.can_assign_board_members'));
        }
    }
}
