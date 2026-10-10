<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\Storage\ModuleStorageDriver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Board\Listeners\EcommerceInquiryHookListener;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 이커머스 샘플 문의 재시드 정리 범위 테스트 (SB-1)
 *
 * `sirsoft-ecommerce.inquiry.purge_sample_posts` 필터 훅은 이커머스가 넘긴 샘플 문의 글과
 * 그 글에 달린 답변만 지워야 한다. 예전 정리는 문의 게시판의 답변 글 전부를 영구 삭제해
 * 운영자가 실제 문의에 단 답변까지 지웠고, 지운 글의 댓글·반응·스크랩을 고아로 남기며
 * 게시판 글 수도 다시 세지 않았다.
 */
class EcommerceInquirySamplePurgeTest extends BoardTestCase
{
    private EcommerceInquiryHookListener $listener;

    /**
     * 이 테스트 전용 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'ecommerce-inquiry-purge';
    }

    /**
     * 테스트 게시판의 기본 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '상품 문의 정리 테스트 게시판', 'en' => 'Inquiry Purge Test Board'],
            'is_active' => true,
            'secret_mode' => 'always',
            'use_comment' => true,
            'use_reply' => true,
            'blocked_keywords' => [],
        ];
    }

    /**
     * 리스너 인스턴스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(EcommerceInquiryHookListener::class);
    }

    /**
     * 넘겨받지 않은 실제 문의와 운영자 답변은 남기고, 샘플 문의의 답변·댓글·반응·스크랩은 함께 지운다.
     *
     * @scenario case=sample_ids_with_children
     *
     * @effects listener_purge_keeps_operator_replies_and_cleans_children
     *
     * @return void 반환값 없음
     */
    public function test_purge_keeps_real_inquiry_replies_and_cleans_sample_children(): void
    {
        $user = User::factory()->create();
        $reactionTypeId = DB::table('board_reaction_types')->value('id') ?? DB::table('board_reaction_types')->insertGetId([
            'code' => 'like',
            'name' => json_encode(['ko' => '추천', 'en' => 'Like']),
            'display_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 샘플 문의 + 답변 + 댓글·반응·스크랩
        $sampleId = $this->createTestPost(['title' => '샘플 문의']);
        $sampleReplyId = $this->createTestPost(['title' => 'Re: 샘플 문의', 'parent_id' => $sampleId, 'depth' => 1]);
        $sampleCommentId = $this->createTestComment($sampleId);
        DB::table('board_reactions')->insert([
            ['user_id' => $user->id, 'target_type' => 'post', 'target_id' => $sampleId, 'reaction_type_id' => $reactionTypeId, 'board_id' => $this->board->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'target_type' => 'comment', 'target_id' => $sampleCommentId, 'reaction_type_id' => $reactionTypeId, 'board_id' => $this->board->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('board_post_scraps')->insert([
            'user_id' => $user->id, 'post_id' => $sampleId, 'board_id' => $this->board->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 실제 문의 + 운영자 답변 (이커머스가 넘기지 않음)
        $realId = $this->createTestPost(['title' => '실제 문의']);
        $operatorReplyId = $this->createTestPost(['title' => 'Re: 실제 문의', 'parent_id' => $realId, 'depth' => 1]);

        $deleted = $this->listener->purgeSamplePosts(0, $this->board->slug, [Post::class => [$sampleId]]);

        $this->assertSame(2, $deleted, '샘플 문의와 그 답변만 지워야 합니다.');
        $this->assertDatabaseMissing('board_posts', ['id' => $sampleId]);
        $this->assertDatabaseMissing('board_posts', ['id' => $sampleReplyId]);
        $this->assertDatabaseHas('board_posts', ['id' => $realId]);
        $this->assertDatabaseHas('board_posts', ['id' => $operatorReplyId]);
        $this->assertDatabaseMissing('board_comments', ['id' => $sampleCommentId]);
        $this->assertSame(0, DB::table('board_reactions')->where('board_id', $this->board->id)->count(), '글·댓글 반응이 고아로 남으면 안 됩니다.');
        $this->assertSame(0, DB::table('board_post_scraps')->where('post_id', $sampleId)->count(), '스크랩이 고아로 남으면 안 됩니다.');
        $this->assertSame(2, (int) DB::table('boards')->where('id', $this->board->id)->value('posts_count'), '게시판 글 수를 다시 세야 합니다.');
        $this->assertSame(0, (int) DB::table('boards')->where('id', $this->board->id)->value('comments_count'));
    }

    /**
     * 샘플 문의 답변에 달린 첨부는 행과 함께 저장소 파일도 지운다 — 행만 지우면 파일이 고아로 남는다.
     *
     * @scenario case=sample_ids_with_attachment_file
     *
     * @effects listener_purge_deletes_attachment_files
     *
     * @return void 반환값 없음
     */
    public function test_purge_deletes_attachment_files_of_sample_posts(): void
    {
        Storage::fake('modules');
        $storage = new ModuleStorageDriver('sirsoft-board', 'modules');
        $path = 'inquiry/purge-probe.jpg';
        $storage->put('attachments', $path, 'probe');

        $sampleId = $this->createTestPost(['title' => '샘플 문의']);
        $replyId = $this->createTestPost(['title' => 'Re: 샘플 문의', 'parent_id' => $sampleId, 'depth' => 1]);
        $attachmentId = DB::table('board_attachments')->insertGetId([
            'board_id' => $this->board->id,
            'post_id' => $replyId,
            'hash' => 'purgeprobe01',
            'original_filename' => 'probe.jpg',
            'stored_filename' => 'purge-probe.jpg',
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size' => 5,
            'collection' => 'attachments',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->listener->purgeSamplePosts(0, $this->board->slug, [Post::class => [$sampleId]]);

        $this->assertDatabaseMissing('board_attachments', ['id' => $attachmentId]);
        $this->assertFalse($storage->exists('attachments', $path), '첨부 파일이 저장소에 고아로 남으면 안 된다.');
    }

    /**
     * 넘겨받은 글이 없으면 아무것도 지우지 않는다(실 문의만 있는 사이트의 재시드).
     *
     * @scenario case=no_sample_ids
     *
     * @effects listener_purge_noop_without_sample_ids
     *
     * @return void 반환값 없음
     */
    public function test_purge_deletes_nothing_without_sample_ids(): void
    {
        $realId = $this->createTestPost(['title' => '실제 문의']);
        $operatorReplyId = $this->createTestPost(['title' => 'Re: 실제 문의', 'parent_id' => $realId, 'depth' => 1]);

        $this->assertSame(0, $this->listener->purgeSamplePosts(0, $this->board->slug, []));
        $this->assertDatabaseHas('board_posts', ['id' => $realId]);
        $this->assertDatabaseHas('board_posts', ['id' => $operatorReplyId]);
    }

    /**
     * 샘플 문의 글·답변을 만들면 게시판 글 수와 질문의 답변 수가 실제 행 수와 맞는다.
     *
     * seedSamplePost 는 알림·활동 기록을 피하려고 서비스 훅을 거치지 않는데, 건수 동기화도 그 훅
     * (post.after_create) 리스너가 맡아 상품 문의 게시판 글 수가 0 으로 남았다(L2 실측: 실제 251건).
     *
     * @scenario case=seed_sample_posts
     *
     * @effects listener_seed_sample_post_syncs_counts
     *
     * @return void 반환값 없음
     */
    public function test_seed_sample_post_syncs_board_and_reply_counts(): void
    {
        $withReply = $this->listener->seedSamplePost(null, $this->board->slug, ['title' => '문의 1', 'content' => '내용'], ['content' => '답변']);
        $this->listener->seedSamplePost(null, $this->board->slug, ['title' => '문의 2', 'content' => '내용']);

        $this->assertSame(3, (int) DB::table('boards')->where('id', $this->board->id)->value('posts_count'), '문의 2건 + 답변 1건');
        $this->assertSame(1, (int) DB::table('board_posts')->where('id', $withReply['post_id'])->value('replies_count'));
    }

    /**
     * 다른 게시판의 같은 ID 글은 지우지 않는다 — 정리는 문의 게시판 안에서만 일어난다.
     *
     * @scenario case=foreign_board_ids
     *
     * @effects listener_purge_scoped_to_inquiry_board
     *
     * @return void 반환값 없음
     */
    public function test_purge_ignores_ids_outside_inquiry_board(): void
    {
        $otherBoardId = DB::table('boards')->insertGetId([
            'name' => json_encode(['ko' => '다른 게시판', 'en' => 'Other']),
            'slug' => 'ecommerce-inquiry-purge-other',
            'type' => 'basic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignId = $this->createTestPost(['board_id' => $otherBoardId, 'title' => '다른 게시판 글']);

        $this->assertSame(0, $this->listener->purgeSamplePosts(0, $this->board->slug, [Post::class => [$foreignId]]));
        $this->assertDatabaseHas('board_posts', ['id' => $foreignId]);
    }
}
