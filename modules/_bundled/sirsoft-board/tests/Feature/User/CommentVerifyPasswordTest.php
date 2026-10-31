<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

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
 */
#[Group('board')]
#[Group('comment')]
#[Group('password')]
class CommentVerifyPasswordTest extends BoardTestCase
{
    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'comment-verify-pw';
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
            'name' => ['ko' => '댓글 비밀번호 검증 테스트 게시판', 'en' => 'Comment Verify Password Test Board'],
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

        // verifyPassword 라우트: permission:user,sirsoft-board.{slug}.comments.write
        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.write']);
    }

    /**
     * 올바른 비밀번호 → 200 + verification_token
     *
     * @scenario action=verify_password, entitlement=entitled
     *
     * @effects correct_password_returns_token
     *
     * @return void 반환값 없음
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
     *
     * @scenario action=verify_password, entitlement=not_entitled
     *
     * @effects wrong_password_returns_401
     *
     * @return void 반환값 없음
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
     *
     * @scenario action=verify_password, entitlement=not_entitled
     *
     * @effects member_comment_returns_400
     *
     * @return void 반환값 없음
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
     *
     * @scenario action=verify_password, entitlement=not_entitled
     *
     * @effects nonexistent_comment_returns_404
     *
     * @return void 반환값 없음
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
     *
     * @scenario action=verify_password, entitlement=not_entitled
     *
     * @effects missing_password_returns_422
     *
     * @return void 반환값 없음
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

    /**
     * 댓글 비밀번호 확인 API 주소를 만듭니다.
     *
     * @param  int  $commentId  댓글 ID
     * @return string API 주소
     */
    private function url(int $commentId): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/boards/{$slug}/comments/{$commentId}/verify-password";
    }
}
