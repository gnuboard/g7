<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 답글 첨부 직렬화 회귀 검증 (76차 브라우저 실측)
 *
 * g7.dev 실측 — 관리자 게시글 상세에서 답글의 「첨부파일 (1)」을 눌러도 목록이 비어 있었다.
 * 응답을 보니 답글 행이 이렇게 왔다:
 *
 *   has_attachment: true, attachment_count: 1, attachments: null
 *
 * 개수는 DB 컬럼(`attachments_count`)에서 오므로 맞고, 목록만 null 이었다.
 * 같은 글을 **단독으로** 조회하면 배열이 정상으로 왔다 — 즉 리소스가 아니라 **적재**가 문제였다.
 *
 * 원인: `PostRepository::loadAllDescendantReplies` 가 후손 답글을 모을 때 `with('user')` 만 걸어
 * `attachments` 관계를 얹지 않았다. `PostResource::getAttachmentsForResponse` 는 관계가
 * 로드되지 않았으면 **null 을 돌려주도록** 되어 있어(권한 판정 이전의 이른 반환), 그 null 이
 * 그대로 화면까지 내려갔다. 개수만 보고 펼친 관리자는 빈 칸을 보게 된다.
 *
 * 계약:
 *   ① 부모 상세의 `replies[]` 각 항목은 `attachments` 를 배열로 싣는다.
 *   ② 그 배열은 단독 조회했을 때와 같은 내용이다 (개수·식별자 일치).
 *   ③ 손자 답글(깊이 2 이상)도 같다 — 적재가 트리 전 단계에 걸린다.
 */
#[Group('board')]
#[Group('admin')]
class ReplyAttachmentSerializationTest extends BoardTestCase
{
    private User $manager;

    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'reply-attachment-serialization';
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
            'name' => ['ko' => '답글 첨부 직렬화 테스트', 'en' => 'Reply Attachment Serialization'],
            'is_active' => true,
            'use_comment' => true,
            'use_reply' => true,
            'use_file_upload' => true,
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

