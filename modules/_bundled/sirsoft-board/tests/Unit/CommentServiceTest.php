<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use Mockery;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\CommentService;
use Tests\TestCase;

/**
 * CommentService 단위 테스트
 *
 * 블라인드/삭제 상태 게시글에 대한 댓글 작성 제한 검증
 */
class CommentServiceTest extends TestCase
{
    private CommentService $service;

    /** @var \Mockery\MockInterface&BoardRepositoryInterface */
    private $boardRepository;

    /** @var \Mockery\MockInterface&CommentRepositoryInterface */
    private $commentRepository;

    /** @var \Mockery\MockInterface&PostRepositoryInterface */
    private $postRepository;

    private string $slug = 'test_board';

    protected function setUp(): void
    {
        parent::setUp();

        // Telescope 비활성화 (테스트 환경)
        config(['telescope.enabled' => false]);

        // Mock Repository 생성
        $this->boardRepository = Mockery::mock(BoardRepositoryInterface::class);
        $this->commentRepository = Mockery::mock(CommentRepositoryInterface::class);
        $this->postRepository = Mockery::mock(PostRepositoryInterface::class);

        // boardRepository 기본 Mock: findBySlug 호출 시 Board Mock 반환 (Phase 8: createComment에서 board_id 조회)
        $mockBoard = Mockery::mock(Board::class)->makePartial();
        $mockBoard->id = 1;
        $this->boardRepository->shouldReceive('findBySlug')->andReturn($mockBoard);

        // CommentService 생성 (#252: CacheInterface 추가)
        $this->service = new CommentService(
            $this->boardRepository,
            $this->commentRepository,
            $this->postRepository,
            Mockery::mock(CacheInterface::class)->shouldIgnoreMissing()
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 블라인드 게시글에 댓글 작성 시 예외 발생 테스트
     */
    public function test_validate_post_for_comment_throws_exception_when_post_is_blinded(): void
    {
        // Given: 블라인드 상태의 게시글 Mock
        $post = $this->createMockPost(PostStatus::Blinded);

        $this->postRepository->shouldReceive('findOrFail')
            ->with($this->slug, 1)
            ->once()
            ->andReturn($post);

        // Then: 예외 발생 기대
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('sirsoft-board::messages.comment.post_blinded'));

        // When: 블라인드 게시글에 댓글 작성 시도
        $this->service->validatePostForComment($this->slug, 1);
    }

    /**
     * 삭제된 게시글에 댓글 작성 시 예외 발생 테스트
     */
    public function test_validate_post_for_comment_throws_exception_when_post_is_deleted(): void
    {
        // Given: 삭제 상태의 게시글 Mock
        $post = $this->createMockPost(PostStatus::Deleted);

        $this->postRepository->shouldReceive('findOrFail')
            ->with($this->slug, 1)
            ->once()
            ->andReturn($post);

        // Then: 예외 발생 기대
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('sirsoft-board::messages.comment.post_deleted'));

        // When: 삭제된 게시글에 댓글 작성 시도
        $this->service->validatePostForComment($this->slug, 1);
    }

    /**
     * soft-deleted 게시글에 댓글 작성 시 예외 발생 테스트
     */
    public function test_validate_post_for_comment_throws_exception_when_post_is_soft_deleted(): void
    {
        // Given: soft-deleted 상태의 게시글 (status는 published이지만 deleted_at이 설정됨)
        $post = $this->createMockPost(PostStatus::Published, now()->toDateTimeString());

        $this->postRepository->shouldReceive('findOrFail')
            ->with($this->slug, 1)
            ->once()
            ->andReturn($post);

        // Then: 예외 발생 기대
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('sirsoft-board::messages.comment.post_deleted'));

