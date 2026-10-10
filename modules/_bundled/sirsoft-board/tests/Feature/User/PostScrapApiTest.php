<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Enums\UserStatus;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 사용자 게시글 스크랩 API 테스트
 *
 * 검증 목적 (기획서 §12):
 * - 스크랩 toggle: 등록·해제 양방향, scrap_count 증감 정합성
 * - 마이페이지 개별 삭제: 본인 소유 레코드만 허용
 * - 스코프 검증: 다른 게시판 postId 로 toggle 요청 시 차단
 * - use_scrap=false → 403
 * - 비로그인 → 401
 * - 활동 로그 기록 (scrap.scrap / scrap.unscrap)
 * - 목록 조회: 최근 스크랩순, 삭제된 원글 포함
 *
 * URL:
 * - POST   /api/modules/sirsoft-board/boards/{slug}/posts/{id}/scrap/toggle
 * - GET    /api/modules/sirsoft-board/me/scraps
 * - DELETE /api/modules/sirsoft-board/me/scraps/{id}
 *
 * @scenario action=toggle_add
 *
 * @effects scrap_record_created_on_add, scrap_count_incremented_on_add, guest_toggle_rejected_401, scrap_disabled_board_rejected_403, cross_board_post_rejected_404, activity_log_scrap_add_written
 */
