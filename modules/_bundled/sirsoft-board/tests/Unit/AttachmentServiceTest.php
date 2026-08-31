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

        $expectedResponse = new StreamedResponse;

        // 파일 이름 선언은 **스토리지 단일 지점**이 만든다 — 원문을 헤더로 넘기면
        // 어댑터가 자기 `makeDisposition()` 을 통째로 건너뛴다(따옴표가 든 이름에서
        // 헤더 구조가 깨진다). 형제 `preview()` 는 8차에 위임됐고 코어 형제도 헤더를
        // 넘기지 않는다 (10차 G3 라운드 B10-2).
        $this->storage->shouldReceive('getDisk')->andReturn('local');
        $this->storage->shouldReceive('download')
            ->once()
            ->withArgs(function ($category, $path, $filename, $headers) {
                return $category === 'attachments'
                    && $path === 'notice/2025/01/21/test.pdf'
                    && $filename === 'document.pdf'
                    && $headers['Content-Type'] === 'application/pdf'
                    && ! array_key_exists('Content-Disposition', $headers);
            })
            ->andReturn($expectedResponse);

        // Act
        $result = $this->service->download('notice', 1);

        // Assert
        $this->assertInstanceOf(StreamedResponse::class, $result);
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

        $expectedResponse = new StreamedResponse;

        // RFC 5987 인코딩도 어댑터가 만든다 — 호출부가 손으로 만들면 그 정제 지점을
        // 건너뛴다 (10차 G3 라운드 B10-2).
        $this->storage->shouldReceive('getDisk')->andReturn('local');
        $this->storage->shouldReceive('download')
            ->once()
            ->withArgs(function ($category, $path, $filename, $headers) {
                return $category === 'attachments'
                    && $path === 'notice/2025/01/21/test.pdf'
                    && $filename === '문서파일.pdf'
                    && $headers['Content-Type'] === 'application/pdf'
                    && ! array_key_exists('Content-Disposition', $headers);
            })
            ->andReturn($expectedResponse);

        // Act
        $result = $this->service->download('notice', 1);

        // Assert
        $this->assertInstanceOf(StreamedResponse::class, $result);
    }

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

        $this->storage->shouldReceive('getDisk')->andReturn('local');
        $this->storage->shouldReceive('download')
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

        $this->storage->shouldReceive('getDisk')->andReturn('local');
        $this->storage->shouldReceive('download')
            ->once()
            ->andReturn(new StreamedResponse);

        // Act (admin 컨텍스트 기본값)
        $this->service->download('notice', 1);

        // Assert
        $this->assertSame('admin', $firedContext, 'admin download should pass admin context');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-board.attachment.after_download');
    }

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

    /**
     * URL 조립도 **행에 기록된 disk** 를 따른다.
     *
     * 같은 클래스의 서빙 4지점·정리 2지점은 이미 `storageForRow()` 를 지나는데 이
     * 메서드만 카테고리 기본 디스크로 갔다 — 디스크를 옮긴 사이트에서 이 값만 다른
     * 디스크의 주소를 가리키고, 오류도 로그도 남지 않는다 (11차 G3 라운드 B11-8).
     */
    #[Test]
    public function test_get_url_uses_the_disk_recorded_on_the_row(): void
    {
        $this->registerRowDisk();

        $attachment = new Attachment([
            'id' => 1,
            'disk' => self::ROW_DISK,
            'path' => 'notice/2025/01/21/test.jpg',
        ]);

        $this->repository->shouldReceive('findById')
            ->once()
            ->with('notice', 1)
            ->andReturn($attachment);

        $this->storage->shouldReceive('getDisk')->andReturn('modules');

        $rowStorage = Mockery::mock(StorageInterface::class);
        $rowStorage->shouldReceive('url')
            ->once()
            ->with('attachments', 'notice/2025/01/21/test.jpg')
            ->andReturn('https://cdn.test/assets/notice/2025/01/21/test.jpg');

        $this->storage->shouldReceive('withDisk')
            ->once()
            ->with(self::ROW_DISK)
            ->andReturn($rowStorage);

        // 주입 스토리지로 새면 그 mock 의 `url` 이 「예상치 못한 호출」로 실패한다.
        $this->assertSame(
            'https://cdn.test/assets/notice/2025/01/21/test.jpg',
            $this->service->getUrl('notice', 1)
        );
    }

    /**
     * 임시 첨부 연결의 파일 이동도 **행에 기록된 disk** 를 따른다.
     *
     * 디스크를 옮긴 사이트에서는 주입 디스크에 그 파일이 없으므로 읽기가 항상 실패한다
     * (11차 G3 라운드 B11-9).
     */
    #[Test]
    public function test_link_temp_attachments_moves_files_on_the_row_disk(): void
    {
        $this->registerRowDisk();

        $slug = 'notice';
        $tempKey = 'temp-row-disk';

        $attachment = Mockery::mock(Attachment::class)->makePartial();
        $attachment->id = 1;
        $attachment->disk = self::ROW_DISK;
        $attachment->path = "{$slug}/temp/{$tempKey}/uuid1.jpg";
        $attachment->stored_filename = 'uuid1.jpg';
        $attachment->shouldReceive('update')->once()->andReturn(true);

        $this->repository->shouldReceive('getByTempKey')
            ->once()
            ->with($slug, $tempKey)
            ->andReturn(new EloquentCollection([$attachment]));

        $this->storage->shouldReceive('getDisk')->andReturn('modules');

        $rowStorage = Mockery::mock(StorageInterface::class);
        $rowStorage->shouldReceive('get')->once()->andReturn('file-content');
        $rowStorage->shouldReceive('put')->once()->andReturn(true);
        $rowStorage->shouldReceive('delete')->once()->andReturn(true);

        $this->storage->shouldReceive('withDisk')
            ->with(self::ROW_DISK)
            ->andReturn($rowStorage);

        // 임시 디렉토리 정리는 종전 그대로 주입 스토리지가 수행한다 (동작 불변).
        $this->storage->shouldReceive('deleteDirectory')->once()->andReturn(true);

        $this->assertSame(1, $this->service->linkTempAttachmentsWithMove($slug, $tempKey, 5));
    }

    /**
     * 파일을 읽지 못하면 연결을 **건너뛰고** DB `path` 를 갱신하지 않는다.
     *
     * 종전에는 `if ($content)` 라 읽기 실패(null)와 0 바이트 파일이 모두 falsy 로 접혀
     * 이동을 건너뛰면서 DB `path` 는 갱신됐다 — 행은 최종 경로를 가리키는데 파일은
     * 임시 경로에 남거나 아예 없어 첨부가 오류도 로그도 없이 영구 유실된다.
     * 여기서는 `update` 가 **불리지 않는 것**이 판정이다 (11차 G3 라운드 B11-9).
     */
    #[Test]
    public function test_link_temp_attachments_skips_the_row_when_the_file_is_unreadable(): void
    {
        $slug = 'notice';
        $tempKey = 'temp-unreadable';

        $attachment = Mockery::mock(Attachment::class)->makePartial();
        $attachment->id = 1;
        $attachment->path = "{$slug}/temp/{$tempKey}/gone.jpg";
        $attachment->stored_filename = 'gone.jpg';
        $attachment->shouldReceive('update')->never();

        $this->repository->shouldReceive('getByTempKey')
            ->once()
            ->with($slug, $tempKey)
            ->andReturn(new EloquentCollection([$attachment]));

        // 읽기 실패 — 이동은 일어나지 않아야 한다.
        $this->storage->shouldReceive('get')->once()->andReturn(null);
        $this->storage->shouldReceive('put')->never();
        $this->storage->shouldReceive('delete')->never();
        $this->storage->shouldReceive('deleteDirectory')->once()->andReturn(true);

        $this->assertSame(0, $this->service->linkTempAttachmentsWithMove($slug, $tempKey, 5));
    }

    /**
     * 0 바이트 파일은 정상 이동한다 (반대 방향 — 회귀 0 확인).
     *
     * 종전 `if ($content)` 는 빈 문자열도 falsy 로 접어 정상 업로드물을 조용히 버렸다.
     */
    #[Test]
    public function test_link_temp_attachments_moves_zero_byte_files(): void
    {
        $slug = 'notice';
        $tempKey = 'temp-zero-byte';

        $attachment = Mockery::mock(Attachment::class)->makePartial();
        $attachment->id = 1;
        $attachment->path = "{$slug}/temp/{$tempKey}/empty.txt";
        $attachment->stored_filename = 'empty.txt';
        $attachment->shouldReceive('update')->once()->andReturn(true);

        $this->repository->shouldReceive('getByTempKey')
            ->once()
            ->with($slug, $tempKey)
            ->andReturn(new EloquentCollection([$attachment]));

        $this->storage->shouldReceive('get')->once()->andReturn('');
        $this->storage->shouldReceive('put')->once()->andReturn(true);
        $this->storage->shouldReceive('delete')->once()->andReturn(true);
        $this->storage->shouldReceive('deleteDirectory')->once()->andReturn(true);

        $this->assertSame(1, $this->service->linkTempAttachmentsWithMove($slug, $tempKey, 5));
    }

    /** 행에만 기록된, 주입 스토리지와 다른 디스크. */
    private const ROW_DISK = 'fake_cdn';

    /**
     * 행 disk 를 config 에 등록합니다 (미등록이면 고아 disk 폴백에 걸린다).
     */
    private function registerRowDisk(): void
    {
        config([
            'filesystems.disks.'.self::ROW_DISK => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/fake_cdn'),
            ],
        ]);
    }
}
