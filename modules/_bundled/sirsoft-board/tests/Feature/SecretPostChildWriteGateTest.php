<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Exceptions\PostNotCommentableException;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Support\SecretContentGate;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 비밀글 하위 쓰기 게이트 테스트 (KVE-2026-2044 + 동형 전수).
 *
 * 읽기 경로는 이미 게이트되어 있었지만(비밀글 원문 마스킹), 하위 생성 경로는 부모
 * 게시글의 열람 권한을 재적용하지 않았다. 그래서 원문을 볼 수 없는 사용자가
 * 비밀글에 댓글·대댓글·답글을 달고 신고까지 남길 수 있었다.
 *
 * 대상 경로 6종:
 *  - C1 댓글 생성 (CommentService 최종 관문)
 *  - C2 댓글 생성 (요청 단계 규칙)
 *  - C3 대댓글 생성 (부모 게시글 비밀 여부)
 *  - C4 답글 생성 (부모 게시글 비밀 여부)
 *  - C5 게시글 신고
 *  - C6 댓글 신고
 *
 * 통과해야 하는 주체: 작성자 본인 · 게시판 관리자(manager) · 비밀글 읽기 권한자.
 *
 * @scenario secret-post-child-write-gate
 *
 * @effects secret_post_comment_blocked_for_outsider,
 *          secret_post_reply_comment_blocked_for_outsider,
 *          secret_post_reply_post_blocked_for_outsider,
 *          secret_post_report_blocked_for_outsider,
 *          secret_comment_report_blocked_for_outsider,
 *          secret_post_comment_allowed_for_author,
 *          secret_post_comment_allowed_for_manager,
 *          normal_post_comment_unaffected
 */
class SecretPostChildWriteGateTest extends BoardTestCase
{
    private User $outsider;

    private User $author;

    private User $manager;

