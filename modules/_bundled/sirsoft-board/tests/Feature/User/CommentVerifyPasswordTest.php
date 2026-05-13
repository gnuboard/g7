<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 비회원 댓글 비밀번호 검증 엔드포인트 테스트
 *
 * 검증 목적:
 * - 올바른 비밀번호 → 200 + verification_token 반환
 * - 잘못된 비밀번호 → 401
 * - 회원 댓글(user_id 있음)에 verifyPassword 호출 → 400
 * - 존재하지 않는 댓글 → 404
 * - 비밀번호 필드 누락 → 422
 *
 * URL: POST /api/modules/sirsoft-board/boards/{slug}/comments/{commentId}/verify-password
 *
 * @group board
 * @group comment
 * @group password
 */
class CommentVerifyPasswordTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'comment-verify-pw';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '댓글 비밀번호 검증 테스트 게시판', 'en' => 'Comment Verify Password Test Board'],
            'is_active' => true,
            'use_comment' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // verifyPassword 라우트: permission:user,sirsoft-board.{slug}.comments.write
        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.write']);
    }

    /**
     * 올바른 비밀번호 → 200 + verification_token
     */
    public function test_correct_password_returns_token(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => null,
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson(
            $this->url($commentId),
            ['password' => 'secret123']
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.verified', true);
        $response->assertJsonStructure(['data' => ['verified', 'comment_id', 'verification_token', 'expires_at']]);
    }

    /**
     * 잘못된 비밀번호 → 401
     */
    public function test_wrong_password_returns_401(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => null,
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson(
            $this->url($commentId),
            ['password' => 'wrongpassword']
        )->assertStatus(401);
    }

    /**
     * 회원 댓글(user_id 있음)에 verifyPassword 호출 → 400
     */
    public function test_member_comment_returns_400(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => $user->id,
            'password' => null,
        ]);

        $this->postJson(
            $this->url($commentId),
            ['password' => 'anypassword']
        )->assertStatus(400);
    }

    /**
     * 존재하지 않는 댓글 → 404
     */
    public function test_nonexistent_comment_returns_404(): void
    {
        $this->postJson(
            $this->url(99999),
            ['password' => 'anypassword']
        )->assertStatus(404);
    }

    /**
     * password 필드 누락 → 422
     */
    public function test_missing_password_returns_422(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => null]);

        $this->postJson(
            $this->url($commentId),
            []
        )->assertStatus(422);
    }

    // ==========================================
    // Helper
    // ==========================================

    private function url(int $commentId): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/boards/{$slug}/comments/{$commentId}/verify-password";
    }
}
