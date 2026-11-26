<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Listeners\UserDeleteCleanupListener;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 회원 삭제 시 게시판 소유 회원 데이터의 명시 삭제와 집계 재계산
 *
 * 회원 행에 삭제 연쇄 외래키로 걸린 게시판 테이블(`board_user_notification_settings`·`board_reactions`·
 * `board_post_scraps`)을 코어 회원 삭제가 `core.user.before_delete` 훅으로 게시판에 맡기고, 게시판은
 * DB CASCADE 없이 서비스 계층에서 지운다. 외래키 검사를 끈 채 회원을 지워도 고아 행이 남지 않아야 하고,
 * 영향받은 게시글·댓글의 `reaction_counts`·`scrap_count` 는 남은 행 수와 같아야 한다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/user-delete-cleanup.yaml
 */
class UserDeleteCleanupTest extends BoardTestCase
{
    private int $likeId;

    /**
     * 각 테스트 전에 반응 유형 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('board_reactions')->delete();
        DB::table('board_post_scraps')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);

        $this->likeId = (int) ReactionType::query()->where('code', 'like')->value('id');
    }

    /**
     * 외래키 검사를 끈 채 회원을 지워도 게시판 소유 행이 남지 않고, 반응·스크랩 집계가 남은 행 기준으로 줄어든다.
     *
     * @scenario case=purge_and_recalc
     *
     * @effects member_board_rows_deleted_without_fk_cascade, reaction_and_scrap_counts_match_remaining_rows
     *
     * @return void 반환값 없음
     */
    public function test_user_delete_purges_board_rows_without_fk_cascade_and_recalculates_counts(): void
    {
        $leaving = $this->createUser();
        $staying = $this->createUser();
        $author = $this->createUser();

        $postId = $this->createTestPost(['user_id' => $author->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        // 게시글: 떠나는 회원·남는 회원이 1건씩 반응 / 댓글: 떠나는 회원만 반응
        $this->insertReaction($leaving->id, 'post', $postId);
        $this->insertReaction($staying->id, 'post', $postId);
        $this->insertReaction($leaving->id, 'comment', $commentId);

        // 스크랩: 두 회원이 같은 글을 스크랩
        $this->insertScrap($leaving->id, $postId);
        $this->insertScrap($staying->id, $postId);

        // 집계 캐시를 현재 행 수에 맞춰 둔다
        DB::table('board_posts')->where('id', $postId)->update([
            'reaction_counts' => json_encode([(string) $this->likeId => 2]),
            'scrap_count' => 2,
        ]);
        DB::table('board_comments')->where('id', $commentId)->update([
            'reaction_counts' => json_encode([(string) $this->likeId => 1]),
        ]);

        DB::table('board_user_notification_settings')->insert([
            'user_id' => $leaving->id,
            'notify_comment' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteUserWithoutForeignKeys($leaving);

        $this->assertDatabaseMissing('users', ['id' => $leaving->id]);
        $this->assertSame(0, DB::table('board_user_notification_settings')->where('user_id', $leaving->id)->count(), '알림 설정이 고아로 남았습니다');
        $this->assertSame(0, DB::table('board_reactions')->where('user_id', $leaving->id)->count(), '반응이 고아로 남았습니다');
        $this->assertSame(0, DB::table('board_post_scraps')->where('user_id', $leaving->id)->count(), '스크랩이 고아로 남았습니다');

        // 남는 회원의 행은 그대로
        $this->assertSame(1, DB::table('board_reactions')->where('user_id', $staying->id)->count());
        $this->assertSame(1, DB::table('board_post_scraps')->where('user_id', $staying->id)->count());

        // 집계는 남은 행 기준으로 감소
        $post = Post::withTrashed()->findOrFail($postId);
        $this->assertSame(1, (int) ($post->reaction_counts[(string) $this->likeId] ?? -1), '게시글 반응 수가 남은 행과 다릅니다');
        $this->assertSame(1, (int) $post->scrap_count, '게시글 스크랩 수가 남은 행과 다릅니다');

        $comment = Comment::withTrashed()->findOrFail($commentId);
        $this->assertSame(0, (int) ($comment->reaction_counts[(string) $this->likeId] ?? -1), '댓글 반응 수가 남은 행과 다릅니다');
    }

    /**
     * 반응 대상이 이미 하드 삭제된 반응도 회원 삭제를 막지 않고 함께 지워진다.
     *
     * @scenario case=target_gone
     *
     * @effects member_board_rows_deleted_without_fk_cascade
     *
     * @return void 반환값 없음
     */
    public function test_user_delete_purges_reactions_whose_target_is_gone(): void
    {
        $leaving = $this->createUser();

        $missingPostId = (int) DB::table('board_posts')->max('id') + 100000;
        $this->insertReaction($leaving->id, 'post', $missingPostId);
        $this->insertScrap($leaving->id, $missingPostId);

        $this->deleteUserWithoutForeignKeys($leaving);

        $this->assertDatabaseMissing('users', ['id' => $leaving->id]);
        $this->assertSame(0, DB::table('board_reactions')->where('user_id', $leaving->id)->count());
        $this->assertSame(0, DB::table('board_post_scraps')->where('user_id', $leaving->id)->count());
    }

    /**
     * 리스너가 회원 삭제 직전 훅을 동기로 구독하고 모듈 리스너 목록에 등록돼 있다.
     *
     * 큐로 미루면 회원 행이 먼저 지워져 정리·집계 재계산의 근거가 사라진다.
     *
     * @scenario case=sync_listener
     *
     * @effects cleanup_listener_runs_before_user_row_deleted
     *
     * @return void 반환값 없음
     */
    public function test_listener_subscribes_before_delete_synchronously(): void
    {
        $hooks = UserDeleteCleanupListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.user.before_delete', $hooks);
        $this->assertTrue($hooks['core.user.before_delete']['sync']);
        $this->assertContains(UserDeleteCleanupListener::class, (new Module)->getHookListeners());
    }

    /**
     * 외래키 검사를 끈 상태에서 코어 회원 삭제를 실행합니다.
     *
     * 끈 상태에서 남는 행이 있다면 그 행은 외래키 연쇄에만 기대 지워지던 것이다.
     *
     * @param  User  $user  삭제할 회원
     * @return void 반환값 없음
     */
    private function deleteUserWithoutForeignKeys(User $user): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            app(UserService::class)->deleteUser($user);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * 반응 행을 직접 넣습니다.
     *
     * @param  int  $userId  반응한 회원 ID
     * @param  string  $targetType  대상 타입 (post|comment)
     * @param  int  $targetId  대상 ID
     * @return void 반환값 없음
     */
    private function insertReaction(int $userId, string $targetType, int $targetId): void
    {
        DB::table('board_reactions')->insert([
            'user_id' => $userId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reaction_type_id' => $this->likeId,
            'board_id' => $this->board->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 스크랩 행을 직접 넣습니다.
     *
     * @param  int  $userId  스크랩한 회원 ID
     * @param  int  $postId  게시글 ID
     * @return void 반환값 없음
     */
    private function insertScrap(int $userId, int $postId): void
    {
        DB::table('board_post_scraps')->insert([
            'user_id' => $userId,
            'post_id' => $postId,
            'board_id' => $this->board->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