#[Group('board')]
#[Group('user')]
#[Group('scrap')]
class PostScrapApiTest extends BoardTestCase
{
    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'user-scrap-api';
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
            'name' => ['ko' => '스크랩 API 테스트 게시판', 'en' => 'Scrap API Test Board'],
            'is_active' => true,
            'use_scrap' => true,
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

        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.write']);
    }

    // ==========================================
    // 스크랩 토글 (등록 / 해제)
    // ==========================================

    /**
     * 회원이 게시글을 스크랩 → added=true, 레코드 생성, scrap_count +1
     *
     * @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
     *
     * @effects scrap_record_created_on_add, scrap_count_incremented_on_add
     *
     * @return void 반환값 없음
     */
    public function test_authenticated_user_can_scrap_post(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $response = $this->actingAs($user)->postJson($this->toggleUrl($postId));

        $response->assertStatus(200);
        $response->assertJsonPath('data.added', true);
        // 응답이 갱신된 수치를 직접 실어 화면이 재조회 없이 확정할 수 있어야 한다.
        $response->assertJsonPath('data.scrap_count', 1);

        $this->assertDatabaseHas('board_post_scraps', [
            'user_id' => $user->id,
            'post_id' => $postId,
            'board_id' => $this->board->id,
        ]);
        $this->assertSame(1, (int) DB::table('board_posts')->where('id', $postId)->value('scrap_count'));
    }

    /**
     * 이미 스크랩한 게시글에 재요청 → added=false, 레코드 삭제, scrap_count -1
     *
     * @scenario action=toggle_remove
     *
     * @effects scrap_record_deleted_on_toggle_remove, scrap_count_decremented_on_remove, scrap_count_never_negative, activity_log_scrap_remove_written
     *
     * @return void 반환값 없음
     */
    public function test_toggle_removes_existing_scrap(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        // 1차 등록
        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);
        // 2차 해제
        $response = $this->actingAs($user)->postJson($this->toggleUrl($postId));

        $response->assertStatus(200);
        $response->assertJsonPath('data.added', false);
        // 해제 후 갱신된 수치도 응답에 실린다.
        $response->assertJsonPath('data.scrap_count', 0);

        $this->assertDatabaseMissing('board_post_scraps', [
            'user_id' => $user->id,
            'post_id' => $postId,
        ]);
        $this->assertSame(0, (int) DB::table('board_posts')->where('id', $postId)->value('scrap_count'));
    }

    /**
     * 본인 글도 스크랩 가능 (확정 07 — 셀프 어뷰징 우려 없음)
     *
     * @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
     *
     * @effects scrap_record_created_on_add
     *
     * @return void 반환값 없음
     */
    public function test_user_can_scrap_own_post(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson($this->toggleUrl($postId));

        $response->assertStatus(200);
        $response->assertJsonPath('data.added', true);
    }

    /**
     * 미인증 요청 → 401
     *
     * @scenario action=toggle_add,actor=guest,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
     *
     * @effects guest_toggle_rejected_401
     *
     * @return void 반환값 없음
     */
    public function test_unauthenticated_cannot_toggle_scrap(): void
    {
        $postId = $this->createTestPost();

        $this->postJson($this->toggleUrl($postId))->assertStatus(401);

        $this->assertDatabaseMissing('board_post_scraps', ['post_id' => $postId]);
    }

    /**
     * use_scrap=false 게시판 → 403
     *
     * @scenario action=toggle_add,actor=owner,board_setting=use_scrap_off,post_state=alive,post_scope=same_board
     *
     * @effects scrap_disabled_board_rejected_403
     *
     * @return void 반환값 없음
     */
    public function test_toggle_blocked_when_scrap_disabled(): void
    {
        $this->updateBoardSettings(['use_scrap' => false]);
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(403);

        $this->assertDatabaseMissing('board_post_scraps', ['post_id' => $postId]);
    }

    /**
     * 다른 게시판의 postId 로 toggle 요청 → 404 (스코프 검증, 교차 접근 차단)
     *
     * @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=other_board
     *
     * @effects cross_board_post_rejected_404
     *
     * @return void 반환값 없음
     */
    public function test_toggle_rejects_post_from_another_board(): void
    {
        $user = $this->createUser();

        // 다른 게시판과 그 소속 게시글 생성
        $otherBoardId = DB::table('boards')->insertGetId([
            'slug' => 'other-scrap-board',
            'name' => json_encode(['ko' => '다른 게시판', 'en' => 'Other Board']),
            'is_active' => true,
            'use_scrap' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherPostId = $this->createTestPost(['board_id' => $otherBoardId]);

        // 우리 게시판 slug 로 다른 게시판 게시글을 스크랩 시도
        $response = $this->actingAs($user)->postJson($this->toggleUrl($otherPostId));

        $response->assertStatus(404);
        $this->assertDatabaseMissing('board_post_scraps', ['post_id' => $otherPostId]);
    }

    /**
     * 스크랩 등록 시 활동 로그(scrap.scrap) 기록
     *
     * @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
     *
     * @effects activity_log_scrap_add_written
     *
     * @return void 반환값 없음
     */
    public function test_scrap_add_writes_activity_log(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'scrap.scrap',
            'loggable_type' => Post::class,
            'loggable_id' => $postId,
        ]);
    }

    /**
     * 스크랩 해제 시 활동 로그(scrap.unscrap) 기록
     *
     * @scenario action=toggle_remove,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
     *
     * @effects activity_log_scrap_remove_written
     *
     * @return void 반환값 없음
     */
    public function test_scrap_remove_writes_activity_log(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);
        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'scrap.unscrap',
            'loggable_type' => Post::class,
            'loggable_id' => $postId,
        ]);
    }

    // ==========================================
    // 마이페이지 목록 조회
    // ==========================================

    /**
     * 스크랩 목록 조회 → 최근 스크랩순
     *
     * @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
     *
     * @effects mypage_list_sorted_recent_first
     *
     * @return void 반환값 없음
     */
    public function test_index_returns_scraps_recent_first(): void
    {
        $user = $this->createUser();
        $firstPost = $this->createTestPost(['title' => '먼저 스크랩']);
        $secondPost = $this->createTestPost(['title' => '나중 스크랩']);

        $this->actingAs($user)->postJson($this->toggleUrl($firstPost))->assertStatus(200);
        $this->actingAs($user)->postJson($this->toggleUrl($secondPost))->assertStatus(200);

        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps');

        $response->assertStatus(200);
        // 최근 스크랩(secondPost)이 먼저
        $response->assertJsonPath('data.data.0.post_id', $secondPost);
        $response->assertJsonPath('data.data.1.post_id', $firstPost);

        // 회귀(#524 브라우저 검수): board_name 은 현재 로케일 문자열이어야 한다.
        // 다국어 JSON 컬럼을 객체 그대로 반환하면 카드 렌더가 charAt 에서 실패한다.
        $this->assertIsString($response->json('data.data.0.board_name'));
    }

    /**
     * 삭제된 원글의 스크랩도 목록에 포함되며 is_deleted=true
     *
     * @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=soft_deleted,post_scope=same_board
     *
     * @effects mypage_list_includes_deleted_post
     *
     * @return void 반환값 없음
     */
    public function test_index_includes_deleted_post_scrap(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        // 원글 소프트 삭제
        DB::table('board_posts')->where('id', $postId)->update(['deleted_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps');

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.is_deleted', true);
    }

    /**
     * 삭제된 원글의 스크랩도 게시판명·작성자명은 노출한다 (제목·댓글수는 미노출).
     * "어떤 글이 삭제됐는지" 맥락을 유지하기 위한 정책 — 완전 미노출로 되돌리지 말 것.
     *
     * @scenario action=mypage_delete
     *
     * @effects mypage_list_deleted_post_exposes_board_and_author
     *
     * @return void 반환값 없음
     */
    public function test_index_deleted_post_scrap_exposes_board_and_author_but_not_title(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost(['author_name' => '삭제전작성자']);

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        DB::table('board_posts')->where('id', $postId)->update(['deleted_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps');

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.is_deleted', true);
        $response->assertJsonPath('data.data.0.author_name', '삭제전작성자');
        $this->assertIsString($response->json('data.data.0.board_name'));
        $response->assertJsonPath('data.data.0.title', null);
        $response->assertJsonPath('data.data.0.comments_count', null);
    }

    /**
     * 탈퇴한 회원이 쓴 글의 스크랩은 author_name 을 익명화한다 (PostResource·CommentResource와 동일 정책).
     * 원글이 삭제됐든 살아있든 예외 없이 적용 — 탈퇴 회원 실명이 스크랩 카드에 남아서는 안 됨.
     *
     * @scenario action=mypage_delete
     *
     * @effects mypage_list_withdrawn_author_anonymized
     *
     * @return void 반환값 없음
     */
    public function test_index_withdrawn_author_scrap_anonymizes_name(): void
    {
        $user = $this->createUser();
        $author = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id, 'author_name' => null]);

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        $author->forceFill(['status' => UserStatus::Withdrawn->value])->save();

        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps');

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.author_name', __('user.withdrawn_user'));
    }

    /**
     * search 파라미터로 게시글 제목을 검색하면 매칭되는 스크랩만 반환한다.
     *
     * @scenario action=mypage_search
     *
     * @effects mypage_list_search_filters_by_title
     *
     * @return void 반환값 없음
     */
    public function test_index_search_filters_by_post_title(): void
    {
        $user = $this->createUser();
        $matched = $this->createTestPost(['title' => '장바구니 정책 안내']);
        $unmatched = $this->createTestPost(['title' => '배송 지연 공지']);

        $this->actingAs($user)->postJson($this->toggleUrl($matched))->assertStatus(200);
        $this->actingAs($user)->postJson($this->toggleUrl($unmatched))->assertStatus(200);

        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps?search='.urlencode('장바구니'));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.post_id', $matched);
    }

    /**
     * search 에 매칭되는 게시글이 없으면 빈 목록을 반환한다 (에러 아님).
     *
     * @scenario action=mypage_search
     *
     * @return void 반환값 없음
     */
    public function test_index_search_no_match_returns_empty_list(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost(['title' => '공지사항']);
        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps?search=존재하지않는키워드');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data.data');
    }

    /**
     * 원글이 삭제된 스크랩은 응답에서 title 이 노출되지 않으므로, search 로도 찾을 수 없다.
     *
     * @scenario action=mypage_search
     *
     * @effects mypage_list_search_excludes_deleted_post_title
     *
     * @return void 반환값 없음
     */
    public function test_index_search_excludes_deleted_post_even_if_title_matches(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost(['title' => '한정판 이벤트 안내']);
        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        DB::table('board_posts')->where('id', $postId)->update(['deleted_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps?search='.urlencode('한정판'));

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data.data');
    }

    // ==========================================
    // 마이페이지 개별 삭제
    // ==========================================

    /**
     * 본인 스크랩 개별 삭제 → 성공, 레코드 제거, scrap_count -1
     *
     * @scenario action=mypage_delete
     *
     * @effects mypage_delete_requires_ownership, mypage_list_sorted_recent_first, mypage_list_includes_deleted_post
     *
     * @return void 반환값 없음
     */
    public function test_user_can_delete_own_scrap(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);
        $scrapId = DB::table('board_post_scraps')->where('user_id', $user->id)->value('id');

        $response = $this->actingAs($user)->deleteJson("/api/modules/sirsoft-board/me/scraps/{$scrapId}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('board_post_scraps', ['id' => $scrapId]);
        $this->assertSame(0, (int) DB::table('board_posts')->where('id', $postId)->value('scrap_count'));
    }

    /**
     * 다른 회원의 스크랩 삭제 시도 → 404 (본인 소유 검증)
     *
     * @scenario action=mypage_delete,actor=other_user,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
     *
     * @effects mypage_delete_requires_ownership
     *
     * @return void 반환값 없음
     */
    public function test_user_cannot_delete_others_scrap(): void
    {
        $owner = $this->createUser();
        $attacker = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($owner)->postJson($this->toggleUrl($postId))->assertStatus(200);
        $scrapId = DB::table('board_post_scraps')->where('user_id', $owner->id)->value('id');

        $response = $this->actingAs($attacker)->deleteJson("/api/modules/sirsoft-board/me/scraps/{$scrapId}");

        $response->assertStatus(404);
        // 소유자 레코드는 그대로 유지
        $this->assertDatabaseHas('board_post_scraps', ['id' => $scrapId]);
    }

    // ==========================================
    // 검색 대상 구분 (search_field)
    // ==========================================

    /**
     * search_field=title_content (기본) → 제목 매칭·내용 매칭 모두 잡는다.
     *
     * @scenario action=mypage_search,search_field=title_content
     *
     * @effects mypage_list_search_field_title_content_matches_title_or_content
     *
     * @return void 반환값 없음
     */
    public function test_index_search_title_content_matches_title_or_content(): void
    {
        $user = $this->createUser();
        $titleHit = $this->createTestPost(['title' => '알파 제목', 'content' => '무관한 본문']);
        $contentHit = $this->createTestPost(['title' => '무관한 제목', 'content' => '알파 본문 키워드']);
        $miss = $this->createTestPost(['title' => '베타 제목', 'content' => '베타 본문']);

        foreach ([$titleHit, $contentHit, $miss] as $postId) {
            $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);
        }

        $response = $this->actingAs($user)->getJson(
            '/api/modules/sirsoft-board/me/scraps?search='.urlencode('알파').'&search_field=title_content'
        );

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.data');
        $ids = array_column($response->json('data.data'), 'post_id');
        sort($ids);
        $expected = [$titleHit, $contentHit];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    /**
     * search_field=title → 본문에만 있는 키워드는 잡지 않는다.
     *
     * @scenario action=mypage_search,search_field=title
     *
     * @effects mypage_list_search_field_title_ignores_content
     *
     * @return void 반환값 없음
     */
    public function test_index_search_title_ignores_content_match(): void
    {
        $user = $this->createUser();
        $titleHit = $this->createTestPost(['title' => '알파 제목', 'content' => '무관한 본문']);
        $contentOnly = $this->createTestPost(['title' => '무관한 제목', 'content' => '알파 본문 키워드']);

        foreach ([$titleHit, $contentOnly] as $postId) {
            $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);
        }

        $response = $this->actingAs($user)->getJson(
            '/api/modules/sirsoft-board/me/scraps?search='.urlencode('알파').'&search_field=title'
        );

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.post_id', $titleHit);
    }

    /**
     * search_field=content → 제목에만 있는 키워드는 잡지 않는다 (제보 ② 의 핵심).
     *
     * @scenario action=mypage_search,search_field=content
     *
     * @effects mypage_list_search_field_content_ignores_title
     *
     * @return void 반환값 없음
     */
    public function test_index_search_content_ignores_title_match(): void
    {
        $user = $this->createUser();
        $titleOnly = $this->createTestPost(['title' => '알파 제목', 'content' => '무관한 본문']);
        $contentHit = $this->createTestPost(['title' => '무관한 제목', 'content' => '알파 본문 키워드']);

        foreach ([$titleOnly, $contentHit] as $postId) {
            $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);
        }

        $response = $this->actingAs($user)->getJson(
            '/api/modules/sirsoft-board/me/scraps?search='.urlencode('알파').'&search_field=content'
        );

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.post_id', $contentHit);
    }

    /**
     * 허용 목록 밖의 search_field 는 422 가 아니라 기본(제목+내용)으로 처리한다 (7.1.0 실측 O-K3-3).
     *
     * @scenario action=mypage_search
     *
     * @effects mypage_list_unknown_search_field_falls_back_to_title_content
     *
     * @return void 반환값 없음
     */
    public function test_index_unknown_search_field_falls_back_to_title_content(): void
    {
        $user = $this->createUser();
        $titleHit = $this->createTestPost(['title' => '알파 제목', 'content' => '무관한 본문']);
        $contentHit = $this->createTestPost(['title' => '무관한 제목', 'content' => '알파 본문 키워드']);

        foreach ([$titleHit, $contentHit] as $postId) {
            $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);
        }

        $expected = [$titleHit, $contentHit];
        sort($expected);

        foreach (['author', 'abc'] as $unknown) {
            $response = $this->actingAs($user)->getJson(
                '/api/modules/sirsoft-board/me/scraps?search='.urlencode('알파').'&search_field='.$unknown
            );

            $response->assertStatus(200);
            $ids = array_column($response->json('data.data'), 'post_id');
            sort($ids);
            $this->assertSame($expected, $ids, "{$unknown}: 제목·내용 매칭이 모두 잡혀야 한다");
        }
    }

    // ==========================================
    // 스크랩 중지 게시판 (use_scrap / is_active)
    // ==========================================

    /**
     * use_scrap=false 로 바뀐 게시판의 기존 스크랩은 목록에 남되 scrap_enabled=false 로 표시된다.
     *
     * 제보 ③ 의 처치 — 행을 숨기지 않고 화면이 「스크랩 중지」 배지 + 해제만 허용하도록 구분한다.
     *
     * @scenario board_setting=use_scrap_off
     *
     * @effects mypage_list_flags_scrap_disabled_board
     *
     * @return void 반환값 없음
     */
    public function test_index_marks_scrap_disabled_when_board_use_scrap_off(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost(['title' => '스크랩 후 중지될 글']);

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        // 스크랩을 담은 뒤 게시판이 스크랩 기능을 끈다
        $this->updateBoardSettings(['use_scrap' => false]);

        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.post_id', $postId);
        $response->assertJsonPath('data.data.0.scrap_enabled', false);
        $response->assertJsonPath('data.data.0.board_deleted', false);
    }

    /**
     * 비활성 게시판(is_active=false)의 스크랩도 같은 분기로 표시된다.
     *
     * @scenario board_setting=inactive
     *
     * @effects mypage_list_flags_inactive_board
     *
     * @return void 반환값 없음
     */
    public function test_index_marks_scrap_disabled_when_board_inactive(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost(['title' => '비활성 게시판 글']);

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        DB::table('boards')->where('id', $this->board->id)->update(['is_active' => false]);

        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.scrap_enabled', false);
    }

    /**
     * 검색에서는 스크랩이 중지된 게시판의 행을 제외한다 (R4 「검색에서는 제외」).
     *
     * @scenario action=mypage_search,board_setting=use_scrap_off
     *
     * @effects mypage_list_search_excludes_disabled_board
     *
     * @return void 반환값 없음
     */
    public function test_index_search_excludes_scrap_disabled_rows(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost(['title' => '알파 제목']);

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);
        $this->updateBoardSettings(['use_scrap' => false]);

        // 기본 목록에는 남아 있다
        $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data');

        // 검색 결과에서는 빠진다
        $response = $this->actingAs($user)->getJson(
            '/api/modules/sirsoft-board/me/scraps?search='.urlencode('알파')
        );

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data.data');
    }

    /**
     * 게시판이 하드 삭제돼 board_id 가 NULL 이 된 스크랩도 목록에 남고 board_deleted=true 로 표시된다.
     *
     * @scenario board_setting=board_deleted
     *
     * @effects mypage_list_keeps_scraps_of_deleted_board
     *
     * @return void 반환값 없음
     */
    public function test_index_keeps_scraps_with_null_board_id_and_flags_board_deleted(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        // 게시판 삭제 시 FK nullOnDelete 가 만드는 상태를 그대로 만든다
        DB::table('board_post_scraps')->where('user_id', $user->id)->update(['board_id' => null]);

        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.board_deleted', true);
        $response->assertJsonPath('data.data.0.scrap_enabled', false);
        $response->assertJsonPath('data.data.0.board_name', null);
    }

    /**
     * 스크랩이 중지된 게시판의 행도 개별 해제는 계속 가능하다 (해제만 허용).
     *
     * @scenario board_setting=use_scrap_off
     *
     * @effects mypage_single_delete_works_for_disabled_row
     *
     * @return void 반환값 없음
     */
    public function test_destroy_still_works_for_scrap_disabled_row(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);
        $scrapId = (int) DB::table('board_post_scraps')->where('user_id', $user->id)->value('id');

        $this->updateBoardSettings(['use_scrap' => false]);

        $response = $this->actingAs($user)->deleteJson("/api/modules/sirsoft-board/me/scraps/{$scrapId}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('board_post_scraps', ['id' => $scrapId]);
    }

    // ==========================================
    // 마이페이지 선택 해제 (일괄 삭제)
    // ==========================================

    /**
     * 내 스크랩만 지워지고 남의 스크랩은 건드리지 않으며, 실제 삭제 건수만 응답한다.
     *
     * @scenario action=mypage_bulk_delete
     *
     * @effects mypage_bulk_delete_removes_only_owned_rows
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_removes_only_owned_scraps_and_reports_count(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();

        $mine1 = $this->createTestPost(['title' => '내 글 1']);
        $mine2 = $this->createTestPost(['title' => '내 글 2']);
        $theirs = $this->createTestPost(['title' => '남의 글']);

        $this->actingAs($owner)->postJson($this->toggleUrl($mine1))->assertStatus(200);
        $this->actingAs($owner)->postJson($this->toggleUrl($mine2))->assertStatus(200);
        $this->actingAs($other)->postJson($this->toggleUrl($theirs))->assertStatus(200);

        $mineIds = DB::table('board_post_scraps')->where('user_id', $owner->id)->pluck('id')->all();
        $theirId = (int) DB::table('board_post_scraps')->where('user_id', $other->id)->value('id');

        $response = $this->actingAs($owner)->deleteJson(
            '/api/modules/sirsoft-board/me/scraps',
            ['ids' => array_merge($mineIds, [$theirId])]
        );

        $response->assertStatus(200);
        // 남의 ID 는 조용히 건너뛰고 건수로만 드러난다 (ID 탐색 방지)
        $response->assertJsonPath('data.deleted_count', 2);

        foreach ($mineIds as $id) {
            $this->assertDatabaseMissing('board_post_scraps', ['id' => $id]);
        }
        $this->assertDatabaseHas('board_post_scraps', ['id' => $theirId]);
    }

    /**
     * 일괄 해제는 원글마다 scrap_count 를 감소시키고 음수로 내려가지 않는다.
     *
     * @scenario action=mypage_bulk_delete
     *
     * @effects mypage_bulk_delete_decrements_scrap_count_per_post, scrap_count_never_negative
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_decrements_scrap_count_per_post(): void
    {
        $user = $this->createUser();
        $first = $this->createTestPost(['title' => '집계 1']);
        $second = $this->createTestPost(['title' => '집계 2']);

        $this->actingAs($user)->postJson($this->toggleUrl($first))->assertStatus(200);
        $this->actingAs($user)->postJson($this->toggleUrl($second))->assertStatus(200);

        $this->assertSame(1, (int) DB::table('board_posts')->where('id', $first)->value('scrap_count'));
        $this->assertSame(1, (int) DB::table('board_posts')->where('id', $second)->value('scrap_count'));

        $ids = DB::table('board_post_scraps')->where('user_id', $user->id)->pluck('id')->all();

        $this->actingAs($user)
            ->deleteJson('/api/modules/sirsoft-board/me/scraps', ['ids' => $ids])
            ->assertStatus(200);

        $this->assertSame(0, (int) DB::table('board_posts')->where('id', $first)->value('scrap_count'));
        $this->assertSame(0, (int) DB::table('board_posts')->where('id', $second)->value('scrap_count'));
    }

    /**
     * 일괄 해제는 행마다 활동 로그(scrap.unscrap)를 남긴다 — 단건 해제와 같은 훅 경로.
     *
     * @scenario action=mypage_bulk_delete
     *
     * @effects mypage_bulk_delete_writes_unscrap_log_per_row
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_writes_unscrap_activity_log_per_row(): void
    {
        $user = $this->createUser();
        $first = $this->createTestPost(['title' => '로그 1']);
        $second = $this->createTestPost(['title' => '로그 2']);

        $this->actingAs($user)->postJson($this->toggleUrl($first))->assertStatus(200);
        $this->actingAs($user)->postJson($this->toggleUrl($second))->assertStatus(200);

        $ids = DB::table('board_post_scraps')->where('user_id', $user->id)->pluck('id')->all();

        $before = (int) DB::table('activity_logs')->where('action', 'scrap.unscrap')->count();

        $this->actingAs($user)
            ->deleteJson('/api/modules/sirsoft-board/me/scraps', ['ids' => $ids])
            ->assertStatus(200);

        $after = (int) DB::table('activity_logs')->where('action', 'scrap.unscrap')->count();

        $this->assertSame($before + 2, $after);
    }

    /**
     * ids 검증 — 누락·빈 배열·상한 초과·정수 아님은 모두 422.
     *
     * @scenario action=mypage_bulk_delete
     *
     * @effects mypage_bulk_delete_validates_ids
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_validates_ids(): void
    {
        $user = $this->createUser();

        // 누락
        $this->actingAs($user)->deleteJson('/api/modules/sirsoft-board/me/scraps', [])
            ->assertStatus(422);

        // 빈 배열
        $this->actingAs($user)->deleteJson('/api/modules/sirsoft-board/me/scraps', ['ids' => []])
            ->assertStatus(422);

        // 상한(100) 초과
        $this->actingAs($user)->deleteJson(
            '/api/modules/sirsoft-board/me/scraps',
            ['ids' => range(1, 101)]
        )->assertStatus(422);

        // 정수 아님
        $this->actingAs($user)->deleteJson('/api/modules/sirsoft-board/me/scraps', ['ids' => ['abc']])
            ->assertStatus(422);
    }

    /**
     * 비로그인 일괄 해제 → 401.
     *
     * @scenario action=mypage_bulk_delete
     *
     * @effects guest_toggle_rejected_401
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_unauthenticated_401(): void
    {
        $response = $this->deleteJson('/api/modules/sirsoft-board/me/scraps', ['ids' => [1]]);

        $response->assertStatus(401);
    }

    /**
     * 스크랩 토글 URL 을 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @return string 토글 엔드포인트 URL
     */
    private function toggleUrl(int $postId): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/boards/{$slug}/posts/{$postId}/scrap/toggle";
    }
}
