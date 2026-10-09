<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AttachmentService 단위 테스트
 *
 * StorageInterface 기반 파일 업로드, 삭제 등을 테스트합니다.
 */
class AttachmentServiceTest extends ModuleTestCase
{
    private AttachmentService $service;

    /** @var MockInterface&AttachmentRepositoryInterface */
    private $repository;

    /** @var MockInterface&BoardRepositoryInterface */
    private $boardRepository;

    /** @var MockInterface&StorageInterface */
    private $storage;

    private User $user;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Telescope 비활성화
        config(['telescope.enabled' => false]);

        // Mock Repository 생성
        $this->repository = Mockery::mock(AttachmentRepositoryInterface::class);
        $this->boardRepository = Mockery::mock(BoardRepositoryInterface::class);

        // Mock Storage 생성 (직접 Mock)
        $this->storage = Mockery::mock(StorageInterface::class);

        // boardRepository 기본 Mock: findBySlug 호출 시 Board Mock 반환
        $mockBoard = Mockery::mock(Board::class)->makePartial();
        $mockBoard->id = 1;
        $this->boardRepository->shouldReceive('findBySlug')->andReturn($mockBoard);

        // Service 생성 (Phase 8: boardRepository 추가)
        $this->service = new AttachmentService($this->repository, $this->boardRepository, $this->storage);