        // When: soft-deleted 게시글에 댓글 작성 시도
        $this->service->validatePostForComment($this->slug, 1);
    }

    /**
     * 정상 게시글에 댓글 작성 가능 테스트
     */
    public function test_validate_post_for_comment_passes_when_post_is_published(): void
    {
        // Given: 정상 상태의 게시글 Mock
        $post = $this->createMockPost(PostStatus::Published);

        $this->postRepository->shouldReceive('findOrFail')
            ->with($this->slug, 1)
            ->once()
            ->andReturn($post);

        // When: 정상 게시글에 댓글 작성 시도
        $result = $this->service->validatePostForComment($this->slug, 1);

        // Then: true 반환
        $this->assertTrue($result);
    }

    /**
     * 블라인드 게시글에 createComment 호출 시 예외 발생 테스트
     */
    public function test_create_comment_throws_exception_when_post_is_blinded(): void
    {
        // Given: 블라인드 상태의 게시글 Mock
        $post = $this->createMockPost(PostStatus::Blinded);

        $this->postRepository->shouldReceive('findOrFail')
            ->with($this->slug, 1)
            ->once()
            ->andReturn($post);

        // CommentRepository create가 호출되지 않아야 함
        $this->commentRepository->shouldNotReceive('create');

        // Then: 예외 발생 기대
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('sirsoft-board::messages.comment.post_blinded'));

        // When: 블라인드 게시글에 댓글 생성 시도
        $this->service->createComment($this->slug, [
            'post_id' => 1,
            'content' => '테스트 댓글',
            'user_id' => 1,
            'author_name' => '테스트 유저',
            'ip_address' => '127.0.0.1',
        ]);
    }

    /**
     * 삭제된 게시글에 createComment 호출 시 예외 발생 테스트
     */
    public function test_create_comment_throws_exception_when_post_is_deleted(): void
    {
        // Given: 삭제 상태의 게시글 Mock
        $post = $this->createMockPost(PostStatus::Deleted);

        $this->postRepository->shouldReceive('findOrFail')
            ->with($this->slug, 1)
            ->once()
            ->andReturn($post);

        // CommentRepository create가 호출되지 않아야 함
        $this->commentRepository->shouldNotReceive('create');

        // Then: 예외 발생 기대
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('sirsoft-board::messages.comment.post_deleted'));

        // When: 삭제된 게시글에 댓글 생성 시도
        $this->service->createComment($this->slug, [
            'post_id' => 1,
            'content' => '테스트 댓글',
            'user_id' => 1,
            'author_name' => '테스트 유저',
            'ip_address' => '127.0.0.1',
        ]);
    }

    /**
     * 정상 게시글에 댓글 생성 성공 테스트
     */
    public function test_create_comment_succeeds_when_post_is_published(): void
    {
        // 훅 리스너(BoardNotificationDataListener)가 Board 테이블을 조회하는 것을 방지 (순수 단위 테스트)
        HookManager::clearAction('sirsoft-board.comment.before_create');
        HookManager::clearAction('sirsoft-board.comment.after_create');
        HookManager::clearFilter('sirsoft-board.comment.filter_create_data');

        // Given: 정상 상태의 게시글 Mock
        $post = $this->createMockPost(PostStatus::Published);

        $this->postRepository->shouldReceive('findOrFail')
            ->with($this->slug, 1)
            ->once()
            ->andReturn($post);

        $mockComment = new Comment;
        $mockComment->id = 1;
        $mockComment->content = '테스트 댓글';

        $this->commentRepository->shouldReceive('create')
            ->once()
            ->andReturn($mockComment);

        // When: 정상 게시글에 댓글 생성
        $result = $this->service->createComment($this->slug, [
            'post_id' => 1,
            'content' => '테스트 댓글',
            'user_id' => 1,
            'author_name' => '테스트 유저',
            'ip_address' => '127.0.0.1',
        ]);

        // Then: 댓글 생성 성공
        $this->assertInstanceOf(Comment::class, $result);
        $this->assertEquals('테스트 댓글', $result->content);
    }

    /**
     * 블라인드 게시글에 답글 작성 시 예외 발생 테스트
     */
    public function test_create_reply_throws_exception_when_post_is_blinded(): void
    {
        // Given: 블라인드 상태의 게시글 Mock
        $post = $this->createMockPost(PostStatus::Blinded);

        $this->postRepository->shouldReceive('findOrFail')
            ->with($this->slug, 1)
            ->once()
            ->andReturn($post);

        // CommentRepository의 find/create가 호출되지 않아야 함
        $this->commentRepository->shouldNotReceive('find');
        $this->commentRepository->shouldNotReceive('create');

        // Then: 예외 발생 기대
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('sirsoft-board::messages.comment.post_blinded'));

        // When: 블라인드 게시글에 답글 생성 시도
        $this->service->createComment($this->slug, [
            'post_id' => 1,
            'parent_id' => 1,
            'content' => '테스트 답글',
            'user_id' => 1,
            'author_name' => '테스트 유저',
            'ip_address' => '127.0.0.1',
        ]);
    }

    // =========================================================
    // canUpdate / canDelete 권한 검증 테스트
    // =========================================================

    /**
     * 비회원 댓글 — 비로그인 상태에서 올바른 비밀번호로 수정 허용
     */
    public function test_can_update_guest_comment_with_correct_password_as_guest(): void
    {
        $comment = $this->createMockComment(userId: null, password: password_hash('secret', PASSWORD_BCRYPT));

        $result = $this->service->canUpdate($comment, null, 'secret', null);

        $this->assertTrue($result);
    }

    /**
     * 비회원 댓글 — 비로그인 상태에서 틀린 비밀번호로 수정 거부
     */
    public function test_can_update_guest_comment_with_wrong_password_as_guest(): void
    {
        $comment = $this->createMockComment(userId: null, password: password_hash('secret', PASSWORD_BCRYPT));

        $result = $this->service->canUpdate($comment, null, 'wrong', null);

        $this->assertFalse($result);
    }

    /**
     * 비회원 댓글 — 로그인한 일반 회원이 올바른 비밀번호로 수정 허용
     */
    public function test_can_update_guest_comment_with_correct_password_as_logged_in_user(): void
    {
        $comment = $this->createMockComment(userId: null, password: password_hash('secret', PASSWORD_BCRYPT));

        $result = $this->service->canUpdate($comment, 99, 'secret', null);

        $this->assertTrue($result);
    }

    /**
     * 비회원 댓글 — 로그인한 일반 회원이 틀린 비밀번호로 수정 거부
     */
    public function test_can_update_guest_comment_with_wrong_password_as_logged_in_user(): void
    {
        $comment = $this->createMockComment(userId: null, password: password_hash('secret', PASSWORD_BCRYPT));

        $result = $this->service->canUpdate($comment, 99, 'wrong', null);

        $this->assertFalse($result);
    }

    /**
     * 회원 댓글 — 본인이 수정 허용
     */
    public function test_can_update_member_comment_as_author(): void
    {
        $comment = $this->createMockComment(userId: 1, password: null);

        $result = $this->service->canUpdate($comment, 1, null, null);

        $this->assertTrue($result);
    }

    /**
     * 회원 댓글 — 타인이 수정 거부
     */
    public function test_cannot_update_member_comment_as_other_user(): void
    {
        $comment = $this->createMockComment(userId: 1, password: null);

        $result = $this->service->canUpdate($comment, 2, null, null);

        $this->assertFalse($result);
    }

    /**
     * 비회원 댓글 — 비로그인 상태에서 올바른 비밀번호로 삭제 허용
     */
    public function test_can_delete_guest_comment_with_correct_password_as_guest(): void
    {
        $comment = $this->createMockComment(userId: null, password: password_hash('secret', PASSWORD_BCRYPT));

        $result = $this->service->canDelete($comment, null, 'secret', null);

        $this->assertTrue($result);
    }

    /**
     * 비회원 댓글 — 로그인한 일반 회원이 올바른 비밀번호로 삭제 허용
     */
    public function test_can_delete_guest_comment_with_correct_password_as_logged_in_user(): void
    {
        $comment = $this->createMockComment(userId: null, password: password_hash('secret', PASSWORD_BCRYPT));

        $result = $this->service->canDelete($comment, 99, 'secret', null);

        $this->assertTrue($result);
    }

    /**
     * 비회원 댓글 — 로그인한 일반 회원이 틀린 비밀번호로 삭제 거부
     */
    public function test_cannot_delete_guest_comment_with_wrong_password_as_logged_in_user(): void
    {
        $comment = $this->createMockComment(userId: null, password: password_hash('secret', PASSWORD_BCRYPT));

        $result = $this->service->canDelete($comment, 99, 'wrong', null);

        $this->assertFalse($result);
    }

    /**
     * Mock Comment 객체 생성 헬퍼
     *
     * @param  int|null  $userId  작성자 user_id (null이면 비회원)
     * @param  string|null  $password  해시된 비밀번호
     * @return \Mockery\MockInterface&Comment Mock 댓글 객체
     */
    private function createMockComment(?int $userId, ?string $password)
    {
        $comment = Mockery::mock(Comment::class)->makePartial();
        $comment->user_id = $userId;
        $comment->password = $password;

        return $comment;
    }

    /**
     * Mock Post 객체 생성 헬퍼
     *
     * @param  PostStatus  $status  게시글 상태
     * @param  string|null  $deletedAt  삭제 일시
     * @return \Mockery\MockInterface&Post Mock 게시글 객체
     */
    private function createMockPost(PostStatus $status, ?string $deletedAt = null)
    {
        $post = Mockery::mock(Post::class)->makePartial();
        $post->status = $status;
        $post->deleted_at = $deletedAt;

        return $post;
    }
}
