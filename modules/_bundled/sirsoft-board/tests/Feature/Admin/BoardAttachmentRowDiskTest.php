<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Contracts\Extension\StorageInterface;
use App\Extension\ModuleManager;
use App\Extension\Storage\ModuleStorageDriver;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\ContentMedia\BoardContentMediaService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 영구 삭제의 첨부 물리 파일 정리가 행 disk 를 따르는지 (#869 R1)
 *
 * 디스크를 전환한 사이트에서는 한 게시판의 첨부가 여러 디스크에 흩어진다. 종전
 * `BoardService::deleteAttachmentFiles()` 는 주입 디스크의 `{slug}` 디렉토리만 지워, 옛 디스크의
 * 파일이 게시판 삭제 뒤에도 영구 잔류했다. 오류도 로그도 없이 저장 공간으로만 드러나는 축이라
 * 실제 디스크에서 파일 존재로 잰다. 구조는 형제 이커머스 `ProductImageServiceRowDiskTest` 와 같다.
 */
class BoardAttachmentRowDiskTest extends ModuleTestCase
{
    private BoardService $boardService;

    /**
     * 가짜 디스크 둘(주입 디스크 modules · 옛 디스크 board_alt)을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('permissions')->where('identifier', 'like', 'sirsoft-board.rowdisk-%')->delete();
        DB::table('boards')->where('slug', 'like', 'rowdisk-%')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        Config::set('filesystems.disks.board_alt', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/board_alt'),
        ]);
        Storage::fake('modules');
        Storage::fake('board_alt');

        $this->actingAs(User::factory()->create());
        $this->boardService = app(BoardService::class);
    }

    /**
     * 테스트 게시판을 만듭니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return Board 게시판
     */
    private function createBoard(string $slug): Board
    {
        return $this->boardService->createBoard([
            'slug' => $slug,
            'name' => ['ko' => "테스트 {$slug}", 'en' => "Test {$slug}"],
            'type' => 'default',
        ]);
    }

    /**
     * 지정 disk 에 파일과 첨부 행을 함께 만듭니다.
     *
     * @param  Board  $board  게시판
     * @param  string|null  $disk  행 disk (파일은 null·빈 값이면 modules 에 쓴다)
     * @param  string  $path  카테고리 하위 경로
     * @param  int|null  $postId  게시글 ID (null 이면 임시 첨부)
     * @return void 반환값 없음
     */
    private function makeRowOnDisk(Board $board, ?string $disk, string $path, ?int $postId): void
    {
        (new ModuleStorageDriver('sirsoft-board', $disk ?: 'modules'))->put('attachments', $path, 'image-bytes');

        Attachment::create([
            'board_id' => $postId === null ? 0 : $board->id,
            'post_id' => $postId,
            'temp_key' => $postId === null ? 'rowdisk-temp' : null,
            'original_filename' => basename($path),
            'stored_filename' => basename($path),
            'disk' => (string) $disk,
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size' => 11,
            'collection' => 'attachments',
            'order' => 1,
        ]);
    }

    /**
     * 게시글 하나를 만듭니다.
     *
     * @param  Board  $board  게시판
     * @return int 게시글 ID
     */
    private function createPost(Board $board): int
    {
        return Post::create([
            'board_id' => $board->id,
            'title' => '행 disk 테스트',
            'content' => '본문',
            'status' => PostStatus::Published,
            'ip_address' => '127.0.0.1',
        ])->id;
    }

    /**
     * 게시판 삭제는 주입 디스크와 옛 디스크 양쪽의 첨부(최종·임시)를 지운다.
     *
     * @scenario case=delete_mixed_disks
     *
     * @effects board_delete_removes_files_on_every_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_board_delete_removes_files_on_every_row_disk(): void
    {
        $board = $this->createBoard('rowdisk-mixed');
        $postId = $this->createPost($board);

        $this->makeRowOnDisk($board, 'modules', "{$board->slug}/2026/11/05/base.jpg", $postId);
        $this->makeRowOnDisk($board, 'board_alt', "{$board->slug}/2026/11/05/alt.jpg", $postId);
        $this->makeRowOnDisk($board, 'board_alt', "{$board->slug}/temp/rowdisk-temp/alt-temp.jpg", null);

        $base = new ModuleStorageDriver('sirsoft-board', 'modules');
        $alt = new ModuleStorageDriver('sirsoft-board', 'board_alt');
        $this->assertTrue($alt->exists('attachments', "{$board->slug}/2026/11/05/alt.jpg"));

        $this->boardService->deleteBoard($board->id);

        $this->assertFalse($base->exists('attachments', "{$board->slug}/2026/11/05/base.jpg"));
        $this->assertFalse($alt->exists('attachments', "{$board->slug}/2026/11/05/alt.jpg"), '옛 디스크의 첨부가 남았다');
        $this->assertFalse($alt->exists('attachments', "{$board->slug}/temp/rowdisk-temp/alt-temp.jpg"), '옛 디스크의 임시 첨부가 남았다');
    }

    /**
     * 다른 게시판의 옛 디스크 파일은 건드리지 않는다.
     *
     * @scenario case=delete_other_board
     *
     * @effects board_delete_keeps_other_board_files_on_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_board_delete_keeps_other_board_files_on_row_disk(): void
    {
        $target = $this->createBoard('rowdisk-target');
        $other = $this->createBoard('rowdisk-other');

        $this->makeRowOnDisk($target, 'board_alt', "{$target->slug}/2026/11/05/a.jpg", $this->createPost($target));
        $this->makeRowOnDisk($other, 'board_alt', "{$other->slug}/2026/11/05/b.jpg", $this->createPost($other));

        $this->boardService->deleteBoard($target->id);

        $alt = new ModuleStorageDriver('sirsoft-board', 'board_alt');
        $this->assertFalse($alt->exists('attachments', "{$target->slug}/2026/11/05/a.jpg"));
        $this->assertTrue($alt->exists('attachments', "{$other->slug}/2026/11/05/b.jpg"), '다른 게시판 파일이 지워졌다');
    }

    /**
     * disk 가 빈 구 데이터 행은 주입 디스크 소속으로 보고 함께 지운다.
     *
     * @scenario case=delete_blank_disk
     *
     * @effects board_delete_normalizes_blank_disk_rows_to_base_storage
     *
     * @return void 반환값 없음
     */
    public function test_board_delete_normalizes_blank_disk_rows_to_base_storage(): void
    {
        $board = $this->createBoard('rowdisk-blank');
        $this->makeRowOnDisk($board, '', "{$board->slug}/2026/11/05/legacy.jpg", $this->createPost($board));

        $this->boardService->deleteBoard($board->id);

        $this->assertFalse((new ModuleStorageDriver('sirsoft-board', 'modules'))->exists('attachments', "{$board->slug}/2026/11/05/legacy.jpg"));
    }

    /**
     * 행 disk 를 제공하던 확장이 꺼져 config 에서 사라져도 게시판 삭제는 끝난다.
     *
     * @scenario case=delete_orphan_disk
     *
     * @effects board_delete_succeeds_when_row_disk_is_orphaned
     *
     * @return void 반환값 없음
     */
    public function test_board_delete_succeeds_when_row_disk_is_orphaned(): void
    {
        $board = $this->createBoard('rowdisk-orphan');
        $this->makeRowOnDisk($board, 'board_alt', "{$board->slug}/2026/11/05/orphan.jpg", $this->createPost($board));

        Config::set('filesystems.disks.board_alt', null);
        Storage::forgetDisk('board_alt');

        $boardId = $board->id;
        $this->boardService->deleteBoard($boardId);

        $this->assertNull(Board::find($boardId));
        $this->assertSame(0, Attachment::withTrashed()->where('board_id', $boardId)->count());
    }

    /**
     * 첨부·글·본문 미디어 서비스는 비공개 첨부 저장소 선택을 따르고, `.env` `SIRSOFT_BOARD_ATTACHMENT_DISK` 고정이면 선택을 무시한다(#916 D7).
     *
     * @scenario case=private_attachment_selection
     *
     * @return void 반환값 없음
     */
    public function test_services_follow_private_attachment_selection_unless_env_pinned(): void
    {
        Config::set('sirsoft-board.attachment.disk_explicit', null);
        Config::set('core.storage.private_attachment_disk', '');
        foreach ([AttachmentService::class, BoardService::class, BoardContentMediaService::class] as $class) {
            $this->assertSame('modules', $this->injectedDisk(app($class)), $class.' 「기본」');
        }

        Config::set('core.storage.private_attachment_disk', 'board_alt');
        foreach ([AttachmentService::class, BoardService::class, BoardContentMediaService::class] as $class) {
            $this->assertSame('board_alt', $this->injectedDisk(app($class)), $class.' 선택');
        }

        Config::set('sirsoft-board.attachment.disk_explicit', 'modules');
        $this->assertSame('modules', $this->injectedDisk(app(AttachmentService::class)), 'env 고정이 선택보다 우선');
        $this->assertSame(['env' => 'SIRSOFT_BOARD_ATTACHMENT_DISK', 'disk' => 'modules'], app(ModuleManager::class)->getModule('sirsoft-board')?->getPrivateAttachmentDiskPin());
    }

    /**
     * 서비스에 주입된 스토리지의 디스크를 읽습니다(클래스 계층의 StorageInterface 속성).
     *
     * @param  object  $service  서비스
     * @return string 디스크 이름
     */
    private function injectedDisk(object $service): string
    {
        for ($class = new \ReflectionClass($service); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                $value = $property->getValue($service);
                if ($value instanceof StorageInterface) {
                    return $value->getDisk();
                }
            }
        }
        $this->fail('주입된 스토리지를 찾지 못했습니다: '.$service::class);
    }
}
