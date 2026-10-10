<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Support\SecretContentGate;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 비밀 댓글 본문 게이트 테스트 (KVE-2026-2371)
 *
 * 게이트가 게시글에만 걸려 있어, 공개 게시글에 달린 비밀 댓글은 댓글 목록·게시글 상세 어느
 * 경로로도 원문이 그대로 나가던 결함을 고정한다.
 *
 * 열람 허용: 댓글 작성자 · 부모 댓글 작성자(대댓글) · 부모 게시글을 열 수 있는 사람
 * (글 작성자 · 열람 토큰 보유자 · 비밀글 읽기 권한자 · 게시판 매니저).
 * 그 밖의 회원·비회원은 content=null + is_secret=true 를 받는다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-secret-content-bypass-paths.yaml 참조.
 */
class SecretCommentContentGateTest extends BoardTestCase
{
    private User $postAuthor;

    private User $commentAuthor;

    private User $parentCommentAuthor;

    private User $outsider;

    private User $manager;

    private User $readSecretUser;

    private int $postId;

    private int $rootCommentId;

    private int $secretReplyId;

    private int $secretRootId;

    /**
     * 이 테스트 전용 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'secret-comment-gate';
    }

    /**
     * 게시판 권한·회원·게시글을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'comments.read']);
        $this->grantUserRolePermissions(['posts.read', 'comments.read', 'comments.write']);

        $userRole = Role::where('identifier', 'user')->first();
        $this->postAuthor = $this->member($userRole);
        $this->commentAuthor = $this->member($userRole);
        $this->parentCommentAuthor = $this->member($userRole);
        $this->outsider = $this->member($userRole);
        $this->manager = $this->member($userRole, ['manager']);
        $this->readSecretUser = $this->member($userRole, ['posts.read-secret']);

        $this->postId = $this->createTestPost([
            'title' => '공개 글',
            'user_id' => $this->postAuthor->id,
            'author_name' => 'post-author',
        ]);
        $this->rootCommentId = $this->createTestComment($this->postId, [
            'content' => 'PUBLIC-ROOT',
            'user_id' => $this->parentCommentAuthor->id,
        ]);
        $this->secretReplyId = $this->createTestComment($this->postId, [
            'content' => 'SECRET-REPLY',
            'user_id' => $this->commentAuthor->id,
            'parent_id' => $this->rootCommentId,
            'depth' => 1,
            'is_secret' => true,
        ]);
        $this->secretRootId = $this->createTestComment($this->postId, [
            'content' => 'SECRET-ROOT',
            'user_id' => $this->commentAuthor->id,
            'is_secret' => true,
        ]);
    }

    /**
     * 게시판 user 역할 회원을 만들고, 필요하면 개인 역할로 게시판 권한을 더 붙인다.
     *
     * @param  Role|null  $userRole  공통 user 역할
     * @param  array<int, string>  $extraKeys  추가 게시판 권한 키
     * @return User 회원
     */
    private function member(?Role $userRole, array $extraKeys = []): User
    {
        $user = User::factory()->create();
        if ($userRole) {
            $user->roles()->attach($userRole->id);
        }

        if ($extraKeys !== []) {
            $role = Role::create([
                'identifier' => 'scg-'.uniqid(),
                'name' => ['ko' => '추가 권한', 'en' => 'Extra'],
            ]);
            foreach ($extraKeys as $key) {
                $perm = Permission::firstOrCreate(
                    ['identifier' => "sirsoft-board.{$this->board->slug}.{$key}"],
                    ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
                );
                $role->permissions()->syncWithoutDetaching([$perm->id]);
            }
            $user->roles()->attach($role->id);
        }

        return $user;
    }

    /**
     * 댓글 목록 응답을 id → [is_secret, content] 로 접는다.
     *
     * @param  User|null  $viewer  열람자 (null 이면 비회원)
     * @param  array<string, string>  $headers  추가 헤더
     * @return array<int, array{is_secret: bool, content: string|null}> 댓글 id 별 비밀 여부·본문
     */
    private function commentsFor(?User $viewer, array $headers = []): array
    {
        $request = $viewer ? $this->actingAs($viewer, 'sanctum') : $this;
        $response = $request->withHeaders($headers)
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->postId}/comments");
        $response->assertOk();

