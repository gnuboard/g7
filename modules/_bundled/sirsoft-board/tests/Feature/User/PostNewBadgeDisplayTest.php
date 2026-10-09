<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Carbon;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * New 배지 표시 기간(new_display_hours)이 사용자 게시글 목록과 관리자 게시글 목록에 반영되는지 검증합니다.
 *
 * 이슈 #413-22-2: 목록 조회 시 게시글에 board 관계가 주입되지 않아
 * Post::isNew()가 게시판 설정값 대신 기본값 24시간으로만 판정하던 회귀.
 *
 * 목록 응답 각 게시글의 is_new 가 게시판 new_display_hours 설정을 따라야 한다. 관리자 목록도 같은 주입을 받으므로
 * 같은 기준인지 함께 본다.
 */
class PostNewBadgeDisplayTest extends BoardTestCase
{
    /**
     * 테스트 게시판 slug
     *
     * @return string 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'new-badge';
    }

    /**
     * 기본 게시판 속성 (비밀글 비활성 — New 판정만 검증)
     *
     * @param  string  $slug  게시판 slug
     * @return array 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => 'New 배지 테스트', 'en' => 'New Badge Test'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
            // 각 테스트가 update로 값을 바꾸므로 setUp(updateOrCreate)마다 기본값(24)을 명시해
            // 이전 테스트의 잔류값(예: 1)이 다음 테스트로 새지 않도록 보장한다.
            'new_display_hours' => 24,
        ];
    }

    /**
     * 목록 응답에서 특정 게시글의 is_new 값을 조회합니다.
     *
     * @param  int  $postId  대상 게시글 ID
     * @return bool|null is_new 값 (목록에 없으면 null)
     */
    private function fetchIsNewFromList(int $postId): ?bool
    {
        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts?per_page=30");
        $response->assertStatus(200);

        foreach ($response->json('data.data') ?? [] as $row) {
            if (($row['id'] ?? null) === $postId) {
                return $row['is_new'];
            }
        }

        return null;
    }

    /**
     * 관리자 게시글 목록 응답에서 특정 게시글의 is_new 값을 조회합니다.
     *
     * @param  int  $postId  대상 게시글 ID
     * @return bool|null is_new 값 (목록에 없으면 null)
     */
    private function fetchIsNewFromAdminList(int $postId): ?bool
    {
        $admin = $this->createAdminUser(["sirsoft-board.{$this->board->slug}.admin.posts.read"]);

        $response = $this->actingAs($admin)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts?per_page=30");
        $response->assertStatus(200);

        foreach ($response->json('data.data') ?? [] as $row) {
            if (($row['id'] ?? null) === $postId) {
                return $row['is_new'];
            }
        }

        return null;
    }

