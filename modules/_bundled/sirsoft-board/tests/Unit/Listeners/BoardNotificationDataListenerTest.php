<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Mockery;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Listeners\BoardNotificationDataListener;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\UserNotificationSetting;
use App\Contracts\Repositories\RoleRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\UserNotificationSettingService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 알림 데이터 리스너 단위 테스트
 *
 * BoardNotificationDataListener의 extractData()가
 * 7종 알림 타입별로 올바른 데이터/컨텍스트를 반환하는지 검증합니다.
 */
class BoardNotificationDataListenerTest extends ModuleTestCase
{
    private BoardNotificationDataListener $listener;

    private BoardRepositoryInterface $boardRepository;

    private PostRepositoryInterface $postRepository;

    private CommentRepositoryInterface $commentRepository;

    private RoleRepositoryInterface $roleRepository;

    /** @var \Mockery\MockInterface&UserNotificationSettingService */
    private $userNotificationSettingService;

    /**
     * 테스트 환경 설정
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->boardRepository = Mockery::mock(BoardRepositoryInterface::class);
        $this->postRepository = Mockery::mock(PostRepositoryInterface::class);
        $this->commentRepository = Mockery::mock(CommentRepositoryInterface::class);
        $this->roleRepository = Mockery::mock(RoleRepositoryInterface::class);
        $this->userNotificationSettingService = Mockery::mock(UserNotificationSettingService::class);

        // 기본: 사용자 알림 설정이 모두 켜진 상태로 Mock (정상 추출 테스트용)
        $defaultSettings = new UserNotificationSetting([
            'notify_comment' => true,
            'notify_reply_comment' => true,
            'notify_post_reply' => true,
            'notify_post_complete' => true,
        ]);
        $this->userNotificationSettingService->shouldReceive('getByUserId')->andReturn($defaultSettings)->byDefault();

        $this->listener = new BoardNotificationDataListener(
            $this->boardRepository,
            $this->postRepository,
            $this->commentRepository,
            $this->userNotificationSettingService,
            $this->roleRepository,
        );
    }

    // ── 훅 구독 등록 확인 ──

    /**
     * 구독할 훅 목록이 올바르게 정의되어 있는지 확인합니다.
     */
    #[Test]
    public function test_getSubscribedHooks_올바른_훅_구독(): void
    {
        $hooks = BoardNotificationDataListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-board.notification.extract_data', $hooks);
        $this->assertEquals('extractData', $hooks['sirsoft-board.notification.extract_data']['method']);
        $this->assertEquals(20, $hooks['sirsoft-board.notification.extract_data']['priority']);
        $this->assertEquals('filter', $hooks['sirsoft-board.notification.extract_data']['type']);
    }

    /**
     * 지원하지 않는 타입은 기본값을 반환합니다.
     */
    #[Test]
    public function test_extractData_미지원_타입_기본값_반환(): void
    {
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => ['test' => true], 'context' => []];

        $result = $this->listener->extractData($default, 'unknown_type', []);