        $this->manager = $this->createAdminUser([
            "sirsoft-board.{$this->board->slug}.admin.posts.read",
            "sirsoft-board.{$this->board->slug}.admin.manage",
        ]);
    }

    /**
     * 관리자 상세 조회 API 경로.
     *
     * @param  int  $postId  게시글 ID
     * @return string 상세 조회 URL
     */
    private function showUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$postId}";
    }

    /**
     * 첨부파일을 직접 DB 에 생성하고 게시글의 집계 컬럼을 맞춥니다.
     *
     * @param  int  $postId  게시글 ID
     * @return int 생성된 첨부 ID
     */
    private function createTestAttachment(int $postId): int
    {
        static $seq = 0;
        $seq++;

        $id = DB::table('board_attachments')->insertGetId([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'hash' => 'r'.str_pad((string) $seq, 11, '0', STR_PAD_LEFT),
            'original_filename' => 'reply-attachment-'.$seq.'.txt',
            'stored_filename' => 'stored-r-'.$seq.'.txt',
            'disk' => 'local',
            'path' => 'attachments/reply-attachment-'.$seq.'.txt',
            'mime_type' => 'text/plain',
            'size' => 128,
            'collection' => 'default',
            'order' => $seq,
            'trigger_type' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('board_posts')->where('id', $postId)->update([
            'attachments_count' => DB::table('board_attachments')
                ->where('post_id', $postId)
                ->whereNull('deleted_at')
                ->count(),
        ]);

        return $id;
    }

    /**
     * 부모 상세의 답글마다 자기 첨부 목록이 실린다 — 답글끼리 섞이지 않는다.
     *
     * @scenario reply_count=multiple
     *
     * @effects reply_attachments_serialized, reply_attachments_not_shared
     *
     * @return void 반환값 없음
     */
    public function test_replies_carry_their_own_attachments_in_parent_detail(): void
    {
        $parentId = $this->createTestPost(['status' => 'published']);

        $replyAId = $this->createTestPost(['status' => 'published', 'parent_id' => $parentId]);
        $replyBId = $this->createTestPost(['status' => 'published', 'parent_id' => $parentId]);

        $attachmentA = $this->createTestAttachment($replyAId);
        $attachmentB = $this->createTestAttachment($replyBId);

        $response = $this->actingAs($this->manager)->getJson($this->showUrl($parentId));
        $response->assertOk();

        $replies = collect($response->json('data.replies'));
        $this->assertCount(2, $replies, '사전조건: 답글 2건이 상세에 실려야 합니다.');

        foreach ([[$replyAId, $attachmentA], [$replyBId, $attachmentB]] as [$replyId, $attachmentId]) {
            $reply = $replies->firstWhere('id', $replyId);

            $this->assertNotNull($reply, "답글 {$replyId} 가 응답에 없습니다.");
            $this->assertTrue($reply['has_attachment'], '사전조건: 첨부 보유로 표시되어야 합니다.');
            $this->assertSame(1, $reply['attachment_count'], '사전조건: 첨부 개수가 1 이어야 합니다.');

            $this->assertIsArray(
                $reply['attachments'],
                "답글 {$replyId} 의 attachments 가 배열이 아닙니다 — 개수만 보이고 목록은 비는 상태"
            );
            $this->assertSame(
                [$attachmentId],
                array_column($reply['attachments'], 'id'),
                "답글 {$replyId} 에 자기 첨부만 실려야 합니다."
            );
        }
    }

    /**
     * 손자 답글(깊이 2)도 첨부 목록을 싣는다 — 적재가 트리 전 단계에 걸린다.
     *
     * @scenario reply_count=multiple
     *
     * @effects nested_reply_attachments_serialized
     *
     * @return void 반환값 없음
     */
    public function test_nested_reply_carries_attachments(): void
    {
        $parentId = $this->createTestPost(['status' => 'published']);
        $childId = $this->createTestPost(['status' => 'published', 'parent_id' => $parentId]);
        $grandChildId = $this->createTestPost(['status' => 'published', 'parent_id' => $childId]);

        $attachmentId = $this->createTestAttachment($grandChildId);

        $response = $this->actingAs($this->manager)->getJson($this->showUrl($parentId));
        $response->assertOk();

        $child = collect($response->json('data.replies'))->firstWhere('id', $childId);
        $this->assertNotNull($child, '사전조건: 직접 자식 답글이 실려야 합니다.');

        $grandChild = collect($child['replies'] ?? [])->firstWhere('id', $grandChildId);
        $this->assertNotNull($grandChild, '사전조건: 손자 답글이 실려야 합니다.');

        $this->assertIsArray($grandChild['attachments'], '손자 답글의 attachments 가 배열이 아닙니다.');
        $this->assertSame([$attachmentId], array_column($grandChild['attachments'], 'id'));
    }

    /**
     * 첨부가 없는 답글은 빈 배열이다 — null 과 구분된다.
     *
     * @scenario reply_count=single
     *
     * @effects empty_reply_attachments_is_array
     *
     * @return void 반환값 없음
     */
    public function test_reply_without_attachment_serializes_empty_array(): void
    {
        $parentId = $this->createTestPost(['status' => 'published']);
        $replyId = $this->createTestPost(['status' => 'published', 'parent_id' => $parentId]);

        $response = $this->actingAs($this->manager)->getJson($this->showUrl($parentId));
        $response->assertOk();

        $reply = collect($response->json('data.replies'))->firstWhere('id', $replyId);

        $this->assertNotNull($reply, '사전조건: 답글이 실려야 합니다.');
        $this->assertSame([], $reply['attachments'], '첨부 없는 답글은 빈 배열이어야 합니다 (null 아님).');
        $this->assertFalse($reply['has_attachment']);
    }
}
