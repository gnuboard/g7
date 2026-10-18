<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 관리자 게시글 목록 응답이 게시판의 「조회수 표시」 설정을 싣는지 검증합니다.
 *
 * 관리자 목록 화면의 「조회수」 열은 이 값(`data.board.settings.show_view_count`)으로 열을 감춘다.
 * 종전 응답에는 이 키가 없어(board 키: slug·name·type·categories·show_category·settings) 열 조건이
 * 가리키는 자리가 늘 비어 있었다(7.1.0 실측 O-K1-11).
 */
class AdminPostListViewCountSettingTest extends BoardTestCase
{
    /**
     * 테스트 게시판 slug
     *
     * @return string 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'admin-list-view-count';
    }

    /**
     * 관리자 게시글 목록을 조회합니다.
     *
     * @return TestResponse 응답
     */
    private function fetchAdminList()
    {
        $admin = $this->createAdminUser(["sirsoft-board.{$this->board->slug}.admin.posts.read"]);

        return $this->actingAs($admin)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts");
    }

    /**
     * 조회수 표시를 끈 게시판은 settings.show_view_count=false 를 싣는다.
     *
     * @scenario show_view_count=false, board_type=admin_list
     *
     * @effects admin_list_view_count_column_follows_show_view_count
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_carries_show_view_count_false(): void
    {
        $this->board->update(['show_view_count' => false]);

        $this->fetchAdminList()
            ->assertStatus(200)
            ->assertJsonPath('data.board.settings.show_view_count', false);
    }

    /**
     * 조회수 표시를 켠 게시판은 settings.show_view_count=true 를 싣는다.
     *
     * @scenario show_view_count=true, board_type=admin_list
     *
     * @effects admin_list_view_count_column_follows_show_view_count
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_carries_show_view_count_true(): void
    {
        $this->board->update(['show_view_count' => true]);

        $this->fetchAdminList()
            ->assertStatus(200)
            ->assertJsonPath('data.board.settings.show_view_count', true);
    }
}
