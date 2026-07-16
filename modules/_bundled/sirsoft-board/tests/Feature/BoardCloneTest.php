<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 복제(copyBoard) 회귀 테스트
 *
 * 이슈 #413-16:
 *  - 16-(1) 복제 저장 시 board_manager_ids 누락 → 422
 *  - 16-(2) 복제 시 권한 미복사 + 역할 identifier 교차 게시판 누수
 *
 * copyBoard() 가 역할/관계 파생 5필드(board_manager_ids/board_managers/
 * board_step_ids/board_steps/permissions)를 채워 반환하고, permissions 의
 * roles 에서 옛 slug 스코프(manager/step) identifier 를 제거하는지 검증한다.
 */
class BoardCloneTest extends ModuleTestCase
{
    private User $adminUser;

    private BoardService $boardService;

    protected function setUp(): void
    {
        parent::setUp();

        // 이전 실행 잔여 정리 (DatabaseTransactions 비활성 환경 호환)
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('permissions')->where('identifier', 'like', 'sirsoft-board.clone-src-%')->delete();
        DB::table('permissions')->where('identifier', 'like', 'sirsoft-board.clone-dst-%')->delete();
        DB::table('roles')->where('identifier', 'like', 'sirsoft-board.clone-src-%')->delete();
        DB::table('roles')->where('identifier', 'like', 'sirsoft-board.clone-dst-%')->delete();
        DB::table('boards')->where('slug', 'like', 'clone-src-%')->delete();
        DB::table('boards')->where('slug', 'like', 'clone-dst-%')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // 게시판 유형(basic) 보장 — store API 의 type 검증에 필요
        if (! Schema::hasTable('board_types')) {
            $this->artisan('migrate', [
                '--path' => $this->getModuleBasePath().'/database/migrations',
                '--realpath' => true,
            ]);
        }
        BoardType::firstOrCreate(
            ['slug' => 'default'],
            ['name' => ['ko' => '기본', 'en' => 'Default'], 'is_active' => true, 'is_default' => true]
        );

        // 게시판 권한을 가진 관리자 (form-data/store API 의 permission 미들웨어 통과)
        $this->adminUser = $this->createAdminUser([
            'sirsoft-board.boards.read',
            'sirsoft-board.boards.create',
            'sirsoft-board.boards.update',
            'sirsoft-board.boards.delete',
        ]);
        $this->actingAs($this->adminUser);

        $this->boardService = app(BoardService::class);
    }

    /**
     * manager 사용자가 지정된 원본 게시판을 생성합니다.
     */
    private function createSourceBoardWithManager(string $slug, User $manager, ?User $step = null): Board
    {
        return $this->boardService->createBoard([
            'slug' => $slug,
            'name' => ['ko' => "복제원본 {$slug}", 'en' => "Source {$slug}"],
            'type' => 'default',
            'board_manager_ids' => [$manager->uuid],
            'board_step_ids' => $step ? [$step->uuid] : [],
        ]);
    }

    /**
     * 16-(1)/(2): copyBoard 반환에 board_manager_ids 와 manager 객체 배열이 채워진다.
     *
     * @scenario original_permissions=default
     * @effects clone_includes_manager_ids, clone_includes_manager_objects_with_labels
     */
    public function test_copy_board_includes_manager_ids_and_objects(): void
    {
        $manager = User::factory()->create(['name' => 'Manager One']);
        $board = $this->createSourceBoardWithManager('clone-src-1', $manager);

        $copyData = $this->boardService->copyBoard($board->id);

        // 16-(1): board_manager_ids 가 원본 manager uuid 를 포함 (누락 시 422)
        $this->assertArrayHasKey('board_manager_ids', $copyData);
        $this->assertContains($manager->uuid, $copyData['board_manager_ids']);

        // 16-(2): board_managers 객체 배열이 라벨 데이터({uuid,name,email})를 포함
        $this->assertArrayHasKey('board_managers', $copyData);
        $managerObj = collect($copyData['board_managers'])->firstWhere('uuid', $manager->uuid);
        $this->assertNotNull($managerObj, 'board_managers 에 원본 manager 객체가 없습니다.');
        $this->assertSame($manager->name, $managerObj['name']);
        $this->assertSame($manager->email, $managerObj['email']);

        // step 키도 존재 (빈 배열이라도)
        $this->assertArrayHasKey('board_step_ids', $copyData);
        $this->assertArrayHasKey('board_steps', $copyData);

        // add_to_menu 는 boolean 으로 채워져야 한다 (누락 시 폼 Toggle 이 undefined 로
        // 초기화되어 저장 시 boolean 검증에 걸림 → 422)
        $this->assertArrayHasKey('add_to_menu', $copyData);
        $this->assertIsBool($copyData['add_to_menu']);
    }