    /**
     * 게시글 상세 응답의 답변 글 목록에서 특정 답변 글의 is_new 값을 찾습니다(손자 답변까지).
     *
     * @param  array  $replies  응답의 replies 배열
     * @param  int  $replyId  대상 답변 글 ID
     * @return bool|null is_new 값 (없으면 null)
     */
    private function findReplyIsNew(array $replies, int $replyId): ?bool
    {
        foreach ($replies as $reply) {
            if (($reply['id'] ?? null) === $replyId) {
                return $reply['is_new'];
            }
            $nested = $this->findReplyIsNew($reply['replies'] ?? [], $replyId);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * 관리자 게시글 상세의 답변 글도 게시판의 New 배지 기간을 따른다 — 0시간(배지 안 붙임) 게시판에서 방금 단 답변 글은 New 가 아니다.
     *
     * 상세가 답변 글을 따로 불러오며 게시판 관계를 붙이지 않아, 원글은 New 가 아닌데 답변 글만 늘 24시간 기준으로
     * New 가 붙었다(7.1.0 실측 K3 — 표시 기간 0 게시판의 답변 3개 모두 「New」).
     *
     * @scenario case=admin_detail_reply_nh0_not_new
     *
     * @effects detail_reply_is_new_follows_board_window
     *
     * @return void 반환값 없음
     */
    public function test_admin_detail_replies_follow_new_display_hours(): void
    {
        $this->board->update(['new_display_hours' => 0]);

        $parentId = $this->createTestPost(['title' => '원글']);
        $replyId = $this->createTestPost(['title' => '답변 글', 'parent_id' => $parentId, 'depth' => 1]);
        $grandId = $this->createTestPost(['title' => '손자 답변 글', 'parent_id' => $replyId, 'depth' => 2]);

        $admin = $this->createAdminUser(["sirsoft-board.{$this->board->slug}.admin.posts.read"]);
        $response = $this->actingAs($admin)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$parentId}");
        $response->assertStatus(200);

        $this->assertFalse($response->json('data.is_new'), '표시 기간 0 게시판의 원글은 New 가 아니어야 한다.');
        $this->assertFalse(
            $this->findReplyIsNew($response->json('data.replies') ?? [], $replyId),
            '표시 기간 0 게시판의 답변 글은 관리자 상세에서도 New 가 아니어야 한다.'
        );
        $this->assertFalse(
            $this->findReplyIsNew($response->json('data.replies') ?? [], $grandId),
            '손자 답변 글도 게시판 기간을 따라야 한다.'
        );
    }

    /**
     * 관리자 게시글 상세의 답변 글은 48시간 게시판에서 26시간 전 답변이 New 다(기본 24시간이 아닌 게시판 기간).
     *
     * @scenario case=admin_detail_reply_nh48_within_new
     *
     * @effects detail_reply_is_new_follows_board_window
     *
     * @return void 반환값 없음
     */
    public function test_admin_detail_reply_within_long_window_is_new(): void
    {
        $this->board->update(['new_display_hours' => 48]);

        $parentId = $this->createTestPost(['title' => '원글', 'created_at' => Carbon::now()->subHours(30)]);
        $replyId = $this->createTestPost([
            'title' => '26시간 전 답변 글',
            'parent_id' => $parentId,
            'depth' => 1,
            'created_at' => Carbon::now()->subHours(26),
        ]);

        $admin = $this->createAdminUser(["sirsoft-board.{$this->board->slug}.admin.posts.read"]);
        $response = $this->actingAs($admin)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$parentId}");
        $response->assertStatus(200);

        $this->assertTrue(
            $this->findReplyIsNew($response->json('data.replies') ?? [], $replyId),
            'new_display_hours=48 일 때 26시간 전 답변 글은 관리자 상세에서 New 여야 한다.'
        );
    }