    /**
     * 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'secret-child-write';
    }

    /**
     * 기본 게시판 속성 (비밀글 허용 + 답글/댓글/신고 활성)
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '비밀글 하위 쓰기', 'en' => 'Secret Child Write'],
            'is_active' => true,
            'secret_mode' => 'enabled',
            'use_comment' => true,
            'use_reply' => true,
            'use_report' => true,
            'max_comment_depth' => 3,
            'max_reply_depth' => 3,
            'blocked_keywords' => [],
        ];
    }

    /**
     * 테스트 사전 준비를 수행합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->outsider = User::factory()->create();
        $this->author = User::factory()->create();
        $this->manager = User::factory()->create();

        $this->grantDefaultGuestPermissions();
        $this->grantUserRolePermissions([
            'posts.read', 'posts.write', 'comments.read', 'comments.write',
        ]);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            foreach ([$this->outsider, $this->author, $this->manager] as $user) {
                $user->roles()->syncWithoutDetaching([$userRole->id]);
            }
        }

        $this->setupManagerRole();
        $this->resetPermissionMiddlewareCache();
    }

    /**
     * 게시판 관리자(manager) 역할을 만들어 manager 사용자에게 부여합니다.
     */
    private function setupManagerRole(): void
    {
        $slug = $this->board->slug;

        $permIds = [];
        foreach (['manager', 'posts.read', 'posts.write', 'comments.read', 'comments.write'] as $action) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$action}"],
                [
                    'name' => ['ko' => $action, 'en' => $action],
                    'slug' => "sirsoft-board.{$slug}.{$action}",
                    'type' => 'user',
                ]
            );
            $permIds[] = $perm->id;
        }

        $managerRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-manager"],
            ['name' => ['ko' => '게시판 관리(사용자)', 'en' => 'Board Manager']]
        );
        $managerRole->permissions()->syncWithoutDetaching($permIds);
        $this->manager->roles()->attach($managerRole->id);
    }

    /**
     * 비밀 게시글을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return int 생성된 게시글 ID
     */
    private function createSecretPost(array $attributes = []): int
    {
        return $this->createTestPost(array_merge([
            'user_id' => $this->author->id,
            'author_name' => '작성자',
            'title' => '비밀 게시글',
            'content' => '비밀 원문입니다.',
            'is_secret' => true,
            'status' => 'published',
        ], $attributes));
    }

    private function commentsUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments";
    }

    private function postsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts";
    }

    private function postReportUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports";
    }

    private function commentReportUrl(int $commentId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/comments/{$commentId}/reports";
    }

    /**
     * 지정 사용자로 요청 컨텍스트를 준비합니다.
     */
    private function asUser(User $user): self
    {
        $this->resetPermissionMiddlewareCache();
        $this->actingAs($user, 'sanctum');

        return $this;
    }

    /**
     * board_comments 총 건수를 반환합니다.
     */
    private function commentCount(): int
    {
        return (int) DB::table('board_comments')->where('board_id', $this->board->id)->count();
    }

    /**
     * board_posts 총 건수를 반환합니다.
     */
    private function postCount(): int
    {
        return (int) DB::table('board_posts')->where('board_id', $this->board->id)->count();
    }

    /**
     * board_reports 총 건수를 반환합니다.
     */
    private function reportCount(): int
    {
        return (int) DB::table('boards_reports')->where('board_id', $this->board->id)->count();
    }

    // =========================================================================
    // 차단 매트릭스 (무권한 사용자)
    // =========================================================================

    /**
     * C1/C2 — 무권한 사용자는 비밀글에 댓글을 달 수 없다.
     *
     * @scenario actor=outsider, target=secret_post
     *
     * @effects secret_post_comment_blocked_for_outsider
     */
    #[Test]
    public function outsider_cannot_comment_on_secret_post(): void
    {
        $postId = $this->createSecretPost();
        $before = $this->commentCount();

        $response = $this->asUser($this->outsider)->postJson($this->commentsUrl($postId), [
            'content' => '무권한 댓글',
        ]);

        $this->assertContains($response->status(), [403, 422], '비밀글 댓글 작성은 차단되어야 한다');
        $this->assertSame($before, $this->commentCount(), '차단된 요청은 댓글을 삽입하지 않아야 한다');
    }

    /**
     * C3 — 무권한 사용자는 비밀글의 댓글에 대댓글을 달 수 없다.
     *
     * @scenario actor=outsider, target=secret_post_comment
     *
     * @effects secret_post_reply_comment_blocked_for_outsider
     */
    #[Test]
    public function outsider_cannot_reply_to_comment_on_secret_post(): void
    {
        $postId = $this->createSecretPost();
        $parentCommentId = $this->createTestComment($postId, [
            'user_id' => $this->author->id,
            'content' => '작성자 댓글',
        ]);
        $before = $this->commentCount();

        $response = $this->asUser($this->outsider)->postJson($this->commentsUrl($postId), [
            'content' => '무권한 대댓글',
            'parent_id' => $parentCommentId,
        ]);

        $this->assertContains($response->status(), [403, 422], '비밀글 대댓글 작성은 차단되어야 한다');
        $this->assertSame($before, $this->commentCount());
    }

    /**
     * C4 — 무권한 사용자는 비밀글에 답글(답변 게시글)을 달 수 없다.
     *
     * @scenario actor=outsider, target=secret_post
     *
     * @effects secret_post_reply_post_blocked_for_outsider
     */
    #[Test]
    public function outsider_cannot_write_reply_post_under_secret_post(): void
    {
        $postId = $this->createSecretPost();
        $before = $this->postCount();

        $response = $this->asUser($this->outsider)->postJson($this->postsUrl(), [
            'title' => '무권한 답글',
            'content' => '무권한 답글 내용입니다. 본문 최소 길이 검증을 넘기기 위한 충분한 길이입니다.',
            'parent_id' => $postId,
        ]);

        $this->assertContains($response->status(), [403, 422], '비밀글 답글 작성은 차단되어야 한다');
        $this->assertSame($before, $this->postCount(), '차단된 요청은 게시글을 삽입하지 않아야 한다');
    }

    /**
     * C5 — 무권한 사용자는 비밀글을 신고할 수 없다.
     *
     * @scenario actor=outsider, target=secret_post
     *
     * @effects secret_post_report_blocked_for_outsider
     */
    #[Test]
    public function outsider_cannot_report_secret_post(): void
    {
        $postId = $this->createSecretPost();
        $before = $this->reportCount();

        $response = $this->asUser($this->outsider)->postJson($this->postReportUrl($postId), [
            'reason_type' => 'spam',
            'reason_detail' => '무권한 신고',
        ]);

        $this->assertContains($response->status(), [403, 422], '비밀글 신고는 차단되어야 한다');
        $this->assertSame($before, $this->reportCount(), '차단된 요청은 신고를 남기지 않아야 한다');
    }

    /**
     * C6 — 무권한 사용자는 비밀글에 달린 댓글을 신고할 수 없다.
     *
     * @scenario actor=outsider, target=secret_post_comment
     *
     * @effects secret_comment_report_blocked_for_outsider
     */
    #[Test]
    public function outsider_cannot_report_comment_on_secret_post(): void
    {
        $postId = $this->createSecretPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => $this->author->id,
            'content' => '작성자 댓글',
        ]);
        $before = $this->reportCount();

        $response = $this->asUser($this->outsider)->postJson($this->commentReportUrl($commentId), [
            'reason_type' => 'spam',
            'reason_detail' => '무권한 신고',
        ]);

        $this->assertContains($response->status(), [403, 422], '비밀글 댓글 신고는 차단되어야 한다');
        $this->assertSame($before, $this->reportCount());
    }

    /**
     * 요청 계층을 우회해도 서비스 최종 관문이 막는다.
     *
     * HTTP 경로에서는 요청 단계 규칙이 먼저 걸리므로 서비스 게이트는 **한 번도 실행되지
     * 않는다**. 그래서 그 게이트가 살아 있는지는 HTTP 테스트로 증명되지 않는다.
     * 훅·확장·콘솔처럼 FormRequest 를 지나지 않는 호출자가 실재하므로, 서비스를 직접
     * 불러 최종 관문을 따로 고정한다 (이중 방어의 두 층을 각각 잠근다).
     *
     * @scenario actor=outsider, target=secret_post, layer=service
     *
     * @effects secret_post_comment_blocked_for_outsider
     */
    #[Test]
    public function the_service_layer_gate_blocks_even_when_the_request_layer_is_bypassed(): void
    {
        $postId = $this->createSecretPost();
        $before = $this->commentCount();

        $this->resetPermissionMiddlewareCache();
        $this->actingAs($this->outsider, 'sanctum');

        $service = app(CommentService::class);

        $threw = false;
        try {
            $service->createComment($this->board->slug, [
                'post_id' => $postId,
                'content' => '요청 계층 우회 댓글',
                'user_id' => $this->outsider->id,
            ]);
        } catch (PostNotCommentableException $e) {
            $threw = true;
            $this->assertSame('sirsoft-board::messages.comment.post_secret', $e->getMessageKey());
        }

        $this->assertTrue($threw, '서비스 최종 관문이 비밀글 하위 쓰기를 막지 않았다');
        $this->assertSame($before, $this->commentCount());
    }

    /**
     * 서비스 최종 관문은 작성자에게는 열려 있다 (정상 흐름 불변).
     *
     * @scenario actor=author, target=secret_post, layer=service
     *
     * @effects secret_post_comment_allowed_for_author
     */
    #[Test]
    public function the_service_layer_gate_allows_the_author(): void
    {
        $postId = $this->createSecretPost();
        $before = $this->commentCount();

        $this->resetPermissionMiddlewareCache();
        $this->actingAs($this->author, 'sanctum');

        app(CommentService::class)->createComment($this->board->slug, [
            'post_id' => $postId,
            'content' => '작성자 댓글 (서비스 직접 호출)',
            'user_id' => $this->author->id,
        ]);

        $this->assertSame($before + 1, $this->commentCount());
    }

    // =========================================================================
    // 통과 매트릭스 (정상 흐름 불변)
    // =========================================================================

    /**
     * 작성자 본인은 자기 비밀글에 댓글을 달 수 있다.
     *
     * @scenario actor=author, target=secret_post
     *
     * @effects secret_post_comment_allowed_for_author
     */
    #[Test]
    public function author_can_comment_on_own_secret_post(): void
    {
        $postId = $this->createSecretPost();
        $before = $this->commentCount();

        $response = $this->asUser($this->author)->postJson($this->commentsUrl($postId), [
            'content' => '작성자 댓글',
        ]);

        $response->assertStatus(201);
        $this->assertSame($before + 1, $this->commentCount());
    }

    /**
     * 게시판 관리자는 비밀글에 댓글을 달 수 있다 (답변 시나리오).
     *
     * @scenario actor=manager, target=secret_post
     *
     * @effects secret_post_comment_allowed_for_manager
     */
    #[Test]
    public function manager_can_comment_on_secret_post(): void
    {
        $postId = $this->createSecretPost();
        $before = $this->commentCount();

        $response = $this->asUser($this->manager)->postJson($this->commentsUrl($postId), [
            'content' => '관리자 답변',
        ]);

        $response->assertStatus(201);
        $this->assertSame($before + 1, $this->commentCount());
    }

    /**
     * 비밀글이 아니면 종전과 같이 누구나 댓글을 달 수 있다 (회귀 방지).
     *
     * @scenario actor=outsider, target=public_post
     *
     * @effects normal_post_comment_unaffected
     */
    #[Test]
    public function outsider_can_still_comment_on_public_post(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->author->id,
            'title' => '공개 게시글',
            'content' => '공개 내용',
            'is_secret' => false,
            'status' => 'published',
        ]);
        $before = $this->commentCount();

        $response = $this->asUser($this->outsider)->postJson($this->commentsUrl($postId), [
            'content' => '일반 댓글',
        ]);

        $response->assertStatus(201);
        $this->assertSame($before + 1, $this->commentCount());
    }

    /**
     * 작성자 본인은 자기 비밀글에 답글을 달 수 있다 (정상 흐름 불변).
     *
     * @scenario actor=author, target=secret_post
     *
     * @effects secret_post_comment_allowed_for_author
     */
    #[Test]
    public function author_can_write_reply_post_under_own_secret_post(): void
    {
        $postId = $this->createSecretPost();
        $before = $this->postCount();

        $response = $this->asUser($this->author)->postJson($this->postsUrl(), [
            'title' => '작성자 답글',
            'content' => '작성자 답글 내용입니다. 본문 최소 길이 검증을 넘기기 위한 충분한 길이입니다.',
            'parent_id' => $postId,
        ]);

        $response->assertStatus(201);
        $this->assertSame($before + 1, $this->postCount());
    }

    /**
     * 비밀글이 아니면 종전과 같이 답글을 달 수 있다 (회귀 방지).
     *
     * @scenario actor=outsider, target=public_post
     *
     * @effects normal_post_comment_unaffected
     */
    #[Test]
    public function outsider_can_still_write_reply_post_under_public_post(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->author->id,
            'title' => '공개 게시글',
            'content' => '공개 내용',
            'is_secret' => false,
            'status' => 'published',
        ]);
        $before = $this->postCount();

        $response = $this->asUser($this->outsider)->postJson($this->postsUrl(), [
            'title' => '일반 답글',
            'content' => '일반 답글 내용입니다. 본문 최소 길이 검증을 넘기기 위한 충분한 길이입니다.',
            'parent_id' => $postId,
        ]);

        $response->assertStatus(201);
        $this->assertSame($before + 1, $this->postCount());
    }

    /**
     * 비밀글이 아니면 종전과 같이 신고할 수 있다 (회귀 방지).
     *
     * @scenario actor=outsider, target=public_post
     *
     * @effects normal_post_comment_unaffected
     */
    #[Test]
    public function outsider_can_still_report_public_post(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->author->id,
            'title' => '공개 게시글',
            'content' => '공개 내용',
            'is_secret' => false,
            'status' => 'published',
        ]);
        $before = $this->reportCount();

        $response = $this->asUser($this->outsider)->postJson($this->postReportUrl($postId), [
            'reason_type' => 'spam',
            'reason_detail' => '정상 신고',
        ]);

        $response->assertStatus(201);
        $this->assertSame($before + 1, $this->reportCount());
    }

    /**
     * 비밀번호 검증 응답은 후속 요청에 쓸 열람 확인 토큰을 함께 돌려준다.
     *
     * @scenario actor=password_holder, target=secret_post
     *
     * @effects secret_view_token_issued_on_password_verify
     */
    #[Test]
    public function password_verification_issues_a_view_token(): void
    {
        $postId = $this->createSecretPost([
            'user_id' => null,
            'password' => bcrypt('guestPw123'),
        ]);

        $response = $this->postJson($this->verifyPasswordUrl($postId), ['password' => 'guestPw123']);

        $response->assertStatus(200);
        $this->assertIsString(
            $response->json('secret_view_token'),
            '비밀번호 검증 응답에 열람 확인 토큰이 없습니다 — 후속 요청이 열람 사실을 증명할 수단이 사라집니다.'
        );
    }

    /**
     * 비밀번호로 원문을 연 사람은 댓글을 달 수 있다.
     *
     * 비회원이 쓴 비밀글의 작성자 본인은 user_id 가 없어 작성자 판정에 걸리지 않는다.
     * 그에게 유일한 신원 증명이 비밀번호이므로, 이 경로가 막히면 자기 글에 댓글을
     * 달 수 없다 — 화면은 원문이 열린 뒤 댓글창을 내주므로 사용자에게는 원인이 보이지 않는다.
     *
     * @scenario actor=password_holder, target=secret_post
     *
     * @effects secret_post_comment_allowed_for_password_holder
     */
    #[Test]
    public function password_holder_can_comment_on_secret_post(): void
    {
        $postId = $this->createSecretPost([
            'user_id' => null,
            'password' => bcrypt('guestPw123'),
        ]);
        $token = $this->issueViewToken($postId, 'guestPw123');
        $before = $this->commentCount();

        $response = $this->asUser($this->outsider)
            ->withHeader(SecretContentGate::VIEW_TOKEN_HEADER, $token)
            ->postJson($this->commentsUrl($postId), [
                'content' => '비밀번호로 열고 남기는 댓글입니다.',
            ]);

        $response->assertStatus(201);
        $this->assertSame($before + 1, $this->commentCount());
    }

    /**
     * 비밀번호로 연 사람은 답글도 쓸 수 있다.
     *
     * @scenario actor=password_holder, target=secret_post
     *
     * @effects secret_post_reply_allowed_for_password_holder
     */
    #[Test]
    public function password_holder_can_write_reply_post_under_secret_post(): void
    {
        $postId = $this->createSecretPost([
            'user_id' => null,
            'password' => bcrypt('guestPw123'),
        ]);
        $token = $this->issueViewToken($postId, 'guestPw123');
        $before = $this->postCount();

        $response = $this->asUser($this->outsider)
            ->withHeader(SecretContentGate::VIEW_TOKEN_HEADER, $token)
            ->postJson($this->postsUrl(), [
                'title' => '답글 제목',
                'content' => '비밀번호로 열고 남기는 답글 본문입니다.',
                'parent_id' => $postId,
            ]);

        $response->assertStatus(201);
        $this->assertSame($before + 1, $this->postCount());
    }

    /**
     * 토큰은 발급받은 게시글에만 통한다 — 다른 비밀글에는 쓰이지 않는다.
     *
     * 이 결속이 없으면 비밀번호를 아는 글 하나로 같은 게시판의 모든 비밀글이 열려,
     * KVE-2026-2044 가 토큰이라는 다른 이름으로 되살아난다.
     *
     * @scenario actor=password_holder, target=other_secret_post
     *
     * @effects secret_view_token_is_bound_to_its_post
     */
    #[Test]
    public function view_token_does_not_work_on_another_post(): void
    {
        $openedPostId = $this->createSecretPost([
            'user_id' => null,
            'password' => bcrypt('guestPw123'),
        ]);
        $otherPostId = $this->createSecretPost([
            'user_id' => $this->author->id,
            'title' => '남의 비밀글',
        ]);
        $token = $this->issueViewToken($openedPostId, 'guestPw123');
        $before = $this->commentCount();

        $response = $this->asUser($this->outsider)
            ->withHeader(SecretContentGate::VIEW_TOKEN_HEADER, $token)
            ->postJson($this->commentsUrl($otherPostId), [
                'content' => '남의 비밀글에 다는 댓글입니다.',
            ]);

        $response->assertStatus(422);
        $this->assertSame($before, $this->commentCount(), '다른 글의 토큰으로 댓글이 저장되었습니다.');
    }

    /**
     * 위조·만료 토큰은 통하지 않는다.
     *
     * @scenario actor=outsider, target=secret_post
     *
     * @effects secret_view_token_rejects_forged_value
     */
    #[Test]
    public function forged_view_token_is_rejected(): void
    {
        $postId = $this->createSecretPost([
            'user_id' => null,
            'password' => bcrypt('guestPw123'),
        ]);
        $before = $this->commentCount();

        $response = $this->asUser($this->outsider)
            ->withHeader(SecretContentGate::VIEW_TOKEN_HEADER, str_repeat('a', 40))
            ->postJson($this->commentsUrl($postId), [
                'content' => '위조 토큰으로 다는 댓글입니다.',
            ]);

        $response->assertStatus(422);
        $this->assertSame($before, $this->commentCount());
    }

    private function verifyPasswordUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password";
    }

    /**
     * 비밀번호를 검증해 열람 확인 토큰을 발급받습니다 (실제 엔드포인트 경유).
     */
    private function issueViewToken(int $postId, string $password): string
    {
        $response = $this->postJson($this->verifyPasswordUrl($postId), ['password' => $password]);
        $response->assertStatus(200);

        return (string) $response->json('secret_view_token');
    }
}