        $byId = [];
        foreach ($response->json('data') as $row) {
            $byId[$row['id']] = ['is_secret' => (bool) $row['is_secret'], 'content' => $row['content']];
        }

        return $byId;
    }

    /**
     * 무관한 회원은 비밀 댓글 원문 대신 null 을 받고 공개 댓글은 그대로 본다.
     *
     * @scenario viewer=outsider
     *
     * @effects outsider_gets_null_secret_comment_content
     *
     * @return void 반환값 없음
     */
    public function test_outsider_gets_null_for_secret_comments_but_sees_public(): void
    {
        $rows = $this->commentsFor($this->outsider);

        $this->assertSame('PUBLIC-ROOT', $rows[$this->rootCommentId]['content']);
        $this->assertNull($rows[$this->secretReplyId]['content']);
        $this->assertTrue($rows[$this->secretReplyId]['is_secret'], '마스킹해도 is_secret 플래그는 남아야 화면이 안내한다');
        $this->assertNull($rows[$this->secretRootId]['content']);
    }

    /**
     * 비회원은 비밀 댓글 원문 대신 null 을 받는다.
     *
     * @scenario viewer=guest
     *
     * @effects guest_gets_null_secret_comment_content
     *
     * @return void 반환값 없음
     */
    public function test_guest_gets_null_for_secret_comments(): void
    {
        $rows = $this->commentsFor(null);

        $this->assertNull($rows[$this->secretReplyId]['content']);
        $this->assertNull($rows[$this->secretRootId]['content']);
        $this->assertSame('PUBLIC-ROOT', $rows[$this->rootCommentId]['content']);
    }

    /**
     * 댓글 작성자는 자기 비밀 댓글 원문을 본다.
     *
     * @scenario viewer=comment_author
     *
     * @effects comment_author_sees_own_secret_comment
     *
     * @return void 반환값 없음
     */
    public function test_comment_author_sees_own_secret_comments(): void
    {
        $rows = $this->commentsFor($this->commentAuthor);

        $this->assertSame('SECRET-REPLY', $rows[$this->secretReplyId]['content']);
        $this->assertSame('SECRET-ROOT', $rows[$this->secretRootId]['content']);
    }

    /**
     * 게시글 작성자는 자기 글에 달린 비밀 댓글 원문을 본다.
     *
     * @scenario viewer=post_author
     *
     * @effects post_author_sees_secret_comments_on_own_post
     *
     * @return void 반환값 없음
     */
    public function test_post_author_sees_secret_comments_on_own_post(): void
    {
        $rows = $this->commentsFor($this->postAuthor);

        $this->assertSame('SECRET-REPLY', $rows[$this->secretReplyId]['content']);
        $this->assertSame('SECRET-ROOT', $rows[$this->secretRootId]['content']);
    }

    /**
     * 부모 댓글 작성자는 자기 댓글에 달린 비밀 대댓글만 본다 — 같은 글의 다른 비밀 댓글은 못 본다.
     *
     * @scenario viewer=parent_comment_author
     *
     * @effects parent_comment_author_sees_secret_reply_only
     *
     * @return void 반환값 없음
     */
    public function test_parent_comment_author_sees_secret_reply_but_not_other_secret_comment(): void
    {
        $rows = $this->commentsFor($this->parentCommentAuthor);

        $this->assertSame('SECRET-REPLY', $rows[$this->secretReplyId]['content']);
        $this->assertNull($rows[$this->secretRootId]['content']);
    }

    /**
     * 게시판 매니저는 비밀 댓글 원문을 본다.
     *
     * @scenario viewer=manager
     *
     * @effects manager_sees_secret_comments
     *
     * @return void 반환값 없음
     */
    public function test_board_manager_sees_secret_comments(): void
    {
        $rows = $this->commentsFor($this->manager);

        $this->assertSame('SECRET-REPLY', $rows[$this->secretReplyId]['content']);
        $this->assertSame('SECRET-ROOT', $rows[$this->secretRootId]['content']);
    }

    /**
     * 비밀글 읽기 권한자는 비밀 댓글 원문을 본다.
     *
     * @scenario viewer=read_secret
     *
     * @effects read_secret_holder_sees_secret_comments
     *
     * @return void 반환값 없음
     */
    public function test_read_secret_holder_sees_secret_comments(): void
    {
        $rows = $this->commentsFor($this->readSecretUser);

        $this->assertSame('SECRET-ROOT', $rows[$this->secretRootId]['content']);
    }

    /**
     * 게시글 상세 응답에 함께 실리는 댓글도 같은 게이트를 탄다.
     *
     * @scenario viewer=outsider
     *
     * @effects post_detail_comments_masked_for_outsider
     *
     * @return void 반환값 없음
     */
    public function test_post_detail_embedded_comments_are_masked_for_outsider(): void
    {
        $response = $this->actingAs($this->outsider, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->postId}");
        $response->assertOk();

        $byId = collect($response->json('data.comments'))->keyBy('id');
        $this->assertNull($byId[$this->secretReplyId]['content']);
        $this->assertNull($byId[$this->secretRootId]['content']);
        $this->assertSame('PUBLIC-ROOT', $byId[$this->rootCommentId]['content']);
    }

    /**
     * 비회원 비밀글 작성자는 비밀번호를 맞혀 받은 열람 토큰으로 자기 글의 비밀 댓글을 본다.
     *
     * @scenario viewer=view_token_holder
     *
     * @effects view_token_holder_sees_secret_comment
     *
     * @return void 반환값 없음
     */
    public function test_view_token_holder_sees_secret_comment_on_guest_secret_post(): void
    {
        $guestPostId = $this->createTestPost([
            'title' => '비회원 비밀글',
            'is_secret' => true,
            'password' => bcrypt('guestpw'),
        ]);
        $commentId = $this->createTestComment($guestPostId, [
            'content' => 'SECRET-ON-GUEST-POST',
            'user_id' => $this->manager->id,
            'is_secret' => true,
        ]);
        $token = app(PostService::class)->issueSecretViewToken($this->board->slug, $guestPostId)['token'];

        $url = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$guestPostId}/comments";

        $withToken = $this->withHeaders([SecretContentGate::VIEW_TOKEN_HEADER => $token])->getJson($url);
        $withToken->assertOk();
        $this->assertSame('SECRET-ON-GUEST-POST', collect($withToken->json('data'))->firstWhere('id', $commentId)['content']);

        $this->flushHeaders();
        $withoutToken = $this->getJson($url);
        $withoutToken->assertOk();
        $this->assertCount(0, $withoutToken->json('data'), '토큰이 없으면 비밀글 댓글 목록은 1차 게이트에서 빈 목록');
    }

    /**
     * 목록 경로는 부모 댓글 작성자를 미리 실어 주므로 게이트가 댓글마다 조회하지 않는다.
     *
     * @effects parent_comment_owner_preloaded_without_query
     *
     * @return void 반환값 없음
     */
    public function test_gate_uses_preloaded_parent_comment_owner_without_query(): void
    {
        $comments = Comment::whereIn('id', [$this->rootCommentId, $this->secretReplyId])->get();
        SecretContentGate::preloadParentCommentOwners($comments);
        $reply = $comments->firstWhere('id', $this->secretReplyId);
        $post = Post::find($this->postId);

        $this->actingAs($this->parentCommentAuthor, 'sanctum');

        DB::enableQueryLog();
        $allowed = app(SecretContentGate::class)->canViewSecretComment($reply, $post);
        $commentQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'board_comments'))
            ->count();
        DB::disableQueryLog();

        $this->assertTrue($allowed);
        $this->assertSame(0, $commentQueries, '미리 실은 부모 댓글 작성자를 두고 다시 조회하면 안 된다');
    }

    /**
     * 부모 게시글을 해석할 수 없으면(관계 미로딩 + 미전달) 가린다 — lazy-load 대신 fail-closed.
     *
     * @effects gate_fails_closed_without_parent_post
     *
     * @return void 반환값 없음
     */
    public function test_gate_fails_closed_when_parent_post_unresolved(): void
    {
        $comment = Comment::find($this->secretRootId);

        $this->actingAs($this->postAuthor, 'sanctum');

        $this->assertFalse(app(SecretContentGate::class)->canViewSecretComment($comment));
    }
}
