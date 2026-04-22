<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

require_once __DIR__.'/../ModuleTestCase.php';

use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 비회원 게시글 삭제 토큰 단위 테스트
 *
 * 검증 목적:
 * - storeDeleteVerifyToken: 토큰 캐시 저장 + token/expires_at 반환
 * - consumeDeleteVerifyToken: 유효 토큰 소비 → true + 재소비 방지 (일회용)
 * - 존재하지 않는 토큰 소비 → false
 * - 다른 slug/postId 토큰은 소비되지 않음 (격리)
 *
 * @group board
 * @group unit
 * @group post
 */
class PostServiceGuestTokenTest extends ModuleTestCase
{
    private PostService $postService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postService = app(PostService::class);
    }

    /**
     * storeDeleteVerifyToken: token 문자열 및 expires_at 반환
     */
    public function test_store_returns_token_and_expires_at(): void
    {
        $token = Str::random(32);
        $result = $this->postService->storeDeleteVerifyToken('test-board', 1, $token);

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertSame($token, $result['token']);
        $this->assertNotEmpty($result['expires_at']);
    }

    /**
     * consumeDeleteVerifyToken: 저장된 토큰 소비 → true
     */
    public function test_consume_valid_token_returns_true(): void
    {
        $token = Str::random(32);
        $this->postService->storeDeleteVerifyToken('test-board', 10, $token);

        $result = $this->postService->consumeDeleteVerifyToken('test-board', 10, $token);

        $this->assertTrue($result);
    }

    /**
     * consumeDeleteVerifyToken: 토큰은 일회용 — 두 번째 소비 → false
     */
    public function test_consume_token_is_one_time_use(): void
    {
        $token = Str::random(32);
        $this->postService->storeDeleteVerifyToken('test-board', 20, $token);

        $this->postService->consumeDeleteVerifyToken('test-board', 20, $token); // 첫 소비
        $second = $this->postService->consumeDeleteVerifyToken('test-board', 20, $token); // 재소비

        $this->assertFalse($second);
    }

    /**
     * consumeDeleteVerifyToken: 저장하지 않은 토큰 소비 → false
     */
    public function test_consume_nonexistent_token_returns_false(): void
    {
        $result = $this->postService->consumeDeleteVerifyToken('test-board', 99, 'nonexistent-token');

        $this->assertFalse($result);
    }

    /**
     * 다른 slug로 저장한 토큰은 소비 불가 (캐시 키 격리)
     */
    public function test_token_is_isolated_by_slug(): void
    {
        $token = Str::random(32);
        $this->postService->storeDeleteVerifyToken('board-a', 1, $token);

        $result = $this->postService->consumeDeleteVerifyToken('board-b', 1, $token);

        $this->assertFalse($result);
    }

    /**
     * 다른 postId로 저장한 토큰은 소비 불가 (캐시 키 격리)
     */
    public function test_token_is_isolated_by_post_id(): void
    {
        $token = Str::random(32);
        $this->postService->storeDeleteVerifyToken('board-a', 1, $token);

        $result = $this->postService->consumeDeleteVerifyToken('board-a', 2, $token);

        $this->assertFalse($result);
    }
}
