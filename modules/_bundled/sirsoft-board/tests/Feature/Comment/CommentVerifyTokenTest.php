<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Comment;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 비회원 댓글 검증 토큰(verification_token) 테스트 (⑮)
 *
 * verify-password 로 발급된 토큰이 실제로 수정/삭제 요청에서 소비되어 평문 비밀번호
 * 재전송을 대체하는지 검증한다. (게시글 verification_token 과 동형)
 * - verify 로 받은 토큰으로 수정/삭제 성공
 * - 잘못된 토큰 / 만료 토큰 거부
 * - 단일 사용(1회 소비 후 재사용 거부)
 */
class CommentVerifyTokenTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'comment-verify-token';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '댓글 검증 토큰 테스트', 'en' => 'Comment Verify Token'],
            'is_active' => true,
            'use_comment' => true,
            'comment_order' => 'DESC',
            'min_comment_length' => 2,
            'max_comment_length' => 1000,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    private int $postId;

    private int $commentId;

    protected function setUp(): void
    {
        parent::setUp();

        // 비회원이 verify/수정/삭제에 접근하려면 comments.write + comments.read 권한 필요
        $this->setGuestPermissions(['posts.read', 'comments.read', 'comments.write']);

        $this->postId = $this->createTestPost([
            'title' => '댓글 대상 게시글',
            'status' => 'published',
        ]);

        // 비회원(guest) 댓글 — 비밀번호 보호
        $this->commentId = $this->createTestComment($this->postId, [
            'user_id' => null,
            'author_name' => '비회원',
            'content' => '원본 댓글 내용',
            'password' => Hash::make('pw1234'),
        ]);

        $this->resetPermissionMiddlewareCache();
    }

    /**
     * 올바른 비밀번호 확인 시 verification_token 이 발급된다.
     *
     * @return string 발급된 토큰
     */
    private function issueToken(): string
    {
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/comments/{$this->commentId}/verify-password",
            ['password' => 'pw1234']
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.verified', true);

        $token = $response->json('data.verification_token');
        $this->assertNotEmpty($token, 'verify-password 는 verification_token 을 발급해야 한다.');

        return $token;
    }

    /**
     * @scenario case=comment_token_lifecycle
     *
     * @effects comment_verify_token_single_use_lifecycle
     */
    #[Test]
    public function verify_password_issues_token(): void
    {
        $token = $this->issueToken();

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $token);
    }

    /**
     * @scenario case=comment_token_lifecycle
     *
     * @effects comment_verify_token_single_use_lifecycle
     */
    #[Test]
    public function update_succeeds_with_valid_token(): void
    {
        $token = $this->issueToken();

        $response = $this->putJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->postId}/comments/{$this->commentId}",
            ['content' => '토큰으로 수정한 댓글', 'verification_token' => $token]
        );

        $response->assertStatus(200);

        $this->assertSame(
            '토큰으로 수정한 댓글',
            DB::table('board_comments')->where('id', $this->commentId)->value('content')
        );
    }

    /**
     * @scenario case=comment_token_lifecycle
     *
     * @effects comment_verify_token_single_use_lifecycle
     */
    #[Test]
    public function delete_succeeds_with_valid_token(): void
    {
        $token = $this->issueToken();

        $response = $this->deleteJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->postId}/comments/{$this->commentId}",
            ['verification_token' => $token]
        );

        $response->assertStatus(200);

        $this->assertNotNull(
            DB::table('board_comments')->where('id', $this->commentId)->value('deleted_at'),
            '토큰 삭제 후 댓글은 소프트 삭제되어야 한다.'
        );
    }

    /**
     * @scenario case=comment_token_lifecycle
     *
     * @effects comment_verify_token_single_use_lifecycle
     */
    #[Test]
    public function update_is_rejected_with_wrong_token(): void
    {
        $this->issueToken(); // 실제 토큰은 발급하되, 요청에는 다른 값을 보낸다

        $response = $this->putJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->postId}/comments/{$this->commentId}",
            ['content' => '잘못된 토큰 수정 시도', 'verification_token' => str_repeat('x', 32)]
        );

        $response->assertStatus(403);

        $this->assertSame(
            '원본 댓글 내용',
            DB::table('board_comments')->where('id', $this->commentId)->value('content'),
            '잘못된 토큰이면 내용이 변경되면 안 된다.'
        );
    }

    /**
     * @scenario case=comment_token_lifecycle
     *
     * @effects comment_verify_token_single_use_lifecycle
     */
    #[Test]
    public function token_is_single_use(): void
    {
        $token = $this->issueToken();

        // 1회차: 성공
        $this->putJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->postId}/comments/{$this->commentId}",
            ['content' => '첫 수정', 'verification_token' => $token]
        )->assertStatus(200);

        // 2회차: 같은 토큰 재사용 → 소비되어 거부
        $this->putJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->postId}/comments/{$this->commentId}",
            ['content' => '두 번째 수정', 'verification_token' => $token]
        )->assertStatus(403);

        $this->assertSame(
            '첫 수정',
            DB::table('board_comments')->where('id', $this->commentId)->value('content'),
            '소비된 토큰의 재사용은 반영되면 안 된다.'
        );
    }

    /**
     * @scenario case=comment_token_lifecycle
     *
     * @effects comment_verify_token_single_use_lifecycle
     */
    #[Test]
    public function expired_token_is_rejected(): void
    {
        $token = $this->issueToken();

        // 토큰 TTL(기본 1시간)을 넘겨 이동 → 캐시에서 만료
        $this->travel(2)->hours();

        try {
            $this->putJson(
                "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->postId}/comments/{$this->commentId}",
                ['content' => '만료 토큰 수정 시도', 'verification_token' => $token]
            )->assertStatus(403);
        } finally {
            $this->travelBack();
        }

        $this->assertSame(
            '원본 댓글 내용',
            DB::table('board_comments')->where('id', $this->commentId)->value('content'),
            '만료 토큰이면 내용이 변경되면 안 된다.'
        );
    }
}