    /**
     * 관리자 목록도 게시판의 New 배지 기간을 따른다 — 1시간 게시판에서 2시간 전 글은 New 가 아니다.
     *
     * 관리자 목록이 게시글에 게시판을 붙이지 않던 동안에는 Post::isNew() 가 기본값 24시간으로만 판정해,
     * 같은 글이 사용자 목록에서는 New 가 아니고 관리자 목록에서는 New 로 보였다.
     *
     * @scenario case=admin_nh1_beyond_not_new
     *
     * @effects admin_list_is_new_follows_board_window
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_follows_new_display_hours_for_old_post(): void
    {
        $this->board->update(['new_display_hours' => 1]);

        $postId = $this->createTestPost([
            'title' => '2시간 전 글',
            'created_at' => Carbon::now()->subHours(2),
        ]);

        $this->assertFalse(
            $this->fetchIsNewFromAdminList($postId),
            'new_display_hours=1 일 때 2시간 전 게시글은 관리자 목록에서도 is_new=false 여야 한다.'
        );
    }

    /**
     * 관리자 목록도 게시판의 New 배지 기간을 따른다 — 48시간 게시판에서 26시간 전 글은 New 다.
     *
     * @scenario case=admin_nh48_within_new
     *
     * @effects admin_list_is_new_follows_board_window
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_follows_new_display_hours_for_recent_post(): void
    {
        $this->board->update(['new_display_hours' => 48]);

        $postId = $this->createTestPost([
            'title' => '26시간 전 글',
            'created_at' => Carbon::now()->subHours(26),
        ]);

        $this->assertTrue(
            $this->fetchIsNewFromAdminList($postId),
            'new_display_hours=48 일 때 26시간 전 게시글은 관리자 목록에서도 is_new=true 여야 한다.'
        );
    }

    /**
     * new_display_hours=1 설정 시, 1시간을 초과한 게시글은 목록에서 is_new=false 여야 한다.
     *
     * 수정 전: 목록이 board 설정을 모르고 24시간 기준으로 계산 → is_new=true (회귀)
     *
     * @scenario case=nh1_beyond_not_new
     *
     * @effects list_is_new_false_when_post_beyond_window
     *
     * @return void 반환값 없음
     */
    public function test_new_display_hours_one_marks_post_older_than_one_hour_as_not_new(): void
    {
        $this->board->update(['new_display_hours' => 1]);

        // 2시간 전 게시글 (1시간 기준 초과)
        $postId = $this->createTestPost([
            'title' => '2시간 전 글',
            'created_at' => Carbon::now()->subHours(2),
        ]);

        $this->assertFalse(
            $this->fetchIsNewFromList($postId),
            'new_display_hours=1 일 때 2시간 전 게시글은 목록에서 is_new=false 여야 한다.'
        );
    }

    /**
     * new_display_hours=48 설정 시, 48시간 이내 게시글은 목록에서 is_new=true 여야 한다.
     *
     * 수정 전: 목록이 24시간 기준으로 계산 → 26시간 전 글이 is_new=false (회귀)
     *
     * @scenario case=nh48_within_new
     *
     * @effects list_is_new_true_when_post_within_window
     *
     * @return void 반환값 없음
     */
    public function test_new_display_hours_forty_eight_marks_recent_post_as_new(): void
    {
        $this->board->update(['new_display_hours' => 48]);

        // 26시간 전 게시글 (24시간 초과, 48시간 이내)
        $postId = $this->createTestPost([
            'title' => '26시간 전 글',
            'created_at' => Carbon::now()->subHours(26),
        ]);

        $this->assertTrue(
            $this->fetchIsNewFromList($postId),
            'new_display_hours=48 일 때 26시간 전 게시글은 목록에서 is_new=true 여야 한다.'
        );
    }

    /**
     * 기본 24시간 게시판: 24시간 이내 게시글은 is_new=true (회귀 방지).
     *
     * @scenario case=default24_within_new
     *
     * @effects default_24h_window_preserved_when_unset
     *
     * @return void 반환값 없음
     */
    public function test_default_twenty_four_hours_marks_recent_post_as_new(): void
    {
        // getDefaultBoardAttributes 에 new_display_hours 미지정 → 모델 기본값(24) 적용
        $recentId = $this->createTestPost([
            'title' => '12시간 전 글',
            'created_at' => Carbon::now()->subHours(12),
        ]);

        $this->assertTrue(
            $this->fetchIsNewFromList($recentId),
            '기본 24시간 기준에서 12시간 전 게시글은 is_new=true 여야 한다.'
        );
    }

    /**
     * 기본 24시간 게시판: 24시간을 초과한 게시글은 is_new=false (회귀 방지).
     *
     * @scenario case=default24_beyond_not_new
     *
     * @effects default_24h_window_preserved_when_unset
     *
     * @return void 반환값 없음
     */
    public function test_default_twenty_four_hours_marks_old_post_as_not_new(): void
    {
        $oldId = $this->createTestPost([
            'title' => '30시간 전 글',
            'created_at' => Carbon::now()->subHours(30),
        ]);

        $this->assertFalse(
            $this->fetchIsNewFromList($oldId),
            '기본 24시간 기준에서 30시간 전 게시글은 is_new=false 여야 한다.'
        );
    }
}
