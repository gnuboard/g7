<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

require_once __DIR__.'/../../ModuleTestCase.php';

use Modules\Sirsoft\Board\Listeners\EcommerceInquiryHookListener;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * EcommerceInquiryHookListener 단위 테스트
 *
 * 검증 목적:
 * - getSubscribedHooks: 7개 훅 등록, 모두 filter 타입
 * - createAndReturn: 성공(post_id/inquirable_type 반환), title 자동생성, parent_id Re: 처리, 예외 시 null
 * - getByIds: 빈 배열 → carry 그대로, 유효 ID 목록 → 필드 포함 배열 반환
 * - getBoardSettings: 존재하지 않는 slug → carry 그대로, 유효 slug → 설정 배열 반환
 * - deletePost: 성공 → carry 반환, 존재하지 않는 PostId → RuntimeException
 * - deleteReplyPost: reply 없음 → RuntimeException, 성공 → carry 반환
 *
 * @group board
 * @group unit
 * @group listener
 */
class EcommerceInquiryHookListenerTest extends BoardTestCase
{
    private EcommerceInquiryHookListener $listener;

    protected function getTestBoardSlug(): string
    {
        return 'ecommerce-inquiry-hook';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '이커머스 문의 훅 테스트 게시판', 'en' => 'Ecommerce Inquiry Hook Test Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'use_file_upload' => true,
            'max_file_count' => 5,
            'max_file_size' => 10485760,
            'allowed_extensions' => [],
            'min_title_length' => 2,
            'max_title_length' => 200,
            'min_content_length' => 1,
            'max_content_length' => 10000,
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(EcommerceInquiryHookListener::class);
    }

    // ==========================================
    // getSubscribedHooks
    // ==========================================

    /**
     * 7개 훅이 모두 등록되어 있고, 모두 filter 타입이어야 합니다.
     */
    public function test_subscribed_hooks_registers_all_seven_filter_hooks(): void
    {
        $hooks = EcommerceInquiryHookListener::getSubscribedHooks();

        $expectedHooks = [
            'sirsoft-ecommerce.inquiry.create',
            'sirsoft-ecommerce.inquiry.update',
            'sirsoft-ecommerce.inquiry.delete',
            'sirsoft-ecommerce.inquiry.update_reply',
            'sirsoft-ecommerce.inquiry.delete_reply',
            'sirsoft-ecommerce.inquiry.get_by_ids',
            'sirsoft-ecommerce.inquiry.get_settings',
        ];

        foreach ($expectedHooks as $hookName) {
            $this->assertArrayHasKey($hookName, $hooks, "훅 {$hookName}이 등록되어 있어야 합니다.");
            $this->assertSame('filter', $hooks[$hookName]['type'], "훅 {$hookName}은 filter 타입이어야 합니다.");
        }

        $this->assertCount(7, $hooks, '총 7개의 훅이 등록되어 있어야 합니다.');
    }

    // ==========================================
    // createAndReturn
    // ==========================================

