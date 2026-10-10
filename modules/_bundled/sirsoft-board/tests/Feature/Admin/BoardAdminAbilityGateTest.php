<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 관리자 화면의 abilities 게이트 회귀 테스트 (7.1.0 abilities 게이트 최종 점검)
 *
 * 화면이 잠글 근거(응답 abilities)가 없거나 화면 축과 서버 축이 어긋난 곳을 맞춘다.
 * - 게시판 생성·복사 폼: form-data 가 `abilities.can_create` 를 실어 등록 권한이 없으면 폼을 잠근다
 * - 환경설정 일괄 적용: 환경설정 수정 + 게시판 수정 권한을 둘 다 요구한다(제품 결정 2026-10-09)
 * - 게시판 유형 관리·SEO 캐시 초기화: 서버가 요구하는 권한을 설정 응답 abilities 로 알린다
 * - 게시판 유형·반응 유형 목록: 그 목록을 그리는 화면의 진입 권한으로 읽을 수 있다
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-admin-ability-gate.yaml 참조.
 */
class BoardAdminAbilityGateTest extends ModuleTestCase
{
    private Board $board;

    /**
     * 기본 게시판 유형과 게시판 하나를 준비합니다.
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

        $super = User::factory()->create(['is_super' => true, 'status' => 'active']);
        $this->actingAs($super);
        $this->board = app(BoardService::class)->createBoard([
            'name' => ['ko' => '게이트 게시판', 'en' => 'Gate Board'],
            'slug' => 'gate710-'.substr(md5(uniqid('', true)), 0, 8),
            'type' => 'basic',
        ]);
        auth()->forgetGuards();
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
            'identifier' => 'gate710_'.uniqid(),
            'name' => ['ko' => '게이트 역할', 'en' => 'Gate Role'],
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
     * 조회 권한만 있는 관리자에게 생성 폼은 등록 불가(can_create=false)로 온다.
     *
     * @scenario case=form_create_without_create
     *
     * @effects form_create_reports_can_create
     *
     * @return void 반환값 없음
     */
    public function test_create_form_reports_can_create_false_without_create_permission(): void
    {
        $reader = $this->adminWith(['sirsoft-board.boards.read']);

        $this->actingAs($reader)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_create', false);
    }

    /**
     * 등록 권한이 있으면 생성·복사 폼 모두 can_create=true 로 온다.
     *
     * @scenario case=form_create_with_create
     *
     * @effects form_create_reports_can_create
     *
     * @return void 반환값 없음
     */
    public function test_create_and_copy_form_report_can_create_true_with_create_permission(): void
    {
        $creator = $this->adminWith(['sirsoft-board.boards.read', 'sirsoft-board.boards.create']);

        $this->actingAs($creator)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_create', true);

        $this->actingAs($creator)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data?copy_id='.$this->board->id)
            ->assertOk()
            ->assertJsonPath('data.abilities.can_create', true);
    }

    /**
     * 복사 폼도 등록 권한이 없으면 can_create=false 로 온다.
     *
     * @scenario case=form_copy_without_create
     *
     * @effects form_create_reports_can_create
     *
     * @return void 반환값 없음
     */
    public function test_copy_form_reports_can_create_false_without_create_permission(): void
    {
        $reader = $this->adminWith(['sirsoft-board.boards.read']);

        $this->actingAs($reader)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data?copy_id='.$this->board->id)
            ->assertOk()
            ->assertJsonPath('data.abilities.can_create', false);
    }

    /**
     * 폼의 반응 유형 「관리」 이동 버튼은 환경설정 수정 권한으로 열린다(_meta 로 알림).
     *
     * @scenario case=form_reaction_manage_flag
     *
     * @effects form_reports_reaction_manage_flag
     *
     * @return void 반환값 없음
     */
    public function test_form_reports_reaction_type_manage_flag(): void
    {
        $reader = $this->adminWith(['sirsoft-board.boards.read']);
        $this->actingAs($reader)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data')
            ->assertOk()
            ->assertJsonPath('data._meta.can_manage_reaction_types', false);

        $manager = $this->adminWith(['sirsoft-board.boards.read', 'sirsoft-board.settings.read', 'sirsoft-board.settings.update']);
        $this->actingAs($manager)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data?board_slug='.$this->board->slug)
            ->assertOk()
            ->assertJsonPath('data._meta.can_manage_reaction_types', true);
    }

