<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Listeners\BoardNotificationListener;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\UserNotificationSetting;
use Modules\Sirsoft\Board\Notifications\NewCommentNotification;
use Modules\Sirsoft\Board\Notifications\NewPostAdminNotification;
use Modules\Sirsoft\Board\Notifications\PostActionNotification;
use Modules\Sirsoft\Board\Notifications\PostReplyNotification;
use Modules\Sirsoft\Board\Notifications\ReplyCommentNotification;
use Modules\Sirsoft\Board\Notifications\ReportActionNotification;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\UserNotificationSettingService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 알림 리스너 단위 테스트
 *
 * BoardNotificationListener의 각 시나리오별 알림 발송/미발송 로직을 검증합니다.
 * - 훅 구독 등록 확인
 * - 시나리오 1+5: 내 글에 댓글 → 글 작성자에게 알림
 * - 시나리오 2: 내 댓글에 대댓글 → 부모 댓글 작성자에게 알림
 * - 시나리오 3: 답변글 → 원글 작성자에게 알림
 * - 시나리오 4: 관리자 처리 → 글 작성자에게 알림
 * - 시나리오 6: 새 게시글 → 게시판 관리자에게 알림
 */
class BoardNotificationListenerTest extends ModuleTestCase
{
    private BoardNotificationListener $listener;

    private UserNotificationSettingService $notificationSettingService;

    private BoardRepositoryInterface $boardRepository;

    private PostRepositoryInterface $postRepository;

    private CommentRepositoryInterface $commentRepository;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // static $processedKeys 초기화 (테스트 간 간섭 방지)
        $reflection = new \ReflectionClass(BoardNotificationListener::class);
        $prop = $reflection->getProperty('processedKeys');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $this->notificationSettingService = Mockery::mock(UserNotificationSettingService::class);
        $this->boardRepository = Mockery::mock(BoardRepositoryInterface::class);
        $this->postRepository = Mockery::mock(PostRepositoryInterface::class);
        $this->commentRepository = Mockery::mock(CommentRepositoryInterface::class);

