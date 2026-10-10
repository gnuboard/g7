<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 관리자·스텝 일괄 지정 (#708)
 *
 * 한 회원을 게시판 N개의 관리자·스텝으로 지정하려면 게시판 폼을 N번 저장해야 했다. 환경설정의
 * 일괄 지정이 게시판마다 기존 명단 동기화(syncBoardRoleUsers)를 타는지, 관리자가 0명이 되는
 * 게시판을 건너뛰는지, 범위 밖 게시판을 건너뛰는지, 한 곳이 실패하면 전부 되돌리는지 찌른다.
 *
 * 시나리오 SSoT: modules/_bundled/sirsoft-board/tests/scenarios/board-bulk-members.yaml
 */
class BoardBulkMembersTest extends ModuleTestCase
{
    private User $superAdmin;

    private Board $boardA;

    private Board $boardB;

    /**
     * 슈퍼관리자가 관리자인 게시판 두 개를 준비합니다.
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
        $this->grantAdminRole(['sirsoft-board.boards.read', 'sirsoft-board.boards.create', 'sirsoft-board.boards.update']);

        $this->actingAs($this->superAdmin);
        $this->boardA = $this->makeBoard();
        $this->boardB = $this->makeBoard();
        auth()->forgetGuards();
    }

    /**
     * 슈퍼관리자가 관리자인 게시판을 만듭니다.
     *
     * @param  int|null  $createdBy  작성자(범위 판정용)
     * @return Board 게시판
     */
    private function makeBoard(?int $createdBy = null): Board
    {
        $board = app(BoardService::class)->createBoard([
            'name' => ['ko' => '일괄 게시판', 'en' => 'Bulk Board'],
            'slug' => 'bbm-'.substr(md5(uniqid('', true)), 0, 10),
            'type' => 'basic',
            'board_manager_ids' => [$this->superAdmin->uuid],
        ]);

        if ($createdBy !== null) {
            $board->forceFill(['created_by' => $createdBy])->save();
        }

        return $board->fresh();
    }

    /**
     * admin 역할에 권한을 붙입니다 (테스트의 admin 역할은 빈 역할로 시작한다).
     *
     * @param  array<int, string>  $identifiers  권한 식별자
     * @return void 반환값 없음
     */
    private function grantAdminRole(array $identifiers): void
    {
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    /**
     * 일괄 지정 요청을 보냅니다.
     *
     * @param  User  $actor  요청자
     * @param  array<string, mixed>  $body  본문
     * @return TestResponse 응답
     */
    private function bulk(User $actor, array $body): TestResponse
    {
        return $this->actingAs($actor)->postJson('/api/modules/sirsoft-board/admin/boards/bulk-members', $body);
    }

    /**
     * 게시판 역할(manager/step)의 회원 uuid 목록 (정렬).
     *
     * @param  Board  $board  게시판
     * @param  string  $type  구분 (manager 관리자 · step 스텝)
     * @return array<int, string> 회원 uuid 목록
     */
    private function members(Board $board, string $type): array
    {
        $uuids = Role::where('identifier', "sirsoft-board.{$board->slug}.{$type}")->firstOrFail()
            ->users()->pluck('users.uuid')->all();
        sort($uuids);

        return $uuids;
    }

    /**
     * 정렬된 uuid 목록.
     *
     * @param  User  ...$users  회원
     * @return array<int, string> 회원 uuid 목록
     */
    private function uuids(User ...$users): array
    {
        $uuids = array_map(fn (User $u) => $u->uuid, $users);
        sort($uuids);

        return $uuids;
    }

    /**
     * 고른 게시판에 관리자를 더하면 그 게시판의 관리자 역할에만 들어간다.
     *
     * @scenario member_type=manager, mode=add, target=selected
     *
     * @effects role_membership_synced
     *
     * @return void 반환값 없음
     */
    public function test_add_manager_to_selected_board(): void
    {
        $u2 = User::factory()->create();

        $this->bulk($this->superAdmin, [
            'apply_all' => false, 'board_ids' => [$this->boardA->id], 'user_uuids' => [$u2->uuid],
            'member_type' => 'manager', 'mode' => 'add',
        ])->assertOk()->assertJsonPath('data.updated_count', 1);

        $this->assertSame($this->uuids($this->superAdmin, $u2), $this->members($this->boardA, 'manager'));
        $this->assertSame($this->uuids($this->superAdmin), $this->members($this->boardB, 'manager'));
    }

    /**
     * 전체 게시판에 관리자를 더하면 두 게시판 모두에 들어간다.
     *
     * @scenario member_type=manager, mode=add, target=all
     *
     * @effects role_membership_synced
     *
     * @return void 반환값 없음
     */
    public function test_add_manager_to_all_boards(): void
    {
        $u2 = User::factory()->create();

        $this->bulk($this->superAdmin, [
            'apply_all' => true, 'user_uuids' => [$u2->uuid], 'member_type' => 'manager', 'mode' => 'add',
        ])->assertOk();

        $this->assertContains($u2->uuid, $this->members($this->boardA, 'manager'));
        $this->assertContains($u2->uuid, $this->members($this->boardB, 'manager'));
    }

    /**
     * 고른 게시판에서 관리자를 빼면 다른 관리자는 남는다.
     *
     * @scenario member_type=manager, mode=remove, target=selected
     *
     * @effects role_membership_synced
     *
     * @return void 반환값 없음
     */
    public function test_remove_manager_from_selected_board(): void
    {
        $u2 = User::factory()->create();
        $this->bulk($this->superAdmin, [
            'apply_all' => false, 'board_ids' => [$this->boardA->id], 'user_uuids' => [$u2->uuid],
            'member_type' => 'manager', 'mode' => 'add',
        ])->assertOk();

        $this->bulk($this->superAdmin, [
            'apply_all' => false, 'board_ids' => [$this->boardA->id], 'user_uuids' => [$u2->uuid],
            'member_type' => 'manager', 'mode' => 'remove',
        ])->assertOk()->assertJsonPath('data.updated_count', 1);

        $this->assertSame($this->uuids($this->superAdmin), $this->members($this->boardA, 'manager'));
    }

    /**
     * 유일한 관리자를 전체 게시판에서 빼면 관리자가 0명이 되는 게시판은 last_manager 로 건너뛴다.
     *
     * @scenario member_type=manager, mode=remove, target=all
     *
     * @effects last_manager_board_skipped
     *
     * @return void 반환값 없음
     */
    public function test_remove_last_manager_skips_board(): void
    {
        $u2 = User::factory()->create();
        $this->bulk($this->superAdmin, [
            'apply_all' => false, 'board_ids' => [$this->boardB->id], 'user_uuids' => [$u2->uuid],
            'member_type' => 'manager', 'mode' => 'add',
        ])->assertOk();

        $response = $this->bulk($this->superAdmin, [
            'apply_all' => true, 'user_uuids' => [$this->superAdmin->uuid], 'member_type' => 'manager', 'mode' => 'remove',
        ])->assertOk();

        $skipped = collect($response->json('data.skipped'))->where('board_id', $this->boardA->id)->values()->all();
        $this->assertSame([['board_id' => $this->boardA->id, 'reason' => 'last_manager', 'board_name' => $this->boardA->getLocalizedName()]], $skipped);
        $this->assertSame($this->uuids($this->superAdmin), $this->members($this->boardA, 'manager'));
        $this->assertSame($this->uuids($u2), $this->members($this->boardB, 'manager'));
    }

    /**
     * 고른 게시판에 스텝을 더한다.
     *
     * @scenario member_type=step, mode=add, target=selected
     *
     * @effects role_membership_synced
     *
     * @return void 반환값 없음
     */
    public function test_add_step_to_selected_board(): void
    {
        $u2 = User::factory()->create();
        $u3 = User::factory()->create();

        $this->bulk($this->superAdmin, [
            'apply_all' => false, 'board_ids' => [$this->boardA->id], 'user_uuids' => [$u2->uuid, $u3->uuid],
            'member_type' => 'step', 'mode' => 'add',
        ])->assertOk();

        $this->assertSame($this->uuids($u2, $u3), $this->members($this->boardA, 'step'));
        $this->assertSame([], $this->members($this->boardB, 'step'));
    }

    /**
     * 전체 게시판에 스텝을 더한다.
     *
     * @scenario member_type=step, mode=add, target=all
     *
     * @effects role_membership_synced
     *
     * @return void 반환값 없음
     */
    public function test_add_step_to_all_boards(): void
    {
        $u2 = User::factory()->create();

        $this->bulk($this->superAdmin, [
            'apply_all' => true, 'user_uuids' => [$u2->uuid], 'member_type' => 'step', 'mode' => 'add',
        ])->assertOk();

        $this->assertSame($this->uuids($u2), $this->members($this->boardA, 'step'));
        $this->assertSame($this->uuids($u2), $this->members($this->boardB, 'step'));
    }

    /**
     * 스텝은 0명이 돼도 된다 — 고른 게시판에서 마지막 스텝을 빼면 비워진다.
     *
     * @scenario member_type=step, mode=remove, target=selected
     *
     * @effects role_membership_synced
     *
     * @return void 반환값 없음
     */
    public function test_remove_last_step_empties_list(): void
    {
        $u2 = User::factory()->create();
        $this->bulk($this->superAdmin, [
            'apply_all' => false, 'board_ids' => [$this->boardA->id], 'user_uuids' => [$u2->uuid],
            'member_type' => 'step', 'mode' => 'add',
        ])->assertOk();

        $this->bulk($this->superAdmin, [
            'apply_all' => false, 'board_ids' => [$this->boardA->id], 'user_uuids' => [$u2->uuid],
            'member_type' => 'step', 'mode' => 'remove',
        ])->assertOk()->assertJsonPath('data.skipped_count', 0);

        $this->assertSame([], $this->members($this->boardA, 'step'));
    }

    /**
     * 전체 게시판에서 스텝을 빼면 가진 게시판에서만 빠지고, 없는 게시판은 바뀐 수에 세지 않는다.
     *
     * @scenario member_type=step, mode=remove, target=all
     *
     * @effects role_membership_synced
     *
     * @return void 반환값 없음
     */
    public function test_remove_step_from_all_counts_only_changed(): void
    {
        $u2 = User::factory()->create();
        $this->bulk($this->superAdmin, [
            'apply_all' => false, 'board_ids' => [$this->boardA->id], 'user_uuids' => [$u2->uuid],
            'member_type' => 'step', 'mode' => 'add',
        ])->assertOk();

        $response = $this->bulk($this->superAdmin, [
            'apply_all' => true, 'user_uuids' => [$u2->uuid], 'member_type' => 'step', 'mode' => 'remove',
        ])->assertOk();

        $this->assertSame([], $this->members($this->boardA, 'step'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.updated_count'));
        $this->assertSame([], $this->members($this->boardB, 'step'));
    }

    /**
     * 바꿀 것이 없는 게시판은 「변화 없음」 수로 따로 센다 — 변경·제외·변화 없음의 합이 대상 게시판 수다.
     *
     * 「제거」 에서 그 회원이 없는 게시판은 바뀐 수에도 제외 수에도 들지 않아 「n개 변경, m개 제외」 합이
     * 대상 게시판 수보다 작게 보였다(7.1.0 실측 G1a 관측, 결정 2026-10-08 「변화 없음」 수도 표시).
     *
     * @scenario member_type=step, mode=remove, target=all
     *
     * @effects unchanged_boards_counted
     *
     * @return void 반환값 없음
     */
    public function test_remove_from_all_counts_unchanged_boards(): void
    {
        $u2 = User::factory()->create();
        $this->bulk($this->superAdmin, [
            'apply_all' => false, 'board_ids' => [$this->boardA->id], 'user_uuids' => [$u2->uuid],
            'member_type' => 'step', 'mode' => 'add',
        ])->assertOk();

        $response = $this->bulk($this->superAdmin, [
            'apply_all' => true, 'user_uuids' => [$u2->uuid], 'member_type' => 'step', 'mode' => 'remove',
        ])->assertOk();

        $total = Board::query()->count();
        $updated = $response->json('data.updated_count');
        $skipped = $response->json('data.skipped_count');
        $this->assertSame(1, $updated);
        $this->assertSame($total - $updated - $skipped, $response->json('data.unchanged_count'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.unchanged_count'));
        $this->assertSame(
            trans_count('sirsoft-board::messages.boards.bulk_members_success', [
                'count' => $updated, 'skipped' => $skipped, 'unchanged' => $response->json('data.unchanged_count'),
            ]),
            $response->json('message')
        );
    }

    /**
     * 이미 관리자인 회원을 고른 게시판에 다시 더하면 그 게시판은 변화 없음으로 센다.
     *
     * @scenario member_type=manager, mode=add, target=selected
     *
     * @effects unchanged_boards_counted
     *
     * @return void 반환값 없음
     */
    public function test_add_existing_manager_counts_unchanged(): void
    {
        $this->bulk($this->superAdmin, [
            'apply_all' => false, 'board_ids' => [$this->boardA->id], 'user_uuids' => [$this->superAdmin->uuid],
            'member_type' => 'manager', 'mode' => 'add',
        ])->assertOk()
            ->assertJsonPath('data.updated_count', 0)
            ->assertJsonPath('data.skipped_count', 0)
            ->assertJsonPath('data.unchanged_count', 1);
    }

    /**
     * 게시판 수정 범위가 self 인 운영자는 남이 만든 게시판을 scope 사유로 건너뛴다.
     *
     * @effects scope_filtered
     * @effects skipped_entries_carry_board_name
     *
     * @return void 반환값 없음
     */
    public function test_out_of_scope_board_is_skipped(): void
    {
        $permission = Permission::updateOrCreate(
            ['identifier' => 'sirsoft-board.boards.update'],
            ['name' => ['ko' => '게시판 수정', 'en' => 'Update Boards'], 'type' => 'admin', 'resource_route_key' => 'board', 'owner_key' => 'created_by']
        );
        PermissionHelper::clearPermissionScopeCache();
        $role = Role::create(['identifier' => 'bbm_scoped_'.uniqid(), 'name' => ['ko' => '범위 운영자', 'en' => 'Scoped'], 'is_active' => true]);
        $role->permissions()->attach($permission->id, ['scope_type' => 'self', 'granted_at' => now()]);
        $operator = User::factory()->create(['status' => 'active']);
        $operator->roles()->attach($role->id);
        $this->boardA->forceFill(['created_by' => $this->superAdmin->id])->save();

        $response = $this->bulk($operator->fresh(), [
            'apply_all' => false, 'board_ids' => [$this->boardA->id], 'user_uuids' => [User::factory()->create()->uuid],
            'member_type' => 'step', 'mode' => 'add',
        ])->assertOk();

        // 사유 줄에 게시판 이름을 함께 싣는다 — 범위 밖 게시판은 화면 선택지에 없어 번호만 보였다(7.1.0 실측 「1 / 관리 권한 범위 밖」)
        $this->assertSame([['board_id' => $this->boardA->id, 'reason' => 'scope', 'board_name' => $this->boardA->getLocalizedName()]], $response->json('data.skipped'));
        $this->assertSame([], $this->members($this->boardA, 'step'));
    }

    /**
     * 두 번째 게시판에서 권한 상한에 걸리면 첫 게시판에 적용한 변경까지 모두 되돌리고 403 이다.
     * 운영자가 직접 고른 게시판은 고른 대로 처리하거나 거절한다 — 건너뛰기는 「모든 게시판」 에만 적용한다.
     *
     * @effects rollback_on_failure
     *
     * @return void 반환값 없음
     */
    public function test_failure_on_later_board_rolls_back_all(): void
    {
        // 위임 운영자: 게시판 수정 + A 게시판 스텝 역할이 가진 권한만 — B 게시판 스텝 역할은 상한 밖
        $delegateRole = Role::create(['identifier' => 'bbm_delegate_'.uniqid(), 'name' => ['ko' => '위임', 'en' => 'Delegate'], 'is_active' => true]);
        foreach (['sirsoft-board.boards.read', 'sirsoft-board.boards.update'] as $identifier) {
            $permission = Permission::firstOrCreate(['identifier' => $identifier], ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']);
            $delegateRole->permissions()->syncWithoutDetaching([$permission->id => ['scope_type' => null, 'granted_at' => now()]]);
        }
        $stepA = Role::where('identifier', "sirsoft-board.{$this->boardA->slug}.step")->firstOrFail();
        foreach ($stepA->permissions as $permission) {
            $delegateRole->permissions()->syncWithoutDetaching([$permission->id => ['scope_type' => null, 'granted_at' => now()]]);
        }
        $stepB = Role::where('identifier', "sirsoft-board.{$this->boardB->slug}.step")->firstOrFail();
        $this->assertNotEmpty($stepB->permissions, 'B 게시판 스텝 역할에 권한이 있어야 상한 위반이 성립한다');
        $delegate = User::factory()->create(['status' => 'active']);
        $delegate->roles()->attach($delegateRole->id);
        $u2 = User::factory()->create();

        $this->bulk($delegate->fresh(), [
            'apply_all' => false, 'board_ids' => [$this->boardA->id, $this->boardB->id], 'user_uuids' => [$u2->uuid],
            'member_type' => 'step', 'mode' => 'add',
        ])->assertForbidden();

        $this->assertSame([], $this->members($this->boardA, 'step'));
        $this->assertSame([], $this->members($this->boardB, 'step'));
    }

    /**
     * A 게시판 스텝 역할의 권한만 가진 위임 운영자를 만듭니다 — B 게시판 스텝 역할은 그의 부여 상한 밖이다.
     *
     * @return User 위임 운영자
     */
    private function makeStepADelegate(): User
    {
        $delegateRole = Role::create(['identifier' => 'bbm_delegate_'.uniqid(), 'name' => ['ko' => '위임', 'en' => 'Delegate'], 'is_active' => true]);
        foreach (['sirsoft-board.boards.read', 'sirsoft-board.boards.update'] as $identifier) {
            $permission = Permission::firstOrCreate(['identifier' => $identifier], ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']);
            $delegateRole->permissions()->syncWithoutDetaching([$permission->id => ['scope_type' => null, 'granted_at' => now()]]);
        }
        $stepA = Role::where('identifier', "sirsoft-board.{$this->boardA->slug}.step")->firstOrFail();
        foreach ($stepA->permissions as $permission) {
            $delegateRole->permissions()->syncWithoutDetaching([$permission->id => ['scope_type' => null, 'granted_at' => now()]]);
        }
        $delegate = User::factory()->create(['status' => 'active']);
        $delegate->roles()->attach($delegateRole->id);

        return $delegate->fresh();
    }

    /**
     * 「모든 게시판」 은 역할을 줄 수 없는 게시판만 건너뛰고 사유(not_grantable)와 함께 알린다 — 그 하나 때문에
     * 요청 전체가 403 이 되지 않는다(7.1.0 실측 O-K3T-7, 결정 2026-10-08). 줄 수 있는 게시판은 그대로 바뀐다.
     *
     * @scenario member_type=step, mode=add, target=all
     *
     * @effects not_grantable_board_skipped_on_apply_all
     * @effects skipped_entries_carry_board_name
     *
     * @return void 반환값 없음
     */
    public function test_apply_all_skips_boards_actor_cannot_grant(): void
    {
        $delegate = $this->makeStepADelegate();
        $u2 = User::factory()->create();

        $response = $this->bulk($delegate, [
            'apply_all' => true, 'user_uuids' => [$u2->uuid], 'member_type' => 'step', 'mode' => 'add',
        ])->assertOk();

        $this->assertSame($this->uuids($u2), $this->members($this->boardA, 'step'));
        $this->assertSame([], $this->members($this->boardB, 'step'));
        $this->assertSame(1, $response->json('data.updated_count'));
        // 테스트 DB 에 다른 게시판이 있으면 그 게시판도 같은 사유로 건너뛴다 — 이 두 게시판만 본다
        $skippedById = array_column($response->json('data.skipped'), null, 'board_id');
        $this->assertSame(
            ['board_id' => $this->boardB->id, 'reason' => 'not_grantable', 'board_name' => $this->boardB->getLocalizedName()],
            $skippedById[$this->boardB->id] ?? null
        );
        $this->assertArrayNotHasKey($this->boardA->id, $skippedById);
        $this->assertSame(count($response->json('data.skipped')), $response->json('data.skipped_count'));
    }

    /**
     * 권한 상승 차단은 그대로다 — 건너뛰기는 거절을 「제외」로 알릴 뿐, 줄 수 없는 역할을 주지 않는다. 게시판 권한이
     * 하나도 없는 위임 운영자가 「모든 게시판」 에 자기를 관리자로 넣어도 어느 게시판의 관리자도 되지 않는다.
     *
     * @scenario member_type=manager, mode=add, target=all
     *
     * @effects not_grantable_board_skipped_on_apply_all
     *
     * @return void 반환값 없음
     */
    public function test_apply_all_never_grants_unheld_manager_role(): void
    {
        $delegateRole = Role::create(['identifier' => 'bbm_bare_'.uniqid(), 'name' => ['ko' => '위임', 'en' => 'Delegate'], 'is_active' => true]);
        foreach (['sirsoft-board.boards.read', 'sirsoft-board.boards.update'] as $identifier) {
            $permission = Permission::firstOrCreate(['identifier' => $identifier], ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']);
            $delegateRole->permissions()->syncWithoutDetaching([$permission->id => ['scope_type' => null, 'granted_at' => now()]]);
        }
        $delegate = User::factory()->create(['status' => 'active']);
        $delegate->roles()->attach($delegateRole->id);

        $response = $this->bulk($delegate->fresh(), [
            'apply_all' => true, 'user_uuids' => [$delegate->uuid], 'member_type' => 'manager', 'mode' => 'add',
        ])->assertOk();

        $this->assertSame(0, $response->json('data.updated_count'));
        $skippedIds = array_column($response->json('data.skipped'), 'board_id');
        $this->assertContains($this->boardA->id, $skippedIds);
        $this->assertContains($this->boardB->id, $skippedIds);
        $this->assertSame(['not_grantable'], array_values(array_unique(array_column($response->json('data.skipped'), 'reason'))));
        $this->assertSame($this->uuids($this->superAdmin), $this->members($this->boardA, 'manager'));
        $this->assertSame($this->uuids($this->superAdmin), $this->members($this->boardB, 'manager'));
    }

    /**
     * 역할 행이 없는 게시판은 명단을 바꿀 수 없다 — 「변경」 도 「변화 없음」 도 아닌 제외(사유 role_missing)로 센다.
     *
     * 명단 동기화(syncBoardRoleUsers)는 역할이 없으면 경고만 남기고 건너뛰는데, 일괄 지정은 그 게시판을
     * 변경 수에 더해 「N개 게시판에 적용」 이 실제보다 많이 나왔다(다른 테스트가 남긴 역할 없는 게시판이 있는
     * DB 에서 test_apply_all_* 가 red 로 드러남). 그 뒤 「변화 없음」 으로 세게 했으나 운영자는 서버 로그를 보지
     * 않으면 데이터가 손상된 게시판을 알 수 없었다 — 결과 화면의 제외 사유로 드러낸다(7.1.0 실측, 결정 2026-10-09).
     * 변경·제외·변화 없음의 합이 대상 게시판 수라는 계약은 그대로다.
     *
     * @scenario member_type=step, mode=add, target=all
     *
     * @effects role_missing_boards_skipped
     *
     * @return void 반환값 없음
     */
    public function test_board_without_role_row_is_skipped_as_role_missing(): void
    {
        $this->actingAs($this->superAdmin);
        $boardC = $this->makeBoard();
        auth()->forgetGuards();

        $stepC = Role::where('identifier', "sirsoft-board.{$boardC->slug}.step")->firstOrFail();
        $stepC->permissions()->detach();
        $stepC->users()->detach();
        $stepC->delete();

        $u2 = User::factory()->create();
        $response = $this->bulk($this->superAdmin, [
            'apply_all' => true, 'user_uuids' => [$u2->uuid], 'member_type' => 'step', 'mode' => 'add',
        ])->assertOk();

        $this->assertSame($this->uuids($u2), $this->members($this->boardA, 'step'));
        $this->assertSame($this->uuids($u2), $this->members($this->boardB, 'step'));
        $this->assertSame(
            Board::count(),
            $response->json('data.updated_count') + $response->json('data.skipped_count') + $response->json('data.unchanged_count')
        );
        $skippedC = array_values(array_filter($response->json('data.skipped'), fn (array $s): bool => $s['board_id'] === $boardC->id));
        $this->assertCount(1, $skippedC);
        $this->assertSame('role_missing', $skippedC[0]['reason']);
        $this->assertSame($boardC->getLocalizedName(), $skippedC[0]['board_name']);
    }
}
