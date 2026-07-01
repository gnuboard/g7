<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Carbon;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * New 배지 표시 기간(new_display_hours)이 사용자 게시글 목록에 반영되는지 검증합니다.
 *
 * 이슈 #413-22-2: 목록 조회 시 게시글에 board 관계가 주입되지 않아
 * Post::isNew()가 게시판 설정값 대신 기본값 24시간으로만 판정하던 회귀.
 *
 * 목록 응답 각 게시글의 is_new 가 게시판 new_display_hours 설정을 따라야 한다.
 */
class PostNewBadgeDisplayTest extends BoardTestCase
{
    /**
     * 테스트 게시판 slug
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
     * new_display_hours=1 설정 시, 1시간을 초과한 게시글은 목록에서 is_new=false 여야 한다.
     *
     * 수정 전: 목록이 board 설정을 모르고 24시간 기준으로 계산 → is_new=true (회귀)
     *
     * @scenario case=nh1_beyond_not_new
     *
     * @effects list_is_new_false_when_post_beyond_window
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
