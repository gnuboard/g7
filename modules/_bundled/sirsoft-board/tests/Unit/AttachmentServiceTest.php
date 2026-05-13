<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * AttachmentService 단위 테스트
 *
 * StorageInterface 기반 파일 업로드, 삭제 등을 테스트합니다.
 */
class AttachmentServiceTest extends ModuleTestCase
{

    private AttachmentService $service;

    /** @var \Mockery\MockInterface&AttachmentRepositoryInterface */
    private $repository;

    /** @var \Mockery\MockInterface&BoardRepositoryInterface */
    private $boardRepository;

    /** @var \Mockery\MockInterface&StorageInterface */
    private $storage;

    private User $user;

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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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

    #[Test]
    public function test_link_temp_attachments_with_move_handles_empty_temp_files(): void
    {
        // Arrange
        $slug = 'notice';
        $tempKey = 'temp-uuid-empty';
        $postId = 5;

        $this->repository
            ->shouldReceive('getByTempKey')
            ->once()
            ->with($slug, $tempKey)
            ->andReturn(new EloquentCollection([]));

        // 파일이 없어도 임시 디렉토리 정리는 실행
        $this->storage
            ->shouldReceive('deleteDirectory')
            ->once()
            ->with('attachments', "{$slug}/temp/{$tempKey}")
            ->andReturn(true);

        // Act
        $result = $this->service->linkTempAttachmentsWithMove($slug, $tempKey, $postId);

        // Assert
        $this->assertEquals(0, $result);
    }

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

    #[Test]
    public function test_link_temp_attachments_links_temp_files_to_post(): void
    {
        // Arrange
        $slug = 'notice';
        $tempKey = 'temp-uuid-123';
        $postId = 5;

        // Service 는 훅 발화를 위해 getByTempKey → linkTempAttachments → getById 순으로 호출
        // Repository 시그니처: Eloquent\Collection 반환 필수 (Support\Collection 불일치)
        $this->repository
            ->shouldReceive('getByTempKey')
            ->once()
            ->with($slug, $tempKey)
            ->andReturn(new \Illuminate\Database\Eloquent\Collection());

        $this->repository
            ->shouldReceive('linkTempAttachments')
            ->once()
            ->with($slug, $tempKey, $postId)
            ->andReturn(3);

        // Act
        $result = $this->service->linkTempAttachments($slug, $tempKey, $postId);

        // Assert
        $this->assertEquals(3, $result);
    }

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

        $expectedResponse = new \Symfony\Component\HttpFoundation\StreamedResponse;

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
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $result);
    }

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

        $expectedResponse = new \Symfony\Component\HttpFoundation\StreamedResponse;

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
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $result);
    }

    #[Test]
    public function test_get_url_returns_url(): void
    {
        // Arrange
        $attachment = new Attachment([
            'id' => 1,
            'path' => 'notice/2025/01/21/test.jpg',
        ]);

        $this->repository->shouldReceive('findById')
            ->once()
            ->with('notice', 1)
            ->andReturn($attachment);

        $this->storage->shouldReceive('url')
            ->once()
            ->with('attachments', 'notice/2025/01/21/test.jpg')
            ->andReturn('https://example.com/storage/modules/sirsoft-board/attachments/notice/2025/01/21/test.jpg');

        // Act
        $result = $this->service->getUrl('notice', 1);

        // Assert
        $this->assertEquals('https://example.com/storage/modules/sirsoft-board/attachments/notice/2025/01/21/test.jpg', $result);
    }
}