    /**
     * createAndReturn: 정상 데이터로 게시글 생성 → post_id와 inquirable_type 반환
     */
    public function test_create_and_return_returns_post_id_and_inquirable_type(): void
    {
        $result = $this->listener->createAndReturn(null, $this->board->slug, [
            'title' => '문의 테스트 제목',
            'content' => '문의 내용입니다.',
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('post_id', $result);
        $this->assertArrayHasKey('inquirable_type', $result);
        $this->assertIsInt($result['post_id']);
        $this->assertSame(Post::class, $result['inquirable_type']);
    }

    /**
     * createAndReturn: title 없으면 content 앞부분으로 자동 생성
     */
    public function test_create_and_return_auto_generates_title_from_content(): void
    {
        $content = '이것은 50자가 넘는 긴 내용입니다. 제목이 없을 때 content 앞부분으로 자동 생성됩니다.';

        $result = $this->listener->createAndReturn(null, $this->board->slug, [
            'content' => $content,
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertNotNull($result);
        $post = Post::find($result['post_id']);
        $this->assertNotEmpty($post->title);
        $this->assertLessThanOrEqual(50, mb_strlen($post->title));
    }

    /**
     * createAndReturn: parent_id 있으면 부모 제목에 Re: 접두사 붙임
     */
    public function test_create_and_return_prepends_re_prefix_for_reply(): void
    {
        $parentPostId = $this->createTestPost(['title' => '원본 문의 제목']);

        $result = $this->listener->createAndReturn(null, $this->board->slug, [
            'content' => '답변 내용입니다.',
            'parent_id' => $parentPostId,
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertNotNull($result);
        $post = Post::find($result['post_id']);
        $this->assertStringStartsWith('Re:', $post->title);
        $this->assertStringContainsString('원본 문의 제목', $post->title);
    }

    /**
     * createAndReturn: 존재하지 않는 slug → 예외 발생하지 않고 null 반환
     */
    public function test_create_and_return_returns_null_on_exception(): void
    {
        $result = $this->listener->createAndReturn(null, 'nonexistent-slug-xyz', [
            'title' => '테스트',
            'content' => '내용',
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertNull($result);
    }

    // ==========================================
    // getByIds
    // ==========================================

    /**
     * getByIds: 빈 ids → carry 그대로 반환
     */
    public function test_get_by_ids_returns_carry_when_ids_empty(): void
    {
        $carry = ['existing' => 'data'];
        $result = $this->listener->getByIds($carry, ['ids' => [], 'slug' => $this->board->slug]);

        $this->assertSame($carry, $result);
    }

    /**
     * getByIds: 유효한 IDs → 필수 필드 포함 배열 반환
     */
    public function test_get_by_ids_returns_mapped_array_for_valid_ids(): void
    {
        $postId = $this->createTestPost(['title' => '문의글', 'content' => '문의 내용']);

        $result = $this->listener->getByIds([], ['ids' => [$postId], 'slug' => $this->board->slug]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);

        $item = $result[0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('board_id', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('content', $item);
        $this->assertArrayHasKey('status', $item);
        $this->assertArrayHasKey('attachments', $item);
        $this->assertArrayHasKey('reply', $item);
        $this->assertSame($postId, $item['id']);
    }

    /**
     * getByIds: ids에 없는 ID 포함해도 예외 없이 조회된 것만 반환
     */
    public function test_get_by_ids_ignores_nonexistent_ids(): void
    {
        $postId = $this->createTestPost();

        $result = $this->listener->getByIds([], [
            'ids' => [$postId, 99999999],
            'slug' => $this->board->slug,
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($postId, $result[0]['id']);
    }

    // ==========================================
    // getBoardSettings
    // ==========================================

    /**
     * getBoardSettings: 존재하지 않는 slug → carry 그대로 반환
     */
    public function test_get_board_settings_returns_carry_for_nonexistent_slug(): void
    {
        $carry = ['fallback' => true];
        $result = $this->listener->getBoardSettings($carry, 'nonexistent-slug-xyz');

        $this->assertSame($carry, $result);
    }

    /**
     * getBoardSettings: 유효한 slug → 게시판 설정 필드 포함 배열 반환
     */
    public function test_get_board_settings_returns_config_for_valid_slug(): void
    {
        $result = $this->listener->getBoardSettings([], $this->board->slug);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('secret_mode', $result);
        $this->assertArrayHasKey('use_file_upload', $result);
        $this->assertArrayHasKey('max_file_count', $result);
        $this->assertArrayHasKey('max_file_size', $result);
        $this->assertArrayHasKey('min_title_length', $result);
        $this->assertArrayHasKey('max_title_length', $result);
        $this->assertArrayHasKey('attachment_upload_url', $result);
        $this->assertArrayHasKey('attachment_delete_url', $result);
        $this->assertStringContainsString($this->board->slug, $result['attachment_upload_url']);
    }

    // ==========================================
    // deletePost
    // ==========================================

    /**
     * deletePost: 성공 → carry 반환
     */
    public function test_delete_post_returns_carry_on_success(): void
    {
        $postId = $this->createTestPost();
        $carry = ['some' => 'carry'];

        $result = $this->listener->deletePost($carry, $this->board->slug, $postId);

        $this->assertSame($carry, $result);
        $this->assertSoftDeleted('board_posts', ['id' => $postId]);
    }

    /**
     * deletePost: 존재하지 않는 postId → RuntimeException
     */
    public function test_delete_post_throws_runtime_exception_for_nonexistent_post(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->listener->deletePost(null, $this->board->slug, 99999999);
    }

    // ==========================================
    // deleteReplyPost
    // ==========================================

    /**
     * deleteReplyPost: 부모 게시글에 reply 없음 → RuntimeException
     */
    public function test_delete_reply_post_throws_runtime_exception_when_no_reply(): void
    {
        $parentPostId = $this->createTestPost();

        $this->expectException(\RuntimeException::class);

        $this->listener->deleteReplyPost(null, $this->board->slug, $parentPostId);
    }

    /**
     * deleteReplyPost: reply 있음 → 삭제 성공 + carry 반환
     */
    public function test_delete_reply_post_returns_carry_on_success(): void
    {
        $parentPostId = $this->createTestPost();
        $replyPostId = $this->createTestPost(['parent_id' => $parentPostId]);
        $carry = ['reply_carry' => true];

        $result = $this->listener->deleteReplyPost($carry, $this->board->slug, $parentPostId);

        $this->assertSame($carry, $result);
        $this->assertSoftDeleted('board_posts', ['id' => $replyPostId]);
    }
}
