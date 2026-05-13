<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\User;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\AttachmentRepository;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * AttachmentRepository 통합 테스트
 *
 * 임시 업로드(board_id=0) → 게시글 연결(실제 board_id) 흐름을 검증합니다.
 */
class AttachmentRepositoryTest extends ModuleTestCase
{
    private AttachmentRepository $repository;

    private User $user;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        // Telescope 비활성화
        config(['telescope.enabled' => false]);

        $this->repository = new AttachmentRepository;
        $this->user = User::factory()->create();

        $this->board = Board::firstOrCreate(
            ['slug' => 'attach-repo-test'],
            [
                'name' => ['ko' => '첨부파일 레포지토리 테스트 게시판'],
                'type' => 'list',
                'per_page' => 20,
                'per_page_mobile' => 10,
                'order_by' => 'created_at',
                'order_direction' => 'DESC',
                'secret_mode' => 'disabled',
                'use_comment' => true,
                'use_reply' => false,
                'use_file_upload' => true,
                'permissions' => [],
                'notify_admin_on_post' => false,
                'notify_author_on_comment' => false,
            ]
        );
    }

    /**
     * 임시 첨부파일 레코드를 생성합니다 (board_id=0).
     *
     * @param  array  $overrides  오버라이드할 속성
     * @return Attachment 생성된 첨부파일
     */
    private function createTempAttachment(array $overrides = []): Attachment
    {
        return Attachment::create(array_merge([
            'board_id' => 0,
            'post_id' => null,
            'temp_key' => 'test-temp-key',
            'original_filename' => 'test-file.pdf',
            'stored_filename' => 'uuid-test.pdf',
            'disk' => 'local',
            'path' => 'attach-repo-test/temp/test-temp-key/uuid-test.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $this->user->id,
        ], $overrides));
    }

    #[Test]
    public function test_link_attachments_by_ids_moves_temp_to_real_board_id(): void
    {
        // Given: board_id=0인 임시 첨부파일 2개 생성
        $attachment1 = $this->createTempAttachment([
            'original_filename' => 'file1.pdf',
            'stored_filename' => 'uuid1.pdf',
            'order' => 1,
        ]);
        $attachment2 = $this->createTempAttachment([
            'original_filename' => 'file2.pdf',
            'stored_filename' => 'uuid2.pdf',
            'order' => 2,
        ]);

        $postId = 100;

        // When: linkAttachmentsByIds 호출
        $linkedCount = $this->repository->linkAttachmentsByIds(
            'attach-repo-test',
            [$attachment1->id, $attachment2->id],
            $postId
        );

        // Then: 2개 연결됨
        $this->assertEquals(2, $linkedCount);

        // board_id가 실제 게시판 ID로 변경되었는지 확인
        $updated1 = Attachment::find($attachment1->id);
        $updated2 = Attachment::find($attachment2->id);

        $this->assertEquals($this->board->id, $updated1->board_id);
        $this->assertEquals($postId, $updated1->post_id);
        $this->assertNull($updated1->temp_key);

        $this->assertEquals($this->board->id, $updated2->board_id);
        $this->assertEquals($postId, $updated2->post_id);
        $this->assertNull($updated2->temp_key);
    }

    #[Test]
    public function test_link_attachments_by_ids_with_empty_array_returns_zero(): void
    {
        // When: 빈 배열로 호출
        $result = $this->repository->linkAttachmentsByIds('attach-repo-test', [], 1);

        // Then: 0 반환
        $this->assertEquals(0, $result);
    }

    #[Test]
    public function test_link_attachments_by_ids_ignores_already_linked_attachments(): void
    {
        // Given: 이미 게시글에 연결된 첨부파일 (board_id != 0)
        $linkedAttachment = Attachment::create([
            'board_id' => $this->board->id,
            'post_id' => 50,
            'temp_key' => null,
            'original_filename' => 'already-linked.pdf',
            'stored_filename' => 'uuid-linked.pdf',
            'disk' => 'local',
            'path' => 'attach-repo-test/2026/03/03/uuid-linked.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $this->user->id,
        ]);

        // When: 이미 연결된 첨부파일 ID로 linkAttachmentsByIds 호출
        $result = $this->repository->linkAttachmentsByIds(
            'attach-repo-test',
            [$linkedAttachment->id],
            100
        );

        // Then: board_id=0이 아니므로 0개 연결
        $this->assertEquals(0, $result);

        // 기존 연결 상태 유지 확인
        $unchanged = Attachment::find($linkedAttachment->id);
        $this->assertEquals(50, $unchanged->post_id);
        $this->assertEquals($this->board->id, $unchanged->board_id);
    }

    #[Test]
    public function test_link_temp_attachments_moves_temp_to_real_board_id(): void
    {
        // Given: board_id=0인 임시 첨부파일 (temp_key 기반)
        $tempKey = 'temp-link-test';
        $attachment = $this->createTempAttachment([
            'temp_key' => $tempKey,
            'original_filename' => 'temp-file.pdf',
        ]);

        $postId = 200;

        // When: linkTempAttachments 호출
        $linkedCount = $this->repository->linkTempAttachments(
            'attach-repo-test',
            $tempKey,
            $postId
        );

        // Then: 1개 연결됨
        $this->assertEquals(1, $linkedCount);

        // board_id가 실제 게시판 ID로 변경되었는지 확인
        $updated = Attachment::find($attachment->id);
        $this->assertEquals($this->board->id, $updated->board_id);
        $this->assertEquals($postId, $updated->post_id);
        $this->assertNull($updated->temp_key);
    }

    #[Test]
    public function test_linked_attachments_are_queryable_by_board_id(): void
    {
        // Given: 임시 첨부파일 생성 후 게시글에 연결 (고유 postId로 격리)
        $postId = 300000 + random_int(1, 99999);
        $attachment = $this->createTempAttachment([
            'original_filename' => 'queryable-file.pdf',
            'stored_filename' => 'uuid-queryable.pdf',
        ]);

        $this->repository->linkAttachmentsByIds(
            'attach-repo-test',
            [$attachment->id],
            $postId
        );

        // When: 게시판 slug + postId로 조회
        $result = $this->repository->getByPost('attach-repo-test', $postId);

        // Then: 연결된 첨부파일이 조회됨
        $this->assertCount(1, $result);
        $this->assertEquals($attachment->id, $result->first()->id);
        $this->assertEquals($this->board->id, $result->first()->board_id);
    }

    #[Test]
    public function test_get_by_temp_key_returns_temp_attachments(): void
    {
        // Given: 고유 temp_key로 임시 첨부파일 2개 생성 (테스트 격리)
        $tempKey = 'get-temp-test-' . uniqid();
        $this->createTempAttachment([
            'temp_key' => $tempKey,
            'original_filename' => 'temp1.pdf',
            'stored_filename' => 'uuid-t1.pdf',
            'order' => 1,
        ]);
        $this->createTempAttachment([
            'temp_key' => $tempKey,
            'original_filename' => 'temp2.pdf',
            'stored_filename' => 'uuid-t2.pdf',
            'order' => 2,
        ]);

        // When: temp_key로 조회
        $result = $this->repository->getByTempKey('attach-repo-test', $tempKey);

        // Then: 2개 조회됨
        $this->assertCount(2, $result);
    }

    #[Test]
    public function test_get_max_order_by_temp_key_returns_correct_value(): void
    {
        // Given: 임시 첨부파일 2개 (order 1, 3)
        $tempKey = 'max-order-test';
        $this->createTempAttachment([
            'temp_key' => $tempKey,
            'order' => 1,
        ]);
        $this->createTempAttachment([
            'temp_key' => $tempKey,
            'order' => 3,
        ]);

        // When: 최대 order 조회
        $maxOrder = $this->repository->getMaxOrderByTempKey('attach-repo-test', $tempKey, 'attachments');

        // Then: 3 반환
        $this->assertEquals(3, $maxOrder);
    }

    #[Test]
    public function test_get_max_order_by_temp_key_returns_zero_for_null_key(): void
    {
        // When: null temp_key로 조회
        $maxOrder = $this->repository->getMaxOrderByTempKey('attach-repo-test', null, 'attachments');

        // Then: 0 반환
        $this->assertEquals(0, $maxOrder);
    }
}
