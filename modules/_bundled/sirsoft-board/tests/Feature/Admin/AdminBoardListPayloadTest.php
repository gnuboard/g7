<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 관리자 게시판 목록 페이로드 회귀 테스트 (#518).
 *
 * 목록 표현(`toAdminListArray`)은 전체 표현(`toArray`)에서 필요한 항목만 골라 담는다.
 * 골라 담는 목록은 화면이 실제로 그리는 항목의 상위집합이어야 한다 — 빠지면 예외도 오류도 없이
 * 그 컬럼만 공란으로 렌더된다.
 */
class AdminBoardListPayloadTest extends ModuleTestCase
{
    protected User $adminUser;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        BoardType::firstOrCreate(
            ['slug' => 'basic'],
            ['name' => ['ko' => '기본', 'en' => 'Basic'], 'is_active' => true, 'is_default' => true]
        );

        $this->adminUser = $this->createAdminUser([
            'sirsoft-board.boards.read',
        ]);
    }

    /**
     * 관리자 목록 행에 등록일이 실린다.
     *
     * 관리자 게시판 목록 화면(admin_board_index.json)은 「등록일」 컬럼에 `row.created_at` 을
     * 그린다. 목록 표현이 이 키를 빼면 화면은 조용히 공란이 된다.
     *
     * @return void
     *
     * @scenario endpoint=admin_list,role_assignment=unassigned
     * @effects admin_list_row_carries_created_at
     */
    public function test_admin_board_list_row_carries_created_at(): void
    {
        $board = Board::create([
            'name' => ['ko' => '등록일 확인 게시판'],
            'slug' => 'created-at-'.uniqid(),
            'type' => 'basic',
            'is_active' => true,
        ]);

        $rows = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards')
            ->assertStatus(200)
            ->json('data.data');

        $row = collect($rows)->firstWhere('id', $board->id);

        $this->assertNotNull($row, '생성한 게시판이 목록에 있어야 한다');
        $this->assertArrayHasKey(
            'created_at',
            $row,
            '관리자 목록 화면이 등록일 컬럼을 그리는데 응답에 키가 없으면 공란으로만 렌더된다'
        );
        $this->assertNotNull($row['created_at'], '등록일 값이 null 이면 화면은 여전히 공란이다');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $row['created_at'],
            '전체 표현(toArray)과 같은 사용자 타임존 문자열 형식이어야 한다'
        );
    }

    /**
     * 목록 표현은 화면이 쓰지 않는 무거운 항목을 싣지 않는다 (프루닝 유지).
     *
     * 등록일 누락을 고치면서 전체 표현으로 되돌리면 게시판마다 매니저/스텝 역할의
     * 소속 사용자 전원(uuid·이름·이메일)이 다시 실린다 — 행당 추가 쿼리이자 개인정보 노출이다.
     *
     * @return void
     *
     * @scenario endpoint=admin_list,role_assignment=unassigned
     * @effects admin_list_omits_role_member_payload
     */
    public function test_admin_board_list_stays_pruned(): void
    {
        Board::create([
            'name' => ['ko' => '프루닝 확인 게시판'],
            'slug' => 'pruned-'.uniqid(),
            'type' => 'basic',
            'is_active' => true,
        ]);

        $row = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/boards')
            ->assertStatus(200)
            ->json('data.data.0');

        foreach (['board_managers', 'board_steps', 'board_manager_ids', 'board_step_ids', 'permissions'] as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $row,
                "목록 표현에 {$key} 가 다시 실리면 행 수만큼 추가 쿼리가 발생한다"
            );
        }
    }
}