    /**
     * 4-3 형식 일치: copyBoard 의 role 데이터가 BoardResource 산출과 동일 구조여야 한다.
     *
     * @scenario original_permissions=default
     * @effects clone_role_data_matches_resource_output
     */
    public function test_copy_board_role_data_matches_resource_output(): void
    {
        $manager = User::factory()->create(['name' => 'Manager Match']);
        $step = User::factory()->create(['name' => 'Step Match']);
        $board = $this->createSourceBoardWithManager('clone-src-2', $manager, $step);

        $copyData = $this->boardService->copyBoard($board->id);

        $expected = \Modules\Sirsoft\Board\Http\Resources\BoardResource::getBoardRoleData(
            "sirsoft-board.{$board->slug}"
        );

        $this->assertSame($expected['board_manager_ids'], $copyData['board_manager_ids']);
        $this->assertSame($expected['board_managers'], $copyData['board_managers']);
        $this->assertSame($expected['board_step_ids'], $copyData['board_step_ids']);
        $this->assertSame($expected['board_steps'], $copyData['board_steps']);
    }

    /**
     * 4-4 (C) 누수 차단: copyBoard 반환 permissions 의 roles 에 옛 slug 스코프
     * manager/step identifier 가 없어야 하고, 비-스코프 역할은 보존되어야 한다.
     *
     * @scenario original_permissions=custom
     * @effects clone_strips_old_slug_scope_roles, clone_preserves_non_scope_roles
     */
    public function test_copy_board_strips_old_slug_scope_roles_but_keeps_others(): void
    {
        $manager = User::factory()->create(['name' => 'Manager Leak']);
        $board = $this->createSourceBoardWithManager('clone-src-3', $manager);

        // 원본 권한에 비-스코프 역할(member)을 하나 추가해 보존 여부 검증
        $memberRole = Role::firstOrCreate(
            ['identifier' => 'member'],
            ['name' => ['ko' => '회원', 'en' => 'Member']]
        );
        $permission = Permission::where('identifier', "sirsoft-board.{$board->slug}.posts.read")->first();
        $this->assertNotNull($permission, 'posts.read 권한이 생성되지 않았습니다.');
        $permission->roles()->syncWithoutDetaching([$memberRole->id]);

        $copyData = $this->boardService->copyBoard($board->id);

        $this->assertArrayHasKey('permissions', $copyData);

        $oldManager = "sirsoft-board.{$board->slug}.manager";
        $oldStep = "sirsoft-board.{$board->slug}.step";

        $foundMember = false;
        foreach ($copyData['permissions'] as $key => $roles) {
            $roles = is_array($roles) ? $roles : (array) $roles;
            $this->assertNotContains($oldManager, $roles, "{$key} 권한에 옛 slug manager 역할이 남아있습니다 (누수).");
            $this->assertNotContains($oldStep, $roles, "{$key} 권한에 옛 slug step 역할이 남아있습니다 (누수).");
            if (in_array('member', $roles, true)) {
                $foundMember = true;
            }
        }

        $this->assertTrue($foundMember, '비-스코프 역할(member)이 복제 permissions 에서 유실되었습니다.');
    }

    /**
     * 16-(1) 저장 회귀: form-data(copy_id) 응답을 그대로 새 slug 만 바꿔 저장하면
     * 422 가 아니라 201 로 생성되고, manager 명단이 원본과 동일하게 승계된다.
     *
     * @scenario original_permissions=default
     * @effects clone_saved_201, managers_copied
     */
    public function test_clone_form_data_saves_201_and_inherits_managers(): void
    {
        $manager = User::factory()->create(['name' => 'Manager Save']);
        $source = $this->createSourceBoardWithManager('clone-src-save', $manager);

        // 복제 폼 데이터 로드 (실제 컨트롤러 경로: copy_id → copyBoard + formatPermissionsForFrontend)
        $formResponse = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data?copy_id='.$source->id);
        $formResponse->assertStatus(200);
        $form = $formResponse->json('data');

        // 폼이 그대로 보내는 payload 를 모사 (새 slug 만 변경)
        $form['slug'] = 'clone-dst-save';
        $payload = $form;

        $storeResponse = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', $payload);

        // 422 아님 → 201
        $storeResponse->assertStatus(201);

        // manager 명단 승계 확인
        $newManagerRole = Role::where('identifier', 'sirsoft-board.clone-dst-save.manager')->first();
        $this->assertNotNull($newManagerRole);
        $this->assertTrue(
            $newManagerRole->users()->where('users.id', $manager->id)->exists(),
            '복제본 manager 역할에 원본 manager 가 승계되지 않았습니다.'
        );
    }

