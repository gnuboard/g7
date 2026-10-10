<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 마이페이지 게시판 활동 검색 대상 구분 + 댓글 OFF 게시판 제외 테스트
 *
 * 검증 목적 (이슈 #524 §4.2 · §4.3):
 * - 내 글: search_field = title_content(기본) / title / content
 * - 내 댓글: search_field = content(기본) / post_title
 * - 적용된 search_field 를 data.query 로 에코해 화면이 셀렉트를 복원할 수 있다
 * - 허용 목록 밖의 값은 422 가 아니라 기본값으로 떨어진다 (탭 왕복 시 옛 쿼리 보호)
 * - use_comment=false 게시판의 댓글은 "내 댓글"·"댓글 단 글" 집계에서 빠진다
 *
 * URL:
 * - GET /api/modules/sirsoft-board/me/board-activities
 * - GET /api/modules/sirsoft-board/me/my-comments
 *
 * @scenario surface=mypage_my_posts,search_field=title
 *
 * @effects mypage_my_posts_search_field_title_only, mypage_my_comments_search_field_post_title
 */
#[Group('board')]
#[Group('user')]
#[Group('search')]
class UserActivitySearchFieldTest extends BoardTestCase
{
    private User $member;

    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'mypage-activity-search';
    }

    /**
     * 이 테스트에 맞춘 게시판 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 slug
     * @return array 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '활동 검색 테스트 게시판', 'en' => 'Activity Search Test Board'],
            'is_active' => true,
            'use_comment' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.write']);
        $this->member = $this->createUser();
    }

    // ==========================================
    // 내 글 검색 대상
    // ==========================================

    /**
     * search_field=title → 본문에만 있는 키워드는 잡지 않는다.
     *
     * @scenario surface=mypage_my_posts,search_field=title
     *
     * @effects mypage_my_posts_search_field_title_only
     *
     * @return void 반환값 없음
     */
    public function test_my_posts_search_field_title_only(): void
    {
        $titleHit = $this->createMyPost(['title' => '알파 제목', 'content' => '무관한 본문']);
        $this->createMyPost(['title' => '무관한 제목', 'content' => '알파 본문']);

        $this->assertSame([$titleHit], $this->myPostIds('알파', 'title'));
    }

    /**
     * search_field=content → 제목에만 있는 키워드는 잡지 않는다.
     *
     * @scenario surface=mypage_my_posts,search_field=content
     *
     * @effects mypage_my_posts_search_field_content_only
     *
     * @return void 반환값 없음
     */
    public function test_my_posts_search_field_content_only(): void
    {
        $this->createMyPost(['title' => '알파 제목', 'content' => '무관한 본문']);
        $contentHit = $this->createMyPost(['title' => '무관한 제목', 'content' => '알파 본문']);

        $this->assertSame([$contentHit], $this->myPostIds('알파', 'content'));
    }

    /**
     * search_field=title_content(기본) → 제목·내용 어느 쪽이든 잡는다.
     *
     * 내 글 검색은 FULLTEXT 가 아니라 LIKE 경로라 트랜잭션 안에서도 행이 보인다.
     *
     * @scenario surface=mypage_my_posts,search_field=title_content
     *
     * @effects mypage_my_posts_search_field_title_content
     *
     * @return void 반환값 없음
     */
    public function test_my_posts_search_field_title_content(): void
    {
        $titleHit = $this->createMyPost(['title' => '알파 제목', 'content' => '무관한 본문']);
        $contentHit = $this->createMyPost(['title' => '무관한 제목', 'content' => '알파 본문']);
        $this->createMyPost(['title' => '베타', 'content' => '베타']);

        $ids = $this->myPostIds('알파', 'title_content');
        sort($ids);

        $expected = [$titleHit, $contentHit];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    /**
     * 적용된 search_field 를 data.query 로 에코하고, 모르는 값은 기본값으로 떨어진다.
     *
     * @scenario surface=mypage_my_posts
     *
     * @effects mypage_my_posts_echoes_applied_search_field
     *
     * @return void 반환값 없음
     */
    public function test_my_posts_echoes_search_field_and_falls_back_on_unknown(): void
    {
        $this->createMyPost(['title' => '알파 제목']);

        $this->actingAs($this->member)
            ->getJson($this->activitiesUrl(['search' => '알파', 'search_field' => 'content']))
            ->assertStatus(200)
            ->assertJsonPath('data.query.search_field', 'content');

        // 허용 목록 밖 → 422 가 아니라 기본값
        $this->actingAs($this->member)
            ->getJson($this->activitiesUrl(['search' => '알파', 'search_field' => 'author']))
            ->assertStatus(200)
            ->assertJsonPath('data.query.search_field', 'title_content');
    }

    // ==========================================
    // 내 댓글 검색 대상
    // ==========================================

    /**
     * search_field=content(기본) → 댓글 내용으로 매칭한다.
     *
     * @scenario surface=mypage_my_comments,search_field=content
     *
     * @effects mypage_my_comments_search_field_content
     *
     * @return void 반환값 없음
     */
    public function test_my_comments_search_field_content(): void
    {
        $postId = $this->createTestPost(['title' => '무관한 제목']);
        $this->createMyComment($postId, ['content' => '알파 댓글 내용']);
        $this->createMyComment($postId, ['content' => '베타 댓글 내용']);

        $response = $this->actingAs($this->member)
            ->getJson($this->commentsUrl(['search' => '알파', 'search_field' => 'content']));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.query.search_field', 'content');
    }

    /**
     * search_field=post_title → 원글 제목으로 매칭한다 (댓글 내용은 보지 않는다).
     *
     * @scenario surface=mypage_my_comments,search_field=post_title
     *
     * @effects mypage_my_comments_search_field_post_title
     *
     * @return void 반환값 없음
     */
    public function test_my_comments_search_field_post_title(): void
    {
        $matchedPost = $this->createTestPost(['title' => '알파 원글 제목']);
        $otherPost = $this->createTestPost(['title' => '베타 원글 제목']);

        // 댓글 내용에는 '알파' 가 없다 — post_title 분기만이 이 행을 잡을 수 있다
        $this->createMyComment($matchedPost, ['content' => '무관한 댓글']);
        // 댓글 내용에는 '알파' 가 있지만 원글 제목에는 없다 — post_title 분기는 잡지 않아야 한다
        $this->createMyComment($otherPost, ['content' => '알파 라고 쓴 댓글']);

        $response = $this->actingAs($this->member)
            ->getJson($this->commentsUrl(['search' => '알파', 'search_field' => 'post_title']));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        // 내 댓글 행은 원글 ID 를 post_id_val 로 낸다 (post_id 가 아니다)
        $response->assertJsonPath('data.data.0.post_id_val', $matchedPost);
    }

    // ==========================================
    // C1 — use_comment OFF 게시판 제외
    // ==========================================

    /**
     * use_comment=false 게시판의 댓글은 "내 댓글" 목록에서 빠진다.
     *
     * @scenario surface=mypage_my_comments,board_setting=use_comment_off
     *
     * @effects mypage_my_comments_excludes_comment_disabled_board
     *
     * @return void 반환값 없음
     */
    public function test_my_comments_excludes_use_comment_off_board(): void
    {
        $postId = $this->createTestPost(['title' => '댓글 달 글']);
        $this->createMyComment($postId, ['content' => '내 댓글']);

        $this->actingAs($this->member)
            ->getJson($this->commentsUrl())
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data');

        // 게시판이 댓글 기능을 끈다
        DB::table('boards')->where('id', $this->board->id)->update(['use_comment' => false]);

        $this->actingAs($this->member)
            ->getJson($this->commentsUrl())
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.data');
    }

    /**
     * use_comment=false 게시판은 "댓글 단 글"(activity_type=commented) 집계에서도 빠진다.
     *
     * @scenario surface=mypage_activities,board_setting=use_comment_off
     *
     * @effects mypage_activities_commented_excludes_comment_disabled_board
     *
     * @return void 반환값 없음
     */
    public function test_board_activities_commented_excludes_use_comment_off_board(): void
    {
        $postId = $this->createTestPost(['title' => '댓글 단 글']);
        $this->createMyComment($postId, ['content' => '내 댓글']);

        $this->actingAs($this->member)
            ->getJson($this->activitiesUrl(['activity_type' => 'commented']))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data');

        DB::table('boards')->where('id', $this->board->id)->update(['use_comment' => false]);

        $this->actingAs($this->member)
            ->getJson($this->activitiesUrl(['activity_type' => 'commented']))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.data');
    }

    // ==========================================
    // 헬퍼
    // ==========================================

    /**
     * 내 활동 목록 URL 을 만듭니다.
     *
     * @param  array<string, mixed>  $query  쿼리 파라미터
     * @return string 엔드포인트 URL
     */
    private function activitiesUrl(array $query = []): string
    {
        $base = '/api/modules/sirsoft-board/me/board-activities';

        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    /**
     * 내 댓글 목록 URL 을 만듭니다.
     *
     * @param  array<string, mixed>  $query  쿼리 파라미터
     * @return string 엔드포인트 URL
     */
    private function commentsUrl(array $query = []): string
    {
        $base = '/api/modules/sirsoft-board/me/my-comments';

        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    /**
     * 내가 쓴 글을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  게시글 속성
     * @return int 게시글 ID
     */
    private function createMyPost(array $attributes = []): int
    {
        return $this->createTestPost(array_merge([
            'user_id' => $this->member->id,
            'author_name' => $this->member->name,
        ], $attributes));
    }

    /**
     * 내가 쓴 댓글을 만듭니다.
     *
     * @param  int  $postId  원글 ID
     * @param  array<string, mixed>  $attributes  댓글 속성
     * @return int 댓글 ID
     */
    private function createMyComment(int $postId, array $attributes = []): int
    {
        return $this->createTestComment($postId, array_merge([
            'user_id' => $this->member->id,
            'author_name' => $this->member->name,
        ], $attributes));
    }

    /**
     * 내 글 검색 결과의 게시글 ID 목록을 반환합니다.
     *
     * @param  string  $search  검색어
     * @param  string  $searchField  검색 대상
     * @return array<int, int> 게시글 ID 목록
     */
    private function myPostIds(string $search, string $searchField): array
    {
        $response = $this->actingAs($this->member)
            ->getJson($this->activitiesUrl(['search' => $search, 'search_field' => $searchField]));

        $response->assertStatus(200);

        return array_map('intval', array_column($response->json('data.data') ?? [], 'id'));
    }
}