        // 테스트 사용자 생성
        $this->user = User::factory()->create();
        Auth::login($this->user);
    }

    /**
     * 테스트 환경을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 업로드한 파일을 저장소에 쓰고 첨부 행을 만든다.
     *
     * @return void 반환값 없음
     *
     * @effects upload_stores_file_and_creates_row
     */
    #[Test]
    public function test_upload_stores_file_and_creates_attachment(): void
    {
        // Arrange
        $slug = 'notice';
        $postId = 1;
        $file = UploadedFile::fake()->create('document.pdf', 100);

        // Storage Mock 기대값
        $this->storage
            ->shouldReceive('put')
            ->once()
            ->withArgs(function ($category, $path, $contents) {
                return $category === 'attachments'
                    && str_contains($path, 'notice/')
                    && str_ends_with($path, '.pdf');
            })
            ->andReturn(true);

        $this->storage
            ->shouldReceive('getDisk')
            ->andReturn('local');

        // Repository Mock 기대값
        $this->repository
            ->shouldReceive('getMaxOrder')
            ->once()
            ->with($slug, $postId, 'attachments')
            ->andReturn(0);

        $expectedAttachment = new Attachment([
            'post_id' => $postId,
            'original_filename' => 'document.pdf',
            'disk' => 'local',
            'collection' => 'attachments',
            'order' => 1,
        ]);
        $expectedAttachment->id = 1;

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($slug) {
                return $slug === 'notice';
            }), Mockery::on(function ($data) use ($postId) {
                return $data['post_id'] === $postId
                    && $data['original_filename'] === 'document.pdf'
                    && $data['disk'] === 'local'
                    && $data['collection'] === 'attachments'
                    && $data['order'] === 1
                    && $data['created_by'] === $this->user->id;
            }))
            ->andReturn($expectedAttachment);

        // Act
        $result = $this->service->upload($slug, $file, $postId);

        // Assert
        $this->assertEquals(1, $result->id);
        $this->assertEquals('document.pdf', $result->original_filename);
    }

    /**
     * temp_key 로 올린 첨부는 키를 보존한 임시 첨부 행으로 저장된다.
     *
     * @return void 반환값 없음
     *
     * @effects temp_upload_stored_under_temp_key_path
     */
    #[Test]
    public function test_upload_with_temp_key_creates_temp_attachment(): void
    {
        // Arrange
        $slug = 'notice';
        $tempKey = 'temp-uuid-123';
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->storage
            ->shouldReceive('put')
            ->once()
            ->andReturn(true);

        $this->storage
            ->shouldReceive('getDisk')
            ->andReturn('local');

        $this->repository
            ->shouldReceive('getMaxOrderByTempKey')
            ->once()
            ->with($slug, $tempKey, 'attachments')
            ->andReturn(0);

        $expectedAttachment = new Attachment;
        $expectedAttachment->id = 1;
        $expectedAttachment->post_id = null;
        $expectedAttachment->temp_key = $tempKey;
        $expectedAttachment->original_filename = 'photo.jpg';
        $expectedAttachment->disk = 'local';
        $expectedAttachment->collection = 'attachments';
        $expectedAttachment->order = 1;

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with($slug, Mockery::on(function ($data) use ($tempKey) {
                return $data['post_id'] === null
                    && $data['temp_key'] === $tempKey
                    && $data['original_filename'] === 'photo.jpg';
            }))
            ->andReturn($expectedAttachment);

        // Act
        $result = $this->service->upload($slug, $file, null, 'attachments', $tempKey);

        // Assert
        $this->assertNull($result->post_id);
        $this->assertEquals($tempKey, $result->temp_key);
    }

    /**
     * 글 없이 올린 첨부는 {slug}/temp/{temp_key} 임시 경로에 저장된다.
     *
     * @return void 반환값 없음
     *
     * @effects temp_upload_stored_under_temp_key_path
     */
    #[Test]
    public function test_upload_creates_file_in_temp_path_when_no_post_id(): void
    {
        // Arrange
        $slug = 'notice';
        $tempKey = 'temp-uuid-456';
        $file = UploadedFile::fake()->create('document.pdf', 100);

        // 핵심: postId=null일 때 임시 경로 사용 확인
        $this->storage
            ->shouldReceive('put')
            ->once()
            ->withArgs(function ($category, $path, $contents) use ($slug, $tempKey) {
                return $category === 'attachments'
                    && str_starts_with($path, "{$slug}/temp/{$tempKey}/")
                    && str_ends_with($path, '.pdf');
            })
            ->andReturn(true);

        $this->storage->shouldReceive('getDisk')->andReturn('local');
        $this->repository->shouldReceive('getMaxOrderByTempKey')->andReturn(0);

        $expectedAttachment = new Attachment(['original_filename' => 'document.pdf']);
        $expectedAttachment->id = 1;
        $this->repository->shouldReceive('create')
            ->once()
            ->with($slug, Mockery::on(function ($data) use ($slug, $tempKey) {
                // path가 임시 경로인지 검증
                return $data['post_id'] === null
                    && $data['temp_key'] === $tempKey
                    && str_starts_with($data['path'], "{$slug}/temp/{$tempKey}/");
            }))
            ->andReturn($expectedAttachment);

        // Act
        $result = $this->service->upload($slug, $file, null, 'attachments', $tempKey);

        // Assert
        $this->assertEquals(1, $result->id);
    }

    /**
     * 글이 있으면 날짜별 최종 경로에 바로 저장한다.
     *
     * @return void 반환값 없음
     *
     * @effects upload_stores_file_and_creates_row
     */
    #[Test]
    public function test_upload_creates_file_in_final_path_when_post_id_exists(): void
    {
        // Arrange
        $slug = 'notice';
        $postId = 10;
        $file = UploadedFile::fake()->create('document.pdf', 100);
        $datePath = date('Y/m/d');

        // 핵심: postId가 있을 때 최종 경로 사용 확인
        $this->storage
            ->shouldReceive('put')
            ->once()
            ->withArgs(function ($category, $path, $contents) use ($slug, $datePath) {
                return $category === 'attachments'
                    && str_starts_with($path, "{$slug}/{$datePath}/")
                    && ! str_contains($path, '/temp/')
                    && str_ends_with($path, '.pdf');
            })
            ->andReturn(true);

        $this->storage->shouldReceive('getDisk')->andReturn('local');
        $this->repository->shouldReceive('getMaxOrder')->andReturn(0);

        $expectedAttachment = new Attachment(['original_filename' => 'document.pdf']);
        $expectedAttachment->id = 1;
        $this->repository->shouldReceive('create')
            ->once()
            ->with($slug, Mockery::on(function ($data) use ($postId, $slug, $datePath) {
                // path가 최종 경로인지 검증
                return $data['post_id'] === $postId
                    && $data['temp_key'] === null
                    && str_starts_with($data['path'], "{$slug}/{$datePath}/")
                    && ! str_contains($data['path'], '/temp/');
            }))
            ->andReturn($expectedAttachment);

        // Act
        $result = $this->service->upload($slug, $file, $postId);

        // Assert
        $this->assertEquals(1, $result->id);
    }

    /**
     * 임시 첨부를 최종 경로로 옮겨 글에 잇고 임시 디렉토리를 정리한다.
     *
     * @return void 반환값 없음
     *
     * @effects owned_temp_attachments_moved_and_temp_dir_cleaned
     */
    #[Test]
    public function test_link_temp_attachments_with_move_moves_files_and_updates_db(): void
    {
        // Arrange
        $slug = 'notice';
        $tempKey = 'temp-uuid-789';
        $postId = 5;
        $datePath = date('Y/m/d');

        // Phase 8: $attachment->update() 직접 호출이므로 Mockery mock 사용
        $attachment1 = Mockery::mock(Attachment::class)->makePartial();
        $attachment1->path = "{$slug}/temp/{$tempKey}/uuid1.pdf";
        $attachment1->stored_filename = 'uuid1.pdf';
        $attachment1->id = 1;
        $attachment1->shouldReceive('update')
            ->once()
            ->with(Mockery::on(function ($data) use ($postId, $slug, $datePath) {
                return $data['post_id'] === $postId
                    && $data['temp_key'] === null
                    && str_starts_with($data['path'], "{$slug}/{$datePath}/")
                    && str_ends_with($data['path'], 'uuid1.pdf')
                    && $data['order'] === 1
                    && isset($data['board_id']);
            }))
            ->andReturn(true);

        $attachment2 = Mockery::mock(Attachment::class)->makePartial();
        $attachment2->path = "{$slug}/temp/{$tempKey}/uuid2.jpg";
        $attachment2->stored_filename = 'uuid2.jpg';
        $attachment2->id = 2;
        $attachment2->shouldReceive('update')
            ->once()
            ->with(Mockery::on(function ($data) use ($postId, $slug, $datePath) {
                return $data['post_id'] === $postId
                    && $data['temp_key'] === null
                    && str_starts_with($data['path'], "{$slug}/{$datePath}/")
                    && str_ends_with($data['path'], 'uuid2.jpg')
                    && $data['order'] === 2
                    && isset($data['board_id']);
            }))
            ->andReturn(true);

        $tempAttachments = new EloquentCollection([$attachment1, $attachment2]);

        // Repository: 임시 첨부파일 조회
        $this->repository
            ->shouldReceive('getByTempKey')
            ->once()
            ->with($slug, $tempKey)
            ->andReturn($tempAttachments);

        // 새 글이라 기존 첨부가 없다 — 번호는 1 부터 (수정 화면이면 기존 마지막 번호 뒤)
        $this->repository->shouldReceive('getMaxOrder')->andReturn(0);

        // Storage: 파일 이동 (get + put + delete) x 2
        $this->storage
            ->shouldReceive('get')
            ->twice()
            ->withArgs(function ($category, $path) {
                return $category === 'attachments' && str_contains($path, '/temp/');
            })
            ->andReturn('file-content');

        $this->storage
            ->shouldReceive('put')
            ->twice()
            ->withArgs(function ($category, $path) use ($slug, $datePath) {
                return $category === 'attachments'
                    && str_starts_with($path, "{$slug}/{$datePath}/")
                    && ! str_contains($path, '/temp/');
            })
            ->andReturn(true);

        $this->storage
            ->shouldReceive('delete')
            ->twice()
            ->withArgs(function ($category, $path) {
                return $category === 'attachments' && str_contains($path, '/temp/');
            })
            ->andReturn(true);

        // Storage: 임시 디렉토리 정리
        $this->storage
            ->shouldReceive('deleteDirectory')
            ->once()
            ->with('attachments', "{$slug}/temp/{$tempKey}")
            ->andReturn(true);

        // Act
        $result = $this->service->linkTempAttachmentsWithMove($slug, $tempKey, $postId);

        // Assert
        $this->assertEquals(2, $result);
    }

    /**
     * 임시 디렉토리 정리가 실패해도 이미 옮긴 첨부의 연결은 끝까지 진행한다.
     *
     * 정리는 부수 작업이다. 막 옮긴 대용량 파일을 운영체제가 잠시 붙잡고 있으면(삭제 대기) 디렉토리
     * 순회가 lstat 오류를 던졌고, 그 예외가 글 저장 전체를 500 으로 되돌렸다 — 파일은 이미 본경로로
     * 옮겨졌는데 DB 는 임시 경로를 가리킨 채 남았다(#703 브라우저 실측). 정리 실패로 남는 것은 빈 임시 디렉토리뿐이다.
     *
     * @return void 반환값 없음
     *
     * @effects temp_dir_cleanup_failure_does_not_abort_link
     */
    #[Test]
    public function test_link_continues_when_temp_directory_cleanup_fails(): void
    {
        $slug = 'notice';
        $tempKey = '9b7c0f1e-2d3c-4b5a-8e7f-6a5b4c3d2e1f';
        $postId = 7;

        $attachment = Mockery::mock(Attachment::class)->makePartial();
        $attachment->path = "{$slug}/temp/{$tempKey}/big.zip";
        $attachment->stored_filename = 'big.zip';
        $attachment->id = 3;
        $attachment->shouldReceive('update')->once()->andReturn(true);

        $this->repository->shouldReceive('getByTempKey')->once()->with($slug, $tempKey)
            ->andReturn(new EloquentCollection([$attachment]));
        $this->repository->shouldReceive('getMaxOrder')->andReturn(0);
        $this->storage->shouldReceive('get')->once()->andReturn('zip-content');
        $this->storage->shouldReceive('put')->once()->andReturn(true);
        $this->storage->shouldReceive('delete')->once()->andReturn(true);
        $this->storage->shouldReceive('deleteDirectory')->once()
            ->andThrow(new \RuntimeException('SplFileInfo::getType(): Lstat failed'));

        $this->assertSame(1, $this->service->linkTempAttachmentsWithMove($slug, $tempKey, $postId));
    }

    /**
     * 옮길 임시 첨부가 없으면 어떤 파일·디렉토리도 건드리지 않고 0 을 반환한다.
     *
     * @return void 반환값 없음
     *
     * @effects empty_temp_link_deletes_nothing
     */
    #[Test]
    public function test_link_temp_attachments_with_move_handles_empty_temp_files(): void
    {
        // Arrange
        $slug = 'notice';
        $tempKey = '5f0c3a3e-1b2c-4d5e-8f90-a1b2c3d4e5f6';
        $postId = 5;

        $this->repository
            ->shouldReceive('getByTempKey')
            ->once()
            ->with($slug, $tempKey)
            ->andReturn(new EloquentCollection([]));

        // 옮길 임시 첨부가 없으면 어떤 파일·디렉토리도 건드리지 않는다 (KVE-2026-2349 —
        // 종전에는 여기서 정리를 실행해 temp_key 조작으로 저장소 루트까지 지워졌다)
        $this->storage->shouldNotReceive('deleteDirectory');
        $this->storage->shouldNotReceive('get');
        $this->storage->shouldNotReceive('put');
        $this->storage->shouldNotReceive('delete');

        // Act
        $result = $this->service->linkTempAttachmentsWithMove($slug, $tempKey, $postId);

        // Assert
        $this->assertEquals(0, $result);
    }

    /**
     * 업로드 전후 훅이 발화한다.
     *
     * @return void 반환값 없음
     *
     * @effects attachment_lifecycle_hooks_fire
     */
    #[Test]
    public function test_upload_fires_hooks(): void
    {
        // Arrange
        $slug = 'notice';
        $beforeUploadFired = false;
        $afterUploadFired = false;
        $filterApplied = false;

        HookManager::addAction('sirsoft-board.attachment.before_upload', function () use (&$beforeUploadFired) {
            $beforeUploadFired = true;
        });

        HookManager::addFilter('sirsoft-board.attachment.filter_upload_file', function ($file) use (&$filterApplied) {
            $filterApplied = true;

            return $file;
        });

        HookManager::addAction('sirsoft-board.attachment.after_upload', function () use (&$afterUploadFired) {
            $afterUploadFired = true;
        });

        $file = UploadedFile::fake()->create('test.pdf');

        $this->storage->shouldReceive('put')->andReturn(true);
        $this->storage->shouldReceive('getDisk')->andReturn('local');
        $this->repository->shouldReceive('getMaxOrder')->andReturn(0);

        $attachment = new Attachment(['id' => 1]);
        $this->repository->shouldReceive('create')->andReturn($attachment);

        // Act
        $this->service->upload($slug, $file, 1);

        // Assert
        $this->assertTrue($beforeUploadFired, 'before_upload hook should be fired');
        $this->assertTrue($filterApplied, 'filter_upload_file hook should be applied');
        $this->assertTrue($afterUploadFired, 'after_upload hook should be fired');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-board.attachment.before_upload');
        HookManager::clearFilter('sirsoft-board.attachment.filter_upload_file');
        HookManager::clearAction('sirsoft-board.attachment.after_upload');
    }

    /**
     * 삭제는 소프트 삭제이며 실제 파일은 남긴다.
     *
     * @return void 반환값 없음
     *
     * @effects soft_delete_keeps_physical_file
     */
    #[Test]
    public function test_delete_soft_deletes_without_removing_physical_file(): void
    {
        // Arrange
        // 물리 파일은 삭제하지 않고 소프트 딜리트만 수행 (배치 정리 예정)
        $slug = 'notice';
        $attachmentId = 1;

        $attachment = new Attachment([
            'post_id' => 1,
            'path' => 'notice/2024/01/19/file.pdf',
            'collection' => 'attachments',
        ]);
        $attachment->id = $attachmentId;

        $this->repository
            ->shouldReceive('findById')
            ->once()
            ->with($slug, $attachmentId)
            ->andReturn($attachment);

        // 물리 파일 삭제(storage->exists, storage->delete)는 호출되지 않아야 함
        $this->storage->shouldNotReceive('exists');
        $this->storage->shouldNotReceive('delete');

        $this->repository
            ->shouldReceive('delete')
            ->once()
            ->with($slug, $attachmentId)
            ->andReturn(true);

        $this->repository
            ->shouldReceive('getByPost')
            ->once()
            ->with($slug, 1, 'attachments')
            ->andReturn(new EloquentCollection([]));

        // Act
        $result = $this->service->delete($slug, $attachmentId);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * 삭제 전후 훅이 발화한다.
     *
     * @return void 반환값 없음
     *
     * @effects attachment_lifecycle_hooks_fire
     */
    #[Test]
    public function test_delete_fires_hooks(): void
    {
        // Arrange
        $slug = 'notice';
        $beforeDeleteFired = false;
        $afterDeleteFired = false;

        HookManager::addAction('sirsoft-board.attachment.before_delete', function () use (&$beforeDeleteFired) {
            $beforeDeleteFired = true;
        });

        HookManager::addAction('sirsoft-board.attachment.after_delete', function () use (&$afterDeleteFired) {
            $afterDeleteFired = true;
        });

        $attachment = new Attachment(['post_id' => 1, 'path' => 'test.pdf', 'collection' => 'attachments']);
        $attachment->id = 1;

        $this->repository->shouldReceive('findById')->andReturn($attachment);
        $this->repository->shouldReceive('delete')->andReturn(true);
        $this->repository->shouldReceive('getByPost')->andReturn(new EloquentCollection([]));

        // Act
        $this->service->delete($slug, 1);

        // Assert
        $this->assertTrue($beforeDeleteFired, 'before_delete hook should be fired');
        $this->assertTrue($afterDeleteFired, 'after_delete hook should be fired');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-board.attachment.before_delete');
        HookManager::clearAction('sirsoft-board.attachment.after_delete');
    }

    /**
     * 파일 이동 없는 임시 첨부 연결은 행만 글에 잇는다.
     *
     * @return void 반환값 없음
     *
     * @effects temp_link_without_move_links_rows
     */
    #[Test]
    public function test_link_temp_attachments_links_temp_files_to_post(): void
    {
        // Arrange
        $slug = 'notice';
        $tempKey = 'temp-uuid-123';
        $postId = 5;

        // Service 는 훅 발화를 위해 getByTempKey → linkTempAttachments → findById 순으로 호출한다.
        // Repository 시그니처: Eloquent\Collection 반환 필수 (Support\Collection 불일치)
        //
        // 후보 컬렉션이 비어 있으면 재조회 루프가 0회 실행되어 재조회 메서드 이름이 틀려도
        // 통과한다(실제로 없는 `getById()` 를 호출하고 있었는데 그 상태로 green 이었다).
        // 따라서 첨부 1건이 든 컬렉션으로 루프를 반드시 태운다.
        $tempAttachment = new Attachment(['temp_key' => $tempKey]);
        $tempAttachment->id = 11;

        $linkedAttachment = new Attachment;
        $linkedAttachment->id = 11;
        $linkedAttachment->post_id = $postId;

        $this->repository
            ->shouldReceive('getByTempKey')
            ->once()
            ->with($slug, $tempKey)
            ->andReturn(new Collection([$tempAttachment]));

        $this->repository
            ->shouldReceive('linkTempAttachments')
            ->once()
            ->with($slug, $tempKey, $postId)
            ->andReturn(3);

        $this->repository
            ->shouldReceive('findById')
            ->once()
            ->with($slug, 11)
            ->andReturn($linkedAttachment);

        // Act
        $result = $this->service->linkTempAttachments($slug, $tempKey, $postId);

        // Assert
        $this->assertEquals(3, $result);
    }

    /**
     * 순서 변경이 각 첨부의 order 를 갱신한다.
     *
     * @return void 반환값 없음
     *
     * @effects reorder_updates_order
     */
    #[Test]
    public function test_reorder_updates_attachment_order(): void
    {
        // Arrange
        $slug = 'notice';
        $orders = [
            1 => 3,
            2 => 1,
            3 => 2,
        ];

        // 순서 변경은 대상 첨부를 하나씩 찾아 범위와 올린 사람을 확인한다(맵의 키가 첨부 ID)
        $this->mockOwnedAttachmentsForReorder([1, 2, 3]);

        $this->repository
            ->shouldReceive('reorder')
            ->once()
            ->with($slug, $orders)
            ->andReturn(true);

        // Act
        $result = $this->service->reorder($slug, $orders);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * 순서 변경 훅이 발화한다.
     *
     * @return void 반환값 없음
     *
     * @effects attachment_lifecycle_hooks_fire
     */
    #[Test]
    public function test_reorder_fires_hooks(): void
    {
        // Arrange
        $slug = 'notice';
        $beforeReorderFired = false;
        $afterReorderFired = false;

        HookManager::addAction('sirsoft-board.attachment.before_reorder', function () use (&$beforeReorderFired) {
            $beforeReorderFired = true;
        });

        HookManager::addAction('sirsoft-board.attachment.after_reorder', function () use (&$afterReorderFired) {
            $afterReorderFired = true;
        });

        $this->mockOwnedAttachmentsForReorder([1, 2]);
        $this->repository->shouldReceive('reorder')->andReturn(true);

        // Act
        $this->service->reorder($slug, [1 => 1, 2 => 2]);

        // Assert
        $this->assertTrue($beforeReorderFired, 'before_reorder hook should be fired');
        $this->assertTrue($afterReorderFired, 'after_reorder hook should be fired');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-board.attachment.before_reorder');
        HookManager::clearAction('sirsoft-board.attachment.after_reorder');
    }

    /**
     * 다운로드는 저장 파일을 스트림 응답으로 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @effects download_streams_stored_file
     */
    #[Test]
    public function test_download_returns_streamed_response(): void
    {
        // Arrange
        $attachment = new Attachment([
            'id' => 1,
            'path' => 'notice/2025/01/21/test.pdf',
            'original_filename' => 'document.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->repository->shouldReceive('findById')
            ->once()
            ->with('notice', 1)
            ->andReturn($attachment);

        $expectedResponse = new StreamedResponse;

        $this->storage->shouldReceive('response')
            ->once()
            ->withArgs(function ($category, $path, $filename, $headers) {
                return $category === 'attachments'
                    && $path === 'notice/2025/01/21/test.pdf'
                    && $filename === 'document.pdf'
                    && $headers['Content-Type'] === 'application/pdf'
                    && str_contains($headers['Content-Disposition'], 'attachment')
                    && str_contains($headers['Content-Disposition'], 'filename="document.pdf"')
                    && str_contains($headers['Content-Disposition'], "filename*=UTF-8''document.pdf");
            })
            ->andReturn($expectedResponse);

        // Act
        $result = $this->service->download('notice', 1);

        // Assert
        $this->assertInstanceOf(StreamedResponse::class, $result);
    }

    /**
     * 첨부가 없으면 다운로드는 null 을 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @effects download_streams_stored_file
     */
    #[Test]
    public function test_download_returns_null_when_not_found(): void
    {
        // Arrange
        $this->repository->shouldReceive('findById')
            ->once()
            ->with('notice', 999)
            ->andReturn(null);

        // Act
        $result = $this->service->download('notice', 999);

        // Assert
        $this->assertNull($result);
    }

    /**
     * 한글 파일명도 다운로드 응답에 그대로 실린다.
     *
     * @return void 반환값 없음
     *
     * @effects download_streams_stored_file
     */
    #[Test]
    public function test_download_with_korean_filename(): void
    {
        // Arrange
        $attachment = new Attachment([
            'id' => 1,
            'path' => 'notice/2025/01/21/test.pdf',
            'original_filename' => '문서파일.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->repository->shouldReceive('findById')
            ->once()
            ->with('notice', 1)
            ->andReturn($attachment);

        $expectedResponse = new StreamedResponse;

        $this->storage->shouldReceive('response')
            ->once()
            ->withArgs(function ($category, $path, $filename, $headers) {
                $expectedEncoded = rawurlencode('문서파일.pdf');

                return $category === 'attachments'
                    && $path === 'notice/2025/01/21/test.pdf'
                    && $filename === '문서파일.pdf'
                    && $headers['Content-Type'] === 'application/pdf'
                    && str_contains($headers['Content-Disposition'], 'attachment')
                    && str_contains($headers['Content-Disposition'], 'filename="문서파일.pdf"')
                    && str_contains($headers['Content-Disposition'], "filename*=UTF-8''{$expectedEncoded}");
            })
            ->andReturn($expectedResponse);

        // Act
        $result = $this->service->download('notice', 1);

        // Assert
        $this->assertInstanceOf(StreamedResponse::class, $result);
    }

    /**
     * 다운로드 후 훅이 발화한다.
     *
     * @return void 반환값 없음
     *
     * @effects attachment_lifecycle_hooks_fire
     */
    #[Test]
    public function test_download_fires_after_download_hook(): void
    {
        // Arrange (#413-58: 다운로드 시 활동이력 기록을 위한 after_download 훅 발화)
        $afterDownloadFired = false;
        $firedAttachment = null;
        $firedContext = null;

        HookManager::addAction(
            'sirsoft-board.attachment.after_download',
            function ($attachment, $context = null) use (&$afterDownloadFired, &$firedAttachment, &$firedContext) {
                $afterDownloadFired = true;
                $firedAttachment = $attachment;
                $firedContext = $context;
            }
        );

        $attachment = new Attachment([
            'id' => 1,
            'path' => 'notice/2025/01/21/test.pdf',
            'original_filename' => 'document.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->repository->shouldReceive('findById')
            ->once()
            ->with('notice', 1)
            ->andReturn($attachment);

        $this->storage->shouldReceive('response')
            ->once()
            ->andReturn(new StreamedResponse);

        // Act (user 컨텍스트)
        $this->service->download('notice', 1, context: 'user');

        // Assert
        $this->assertTrue($afterDownloadFired, 'after_download hook should be fired');
        $this->assertSame($attachment, $firedAttachment, 'fired attachment should be the downloaded one');
        $this->assertSame('user', $firedContext, 'context should be passed to the hook');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-board.attachment.after_download');
    }

    /**
     * 다운로드 훅에 관리자 여부가 함께 전달된다.
     *
     * @return void 반환값 없음
     *
     * @effects attachment_lifecycle_hooks_fire
     */
    #[Test]
    public function test_download_passes_admin_context_to_hook(): void
    {
        // Arrange (#413-58: admin 진입점은 context 기본값 'admin' 으로 호출)
        $firedContext = null;

        HookManager::addAction(
            'sirsoft-board.attachment.after_download',
            function ($attachment, $context = null) use (&$firedContext) {
                $firedContext = $context;
            }
        );

        $attachment = new Attachment([
            'id' => 1,
            'path' => 'notice/2025/01/21/test.pdf',
            'original_filename' => 'document.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->repository->shouldReceive('findById')
            ->once()
            ->with('notice', 1)
            ->andReturn($attachment);

        $this->storage->shouldReceive('response')
            ->once()
            ->andReturn(new StreamedResponse);

        // Act (admin 컨텍스트 기본값)
        $this->service->download('notice', 1);

        // Assert
        $this->assertSame('admin', $firedContext, 'admin download should pass admin context');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-board.attachment.after_download');
    }

    /**
     * 첨부가 없으면 다운로드 훅을 발화하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects attachment_lifecycle_hooks_fire
     */
    #[Test]
    public function test_download_does_not_fire_hook_when_not_found(): void
    {
        // Arrange (#413-58: 첨부 미존재 시 훅 미발화 — 다운로드 실패는 이력 미기록)
        $afterDownloadFired = false;

        HookManager::addAction(
            'sirsoft-board.attachment.after_download',
            function () use (&$afterDownloadFired) {
                $afterDownloadFired = true;
            }
        );

        $this->repository->shouldReceive('findById')
            ->once()
            ->with('notice', 999)
            ->andReturn(null);

        // Act
        $result = $this->service->download('notice', 999, context: 'user');

        // Assert
        $this->assertNull($result);
        $this->assertFalse($afterDownloadFired, 'hook should not fire when attachment not found');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-board.attachment.after_download');
    }

    /**
     * 업로드 응답의 url 칸이 게이트가 살아 있는 서빙 URL 이어야 합니다.
     *
     * 이전에는 비공개 디스크에서 항상 null 이 나가 응답의 url 칸이 늘 비어 있었다.
     * 게시판 첨부는 비밀글·삭제글 게이트가 걸려 있으므로 직접 URL 로 바꾸지 않고,
     * 그 게이트를 통과하는 다운로드 서빙 URL 로 채운다.
     *
     * @return void 반환값 없음
     *
     * @effects board_upload_response_url_uses_gated_route, download_url_falls_back_to_api_path_when_direct_unavailable
     */
    #[Test]
    public function test_get_url_returns_gated_serving_url(): void
    {
        // Arrange
        $attachment = new Attachment([
            'path' => 'notice/2025/01/21/test.jpg',
        ]);
        $attachment->hash = 'abc123def456';
        $attachment->setRelation('board', new Board(['slug' => 'notice']));

        $this->repository->shouldReceive('findById')
            ->once()
            ->with('notice', 1)
            ->andReturn($attachment);

        // 직접 URL 은 시도조차 하지 않는다 (게이트 우회 차단)
        $this->storage->shouldNotReceive('url');

        // Act
        $result = $this->service->getUrl('notice', 1);

        // Assert
        $this->assertSame(
            '/api/modules/sirsoft-board/boards/notice/attachment/abc123def456',
            $result
        );
    }

    /**
     * 첨부가 없으면 null 이어야 합니다 (기존 계약 유지).
     *
     * @return void 반환값 없음
     *
     * @effects url_null_when_attachment_missing
     */
    #[Test]
    public function test_get_url_returns_null_when_attachment_missing(): void
    {
        $this->repository->shouldReceive('findById')
            ->once()
            ->with('notice', 99)
            ->andReturnNull();

        $this->assertNull($this->service->getUrl('notice', 99));
    }

    /**
     * 순서 변경 대상 첨부를 현재 사용자가 올린 글 첨부로 돌려주도록 저장소를 설정합니다.
     *
     * @param  array<int, int>  $ids  첨부 ID 목록
     * @return void 반환값 없음
     */
    private function mockOwnedAttachmentsForReorder(array $ids): void
    {
        foreach ($ids as $id) {
            $attachment = new Attachment(['post_id' => 1, 'path' => "notice/file-{$id}.pdf", 'collection' => 'attachments']);
            $attachment->id = $id;
            $attachment->created_by = $this->user->id;

            $this->repository->shouldReceive('findById')->with('notice', $id)->andReturn($attachment);
        }
    }
}