    /**
     * 16-(2) 누수 차단 (Feature): 복제 저장 후 새 게시판 권한에 원본 slug 스코프
     * 역할(sirsoft-board.{src}.manager/step)이 attach 되지 않는다 (교차 게시판 누수 0).
     *
     * @scenario original_permissions=custom
     * @effects permissions_copied, no_cross_board_role_leak
     */
    public function test_clone_save_does_not_leak_source_scope_roles(): void
    {
        $manager = User::factory()->create(['name' => 'Manager NoLeak']);
        $source = $this->createSourceBoardWithManager('clone-src-leak', $manager);

        $form = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data?copy_id='.$source->id)
            ->json('data');
        $form['slug'] = 'clone-dst-leak';

        $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', $form)
            ->assertStatus(201);

        // 새 게시판 권한 전체를 순회하며 원본 스코프 역할 부재 확인
        $newPermissions = Permission::where('identifier', 'like', 'sirsoft-board.clone-dst-leak.%')->get();
        $this->assertNotEmpty($newPermissions, '복제본 권한이 생성되지 않았습니다.');

        $srcManager = 'sirsoft-board.clone-src-leak.manager';
        $srcStep = 'sirsoft-board.clone-src-leak.step';

        foreach ($newPermissions as $permission) {
            $assigned = $permission->roles()->pluck('identifier')->toArray();
            $this->assertNotContains($srcManager, $assigned, "{$permission->identifier} 에 원본 manager 역할 누수.");
            $this->assertNotContains($srcStep, $assigned, "{$permission->identifier} 에 원본 step 역할 누수.");
        }
    }

    /**
     * 권한 양성 복사: 원본의 커스텀 역할 할당(member)이 복제 저장 후 새 게시판
     * 동일 권한에 그대로 복사된다. (누수 차단과 별개로, 의도 권한이 실제 승계되는지)
     *
     * @scenario original_permissions=custom
     * @effects permissions_copied, custom_role_assignment_preserved
     */
    public function test_clone_save_copies_custom_role_assignment(): void
    {
        $manager = User::factory()->create(['name' => 'Manager Custom']);
        $source = $this->createSourceBoardWithManager('clone-src-custom', $manager);

        // 원본 posts.read 권한에 비-스코프 커스텀 역할(member) 할당
        $memberRole = Role::firstOrCreate(
            ['identifier' => 'member'],
            ['name' => ['ko' => '회원', 'en' => 'Member']]
        );
        $srcPerm = Permission::where('identifier', "sirsoft-board.{$source->slug}.posts.read")->first();
        $this->assertNotNull($srcPerm);
        $srcPerm->roles()->syncWithoutDetaching([$memberRole->id]);

        // 복제 폼 로드 → 새 slug 저장
        $form = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data?copy_id='.$source->id)
            ->json('data');
        $form['slug'] = 'clone-dst-custom';

        $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', $form)
            ->assertStatus(201);

        // 새 게시판 posts.read 권한에 member 역할이 복사되어야 한다
        $dstPerm = Permission::where('identifier', 'sirsoft-board.clone-dst-custom.posts.read')->first();
        $this->assertNotNull($dstPerm, '복제본 posts.read 권한이 생성되지 않았습니다.');
        $assigned = $dstPerm->roles()->pluck('identifier')->toArray();
        $this->assertContains('member', $assigned, '원본의 커스텀 역할(member)이 복제본에 복사되지 않았습니다.');
    }

    /**
     * 추가버그(1): copyBoard 반환에 add_to_menu 가 boolean 으로 채워져,
     * 복제 폼 저장이 boolean 검증(422)에 걸리지 않는다.
     *
     * @scenario original_permissions=default
     * @effects clone_includes_add_to_menu_boolean
     */
    public function test_copy_board_includes_add_to_menu_as_boolean(): void
    {
        $manager = User::factory()->create(['name' => 'Manager Menu']);
        $source = $this->createSourceBoardWithManager('clone-src-menu', $manager);

        $copyData = $this->boardService->copyBoard($source->id);

        $this->assertArrayHasKey('add_to_menu', $copyData);
        $this->assertIsBool($copyData['add_to_menu']);
    }

    /**
     * 추가버그(2): add_to_menu 검증 실패 메시지가 필드명(add_to_menu)이 아니라
     * 한글 라벨로 치환되어 노출된다.
     *
     * @effects add_to_menu_validation_uses_translated_label
     */
    public function test_add_to_menu_validation_error_uses_translated_label(): void
    {
        $manager = User::factory()->create(['name' => 'Manager Label']);

        // 한글 라벨 치환을 결정적으로 검증하기 위해 locale 고정
        app()->setLocale('ko');

        $payload = [
            'slug' => 'clone-dst-label',
            'name' => ['ko' => '라벨 테스트', 'en' => 'Label Test'],
            'type' => 'default',
            'board_manager_ids' => [$manager->uuid],
            'add_to_menu' => 'not-a-boolean', // boolean 위반 유도
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/boards', $payload, ['Accept-Language' => 'ko']);

        $response->assertStatus(422);
        $message = $response->json('errors.add_to_menu.0') ?? $response->json('message');

        // 원시 필드명(add_to_menu / "add to menu")이 그대로 노출되지 않고 한글 라벨로 치환되어야 함
        $this->assertStringNotContainsString('add to menu', (string) $message);
        $this->assertStringNotContainsString('add_to_menu', (string) $message);
        $this->assertStringContainsString('관리자 메뉴에 표시', (string) $message);
    }
}
