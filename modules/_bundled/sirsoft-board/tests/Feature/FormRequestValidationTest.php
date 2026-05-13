<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

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
}
