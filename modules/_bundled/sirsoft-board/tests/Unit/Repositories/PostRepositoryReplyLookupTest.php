<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Repositories;

// ModuleTestCase 수동 로드 (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Modules\Sirsoft\Board\Repositories\PostRepository;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * PostRepository 답글 조회 메서드 테스트
 *
 * 검증 목적:
 * - countRepliesByParentId: 살아있는 직계 자식만 집계 (SoftDeletes 기본 스코프)
 * - hasAliveReplies: 살아있는 직계 답글 존재 여부 판정 (답글 삭제 정책 block 의 차단 기준)
 *   - 직계 살아있는 답글 존재 → true
 *   - 직계 답글 전부 trashed → false
 *   - 직계는 trashed 이고 손자만 살아있음(끊긴 체인 고아) → false (직계만 판정)
 *
 * @group board
 * @group unit
 * @group repository
 */
class PostRepositoryReplyLookupTest extends BoardTestCase
{
    private PostRepository $repository;

    protected function getTestBoardSlug(): string
    {
        return 'post-repository-reply-lookup';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(PostRepository::class);
    }

    // ==========================================
    // countRepliesByParentId
    // ==========================================

    /**
     * countRepliesByParentId: 살아있는 자식만 집계한다 (trashed 자식 제외).
     */
    public function test_count_replies_by_parent_id_counts_only_live_children(): void
    {
        $parentId = $this->createTestPost(['title' => '부모 글']);

        $this->createTestPost([
            'title' => '살아있는 답글',
            'parent_id' => $parentId,
            'depth' => 1,
        ]);
        $this->createTestPost([
            'title' => '삭제된 답글',
            'parent_id' => $parentId,
            'depth' => 1,
            'status' => 'deleted',
            'trigger_type' => 'user',
            'deleted_at' => now()->subHour(),
        ]);

        $this->assertSame(
            1,
            $this->repository->countRepliesByParentId($parentId),
            'trashed 자식은 집계에서 제외되어야 합니다.'
        );
    }

    // ==========================================
    // hasAliveReplies
    // ==========================================

    /**
     * hasAliveReplies: 살아있는 직계 답글이 있으면 true.
     */
    public function test_has_alive_replies_returns_true_for_direct_live_reply(): void
    {
        $parentId = $this->createTestPost(['title' => '부모 글']);
        $this->createTestPost([
            'title' => '살아있는 답글',
            'parent_id' => $parentId,
            'depth' => 1,
        ]);

        $this->assertTrue($this->repository->hasAliveReplies($this->board->slug, $parentId));
    }

    /**
     * hasAliveReplies: 직계 답글이 전부 trashed 이면 false.
     */
    public function test_has_alive_replies_returns_false_when_all_replies_trashed(): void
    {
        $parentId = $this->createTestPost(['title' => '부모 글']);
        $this->createTestPost([
            'title' => '삭제된 답글 1',
            'parent_id' => $parentId,
            'depth' => 1,
            'status' => 'deleted',
            'trigger_type' => 'user',
            'deleted_at' => now()->subHour(),
        ]);
        $this->createTestPost([
            'title' => '삭제된 답글 2',
            'parent_id' => $parentId,
            'depth' => 1,
            'status' => 'deleted',
            'trigger_type' => 'user',
            'deleted_at' => now()->subHour(),
        ]);

        $this->assertFalse(
            $this->repository->hasAliveReplies($this->board->slug, $parentId),
            '직계 답글이 전부 trashed 이면 살아있는 답글이 없는 것으로 판정되어야 합니다.'
        );
    }

    /**
     * hasAliveReplies: 직계는 trashed 이고 손자만 살아있으면 false (직계만 판정).
     *
     * 끊긴 체인 밑 고아 손자는 별도로 cascade 스윕이 정리하며, block 정책 차단
     * 판정은 직계 검사만으로 완결됩니다.
     */
    public function test_has_alive_replies_returns_false_when_only_grandchild_alive(): void
    {
        $parentId = $this->createTestPost(['title' => '부모 글']);
        $trashedChildId = $this->createTestPost([
            'title' => '삭제된 직계 답글',
            'parent_id' => $parentId,
            'depth' => 1,
            'status' => 'deleted',
            'trigger_type' => 'user',
            'deleted_at' => now()->subHour(),
        ]);
        $this->createTestPost([
            'title' => '살아있는 손자 답글',
            'parent_id' => $trashedChildId,
            'depth' => 2,
        ]);

        $this->assertFalse(
            $this->repository->hasAliveReplies($this->board->slug, $parentId),
            '손자만 살아있는 경우 직계 판정은 false 여야 합니다.'
        );
    }
}
