<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\ModuleStorageDriver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 영구 삭제 시 하위 데이터 정리 회귀 테스트 (#413-77)
 *
 * - 게시글·댓글·첨부 DB 레코드: soft delete → force delete (완전 제거)
 * - 첨부 물리 파일: 저장 경로(slug 기준) 일치 삭제
 * - 신고(Report): 설계 의도대로 보존 (board_id 만 NULL)
 */
class BoardDeletionTest extends ModuleTestCase
{
    private User $adminUser;

    private BoardService $boardService;

    private StorageInterface $storage;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 이전 실행 잔여 데이터 정리 (DatabaseTransactions 비활성 환경 호환)
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('permissions')->where('identifier', 'like', 'sirsoft-board.del-test-%')->delete();
        DB::table('boards')->where('slug', 'like', 'del-test-%')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->adminUser = User::factory()->create(['name' => 'Admin User']);
        $this->actingAs($this->adminUser);

        $this->boardService = app(BoardService::class);
        // BoardService 에 주입되는 것과 동일한 모듈 스토리지 드라이버
        // (AbstractModule::getStorage() 가 생성하는 것과 같은 identifier/disk)
        $this->storage = new ModuleStorageDriver('sirsoft-board', 'modules');
    }

    /**
     * 게시판 삭제 시 하위 게시글·댓글·첨부 DB 레코드가 완전히 제거되는지 검증합니다.
     *
     * 현행(soft delete) 코드에서는 withTrashed count 가 0 이 아니어서 fail (red).
     *
     * @scenario child_type=post
     * @effects post_records_force_deleted,comment_records_force_deleted,attachment_records_force_deleted,board_itself_force_deleted
     */
    public function test_deleting_board_force_deletes_child_records(): void
    {
        $board = $this->createTestBoard('del-test-1');

        $post = Post::create([
            'board_id' => $board->id,
            'title' => '삭제 대상 게시글',
            'content' => '본문',
            'status' => PostStatus::Published,
            'ip_address' => '127.0.0.1',
        ]);

        Comment::create([
            'board_id' => $board->id,
            'post_id' => $post->id,
            'content' => '삭제 대상 댓글',
            'status' => PostStatus::Published,
            'ip_address' => '127.0.0.1',
        ]);

        Attachment::create([
            'board_id' => $board->id,
            'post_id' => $post->id,
            'original_filename' => 'file.txt',
            'stored_filename' => 'stored.txt',
            'disk' => 'local',
            'path' => "{$board->slug}/2026/06/25/stored.txt",
            'mime_type' => 'text/plain',
            'size' => 10,
            'collection' => 'attachments',
            'order' => 1,
        ]);

        $boardId = $board->id;

        $this->boardService->deleteBoard($board->id);

        // withTrashed 로도 잔존하지 않아야 함 (force delete)
        $this->assertSame(0, Post::withTrashed()->where('board_id', $boardId)->count());
        $this->assertSame(0, Comment::withTrashed()->where('board_id', $boardId)->count());
        $this->assertSame(0, Attachment::withTrashed()->where('board_id', $boardId)->count());

        // 게시판 자체도 영구 삭제 (Board 모델은 SoftDeletes 미사용 → 단순 find)
        $this->assertNull(Board::find($boardId));
    }

    /**
     * 게시판 삭제 시 첨부 물리 파일이 실제로 삭제되는지 검증합니다.
     *
     * 저장 경로는 slug 기준({slug}/...)인데 기존 삭제는 board_id 기준이라
     * 파일이 남아 있던 버그(#413-77)의 회귀 테스트. 현행 코드에서는 파일이
     * 남아 fail (red), slug 기반 삭제로 수정하면 green.
     *
     * @scenario child_type=attachment
     * @effects attachment_physical_files_removed
     */
    public function test_deleting_board_removes_attachment_physical_files(): void
    {
        $board = $this->createTestBoard('del-test-2');

        // 실제 저장 경로 패턴으로 파일 생성 (최종 + 임시)
        $finalPath = "{$board->slug}/2026/06/25/final.txt";
        $tempPath = "{$board->slug}/temp/tempkey/temp.txt";
        $this->storage->put('attachments', $finalPath, 'final-content');
        $this->storage->put('attachments', $tempPath, 'temp-content');

        $this->assertTrue($this->storage->exists('attachments', $finalPath));
        $this->assertTrue($this->storage->exists('attachments', $tempPath));

        $this->boardService->deleteBoard($board->id);

        // slug 디렉토리 통째 삭제로 최종·임시 모두 제거되어야 함
        $this->assertFalse($this->storage->exists('attachments', $finalPath));
        $this->assertFalse($this->storage->exists('attachments', $tempPath));
    }

    /**
     * 게시판 삭제 시 신고(Report)는 설계 의도대로 보존되는지 검증합니다.
     *
     * boards_reports.board_id 는 nullable + nullOnDelete 로 설계되어 있어
     * 게시판이 사라져도 신고 레코드는 보존되고 board_id 만 NULL 이 된다.
     *
     * @scenario child_type=report
     * @effects reports_preserved_with_null_board_id
     */
    public function test_deleting_board_preserves_reports(): void
    {
        $board = $this->createTestBoard('del-test-3');

        $report = Report::factory()->create([
            'board_id' => $board->id,
            'author_id' => $this->adminUser->id,
        ]);

        $reportId = $report->id;

        $this->boardService->deleteBoard($board->id);

        $preserved = Report::find($reportId);
        $this->assertNotNull($preserved, '게시판 삭제 후에도 신고는 보존되어야 합니다.');
        $this->assertNull($preserved->board_id, '게시판 삭제 후 신고의 board_id 는 NULL 이어야 합니다.');
    }

    /**
     * 게시판 삭제가 다른 게시판의 첨부 파일을 건드리지 않는지 검증합니다.
     *
     * (cross product child_type=comment 케이스도 본 테스트가 포함 검증 — 다른 게시판의
     *  댓글·첨부 등 하위 데이터가 영향받지 않음을 board 격리 관점에서 함께 보장)
     *
     * @scenario child_type=comment
     * @effects other_board_files_not_affected
     */
    public function test_deleting_board_does_not_affect_other_board_files(): void
    {
        $boardA = $this->createTestBoard('del-test-4a');
        $boardB = $this->createTestBoard('del-test-4b');

        $pathA = "{$boardA->slug}/2026/06/25/a.txt";
        $pathB = "{$boardB->slug}/2026/06/25/b.txt";
        $this->storage->put('attachments', $pathA, 'a');
        $this->storage->put('attachments', $pathB, 'b');

        $this->boardService->deleteBoard($boardA->id);

        $this->assertFalse($this->storage->exists('attachments', $pathA));
        $this->assertTrue(
            $this->storage->exists('attachments', $pathB),
            '다른 게시판(B)의 첨부 파일은 영향받지 않아야 합니다.'
        );

        // 정리
        $this->boardService->deleteBoard($boardB->id);
    }

    /**
     * 테스트용 게시판을 생성합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return Board 생성된 게시판
     */
    private function createTestBoard(string $slug): Board
    {
        return $this->boardService->createBoard([
            'slug' => $slug,
            'name' => ['ko' => "테스트 {$slug}", 'en' => "Test {$slug}"],
            'type' => 'default',
        ]);
    }
}
