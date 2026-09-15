<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * FormRequest 검증 규칙 테스트
 *
 * 검증 목적:
 * StorePostRequest:
 * - title 누락 → 422
 * - content 누락 → 422
 * - title 최소 길이 미만 → 422
 * - content_mode 유효하지 않은 값 → 422
 * - 정상 데이터 → 201
 *
 * StoreCommentRequest:
 * - content 누락 → 422
 * - post_id 누락 → 422
 * - 비회원이 author_name 누락 → 422
 * - 비회원이 password 누락 → 422
 * - 비회원 정상 데이터 → 201
 *
 * StoreReportRequest:
 * - reason_type 누락 → 422
 * - reason_detail 누락 → 422
 * - reason_type 유효하지 않은 값 → 422
 * - 정상 데이터 → 201
 *
 * UpdatePostRequest:
 * - title 최소 길이 미만 → 422
 * - password 배열 주입 → 422
 *
 * DestroyCommentRequest:
 * - password 배열 주입 → 422
 * - password 최소 길이(4) 미만 → 422
 * - 회원 본인 댓글 password 없이 삭제 → 200
 *
 * DestroyPostRequest:
 * - verification_token 배열 주입 → 422
 * - 비회원 글 올바른 password 로 삭제 → 200
 *
 * @group board
 * @group feature
 * @group formrequest
 */
class FormRequestValidationTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'form-request-validation';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => 'FormRequest 검증 테스트 게시판', 'en' => 'FormRequest Validation Test Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
            'use_comment' => true,
            'use_report' => true,
            'min_title_length' => 2,
            'max_title_length' => 200,
            'min_content_length' => 5,
            'max_content_length' => 10000,
            'min_comment_length' => 2,
            'max_comment_length' => 1000,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.write', 'attachments.upload']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.write', 'attachments.upload']);
    }

    private function postUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts";
    }

    private function commentUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments";
    }

    private function reportUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports";
    }

    // ==========================================
    // StorePostRequest
    // ==========================================

    /**
     * title 누락 → 422
     */
    public function test_store_post_fails_without_title(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->postJson($this->postUrl(), [
            'content' => '정상 내용입니다.',
        ])->assertStatus(422);
    }

    /**
     * content 누락 → 422
     */
    public function test_store_post_fails_without_content(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->postJson($this->postUrl(), [
            'title' => '정상 제목',
        ])->assertStatus(422);
    }

    /**
     * title 최소 길이 미만 (1자) → 422
     */
    public function test_store_post_fails_with_title_too_short(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->postJson($this->postUrl(), [
            'title' => '가', // min_title_length=2 미만
            'content' => '정상적인 내용입니다.',
        ])->assertStatus(422);
    }

    /**
     * content_mode 유효하지 않은 값 → 422
     */
    public function test_store_post_fails_with_invalid_content_mode(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->postJson($this->postUrl(), [
            'title' => '정상 제목',
            'content' => '정상적인 내용입니다.',
            'content_mode' => 'markdown', // text|html 만 허용
        ])->assertStatus(422);
    }

    /**
     * 정상 데이터 → 201
     */
    public function test_store_post_succeeds_with_valid_data(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->postJson($this->postUrl(), [
            'title' => '정상 게시글 제목',
            'content' => '정상적인 게시글 내용입니다.',
        ])->assertStatus(201);
    }

    // ==========================================
    // StoreCommentRequest
    // ==========================================

    /**
     * content 누락 → 422
     */
    public function test_store_comment_fails_without_content(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->commentUrl($postId), [
            'post_id' => $postId,
        ])->assertStatus(422);
    }

    /**
     * post_id 누락 → 422
     */
    public function test_store_comment_fails_without_post_id(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->postJson($this->commentUrl(1), [
            'content' => '댓글 내용입니다.',
        ])->assertStatus(422);
    }

    /**
     * 비회원이 author_name 누락 → 422
     */
    public function test_store_comment_guest_fails_without_author_name(): void
    {
        $postId = $this->createTestPost();

        $this->postJson($this->commentUrl($postId), [
            'content' => '댓글 내용입니다.',
            'post_id' => $postId,
            'password' => 'pass1234',
            // author_name 누락
        ])->assertStatus(422);
    }

    /**
     * 비회원이 password 누락 → 422
     */
    public function test_store_comment_guest_fails_without_password(): void
    {
        $postId = $this->createTestPost();

        $this->postJson($this->commentUrl($postId), [
            'content' => '댓글 내용입니다.',
            'post_id' => $postId,
            'author_name' => '비회원',
            // password 누락
        ])->assertStatus(422);
    }

    /**
     * 비회원 정상 댓글 데이터 → 201
     */
    public function test_store_comment_guest_succeeds_with_valid_data(): void
    {
        $postId = $this->createTestPost();

        $this->postJson($this->commentUrl($postId), [
            'content' => '정상적인 댓글 내용입니다.',
            'post_id' => $postId,
            'author_name' => '비회원',
            'password' => 'pass1234',
        ])->assertStatus(201);
    }

    // ==========================================
    // StoreReportRequest
    // ==========================================

    /**
     * reason_type 누락 → 422
     */
    public function test_store_report_fails_without_reason_type(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reporter)->postJson($this->reportUrl($postId), [
            'reason_detail' => '신고 상세 내용입니다.',
        ])->assertStatus(422);
    }

    /**
     * reason_detail 누락 → 422
     */
    public function test_store_report_fails_without_reason_detail(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reporter)->postJson($this->reportUrl($postId), [
            'reason_type' => 'spam',
        ])->assertStatus(422);
    }

    /**
     * reason_type 유효하지 않은 값 → 422
     */
    public function test_store_report_fails_with_invalid_reason_type(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reporter)->postJson($this->reportUrl($postId), [
            'reason_type' => 'not_a_valid_type',
            'reason_detail' => '상세 내용입니다.',
        ])->assertStatus(422);
    }

    /**
     * 정상 신고 데이터 → 201
     */
    public function test_store_report_succeeds_with_valid_data(): void
    {
        $author = $this->createUser();
        $reporter = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reporter)->postJson($this->reportUrl($postId), [
            'reason_type' => 'spam',
            'reason_detail' => '스팸 게시글입니다.',
        ])->assertStatus(201);
    }

    // ==========================================
    // UpdatePostRequest
    // ==========================================

    /**
     * title 최소 길이 미만으로 수정 → 422
     */
    public function test_update_post_fails_with_title_too_short(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $user->id]);

        $this->actingAs($user)->putJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}",
            ['title' => '가'] // min_title_length=2 미만
        )->assertStatus(422);
    }

    /**
     * 게시글 수정에 password 배열 주입 → 422 (string 규칙 차단)
     */
    public function test_post_update_rejects_array_password(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $user->id]);

        $this->actingAs($user)->putJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}",
            [
                'title' => '정상 제목',
                'password' => ['x'], // 배열 주입
            ]
        )->assertStatus(422);
    }

    // ==========================================
    // DestroyCommentRequest
    // ==========================================

    /**
     * 댓글 삭제에 password 배열 주입 → 422 (string 규칙 차단)
     */
    public function test_comment_destroy_rejects_array_password(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'password' => Hash::make('pass1234'),
        ]);

        $this->deleteJson(
            $this->commentUrl($postId)."/{$commentId}",
            ['password' => ['x']] // 배열 주입
        )->assertStatus(422);
    }

    /**
     * 댓글 삭제에 최소 길이(4) 미만 password → 422
     */
    public function test_comment_destroy_rejects_too_short_password(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'password' => Hash::make('pass1234'),
        ]);

        $this->deleteJson(
            $this->commentUrl($postId)."/{$commentId}",
            ['password' => '123'] // min:4 미만
        )->assertStatus(422);
    }

    /**
     * 회원 본인 댓글은 password 없이 삭제 가능 → 200
     * (password 는 nullable — 비회원 소유권 확인용으로만 요구)
     */
    public function test_member_comment_destroy_without_password_succeeds(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'user_id' => $user->id,
            'author_name' => $user->name,
        ]);

        $this->actingAs($user)
            ->deleteJson($this->commentUrl($postId)."/{$commentId}")
            ->assertStatus(200);
    }

    // ==========================================
    // DestroyPostRequest
    // ==========================================

    /**
     * 게시글 삭제에 verification_token 배열 주입 → 422 (string 규칙 차단)
     */
    public function test_post_destroy_rejects_array_verification_token(): void
    {
        $postId = $this->createTestPost([
            'user_id' => null,
            'password' => Hash::make('pass1234'),
        ]);

        $this->deleteJson(
            $this->postUrl()."/{$postId}",
            ['verification_token' => ['x']] // 배열 주입
        )->assertStatus(422);
    }

    /**
     * 비회원 글은 올바른 password 로 삭제 가능 → 200
     */
    public function test_guest_post_destroy_with_valid_password_succeeds(): void
    {
        $postId = $this->createTestPost([
            'user_id' => null,
            'password' => Hash::make('pass1234'),
        ]);

        $this->deleteJson(
            $this->postUrl()."/{$postId}",
            ['password' => 'pass1234']
        )->assertStatus(200);
    }
}