        $this->assertEquals($default, $result);
    }

    // ── new_comment ──

    /**
     * 새 댓글 알림 데이터를 올바르게 추출합니다.
     */
    #[Test]
    public function test_new_comment_정상_추출(): void
    {
        $postAuthor = $this->createMockUser(10, '게시글작성자');
        $commentAuthor = $this->createMockUser(20, '댓글작성자');

        $comment = $this->createMockComment([
            'id' => 1,
            'post_id' => 100,
            'parent_id' => null,
            'user_id' => 20,
            'content' => '테스트 댓글 내용',
            'user' => $commentAuthor,
            'author_name' => '댓글작성자',
        ]);

        $board = $this->createMockBoard('test-board', true);
        $post = $this->createMockPost(100, '테스트 게시글', 10, $postAuthor);

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);
        $this->postRepository->shouldReceive('find')->with('test-board', 100)->andReturn($post);

        $result = $this->listener->extractData([], 'new_comment', [$comment, 'test-board']);

        $this->assertNotEmpty($result['data']);
        $this->assertEquals('테스트 게시글', $result['data']['post_title']);
        $this->assertEquals('댓글작성자', $result['data']['comment_author']);
        $this->assertEquals(20, $result['context']['trigger_user_id']);
        $this->assertSame($commentAuthor, $result['context']['trigger_user']);
        $this->assertSame($postAuthor, $result['context']['related_users']['post_author']);
    }

    /**
     * 대댓글이면 new_comment에서 빈 결과를 반환합니다 (reply_comment 타입에서 처리).
     */
    #[Test]
    public function test_new_comment_대댓글이면_빈결과(): void
    {
        $comment = $this->createMockComment([
            'parent_id' => 5,
            'user_id' => 20,
        ]);

        $result = $this->listener->extractData([], 'new_comment', [$comment, 'test-board']);

        $this->assertEmptyResult($result);
    }

    /**
     * 게시판 알림 설정이 꺼져있으면 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_new_comment_알림OFF_빈결과(): void
    {
        $comment = $this->createMockComment(['parent_id' => null, 'user_id' => 20]);

        $board = $this->createMockBoard('test-board', false); // notify_author = false
        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);

        $result = $this->listener->extractData([], 'new_comment', [$comment, 'test-board']);

        $this->assertEmptyResult($result);
    }

    /**
     * 사용자 알림 설정 레코드가 없으면 빈 결과를 반환합니다 (기본값: 미수신).
     */
    #[Test]
    public function test_new_comment_설정레코드없음_빈결과(): void
    {
        $postAuthor = $this->createMockUser(10, '게시글작성자');
        $commentAuthor = $this->createMockUser(20, '댓글작성자');

        $comment = $this->createMockComment([
            'post_id' => 100,
            'parent_id' => null,
            'user_id' => 20,
            'content' => '댓글 내용',
            'user' => $commentAuthor,
        ]);

        $board = $this->createMockBoard('test-board', true);
        $post = $this->createMockPost(100, '테스트 게시글', 10, $postAuthor);

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);
        $this->postRepository->shouldReceive('find')->with('test-board', 100)->andReturn($post);

        // 설정 레코드 없음 → 기본값 미수신
        $this->userNotificationSettingService->shouldReceive('getByUserId')->with(10)->andReturn(null);

        $result = $this->listener->extractData([], 'new_comment', [$comment, 'test-board']);

        $this->assertEmptyResult($result);
    }

    /**
     * 사용자 개인 알림 설정에서 댓글 알림이 꺼져있으면 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_new_comment_사용자설정OFF_빈결과(): void
    {
        $postAuthor = $this->createMockUser(10, '게시글작성자');
        $commentAuthor = $this->createMockUser(20, '댓글작성자');

        $comment = $this->createMockComment([
            'post_id' => 100,
            'parent_id' => null,
            'user_id' => 20,
            'content' => '댓글 내용',
            'user' => $commentAuthor,
        ]);

        $board = $this->createMockBoard('test-board', true);
        $post = $this->createMockPost(100, '테스트 게시글', 10, $postAuthor);

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);
        $this->postRepository->shouldReceive('find')->with('test-board', 100)->andReturn($post);

        // 사용자 알림 설정: notify_comment = false
        $settings = new UserNotificationSetting(['notify_comment' => false, 'notify_reply_comment' => true, 'notify_post_reply' => true]);
        $this->userNotificationSettingService->shouldReceive('getByUserId')->with(10)->andReturn($settings);

        $result = $this->listener->extractData([], 'new_comment', [$comment, 'test-board']);

        $this->assertEmptyResult($result);
    }

    // ── reply_comment ──

    /**
     * 대댓글 알림 데이터를 올바르게 추출합니다.
     */
    #[Test]
    public function test_reply_comment_정상_추출(): void
    {
        $parentAuthor = $this->createMockUser(10, '부모댓글작성자');
        $replyAuthor = $this->createMockUser(20, '대댓글작성자');

        $comment = $this->createMockComment([
            'id' => 2,
            'post_id' => 100,
            'parent_id' => 1,
            'user_id' => 20,
            'content' => '대댓글 내용',
            'user' => $replyAuthor,
            'author_name' => '대댓글작성자',
        ]);

        $parentComment = $this->createMockComment([
            'id' => 1,
            'user_id' => 10,
            'user' => $parentAuthor,
        ]);

        $board = $this->createMockBoard('test-board', true);
        $post = $this->createMockPost(100, '테스트 게시글', 10);

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);
        $this->postRepository->shouldReceive('find')->with('test-board', 100)->andReturn($post);
        $this->commentRepository->shouldReceive('find')->with('test-board', 1)->andReturn($parentComment);

        $result = $this->listener->extractData([], 'reply_comment', [$comment, 'test-board']);

        $this->assertNotEmpty($result['data']);
        $this->assertEquals(20, $result['context']['trigger_user_id']);
        $this->assertSame($parentAuthor, $result['context']['related_users']['parent_comment_author']);
    }

    /**
     * parent_id가 없으면 reply_comment에서 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_reply_comment_부모없으면_빈결과(): void
    {
        $comment = $this->createMockComment(['parent_id' => null, 'user_id' => 20]);

        $result = $this->listener->extractData([], 'reply_comment', [$comment, 'test-board']);

        $this->assertEmptyResult($result);
    }

    /**
     * 사용자 개인 알림 설정에서 대댓글 알림이 꺼져있으면 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_reply_comment_사용자설정OFF_빈결과(): void
    {
        $parentAuthor = $this->createMockUser(10, '부모댓글작성자');
        $replyAuthor = $this->createMockUser(20, '대댓글작성자');

        $comment = $this->createMockComment([
            'id' => 2,
            'post_id' => 100,
            'parent_id' => 1,
            'user_id' => 20,
            'content' => '대댓글 내용',
            'user' => $replyAuthor,
        ]);

        $parentComment = $this->createMockComment([
            'id' => 1,
            'user_id' => 10,
            'user' => $parentAuthor,
        ]);

        $board = $this->createMockBoard('test-board', true);
        $post = $this->createMockPost(100, '테스트 게시글', 10);

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);
        $this->postRepository->shouldReceive('find')->with('test-board', 100)->andReturn($post);
        $this->commentRepository->shouldReceive('find')->with('test-board', 1)->andReturn($parentComment);

        // 사용자 알림 설정: notify_reply_comment = false
        $settings = new UserNotificationSetting(['notify_comment' => true, 'notify_reply_comment' => false, 'notify_post_reply' => true]);
        $this->userNotificationSettingService->shouldReceive('getByUserId')->with(10)->andReturn($settings);

        $result = $this->listener->extractData([], 'reply_comment', [$comment, 'test-board']);

        $this->assertEmptyResult($result);
    }

    // ── post_reply ──

    /**
     * 답변글 알림 데이터를 올바르게 추출합니다.
     */
    #[Test]
    public function test_post_reply_정상_추출(): void
    {
        $originalAuthor = $this->createMockUser(10, '원글작성자');
        $replyAuthor = $this->createMockUser(20, '답변작성자');

        $replyPost = $this->createMockPost(200, '답변글', 20, $replyAuthor, 100);
        $originalPost = $this->createMockPost(100, '원본 게시글', 10, $originalAuthor);
        $board = $this->createMockBoard('test-board', true);

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);
        $this->postRepository->shouldReceive('find')->with('test-board', 100)->andReturn($originalPost);

        $result = $this->listener->extractData([], 'post_reply', [$replyPost, 'test-board']);

        $this->assertNotEmpty($result['data']);
        $this->assertEquals('원본 게시글', $result['data']['post_title']);
        $this->assertEquals(20, $result['context']['trigger_user_id']);
        $this->assertSame($originalAuthor, $result['context']['related_users']['original_post_author']);
    }

    /**
     * parent_id가 없으면 post_reply에서 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_post_reply_답변글아니면_빈결과(): void
    {
        $post = $this->createMockPost(100, '일반 게시글', 10, null, null);

        $result = $this->listener->extractData([], 'post_reply', [$post, 'test-board']);

        $this->assertEmptyResult($result);
    }

    /**
     * 사용자 개인 알림 설정에서 답변글 알림이 꺼져있으면 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_post_reply_사용자설정OFF_빈결과(): void
    {
        $originalAuthor = $this->createMockUser(10, '원글작성자');
        $replyAuthor = $this->createMockUser(20, '답변작성자');

        $replyPost = $this->createMockPost(200, '답변글', 20, $replyAuthor, 100);
        $originalPost = $this->createMockPost(100, '원본 게시글', 10, $originalAuthor);
        $board = $this->createMockBoard('test-board', true);

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);
        $this->postRepository->shouldReceive('find')->with('test-board', 100)->andReturn($originalPost);

        // 사용자 알림 설정: notify_post_reply = false
        $settings = new UserNotificationSetting(['notify_comment' => true, 'notify_reply_comment' => true, 'notify_post_reply' => false]);
        $this->userNotificationSettingService->shouldReceive('getByUserId')->with(10)->andReturn($settings);

        $result = $this->listener->extractData([], 'post_reply', [$replyPost, 'test-board']);

        $this->assertEmptyResult($result);
    }

    // ── post_action ──

    /**
     * 관리자 직권 처리 알림 데이터를 올바르게 추출합니다.
     */
    #[Test]
    public function test_post_action_정상_추출(): void
    {
        $admin = $this->createMockUser(1, '관리자');
        $postAuthor = $this->createMockUser(10, '게시글작성자');

        Auth::shouldReceive('id')->andReturn(1);
        Auth::shouldReceive('user')->andReturn($admin);

        $post = $this->createMockPost(100, '테스트 게시글', 10, $postAuthor);
        $post->trigger_type = null; // 직권 처리 (신고 아님)
        $post->shouldReceive('trashed')->andReturn(true);

        $board = $this->createMockBoard('test-board', true);

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);

        $result = $this->listener->extractData([], 'post_action', [$post, 'test-board']);

        $this->assertNotEmpty($result['data']);
        $this->assertArrayHasKey('target_type', $result['data']);
        $this->assertEquals(1, $result['context']['trigger_user_id']);
        $this->assertSame($admin, $result['context']['trigger_user']);
        $this->assertSame($postAuthor, $result['context']['related_users']['post_author']);
    }

    /**
     * trigger_type이 report이면 post_action에서 빈 결과를 반환합니다 (report_action에서 처리).
     */
    #[Test]
    public function test_post_action_신고처리이면_빈결과(): void
    {
        $post = $this->createMockPost(100, '테스트 게시글', 10);
        $post->trigger_type = TriggerType::Report;

        $board = $this->createMockBoard('test-board', true);
        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);

        $result = $this->listener->extractData([], 'post_action', [$post, 'test-board']);

        $this->assertEmptyResult($result);
    }

    /**
     * trigger_type이 auto_hide이면 post_action에서 빈 결과를 반환합니다 (report_action에서 처리).
     */
    #[Test]
    public function test_post_action_자동블라인드이면_빈결과(): void
    {
        $post = $this->createMockPost(100, '테스트 게시글', 10);
        $post->trigger_type = TriggerType::AutoHide;

        $board = $this->createMockBoard('test-board', true);
        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);

        $result = $this->listener->extractData([], 'post_action', [$post, 'test-board']);

        $this->assertEmptyResult($result);
    }

    /**
     * 댓글의 trigger_type이 auto_hide이면 post_action에서 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_post_action_댓글_자동블라인드이면_빈결과(): void
    {
        $comment = $this->createMockComment([
            'id' => 1,
            'post_id' => 100,
            'user_id' => 10,
            'trigger_type' => TriggerType::AutoHide,
        ]);

        $board = $this->createMockBoard('test-board', true);
        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);

        $result = $this->listener->extractData([], 'post_action', [$comment, 'test-board']);

        $this->assertEmptyResult($result);
    }

    /**
     * 댓글에 대한 관리자 직권 처리 알림 데이터를 올바르게 추출합니다.
     */
    #[Test]
    public function test_post_action_댓글_직권처리_정상_추출(): void
    {
        $admin = $this->createMockUser(1, '관리자');
        $commentAuthor = $this->createMockUser(10, '댓글작성자');

        Auth::shouldReceive('id')->andReturn(1);
        Auth::shouldReceive('user')->andReturn($admin);

        $comment = $this->createMockComment([
            'id' => 1,
            'post_id' => 100,
            'user_id' => 10,
            'user' => $commentAuthor,
            'trigger_type' => null,
        ]);
        $comment->shouldReceive('trashed')->andReturn(true);

        $post = $this->createMockPost(100, '테스트 게시글', 20);
        $board = $this->createMockBoard('test-board', true);

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);
        $this->postRepository->shouldReceive('find')->with('test-board', 100)->andReturn($post);

        $result = $this->listener->extractData([], 'post_action', [$comment, 'test-board']);

        $this->assertNotEmpty($result['data']);
        $this->assertArrayHasKey('target_type', $result['data']);
        $this->assertEquals(1, $result['context']['trigger_user_id']);
        $this->assertSame($commentAuthor, $result['context']['related_users']['post_author']);
    }

    /**
     * 댓글의 trigger_type이 report이면 post_action에서 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_post_action_댓글_신고처리이면_빈결과(): void
    {
        $comment = $this->createMockComment([
            'id' => 1,
            'post_id' => 100,
            'user_id' => 10,
            'trigger_type' => TriggerType::Report,
        ]);

        $board = $this->createMockBoard('test-board', true);
        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);

        $result = $this->listener->extractData([], 'post_action', [$comment, 'test-board']);

        $this->assertEmptyResult($result);
    }

    // ── report_action ──

    /**
     * 신고 처리 결과 알림 데이터를 올바르게 추출합니다.
     */
    #[Test]
    public function test_report_action_정상_추출(): void
    {
        $admin = $this->createMockUser(1, '관리자');
        $postAuthor = $this->createMockUser(10, '게시글작성자');

        Auth::shouldReceive('id')->andReturn(1);
        Auth::shouldReceive('user')->andReturn($admin);

        Config::set('g7_settings.modules.sirsoft-board.report_policy', [
            'notify_author_on_report_action' => true,
        ]);

        $post = $this->createMockPost(100, '신고된 게시글', 10, $postAuthor);
        $post->trigger_type = TriggerType::Report; // 신고 처리
        $post->shouldReceive('trashed')->andReturn(false);
        $post->status = PostStatus::Blinded;

        $board = $this->createMockBoard('test-board', true);
        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);

        $result = $this->listener->extractData([], 'report_action', [$post, 'test-board']);

        $this->assertNotEmpty($result['data']);
        $this->assertArrayHasKey('action_type', $result['data']);
        $this->assertArrayHasKey('target_type', $result['data']);
        $this->assertEquals(1, $result['context']['trigger_user_id']);
        $this->assertSame($admin, $result['context']['trigger_user']);
        $this->assertSame($postAuthor, $result['context']['related_users']['post_author']);
    }

    /**
     * 신고 처리 결과 알림 — 댓글에 대해 올바르게 추출합니다.
     */
    #[Test]
    public function test_report_action_댓글_정상_추출(): void
    {
        $admin = $this->createMockUser(1, '관리자');
        $commentAuthor = $this->createMockUser(10, '댓글작성자');

        Auth::shouldReceive('id')->andReturn(1);
        Auth::shouldReceive('user')->andReturn($admin);

        Config::set('g7_settings.modules.sirsoft-board.report_policy', [
            'notify_author_on_report_action' => true,
        ]);

        $comment = $this->createMockComment([
            'id' => 1,
            'post_id' => 100,
            'user_id' => 10,
            'user' => $commentAuthor,
            'trigger_type' => TriggerType::Report,
        ]);
        $comment->shouldReceive('trashed')->andReturn(false);
        $comment->status = PostStatus::Blinded;

        $post = $this->createMockPost(100, '테스트 게시글', 20);
        $board = $this->createMockBoard('test-board', true);

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);
        $this->postRepository->shouldReceive('find')->with('test-board', 100)->andReturn($post);

        $result = $this->listener->extractData([], 'report_action', [$comment, 'test-board']);

        $this->assertNotEmpty($result['data']);
        $this->assertArrayHasKey('target_type', $result['data']);
        $this->assertEquals(1, $result['context']['trigger_user_id']);
        $this->assertSame($commentAuthor, $result['context']['related_users']['post_author']);
    }

    /**
     * 자동 블라인드(auto_hide)도 report_action에서 정상 추출합니다.
     */
    #[Test]
    public function test_report_action_자동블라인드_정상_추출(): void
    {
        $admin = $this->createMockUser(1, '관리자');
        $postAuthor = $this->createMockUser(10, '게시글작성자');

        Auth::shouldReceive('id')->andReturn(1);
        Auth::shouldReceive('user')->andReturn($admin);

        Config::set('g7_settings.modules.sirsoft-board.report_policy', [
            'notify_author_on_report_action' => true,
        ]);

        $post = $this->createMockPost(100, '자동블라인드 게시글', 10, $postAuthor);
        $post->trigger_type = TriggerType::AutoHide;
        $post->shouldReceive('trashed')->andReturn(false);
        $post->status = PostStatus::Blinded;

        $board = $this->createMockBoard('test-board', true);
        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);

        $result = $this->listener->extractData([], 'report_action', [$post, 'test-board']);

        $this->assertNotEmpty($result['data']);
        $this->assertArrayHasKey('action_type', $result['data']);
        $this->assertArrayHasKey('target_type', $result['data']);
        $this->assertSame($postAuthor, $result['context']['related_users']['post_author']);
    }

    /**
     * 자동 블라인드(auto_hide) 댓글도 report_action에서 정상 추출합니다.
     */
    #[Test]
    public function test_report_action_댓글_자동블라인드_정상_추출(): void
    {
        $admin = $this->createMockUser(1, '관리자');
        $commentAuthor = $this->createMockUser(10, '댓글작성자');

        Auth::shouldReceive('id')->andReturn(1);
        Auth::shouldReceive('user')->andReturn($admin);

        Config::set('g7_settings.modules.sirsoft-board.report_policy', [
            'notify_author_on_report_action' => true,
        ]);

        $comment = $this->createMockComment([
            'id' => 1,
            'post_id' => 100,
            'user_id' => 10,
            'user' => $commentAuthor,
            'trigger_type' => TriggerType::AutoHide,
        ]);
        $comment->shouldReceive('trashed')->andReturn(false);
        $comment->status = PostStatus::Blinded;

        $post = $this->createMockPost(100, '테스트 게시글', 20);
        $board = $this->createMockBoard('test-board', true);

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);
        $this->postRepository->shouldReceive('find')->with('test-board', 100)->andReturn($post);

        $result = $this->listener->extractData([], 'report_action', [$comment, 'test-board']);

        $this->assertNotEmpty($result['data']);
        $this->assertArrayHasKey('target_type', $result['data']);
        $this->assertSame($commentAuthor, $result['context']['related_users']['post_author']);
    }

    /**
     * 댓글의 trigger_type이 report가 아니면 report_action에서 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_report_action_댓글_직권처리이면_빈결과(): void
    {
        Config::set('g7_settings.modules.sirsoft-board.report_policy', [
            'notify_author_on_report_action' => true,
        ]);

        $comment = $this->createMockComment([
            'id' => 1,
            'post_id' => 100,
            'user_id' => 10,
            'trigger_type' => TriggerType::Admin,
        ]);

        $board = $this->createMockBoard('test-board', true);
        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);

        $result = $this->listener->extractData([], 'report_action', [$comment, 'test-board']);

        $this->assertEmptyResult($result);
    }

    /**
     * notify_author_on_report_action이 꺼져있으면 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_report_action_설정OFF_빈결과(): void
    {
        Config::set('g7_settings.modules.sirsoft-board.report_policy', [
            'notify_author_on_report_action' => false,
        ]);

        $post = $this->createMockPost(100, '테스트', 10);

        $result = $this->listener->extractData([], 'report_action', [$post, 'test-board']);

        $this->assertEmptyResult($result);
    }

    // ── new_post_admin ──

    /**
     * 신규 게시글 관리자 알림 데이터를 올바르게 추출합니다.
     */
    #[Test]
    public function test_new_post_admin_정상_추출(): void
    {
        $postAuthor = $this->createMockUser(10, '게시글작성자');

        // DB에 Role + User 생성 (Eloquent 쿼리 사용하므로 실제 DB 필요)
        $manager = User::factory()->create(['name' => '게시판관리자']);
        $role = Role::create([
            'name' => '테스트게시판 관리자',
            'identifier' => 'sirsoft-board.test-board.manager',
            'is_admin' => false,
        ]);
        $role->users()->attach($manager);

        $post = $this->createMockPost(100, '새 게시글', 10, $postAuthor, null);

        $board = $this->createMockBoard('test-board', true, true); // notify_admin_on_post = true

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);
        $this->roleRepository->shouldReceive('findByIdentifier')
            ->with('sirsoft-board.test-board.manager')
            ->andReturn($role);

        $result = $this->listener->extractData([], 'new_post_admin', [$post, 'test-board']);

        $this->assertNotEmpty($result['data']);
        $this->assertEquals('새 게시글', $result['data']['post_title']);
        $this->assertEquals(10, $result['context']['trigger_user_id']);
        $this->assertCount(1, $result['context']['related_users']['board_managers']);
    }

    /**
     * notify_admin_on_post가 꺼져있으면 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_new_post_admin_알림OFF_빈결과(): void
    {
        $post = $this->createMockPost(100, '새 게시글', 10, null, null);
        $board = $this->createMockBoard('test-board', true, false); // notify_admin_on_post = false

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);

        $result = $this->listener->extractData([], 'new_post_admin', [$post, 'test-board']);

        $this->assertEmptyResult($result);
    }

    /**
     * skip_notification 옵션이 있으면 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_new_post_admin_skip_notification_빈결과(): void
    {
        $post = $this->createMockPost(100, '새 게시글', 10, null, null);

        $result = $this->listener->extractData([], 'new_post_admin', [$post, 'test-board', ['skip_notification' => true]]);

        $this->assertEmptyResult($result);
    }

    /**
     * 답변글은 new_post_admin에서 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_new_post_admin_답변글_빈결과(): void
    {
        $post = $this->createMockPost(200, '답변글', 10, null, 100); // parent_id = 100
        $board = $this->createMockBoard('test-board', true, true);

        $this->boardRepository->shouldReceive('findBySlug')->with('test-board')->andReturn($board);

        $result = $this->listener->extractData([], 'new_post_admin', [$post, 'test-board']);

        $this->assertEmptyResult($result);
    }

    // ── report_received_admin ──

    /**
     * 신고 접수 관리자 알림 데이터를 올바르게 추출합니다.
     */
    #[Test]
    public function test_report_received_admin_정상_추출(): void
    {
        $reporter = $this->createMockUser(20, '신고자');

        Config::set('g7_settings.modules.sirsoft-board.report_policy', [
            'notify_admin_on_report' => true,
            'notify_admin_on_report_scope' => 'per_report',
        ]);

        $board = $this->createMockBoard('test-board', true);

        $log = Mockery::mock();
        $log->snapshot = ['title' => '신고된 게시글', 'board_name' => '자유게시판'];
        $log->reason_type = 'spam';

        $report = Mockery::mock(Report::class)->makePartial();
        $report->target_type = 'post';
        $report->reporter_id = 20;
        $report->reporter = $reporter;
        $report->board = $board;
        $report->shouldReceive('loadMissing')->andReturnSelf();
        $report->logs = collect([$log]);

        $result = $this->listener->extractData([], 'report_received_admin', [$report]);

        $this->assertNotEmpty($result['data']);
        $this->assertEquals('신고된 게시글', $result['data']['post_title']);
        $this->assertEquals(20, $result['context']['trigger_user_id']);
        $this->assertSame($reporter, $result['context']['trigger_user']);
    }

    /**
     * notify_admin_on_report가 꺼져있으면 빈 결과를 반환합니다.
     */
    #[Test]
    public function test_report_received_admin_설정OFF_빈결과(): void
    {
        Config::set('g7_settings.modules.sirsoft-board.report_policy', [
            'notify_admin_on_report' => false,
        ]);

        $report = Mockery::mock(Report::class);

        $result = $this->listener->extractData([], 'report_received_admin', [$report]);

        $this->assertEmptyResult($result);
    }

    // ── 헬퍼 메서드 ──

    /**
     * 빈 결과 구조를 검증합니다.
     *
     * @param array $result 검증 대상
     * @return void
     */
    private function assertEmptyResult(array $result): void
    {
        $this->assertNull($result['notifiable']);
        $this->assertNull($result['notifiables']);
        $this->assertEmpty($result['data']);
        // emptyResult() 는 NotificationHookListener 에서 발송을 차단하기 위해
        // context.skip=true 를 반환한다 (2026-04-24 sirsoft-board report_policy).
        $this->assertTrue($result['context']['skip'] ?? false);
    }

    /**
     * Mock User 객체를 생성합니다.
     *
     * @param int $id 사용자 ID
     * @param string $name 사용자 이름
     * @return User
     */
    private function createMockUser(int $id, string $name): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = $id;
        $user->name = $name;

        return $user;
    }

    /**
     * Mock Board 객체를 생성합니다.
     *
     * @param string $slug 게시판 슬러그
     * @param bool $notifyAuthor 작성자 알림 여부
     * @param bool $notifyAdminOnPost 관리자 알림 여부
     * @return Board
     */
    private function createMockBoard(string $slug, bool $notifyAuthor, bool $notifyAdminOnPost = false): Board
    {
        $board = Mockery::mock(Board::class)->makePartial();
        $board->slug = $slug;
        $board->name = '테스트게시판';
        $board->localizedName = '테스트게시판';
        $board->notify_author = $notifyAuthor;
        $board->notify_admin_on_post = $notifyAdminOnPost;

        return $board;
    }

    /**
     * Mock Post 객체를 생성합니다.
     *
     * @param int $id 게시글 ID
     * @param string $title 게시글 제목
     * @param int|null $userId 작성자 ID
     * @param User|null $user 작성자 User 객체
     * @param int|null $parentId 부모 게시글 ID
     * @return Post
     */
    private function createMockPost(int $id, string $title, ?int $userId, ?User $user = null, ?int $parentId = null): Post
    {
        $post = Mockery::mock(Post::class)->makePartial();
        $post->id = $id;
        $post->title = $title;
        $post->user_id = $userId;
        $post->user = $user;
        $post->parent_id = $parentId;
        $post->trigger_type = null;
        $post->status = PostStatus::Published ?? 'published';

        return $post;
    }

    /**
     * Mock Comment 객체를 생성합니다.
     *
     * @param array $attributes 댓글 속성
     * @return Comment
     */
    private function createMockComment(array $attributes): Comment
    {
        $comment = Mockery::mock(Comment::class)->makePartial();
        foreach ($attributes as $key => $value) {
            $comment->{$key} = $value;
        }

        return $comment;
    }
}
