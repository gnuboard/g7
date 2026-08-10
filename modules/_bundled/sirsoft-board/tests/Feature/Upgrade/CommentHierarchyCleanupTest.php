<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_0_3;
use Psr\Log\LoggerInterface;

/**
 * 1.0.3 댓글 계층 오염 데이터 정리 업그레이드 스텝 테스트
 *
 * 검증 목적:
 * - 교차 게시글 부모를 가진 고아 댓글이 최상위로 승격된다 (본문 보존)
 * - depth 가 루트부터 부모 depth + 1 로 재계산된다
 * - 재실행해도 결과가 동일하다 (멱등)
 *
 * @group board
 * @group upgrade
 */
class CommentHierarchyCleanupTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'comment-hierarchy-cleanup';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '댓글 계층 정리 게시판', 'en' => 'Comment Hierarchy Cleanup Board'],
            'is_active' => true,
            'use_comment' => true,
            'max_comment_depth' => 10,
        ];
    }

    /**
     * 교차 게시글 부모 댓글이 최상위로 승격되고 본문은 보존된다
     *
     * @scenario entity=board_comment, parent_target=foreign
     *
     * @effects existing_cross_post_parent_detached_content_preserved
     */
    public function test_cross_post_parent_is_detached_and_content_preserved(): void
    {
        $postA = $this->createTestPost(['title' => '게시글 A']);
        $postB = $this->createTestPost(['title' => '게시글 B']);

        $parentInA = $this->createTestComment($postA, ['depth' => 0]);
        $orphan = $this->createTestComment($postB, [
            'parent_id' => $parentInA,
            'depth' => 1,
            'content' => '보존되어야 하는 본문',
        ]);

        $this->runCleanup();

        $row = DB::table('board_comments')->where('id', $orphan)->first();

        $this->assertNull($row->parent_id, '교차 게시글 부모는 해제되어야 합니다.');
        $this->assertSame(0, (int) $row->depth, '승격된 댓글의 depth 는 0 이어야 합니다.');
        $this->assertSame('보존되어야 하는 본문', $row->content, '본문은 보존되어야 합니다.');
        $this->assertSame($postB, (int) $row->post_id, 'post_id 는 바뀌지 않아야 합니다.');
    }

    /**
     * 클램프로 뭉개진 depth 가 부모 depth + 1 로 재계산된다
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects existing_clamped_depth_recalculated
     */
    public function test_clamped_depth_is_recalculated_from_root(): void
    {
        $postId = $this->createTestPost();

        // 8단계 체인을 만들되, 6단계 이후 depth 를 5 로 뭉개 둔다 (구 클램프 재현)
        $parentId = null;
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $storedDepth = min($i, 5);
            $parentId = $this->createTestComment($postId, [
                'parent_id' => $parentId,
                'depth' => $storedDepth,
            ]);
            $ids[$i] = $parentId;
        }

        $this->runCleanup();

        foreach ($ids as $expectedDepth => $id) {
            $this->assertSame(
                $expectedDepth,
                (int) DB::table('board_comments')->where('id', $id)->value('depth'),
                "{$expectedDepth} 단계 댓글의 depth 가 재계산되어야 합니다."
            );
        }
    }

    /**
     * 재실행해도 결과가 동일하다 (멱등)
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects cleanup_is_idempotent
     */
    public function test_cleanup_is_idempotent(): void
    {
        $postA = $this->createTestPost(['title' => '게시글 A']);
        $postB = $this->createTestPost(['title' => '게시글 B']);

        $parentInA = $this->createTestComment($postA, ['depth' => 0]);
        $this->createTestComment($postB, ['parent_id' => $parentInA, 'depth' => 1]);

        $chainRoot = $this->createTestComment($postA, ['parent_id' => $parentInA, 'depth' => 5]);
        $this->createTestComment($postA, ['parent_id' => $chainRoot, 'depth' => 5]);

        $this->runCleanup();
        $first = $this->snapshot();

        $this->runCleanup();
        $second = $this->snapshot();

        $this->assertSame($first, $second, '재실행 결과가 동일해야 합니다.');
    }

    /**
     * 부모 행이 삭제된 고아 댓글은 값이 보존되고 경고 로그가 남는다
     *
     * 정리 1단계는 부모와 INNER JOIN 하므로 부모 행 자체가 없는 댓글은 매칭되지 않고,
     * 2단계 BFS 는 `parent_id IS NULL` 루트에서 출발하므로 이 댓글에 도달하지 못한다.
     * 값을 임의로 고치거나 지우면 데이터 손실이므로 보존하되, 정정되지 않았다는 사실은
     * 경고로 남겨야 한다.
     *
     * @scenario entity=board_comment, parent_target=missing
     *
     * @effects dangling_parent_skipped_with_warning
     */
    public function test_dangling_parent_is_skipped_with_warning(): void
    {
        $post = $this->createTestPost(['title' => '게시글 A']);

        $parent = $this->createTestComment($post, ['depth' => 0]);
        $dangling = $this->createTestComment($post, ['parent_id' => $parent, 'depth' => 3]);

        // 부모 행만 물리 삭제하여 dangling parent_id 상태를 만든다.
        DB::table('board_comments')->where('id', $parent)->delete();

        $logger = \Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('extension-upgrade')
            ->andReturn($logger);

        $this->runCleanup();

        $this->assertDatabaseHas('board_comments', [
            'id' => $dangling,
            'parent_id' => $parent,
            'depth' => 3,
        ]);

        $logger->shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, '부모 행이 없는 댓글')
                && str_contains($message, (string) $dangling))
            ->once();
    }

    /**
     * 루트에서 도달할 수 없는 순환 참조 댓글은 값이 보존되고 경고 로그가 남는다
     *
     * 부모 행이 실재하므로 고아 판정(`부모 행이 없는 댓글`)에 걸리지 않고,
     * BFS 는 루트에서만 출발하므로 순환 컴포넌트에 진입조차 못 해 세대 상한에도
     * 걸리지 않는다. 두 그물을 모두 빠져나가므로 전용 경고가 없으면
     * "정정되지 않았다" 는 사실이 조용히 묻힌다.
     *
     * @scenario entity=board_comment, parent_target=cycle
     *
     * @effects unreachable_cycle_skipped_with_warning
     */
    public function test_unreachable_cycle_is_skipped_with_warning(): void
    {
        $post = $this->createTestPost(['title' => '게시글 A']);

        $first = $this->createTestComment($post, ['depth' => 0]);
        $second = $this->createTestComment($post, ['parent_id' => $first, 'depth' => 1]);

        // 첫 댓글의 부모를 두 번째로 돌려 루트 없는 순환(A→B→A)을 만든다.
        DB::table('board_comments')->where('id', $first)->update([
            'parent_id' => $second,
            'depth' => 4,
        ]);

        $logger = \Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('extension-upgrade')
            ->andReturn($logger);

        $this->runCleanup();

        // 값 보존 — 순환의 어느 지점을 끊을지는 운영자 판단이므로 건드리지 않는다.
        $this->assertDatabaseHas('board_comments', [
            'id' => $first,
            'parent_id' => $second,
            'depth' => 4,
        ]);
        $this->assertDatabaseHas('board_comments', [
            'id' => $second,
            'parent_id' => $first,
            'depth' => 1,
        ]);

        $logger->shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, '루트에서 도달하지 못한 댓글')
                && str_contains($message, (string) $first)
                && str_contains($message, (string) $second))
            ->once();
    }

    /**
     * 정합한 계층만 있으면 순환 경고가 발생하지 않는다 (과잉 경고 방지)
     *
     * @scenario entity=board_comment, parent_target=self
     *
     * @effects healthy_hierarchy_emits_no_cycle_warning
     */
    public function test_healthy_hierarchy_emits_no_cycle_warning(): void
    {
        $post = $this->createTestPost(['title' => '게시글 A']);

        $root = $this->createTestComment($post, ['depth' => 0]);
        $this->createTestComment($post, ['parent_id' => $root, 'depth' => 1]);

        $logger = \Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('extension-upgrade')
            ->andReturn($logger);

        $this->runCleanup();

        $logger->shouldNotHaveReceived('warning', [
            \Mockery::on(fn (string $message) => str_contains($message, '루트에서 도달하지 못한 댓글')),
        ]);
    }

    /**
     * 1.0.3 정리 마이그레이션을 실행합니다.
     */
    private function runCleanup(): void
    {
        $context = new UpgradeContext('1.0.2', '1.0.3', '1.0.3', 'extension-upgrade');

        $step = new Upgrade_1_0_3;
        $step->run($context);
    }

    /**
     * 이 게시판 댓글의 (id, parent_id, depth) 스냅샷을 반환합니다.
     *
     * @return array<int, array{id: int, parent_id: int|null, depth: int}> 스냅샷 배열
     */
    private function snapshot(): array
    {
        return DB::table('board_comments')
            ->where('board_id', $this->board->id)
            ->orderBy('id')
            ->get(['id', 'parent_id', 'depth'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'depth' => (int) $row->depth,
            ])
            ->all();
    }
}