    /**
     * 게시판 폼의 반응 유형 체크박스 목록은 게시판 조회 권한으로 읽힌다(환경설정 조회 권한이 없어도).
     *
     * @scenario case=reaction_types_list_by_board_reader
     *
     * @effects reaction_types_readable_by_board_reader
     *
     * @return void 반환값 없음
     */
    public function test_reaction_types_list_is_readable_with_board_read(): void
    {
        $reader = $this->adminWith(['sirsoft-board.boards.read']);

        $this->actingAs($reader)
            ->getJson('/api/modules/sirsoft-board/admin/reaction-types')
            ->assertOk();
    }

    /**
     * 환경설정 응답 abilities 는 일괄 적용·유형 관리·SEO 캐시 초기화 권한을 따로 알린다.
     *
     * @scenario case=settings_abilities_settings_update_only
     *
     * @effects settings_reports_split_abilities
     *
     * @return void 반환값 없음
     */
    public function test_settings_abilities_report_split_permissions_for_settings_editor(): void
    {
        $editor = $this->adminWith(['sirsoft-board.settings.read', 'sirsoft-board.settings.update']);

        $this->actingAs($editor)
            ->getJson('/api/modules/sirsoft-board/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_update', true)
            ->assertJsonPath('data.abilities.can_bulk_apply', false)
            ->assertJsonPath('data.abilities.can_manage_board_types', false)
            ->assertJsonPath('data.abilities.can_clear_seo_cache', false);
    }

    /**
     * 필요한 권한을 모두 가지면 세 키가 true 로 온다.
     *
     * @scenario case=settings_abilities_all_granted
     *
     * @effects settings_reports_split_abilities
     *
     * @return void 반환값 없음
     */
    public function test_settings_abilities_true_when_all_permissions_granted(): void
    {
        $admin = $this->adminWith([
            'sirsoft-board.settings.read',
            'sirsoft-board.settings.update',
            'sirsoft-board.boards.update',
            'sirsoft-board.boards.create',
            'core.settings.update',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/modules/sirsoft-board/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_bulk_apply', true)
            ->assertJsonPath('data.abilities.can_manage_board_types', true)
            ->assertJsonPath('data.abilities.can_clear_seo_cache', true);
    }

    /**
     * 게시판 수정 권한 없이 환경설정 수정 권한만으로는 일괄 적용이 403 이다.
     *
     * @scenario case=bulk_apply_without_board_update
     *
     * @effects bulk_apply_requires_board_update
     *
     * @return void 반환값 없음
     */
    public function test_bulk_apply_is_forbidden_without_board_update(): void
    {
        $editor = $this->adminWith(['sirsoft-board.settings.read', 'sirsoft-board.settings.update']);

        $this->actingAs($editor)
            ->postJson('/api/modules/sirsoft-board/admin/settings/bulk-apply', [
                'fields' => ['show_view_count'],
                'apply_all' => true,
            ])
            ->assertForbidden();
    }

    /**
     * 환경설정 조회 권한만 있어도 「기본 유형」 선택지를 그릴 게시판 유형 목록은 읽힌다. 쓰기는 그대로 등록 권한이다.
     *
     * @scenario case=board_types_list_by_settings_reader
     *
     * @effects board_types_readable_by_settings_reader
     *
     * @return void 반환값 없음
     */
    public function test_board_types_list_readable_by_settings_reader_but_write_needs_create(): void
    {
        $reader = $this->adminWith(['sirsoft-board.settings.read']);

        $this->actingAs($reader)
            ->getJson('/api/modules/sirsoft-board/admin/board-types')
            ->assertOk();

        $this->actingAs($reader)
            ->postJson('/api/modules/sirsoft-board/admin/board-types', [
                'slug' => 'gate710type',
                'name' => ['ko' => '유형', 'en' => 'Type'],
            ])
            ->assertForbidden();
    }
}