        $this->listener = new BoardNotificationListener(
            $this->notificationSettingService,
            $this->boardRepository,
            $this->postRepository,
            $this->commentRepository
        );
    }

    /**
     * 테스트 환경 정리
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── 훅 구독 등록 확인 ──

    /**
     * 구독할 훅 목록이 올바르게 정의되어 있는지 확인합니다.
     */
    #[Test]
    public function test_getSubscribedHooks_올바른_훅_구독_등록(): void
    {
        $hooks = BoardNotificationListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-board.comment.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-board.post.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-board.post.after_blind', $hooks);
        $this->assertArrayHasKey('sirsoft-board.post.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-board.post.after_restore', $hooks);
        // 이슈 #35 Phase 1: 댓글 훅 3개 추가
        $this->assertArrayHasKey('sirsoft-board.comment.after_blind', $hooks);
        $this->assertArrayHasKey('sirsoft-board.comment.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-board.comment.after_restore', $hooks);

        // priority 20 확인
        foreach ($hooks as $hook) {
            $this->assertEquals(20, $hook['priority']);
        }

        // method 매핑 확인
        $this->assertEquals('afterCommentCreate', $hooks['sirsoft-board.comment.after_create']['method']);
        $this->assertEquals('afterPostCreate', $hooks['sirsoft-board.post.after_create']['method']);
        $this->assertEquals('afterPostBlind', $hooks['sirsoft-board.post.after_blind']['method']);
        $this->assertEquals('afterPostDelete', $hooks['sirsoft-board.post.after_delete']['method']);
        $this->assertEquals('afterPostRestore', $hooks['sirsoft-board.post.after_restore']['method']);
        $this->assertEquals('afterCommentBlind', $hooks['sirsoft-board.comment.after_blind']['method']);
        $this->assertEquals('afterCommentDelete', $hooks['sirsoft-board.comment.after_delete']['method']);
        $this->assertEquals('afterCommentRestore', $hooks['sirsoft-board.comment.after_restore']['method']);
    }

    // ── 시나리오 1+5: 내 글에 댓글 알림 ──

    /**
     * 게시판 설정 ON + 사용자 설정 ON → 알림 발송
     */
    #[Test]
    public function test_afterCommentCreate_새댓글_정상발송(): void
    {
        $postAuthor = User::factory()->create();
        $commentAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);
        $comment = $this->createMockComment(
            postId: $post->id,
            userId: $commentAuthor->id,
            userName: $commentAuthor->name
        );

        $this->postRepository->shouldReceive('find')
            ->with($board->slug, $comment->post_id)
            ->andReturn($post);

        $this->mockUserNotificationSetting($postAuthor->id, ['notify_comment' => true]);

        $this->listener->afterCommentCreate($comment, $board->slug);

        Notification::assertSentTo($postAuthor, NewCommentNotification::class);
    }

    /**
     * 게시판 설정 OFF → 알림 미발송
     */
    #[Test]
    public function test_afterCommentCreate_게시판설정OFF_미발송(): void
    {
        $postAuthor = User::factory()->create();
        $commentAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => false,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);
        $comment = $this->createMockComment(
            postId: $post->id,
            userId: $commentAuthor->id,
            userName: $commentAuthor->name
        );

        $this->postRepository->shouldReceive('find')
            ->with($board->slug, $comment->post_id)
            ->andReturn($post);

        $this->listener->afterCommentCreate($comment, $board->slug);

        Notification::assertNotSentTo($postAuthor, NewCommentNotification::class);
    }

    /**
     * 사용자 알림 설정 OFF → 알림 미발송
     */
    #[Test]
    public function test_afterCommentCreate_사용자설정OFF_미발송(): void
    {
        $postAuthor = User::factory()->create();
        $commentAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);
        $comment = $this->createMockComment(
            postId: $post->id,
            userId: $commentAuthor->id,
            userName: $commentAuthor->name
        );

        $this->postRepository->shouldReceive('find')
            ->with($board->slug, $comment->post_id)
            ->andReturn($post);

        $this->mockUserNotificationSetting($postAuthor->id, ['notify_comment' => false]);

        $this->listener->afterCommentCreate($comment, $board->slug);

        Notification::assertNotSentTo($postAuthor, NewCommentNotification::class);
    }

    /**
     * 자기 자신에게 댓글 → 알림 미발송
     */
    #[Test]
    public function test_afterCommentCreate_자기자신_미발송(): void
    {
        $user = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost($user->id, $board->slug);
        $comment = $this->createMockComment(
            postId: $post->id,
            userId: $user->id,
            userName: $user->name
        );

        $this->postRepository->shouldReceive('find')
            ->with($board->slug, $comment->post_id)
            ->andReturn($post);

        $this->listener->afterCommentCreate($comment, $board->slug);

        Notification::assertNotSentTo($user, NewCommentNotification::class);
    }

    /**
     * 비회원 게시글에 댓글 → 알림 미발송
     */
    #[Test]
    public function test_afterCommentCreate_비회원게시글_미발송(): void
    {
        $commentAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost(null, $board->slug);
        $comment = $this->createMockComment(
            postId: $post->id,
            userId: $commentAuthor->id,
            userName: $commentAuthor->name
        );

        $this->postRepository->shouldReceive('find')
            ->with($board->slug, $comment->post_id)
            ->andReturn($post);

        $this->listener->afterCommentCreate($comment, $board->slug);

        Notification::assertNothingSent();
    }

    // ── 시나리오 2: 대댓글 알림 ──

    /**
     * 대댓글 → 부모 댓글 작성자에게 알림 발송
     */
    #[Test]
    public function test_afterCommentCreate_대댓글_정상발송(): void
    {
        $postAuthor = User::factory()->create();
        $parentCommentAuthor = User::factory()->create();
        $replyAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true, // 작성자 알림 ON (대댓글 알림 포함)
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        $parentComment = $this->createMockComment(
            postId: $post->id,
            userId: $parentCommentAuthor->id,
            userName: $parentCommentAuthor->name,
            id: 100
        );

        $replyComment = $this->createMockComment(
            postId: $post->id,
            userId: $replyAuthor->id,
            userName: $replyAuthor->name,
            parentId: 100
        );

        $this->postRepository->shouldReceive('find')
            ->with($board->slug, $replyComment->post_id)
            ->andReturn($post);

        $this->commentRepository->shouldReceive('find')
            ->with($board->slug, 100)
            ->andReturn($parentComment);

        $this->mockUserNotificationSetting($parentCommentAuthor->id, ['notify_reply_comment' => true]);

        $this->listener->afterCommentCreate($replyComment, $board->slug);

        Notification::assertSentTo($parentCommentAuthor, ReplyCommentNotification::class);
    }

    /**
     * 대댓글 작성자 == 부모 댓글 작성자 → 미발송
     */
    #[Test]
    public function test_afterCommentCreate_대댓글_자기자신_미발송(): void
    {
        $postAuthor = User::factory()->create();
        $sameUser = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => false,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        $parentComment = $this->createMockComment(
            postId: $post->id,
            userId: $sameUser->id,
            userName: $sameUser->name,
            id: 100
        );

        $replyComment = $this->createMockComment(
            postId: $post->id,
            userId: $sameUser->id,
            userName: $sameUser->name,
            parentId: 100
        );

        $this->postRepository->shouldReceive('find')
            ->with($board->slug, $replyComment->post_id)
            ->andReturn($post);

        $this->commentRepository->shouldReceive('find')
            ->with($board->slug, 100)
            ->andReturn($parentComment);

        $this->listener->afterCommentCreate($replyComment, $board->slug);

        Notification::assertNotSentTo($sameUser, ReplyCommentNotification::class);
    }

    // ── 시나리오 3: 답변글 알림 ──

    /**
     * 답변글 → 원글 작성자에게 알림 발송
     */
    #[Test]
    public function test_afterPostCreate_답변글_정상발송(): void
    {
        $parentPostAuthor = User::factory()->create();
        $replyPostAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_admin_on_post' => false, // 관리자 알림 OFF (답변글만 테스트)
            'notify_author' => true, // 작성자 알림 ON (답변글 알림 테스트)
        ]);

        $parentPost = $this->createMockPost($parentPostAuthor->id, $board->slug, id: 100);
        $replyPost = $this->createMockPost($replyPostAuthor->id, $board->slug, parentId: 100);

        $this->postRepository->shouldReceive('find')
            ->with($board->slug, 100)
            ->andReturn($parentPost);

        $this->mockUserNotificationSetting($parentPostAuthor->id, ['notify_post_reply' => true]);

        $this->listener->afterPostCreate($replyPost, $board->slug);

        Notification::assertSentTo($parentPostAuthor, PostReplyNotification::class);
    }

    /**
     * 답변글 - 자기 자신에게 답변 → 미발송
     */
    #[Test]
    public function test_afterPostCreate_답변글_자기자신_미발송(): void
    {
        $user = User::factory()->create();

        $board = Board::factory()->create([
            'notify_admin_on_post' => false,
            'notify_author' => true,
        ]);

        $parentPost = $this->createMockPost($user->id, $board->slug, id: 100);
        $replyPost = $this->createMockPost($user->id, $board->slug, parentId: 100);

        $this->postRepository->shouldReceive('find')
            ->with($board->slug, 100)
            ->andReturn($parentPost);

        $this->listener->afterPostCreate($replyPost, $board->slug);

        Notification::assertNotSentTo($user, PostReplyNotification::class);
    }

    // ── 시나리오 4: 관리자 처리 알림 ──

    /**
     * 관리자가 게시글 블라인드 → 글 작성자에게 알림 발송
     */
    #[Test]
    public function test_afterPostBlind_정상발송(): void
    {
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        $this->mockUserNotificationSetting($postAuthor->id, ['notify_post_complete' => true]);

        $this->listener->afterPostBlind($post, $board->slug);

        Notification::assertSentTo($postAuthor, PostActionNotification::class);
    }

    /**
     * 관리자가 게시글 삭제 → 글 작성자에게 알림 발송
     */
    #[Test]
    public function test_afterPostDelete_정상발송(): void
    {
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        $this->mockUserNotificationSetting($postAuthor->id, ['notify_post_complete' => true]);

        $this->listener->afterPostDelete($post, $board->slug);

        Notification::assertSentTo($postAuthor, PostActionNotification::class);
    }

    /**
     * skip_notification 옵션이 true인 경우 게시글 삭제 알림 발송하지 않음
     * (이커머스 경로로 삭제된 문의글 — 게시판 알림 불필요)
     */
    #[Test]
    public function test_afterPostDelete_skip_notification_옵션_알림미발송(): void
    {
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        $this->mockUserNotificationSetting($postAuthor->id, ['notify_post_complete' => true]);

        $this->listener->afterPostDelete($post, $board->slug, ['skip_notification' => true]);

        Notification::assertNothingSent();
    }

    /**
     * 관리자가 게시글 복원 → 글 작성자에게 알림 발송
     */
    #[Test]
    public function test_afterPostRestore_정상발송(): void
    {
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        $this->mockUserNotificationSetting($postAuthor->id, ['notify_post_complete' => true]);

        $this->listener->afterPostRestore($post, $board->slug);

        Notification::assertSentTo($postAuthor, PostActionNotification::class);
    }

    /**
     * 사용자 설정 OFF → 미발송
     */
    #[Test]
    public function test_afterPostBlind_사용자설정OFF_미발송(): void
    {
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        $this->mockUserNotificationSetting($postAuthor->id, ['notify_post_complete' => false]);

        $this->listener->afterPostBlind($post, $board->slug);

        Notification::assertNotSentTo($postAuthor, PostActionNotification::class);
    }

    /**
     * 비회원 게시글 → 미발송
     */
    #[Test]
    public function test_afterPostBlind_비회원_미발송(): void
    {
        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost(null, $board->slug);

        $this->listener->afterPostBlind($post, $board->slug);

        Notification::assertNothingSent();
    }

    // ── 시나리오 6: 관리자 새글 알림 ──

    /**
     * 게시판 설정 ON + 관리자 존재 → 알림 발송
     */
    #[Test]
    public function test_afterPostCreate_관리자새글알림_정상발송(): void
    {
        $manager = User::factory()->create();
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_admin_on_post' => true,
        ]);

        // 역할 기반 게시판 관리자 할당
        $managerRole = Role::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.manager"],
            ['name' => ['ko' => '게시판 관리자', 'en' => 'Board Manager']]
        );
        $manager->roles()->syncWithoutDetaching([$managerRole->id]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        $this->listener->afterPostCreate($post, $board->slug);

        Notification::assertSentTo($manager, NewPostAdminNotification::class);
    }

    /**
     * 관리자가 직접 작성 → 미발송
     */
    #[Test]
    public function test_afterPostCreate_관리자직접작성_미발송(): void
    {
        $manager = User::factory()->create();

        $board = Board::factory()->create([
            'notify_admin_on_post' => true,
        ]);

        // 역할 기반 게시판 관리자 할당
        $managerRole = Role::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.manager"],
            ['name' => ['ko' => '게시판 관리자', 'en' => 'Board Manager']]
        );
        $manager->roles()->syncWithoutDetaching([$managerRole->id]);

        $post = $this->createMockPost($manager->id, $board->slug);

        $this->listener->afterPostCreate($post, $board->slug);

        Notification::assertNotSentTo($manager, NewPostAdminNotification::class);
    }

    /**
     * 게시판 설정 OFF → 미발송
     */
    #[Test]
    public function test_afterPostCreate_관리자알림설정OFF_미발송(): void
    {
        $manager = User::factory()->create();
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_admin_on_post' => false,
        ]);

        // 역할 기반 게시판 관리자 할당
        $managerRole = Role::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.manager"],
            ['name' => ['ko' => '게시판 관리자', 'en' => 'Board Manager']]
        );
        $manager->roles()->syncWithoutDetaching([$managerRole->id]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        $this->listener->afterPostCreate($post, $board->slug);

        Notification::assertNotSentTo($manager, NewPostAdminNotification::class);
    }

    /**
     * 관리자 미지정 → 최고관리자(super_admin)에게 발송
     */
    #[Test]
    public function test_afterPostCreate_관리자미지정_최고관리자발송(): void
    {
        // super_admin 생성
        $superAdmin = User::factory()->create(['is_super' => true]);
        $postAuthor = User::factory()->create();

        // 게시판 관리자 역할 미할당 (역할 없음 → super_admin 폴백)
        $board = Board::factory()->create([
            'notify_admin_on_post' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        $this->listener->afterPostCreate($post, $board->slug);

        Notification::assertSentTo($superAdmin, NewPostAdminNotification::class);
    }

    /**
     * 관리자 미지정 + 최고관리자 없음 → 미발송
     */
    #[Test]
    public function test_afterPostCreate_관리자미지정_최고관리자없음_미발송(): void
    {
        // super_admin 없이 일반 사용자만 생성
        $postAuthor = User::factory()->create(['is_super' => false]);

        // 게시판 관리자 역할 미할당 + super_admin 없음
        $board = Board::factory()->create([
            'notify_admin_on_post' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        $this->listener->afterPostCreate($post, $board->slug);

        Notification::assertNothingSent();
    }

    // ── SKIP 훅: sirsoft-board.notification.skip_post_create ──

    /**
     * skip_notification 옵션이 true이면 afterPostCreate 알림 미발송
     */
    #[Test]
    public function test_afterPostCreate_skip_notification옵션_true_미발송(): void
    {
        $manager = User::factory()->create();
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_admin_on_post' => true,
        ]);

        $role = Role::factory()->create(['identifier' => "sirsoft-board.{$board->slug}.manager"]);
        $manager->roles()->attach($role);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        // 이커머스 경로처럼 skip_notification => true 전달
        $this->listener->afterPostCreate($post, $board->slug, ['skip_notification' => true]);

        Notification::assertNothingSent();
    }

    /**
     * skip_notification 옵션이 없으면 afterPostCreate 알림 정상 발송
     */
    #[Test]
    public function test_afterPostCreate_skip_notification옵션_없음_정상발송(): void
    {
        $manager = User::factory()->create();
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_admin_on_post' => true,
        ]);

        $role = Role::factory()->create(['identifier' => "sirsoft-board.{$board->slug}.manager"]);
        $manager->roles()->attach($role);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        // 일반 게시판 경로 — options 없음
        $this->listener->afterPostCreate($post, $board->slug);

        Notification::assertSentTo($manager, NewPostAdminNotification::class);
    }

    // ── 시나리오 4 (이슈 #35): 신고 처리 알림 ──

    /**
     * 게시글 신고 처리(blind) → TriggerType::Report + report_policy ON → ReportActionNotification 발송
     */
    #[Test]
    public function test_afterPostBlind_신고처리_ReportActionNotification_발송(): void
    {
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => false, // board->notify_author OFF여도 report_policy가 우선
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);
        $post->trigger_type = TriggerType::Report;

        // g7_module_settings mock
        $this->mockReportPolicy(['notify_author_on_report_action' => true]);

        $this->listener->afterPostBlind($post, $board->slug);

        Notification::assertSentTo($postAuthor, ReportActionNotification::class);
        Notification::assertNotSentTo($postAuthor, PostActionNotification::class);
    }

    /**
     * 게시글 신고 처리 → report_policy.notify_author_on_report_action OFF → 미발송
     */
    #[Test]
    public function test_afterPostBlind_신고처리_정책OFF_미발송(): void
    {
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);
        $post->trigger_type = TriggerType::Report;

        $this->mockReportPolicy(['notify_author_on_report_action' => false]);

        $this->listener->afterPostBlind($post, $board->slug);

        Notification::assertNothingSent();
    }

    /**
     * 게시글 신고 처리 삭제(deleted) → ReportActionNotification 발송
     */
    #[Test]
    public function test_afterPostDelete_신고처리_ReportActionNotification_발송(): void
    {
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create();

        $post = $this->createMockPost($postAuthor->id, $board->slug);
        $post->trigger_type = TriggerType::Report;

        $this->mockReportPolicy(['notify_author_on_report_action' => true]);

        $this->listener->afterPostDelete($post, $board->slug);

        Notification::assertSentTo($postAuthor, ReportActionNotification::class);
    }

    /**
     * 게시글 신고 처리 복원(restored) → ReportActionNotification 발송
     */
    #[Test]
    public function test_afterPostRestore_신고처리_ReportActionNotification_발송(): void
    {
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create();

        $post = $this->createMockPost($postAuthor->id, $board->slug);
        $post->trigger_type = TriggerType::Report;

        $this->mockReportPolicy(['notify_author_on_report_action' => true]);

        $this->listener->afterPostRestore($post, $board->slug);

        Notification::assertSentTo($postAuthor, ReportActionNotification::class);
    }

    /**
     * 자동 블라인드(TriggerType::AutoHide) → ReportActionNotification 발송 (report_policy ON)
     */
    #[Test]
    public function test_afterPostBlind_자동블라인드_ReportActionNotification_발송(): void
    {
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => false, // board->notify_author OFF여도 report_policy가 우선
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);
        $post->trigger_type = TriggerType::AutoHide;

        $this->mockReportPolicy(['notify_author_on_report_action' => true]);

        $this->listener->afterPostBlind($post, $board->slug);

        Notification::assertSentTo($postAuthor, ReportActionNotification::class);
        Notification::assertNotSentTo($postAuthor, PostActionNotification::class);
    }

    /**
     * 자동 블라인드(TriggerType::AutoHide) → report_policy OFF → 미발송
     */
    #[Test]
    public function test_afterPostBlind_자동블라인드_정책OFF_미발송(): void
    {
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);
        $post->trigger_type = TriggerType::AutoHide;

        $this->mockReportPolicy(['notify_author_on_report_action' => false]);

        $this->listener->afterPostBlind($post, $board->slug);

        Notification::assertNothingSent();
    }

    /**
     * 관리자 직권 처리(TriggerType::Admin) → PostActionNotification 발송 (기존 로직 유지)
     */
    #[Test]
    public function test_afterPostBlind_직권처리_PostActionNotification_발송(): void
    {
        $postAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);
        $post->trigger_type = TriggerType::Admin;

        $this->mockUserNotificationSetting($postAuthor->id, ['notify_post_complete' => true]);

        $this->listener->afterPostBlind($post, $board->slug);

        Notification::assertSentTo($postAuthor, PostActionNotification::class);
        Notification::assertNotSentTo($postAuthor, ReportActionNotification::class);
    }

    /**
     * 비회원 게시글 신고 처리 → user_id 없음 → 미발송
     */
    #[Test]
    public function test_afterPostBlind_신고처리_비회원게시글_미발송(): void
    {
        $board = Board::factory()->create();

        $post = $this->createMockPost(null, $board->slug);
        $post->trigger_type = TriggerType::Report;

        $this->listener->afterPostBlind($post, $board->slug);

        Notification::assertNothingSent();
    }

    // ── 시나리오: 댓글 신고 처리 알림 (이슈 #35) ──

    /**
     * 댓글 신고 처리(blind) → TriggerType::Report + report_policy ON → ReportActionNotification 발송
     */
    #[Test]
    public function test_afterCommentBlind_신고처리_ReportActionNotification_발송(): void
    {
        $commentAuthor = User::factory()->create();

        $board = Board::factory()->create();

        $post = $this->createMockPost(User::factory()->create()->id, $board->slug, id: 10);
        $comment = $this->createMockComment(postId: $post->id, userId: $commentAuthor->id);
        $comment->trigger_type = TriggerType::Report;
        $comment->shouldReceive('getAttribute')->with('post')->andReturn($post);
        $comment->post = $post;

        $this->mockReportPolicy(['notify_author_on_report_action' => true]);

        $this->listener->afterCommentBlind($comment, $board->slug);

        Notification::assertSentTo($commentAuthor, ReportActionNotification::class);
    }

    /**
     * 댓글 신고 처리 → report_policy OFF → 미발송
     */
    #[Test]
    public function test_afterCommentBlind_신고처리_정책OFF_미발송(): void
    {
        $commentAuthor = User::factory()->create();

        $board = Board::factory()->create();

        $comment = $this->createMockComment(postId: 10, userId: $commentAuthor->id);
        $comment->trigger_type = TriggerType::Report;

        $this->mockReportPolicy(['notify_author_on_report_action' => false]);

        $this->listener->afterCommentBlind($comment, $board->slug);

        Notification::assertNothingSent();
    }

    /**
     * 댓글 자동 블라인드(TriggerType::AutoHide) → ReportActionNotification 발송 (report_policy ON)
     */
    #[Test]
    public function test_afterCommentBlind_자동블라인드_ReportActionNotification_발송(): void
    {
        $commentAuthor = User::factory()->create();

        $board = Board::factory()->create();

        $post = $this->createMockPost(User::factory()->create()->id, $board->slug, id: 10);
        $comment = $this->createMockComment(postId: $post->id, userId: $commentAuthor->id);
        $comment->trigger_type = TriggerType::AutoHide;
        $comment->shouldReceive('getAttribute')->with('post')->andReturn($post);
        $comment->post = $post;

        $this->mockReportPolicy(['notify_author_on_report_action' => true]);

        $this->listener->afterCommentBlind($comment, $board->slug);

        Notification::assertSentTo($commentAuthor, ReportActionNotification::class);
    }

    /**
     * 댓글 자동 블라인드(TriggerType::AutoHide) → report_policy OFF → 미발송
     */
    #[Test]
    public function test_afterCommentBlind_자동블라인드_정책OFF_미발송(): void
    {
        $commentAuthor = User::factory()->create();

        $board = Board::factory()->create();

        $comment = $this->createMockComment(postId: 10, userId: $commentAuthor->id);
        $comment->trigger_type = TriggerType::AutoHide;

        $this->mockReportPolicy(['notify_author_on_report_action' => false]);

        $this->listener->afterCommentBlind($comment, $board->slug);

        Notification::assertNothingSent();
    }

    /**
     * 댓글 직권 처리(TriggerType::Admin) → 미발송 (댓글 직권 처리 알림은 별도 이슈)
     */
    #[Test]
    public function test_afterCommentBlind_직권처리_미발송(): void
    {
        $commentAuthor = User::factory()->create();

        $board = Board::factory()->create();

        $comment = $this->createMockComment(postId: 10, userId: $commentAuthor->id);
        $comment->trigger_type = TriggerType::Admin;

        $this->listener->afterCommentBlind($comment, $board->slug);

        Notification::assertNothingSent();
    }

    /**
     * 댓글 신고 삭제(deleted) → ReportActionNotification 발송
     */
    #[Test]
    public function test_afterCommentDelete_신고처리_ReportActionNotification_발송(): void
    {
        $commentAuthor = User::factory()->create();

        $board = Board::factory()->create();

        $post = $this->createMockPost(User::factory()->create()->id, $board->slug, id: 10);
        $comment = $this->createMockComment(postId: $post->id, userId: $commentAuthor->id);
        $comment->trigger_type = TriggerType::Report;
        $comment->shouldReceive('getAttribute')->with('post')->andReturn($post);
        $comment->post = $post;

        $this->mockReportPolicy(['notify_author_on_report_action' => true]);

        $this->listener->afterCommentDelete($comment, $board->slug);

        Notification::assertSentTo($commentAuthor, ReportActionNotification::class);
    }

    /**
     * 댓글 신고 복원(restored) → ReportActionNotification 발송
     */
    #[Test]
    public function test_afterCommentRestore_신고처리_ReportActionNotification_발송(): void
    {
        $commentAuthor = User::factory()->create();

        $board = Board::factory()->create();

        $post = $this->createMockPost(User::factory()->create()->id, $board->slug, id: 10);
        $comment = $this->createMockComment(postId: $post->id, userId: $commentAuthor->id);
        $comment->trigger_type = TriggerType::Report;
        $comment->shouldReceive('getAttribute')->with('post')->andReturn($post);
        $comment->post = $post;

        $this->mockReportPolicy(['notify_author_on_report_action' => true]);

        $this->listener->afterCommentRestore($comment, $board->slug);

        Notification::assertSentTo($commentAuthor, ReportActionNotification::class);
    }

    /**
     * 비회원 댓글 신고 처리 → user_id 없음 → 미발송
     */
    #[Test]
    public function test_afterCommentBlind_신고처리_비회원댓글_미발송(): void
    {
        $board = Board::factory()->create();

        $comment = $this->createMockComment(postId: 10, userId: null);
        $comment->trigger_type = TriggerType::Report;

        $this->listener->afterCommentBlind($comment, $board->slug);

        Notification::assertNothingSent();
    }

    // ── 복합 시나리오 ──

    /**
     * 대댓글 생성 시 부모 댓글 작성자에게만 알림 발송 (글 작성자에게는 미발송)
     */
    #[Test]
    public function test_afterCommentCreate_대댓글_부모댓글작성자만_알림(): void
    {
        $postAuthor = User::factory()->create();
        $parentCommentAuthor = User::factory()->create();
        $replyAuthor = User::factory()->create();

        $board = Board::factory()->create([
            'notify_author' => true,
        ]);

        $post = $this->createMockPost($postAuthor->id, $board->slug);

        $parentComment = $this->createMockComment(
            postId: $post->id,
            userId: $parentCommentAuthor->id,
            userName: $parentCommentAuthor->name,
            id: 100
        );

        $replyComment = $this->createMockComment(
            postId: $post->id,
            userId: $replyAuthor->id,
            userName: $replyAuthor->name,
            parentId: 100
        );

        $this->postRepository->shouldReceive('find')
            ->with($board->slug, $replyComment->post_id)
            ->andReturn($post);

        $this->commentRepository->shouldReceive('find')
            ->with($board->slug, 100)
            ->andReturn($parentComment);

        $this->mockUserNotificationSetting($postAuthor->id, ['notify_comment' => true]);
        $this->mockUserNotificationSetting($parentCommentAuthor->id, ['notify_reply_comment' => true]);

        $this->listener->afterCommentCreate($replyComment, $board->slug);

        // 대댓글이면 글 작성자에게는 알림 미발송
        Notification::assertNotSentTo($postAuthor, NewCommentNotification::class);
        // 부모 댓글 작성자에게만 대댓글 알림 발송
        Notification::assertSentTo($parentCommentAuthor, ReplyCommentNotification::class);
    }

    // ── 헬퍼 메서드 ──

    /**
     * Mock Post 객체를 생성합니다.
     *
     * @param int|null $userId 작성자 ID
     * @param string $slug 게시판 슬러그
     * @param int $id 게시글 ID
     * @param int|null $parentId 부모 게시글 ID
     * @return Post
     */
    private function createMockPost(?int $userId, string $slug, int $id = 1, ?int $parentId = null): Post
    {
        $post = Mockery::mock(Post::class)->makePartial();
        $post->id = $id;
        $post->user_id = $userId;
        $post->parent_id = $parentId;
        $post->title = '테스트 게시글';

        if ($userId) {
            $user = User::find($userId);
            $post->shouldReceive('getAttribute')->with('user')->andReturn($user);
            // user 관계를 통한 notify 호출을 위해 실제 User 객체 반환
            $post->user = $user;
        }

        return $post;
    }

    /**
     * Mock Comment 객체를 생성합니다.
     *
     * @param int $postId 게시글 ID
     * @param int|null $userId 작성자 ID
     * @param string $userName 작성자 이름
     * @param int $id 댓글 ID
     * @param int|null $parentId 부모 댓글 ID
     * @return Comment
     */
    private function createMockComment(
        int $postId,
        ?int $userId = null,
        string $userName = '',
        int $id = 1,
        ?int $parentId = null
    ): Comment {
        $comment = Mockery::mock(Comment::class)->makePartial();
        $comment->id = $id;
        $comment->post_id = $postId;
        $comment->user_id = $userId;
        $comment->parent_id = $parentId;
        $comment->author_name = $userName;
        $comment->content = '테스트 댓글 내용';

        if ($userId) {
            $user = User::find($userId);
            $comment->user = $user;
        } else {
            $comment->user = null;
        }

        return $comment;
    }

    /**
     * g7_module_settings('sirsoft-board', 'report_policy') mock 설정을 합니다.
     *
     * g7_module_settings()는 Config::get("g7_settings.modules.{identifier}.{key}")를 사용하므로
     * config()로 직접 주입합니다.
     *
     * @param array $overrides 설정 오버라이드
     * @return void
     */
    private function mockReportPolicy(array $overrides = []): void
    {
        $defaults = [
            'notify_author_on_report_action' => false,
            'notify_author_on_report_action_channels' => ['mail'],
        ];

        $policy = array_merge($defaults, $overrides);

        config(['g7_settings.modules.sirsoft-board.report_policy' => $policy]);
    }

    /**
     * UserNotificationSettingService mock 설정을 합니다.
     *
     * @param int $userId 사용자 ID
     * @param array $overrides 설정 오버라이드
     * @return void
     */
    private function mockUserNotificationSetting(int $userId, array $overrides = []): void
    {
        $defaults = [
            'notify_comment' => false,
            'notify_reply_comment' => false,
            'notify_post_reply' => false,
            'notify_post_complete' => false,
        ];

        $data = array_merge($defaults, $overrides);

        $setting = new UserNotificationSetting(array_merge(['user_id' => $userId], $data));

        $this->notificationSettingService->shouldReceive('getByUserId')
            ->with($userId)
            ->andReturn($setting);
    }
}
