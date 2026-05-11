<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use App\ActivityLog\ChangeDetector;
use App\Enums\ActivityLogType;
use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Board\Listeners\BoardActivityLogListener;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 활동 로그 리스너 단위 테스트
 *
 * BoardActivityLogListener의 모든 훅 핸들러를 검증합니다.
 * - 훅 구독 등록 (27개 훅: 로깅 전용)
 * - Board CRUD + add_to_menu 로그
 * - BoardType CRUD 로그
 * - Settings bulk_apply 로그
 * - Post CRUD + blind/restore 로그
 * - Comment CRUD + blind/restore 로그
 * - Attachment upload/delete 로그
 * - Report create/update_status/bulk_update_status/delete/restore_content/blind_content/delete_content 로그
 * - 예외 발생 시 graceful 처리
 */
class BoardActivityLogListenerTest extends ModuleTestCase
{
    private BoardActivityLogListener $listener;

    private $logChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('request', Request::create('/api/admin/test'));

        $this->listener = app(BoardActivityLogListener::class);
        $this->logChannel = Mockery::mock(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturn($this->logChannel);
        // catch 블록의 Log::error() 호출 허용 (기본적으로 호출되지 않아야 함)
        Log::shouldReceive('error')->byDefault();
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks 검증
    // ═══════════════════════════════════════════

    #[Test]
    public function getSubscribedHooks는_27개_훅을_반환한다(): void
    {
        $hooks = BoardActivityLogListener::getSubscribedHooks();

        $this->assertCount(27, $hooks);
    }

    #[Test]
    public function getSubscribedHooks는_올바른_메서드_매핑을_포함한다(): void
    {
        $hooks = BoardActivityLogListener::getSubscribedHooks();

        // Board 훅
        $this->assertEquals('handleBoardAfterCreate', $hooks['sirsoft-board.board.after_create']['method']);
        $this->assertEquals('handleBoardAfterUpdate', $hooks['sirsoft-board.board.after_update']['method']);
        $this->assertEquals('handleBoardAfterDelete', $hooks['sirsoft-board.board.after_delete']['method']);
        $this->assertEquals('handleBoardAfterAddToMenu', $hooks['sirsoft-board.board.after_add_to_menu']['method']);
        $this->assertEquals('handleSettingsAfterBulkApply', $hooks['sirsoft-board.settings.after_bulk_apply']['method']);

        // BoardType 훅
        $this->assertEquals('handleBoardTypeAfterCreate', $hooks['sirsoft-board.board_type.after_create']['method']);
        $this->assertEquals('handleBoardTypeAfterUpdate', $hooks['sirsoft-board.board_type.after_update']['method']);
        $this->assertEquals('handleBoardTypeAfterDelete', $hooks['sirsoft-board.board_type.after_delete']['method']);

        // Post 훅
        $this->assertEquals('handlePostAfterCreate', $hooks['sirsoft-board.post.after_create']['method']);
        $this->assertEquals('handlePostAfterUpdate', $hooks['sirsoft-board.post.after_update']['method']);
        $this->assertEquals('handlePostAfterDelete', $hooks['sirsoft-board.post.after_delete']['method']);
        $this->assertEquals('handlePostAfterBlind', $hooks['sirsoft-board.post.after_blind']['method']);
        $this->assertEquals('handlePostAfterRestore', $hooks['sirsoft-board.post.after_restore']['method']);

        // Comment 훅
        $this->assertEquals('handleCommentAfterCreate', $hooks['sirsoft-board.comment.after_create']['method']);
        $this->assertEquals('handleCommentAfterUpdate', $hooks['sirsoft-board.comment.after_update']['method']);
        $this->assertEquals('handleCommentAfterDelete', $hooks['sirsoft-board.comment.after_delete']['method']);
        $this->assertEquals('handleCommentAfterBlind', $hooks['sirsoft-board.comment.after_blind']['method']);
        $this->assertEquals('handleCommentAfterRestore', $hooks['sirsoft-board.comment.after_restore']['method']);

        // Attachment 훅
        $this->assertEquals('handleAttachmentAfterUpload', $hooks['sirsoft-board.attachment.after_upload']['method']);
        $this->assertEquals('handleAttachmentAfterDelete', $hooks['sirsoft-board.attachment.after_delete']['method']);

        // Report 훅
        $this->assertEquals('handleReportAfterCreate', $hooks['sirsoft-board.report.after_create']['method']);
        $this->assertEquals('handleReportAfterUpdateStatus', $hooks['sirsoft-board.report.after_update_status']['method']);
        $this->assertEquals('handleReportAfterBulkUpdateStatus', $hooks['sirsoft-board.report.after_bulk_update_status']['method']);
        $this->assertEquals('handleReportAfterDelete', $hooks['sirsoft-board.report.after_delete']['method']);
        $this->assertEquals('handleReportAfterRestoreContent', $hooks['sirsoft-board.report.after_restore_content']['method']);
        $this->assertEquals('handleReportAfterBlindContent', $hooks['sirsoft-board.report.after_blind_content']['method']);
        $this->assertEquals('handleReportAfterDeleteContent', $hooks['sirsoft-board.report.after_delete_content']['method']);
    }

    #[Test]
    public function 모든_훅은_priority_20이다(): void
    {
        $hooks = BoardActivityLogListener::getSubscribedHooks();

        // 모든 훅은 로깅 훅 (priority 20)
        $this->assertEquals(20, $hooks['sirsoft-board.board.after_create']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-board.board.after_update']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-board.board.after_delete']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-board.post.after_update']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-board.comment.after_update']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-board.report.after_bulk_update_status']['priority']);
    }

    // ═══════════════════════════════════════════
    // Board 핸들러 검증
    // ═══════════════════════════════════════════

    #[Test]
    public function handleBoardAfterCreate는_board_create_로그를_기록한다(): void
    {
        $board = $this->createMockBoard(1, '공지사항', 'notice');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board.create', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_create'
                    && $context['description_params']['board_name'] === '공지사항'
                    && $context['properties']['name'] === '공지사항'
                    && $context['properties']['slug'] === 'notice';
            }));

        $this->listener->handleBoardAfterCreate($board, ['name' => '공지사항']);
    }

    #[Test]
    public function handleBoardAfterUpdate는_변경사항과_함께_board_update_로그를_기록한다(): void
    {
        $board = $this->createMockBoard(1, '공지사항', 'notice');
        $snapshot = $board->toArray();

        // 업데이트된 보드
        $updatedBoard = $this->createMockBoard(1, '새공지사항', 'notice');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board.update', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_update'
                    && $context['description_params']['board_name'] === '새공지사항'
                    && array_key_exists('changes', $context);
            }));

        $this->listener->handleBoardAfterUpdate($updatedBoard, ['name' => '새공지사항'], $snapshot);
    }

    #[Test]
    public function handleBoardAfterDelete는_board_delete_로그를_기록한다(): void
    {
        $board = $this->createMockBoard(1, '공지사항', 'notice');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board.delete', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_delete'
                    && $context['description_params']['board_name'] === '공지사항'
                    && $context['properties']['name'] === '공지사항'
                    && $context['properties']['slug'] === 'notice';
            }));

        $this->listener->handleBoardAfterDelete($board);
    }

    #[Test]
    public function handleBoardAfterAddToMenu는_board_add_to_menu_로그를_기록한다(): void
    {
        $menu = Mockery::mock(Menu::class)->makePartial();
        $menu->shouldReceive('getAttribute')->with('id')->andReturn(99);
        $menu->shouldReceive('getAttribute')->with('name')->andReturn('메인메뉴');

        $board = $this->createMockBoard(1, '공지사항', 'notice');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board.add_to_menu', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_add_to_menu'
                    && $context['description_params']['board_name'] === '공지사항'
                    && $context['description_params']['menu_name'] === '메인메뉴'
                    && $context['properties']['menu_id'] === 99
                    && $context['properties']['board_id'] === 1;
            }));

        $this->listener->handleBoardAfterAddToMenu($menu, $board);
    }

    #[Test]
    public function handleSettingsAfterBulkApply는_settings_bulk_apply_로그를_기록한다(): void
    {
        $fields = ['comments_per_page', 'posts_per_page'];
        $updatedCount = 5;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board_settings.bulk_apply', Mockery::on(function (array $context) use ($fields, $updatedCount) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_settings_bulk_apply'
                    && $context['description_params']['updated_count'] === $updatedCount
                    && $context['properties']['fields'] === $fields
                    && $context['properties']['updated_count'] === $updatedCount;
            }));

        $this->listener->handleSettingsAfterBulkApply($fields, $updatedCount);
    }

    // ═══════════════════════════════════════════
    // BoardType 핸들러 검증
    // ═══════════════════════════════════════════

    #[Test]
    public function handleBoardTypeAfterCreate는_board_type_create_로그를_기록한다(): void
    {
        $boardType = $this->createMockBoardType(1, '일반형');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board_type.create', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_type_create'
                    && $context['description_params']['type_name'] === '일반형'
                    && $context['properties']['name'] === '일반형';
            }));

        $this->listener->handleBoardTypeAfterCreate($boardType, ['name' => '일반형']);
    }

    #[Test]
    public function handleBoardTypeAfterUpdate는_변경사항과_함께_board_type_update_로그를_기록한다(): void
    {
        $boardType = $this->createMockBoardType(5, '일반형');
        $snapshot = $boardType->toArray();

        $updatedBoardType = $this->createMockBoardType(5, '갤러리형');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board_type.update', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_type_update'
                    && $context['description_params']['type_name'] === '갤러리형'
                    && array_key_exists('changes', $context);
            }));

        $this->listener->handleBoardTypeAfterUpdate($updatedBoardType, ['name' => '갤러리형'], $snapshot);
    }

    #[Test]
    public function handleBoardTypeAfterDelete는_board_type_delete_로그를_기록한다(): void
    {
        $boardType = $this->createMockBoardType(1, '일반형');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board_type.delete', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_type_delete'
                    && $context['description_params']['type_name'] === '일반형'
                    && $context['properties']['name'] === '일반형';
            }));

        $this->listener->handleBoardTypeAfterDelete($boardType);
    }

    // ═══════════════════════════════════════════
    // Post 핸들러 검증 (dataProvider 사용)
    // ═══════════════════════════════════════════

    #[Test]
    public function handlePostAfterCreate는_post_create_로그를_기록한다(): void
    {
        $board = $this->createMockBoard(1, '공지사항', 'notice');
        $post = $this->createMockPostWithBoard(10, '테스트 게시글', $board);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('post.create', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.post_create'
                    && $context['description_params']['title'] === '테스트 게시글'
                    && $context['description_params']['board_name'] === '공지사항'
                    && $context['properties']['title'] === '테스트 게시글'
                    && $context['properties']['slug'] === 'notice';
            }));

        $this->listener->handlePostAfterCreate($post, 'notice');
    }

    #[Test]
    public function handlePostAfterUpdate는_변경사항과_함께_post_update_로그를_기록한다(): void
    {
        $post = $this->createMockPost(10, '원래 제목');
        $snapshot = $post->toArray();

        $board = $this->createMockBoard(1, '공지사항', 'notice');
        $updatedPost = $this->createMockPostWithBoard(10, '수정된 제목', $board);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('post.update', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.post_update'
                    && $context['description_params']['title'] === '수정된 제목'
                    && $context['description_params']['board_name'] === '공지사항'
                    && array_key_exists('changes', $context);
            }));

        $this->listener->handlePostAfterUpdate($updatedPost, 'notice', $snapshot);
    }

    /**
     * Post 단순 로깅 핸들러 데이터 프로바이더 (delete, blind, restore)
     *
     * @return array<string, array{string, string, string}>
     */
    public static function postSimpleLogDataProvider(): array
    {
        return [
            'delete' => ['handlePostAfterDelete', 'post.delete', 'sirsoft-board::activity_log.description.post_delete'],
            'blind' => ['handlePostAfterBlind', 'post.blind', 'sirsoft-board::activity_log.description.post_blind'],
            'restore' => ['handlePostAfterRestore', 'post.restore', 'sirsoft-board::activity_log.description.post_restore'],
        ];
    }

    #[Test]
    #[DataProvider('postSimpleLogDataProvider')]
    public function post_단순_로깅_핸들러는_올바른_로그를_기록한다(
        string $method,
        string $expectedAction,
        string $expectedDescriptionKey
    ): void {
        $board = $this->createMockBoard(1, '공지사항', 'notice');
        $post = $this->createMockPostWithBoard(10, '테스트 게시글', $board);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with($expectedAction, Mockery::on(function (array $context) use ($expectedDescriptionKey) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === $expectedDescriptionKey
                    && $context['description_params']['post_id'] === 10
                    && $context['description_params']['board_name'] === '공지사항'
                    && $context['properties']['title'] === '테스트 게시글'
                    && $context['properties']['slug'] === 'notice';
            }));

        $this->listener->{$method}($post, 'notice');
    }

    // ═══════════════════════════════════════════
    // Comment 핸들러 검증 (dataProvider 사용)
    // ═══════════════════════════════════════════

    #[Test]
    public function handleCommentAfterCreate는_comment_create_로그를_기록한다(): void
    {
        $board = $this->createMockBoard(1, '공지사항', 'notice');
        $comment = $this->createMockComment(20, 10, $board);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('comment.create', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.comment_create'
                    && $context['description_params']['board_name'] === '공지사항'
                    && $context['description_params']['post_id'] === 10
                    && $context['properties']['slug'] === 'notice'
                    && $context['properties']['post_id'] === 10;
            }));

        $this->listener->handleCommentAfterCreate($comment, 'notice');
    }

    #[Test]
    public function handleCommentAfterUpdate는_변경사항과_함께_comment_update_로그를_기록한다(): void
    {
        $comment = $this->createMockComment(20, 10);
        $snapshot = $comment->toArray();

        $updatedComment = $this->createMockComment(20, 10);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('comment.update', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.comment_update'
                    && $context['description_params']['comment_id'] === 20
                    && array_key_exists('changes', $context);
            }));

        $this->listener->handleCommentAfterUpdate($updatedComment, 'notice', $snapshot);
    }

    /**
     * Comment 단순 로깅 핸들러 데이터 프로바이더 (delete, blind, restore)
     *
     * @return array<string, array{string, string, string}>
     */
    public static function commentSimpleLogDataProvider(): array
    {
        return [
            'delete' => ['handleCommentAfterDelete', 'comment.delete', 'sirsoft-board::activity_log.description.comment_delete'],
            'blind' => ['handleCommentAfterBlind', 'comment.blind', 'sirsoft-board::activity_log.description.comment_blind'],
            'restore' => ['handleCommentAfterRestore', 'comment.restore', 'sirsoft-board::activity_log.description.comment_restore'],
        ];
    }

    #[Test]
    #[DataProvider('commentSimpleLogDataProvider')]
    public function comment_단순_로깅_핸들러는_올바른_로그를_기록한다(
        string $method,
        string $expectedAction,
        string $expectedDescriptionKey
    ): void {
        $comment = $this->createMockComment(20, 10);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with($expectedAction, Mockery::on(function (array $context) use ($expectedDescriptionKey) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === $expectedDescriptionKey
                    && $context['description_params']['comment_id'] === 20
                    && $context['properties']['slug'] === 'notice'
                    && $context['properties']['post_id'] === 10;
            }));

        $this->listener->{$method}($comment, 'notice');
    }

    // ═══════════════════════════════════════════
    // Attachment 핸들러 검증
    // ═══════════════════════════════════════════

    #[Test]
    public function handleAttachmentAfterUpload은_attachment_upload_로그를_기록한다(): void
    {
        $attachment = $this->createMockAttachment('document.pdf', 1024, 10);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('attachment.upload', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_attachment_upload'
                    && $context['description_params']['post_id'] === 10
                    && $context['properties']['original_name'] === 'document.pdf'
                    && $context['properties']['size'] === 1024;
            }));

        $this->listener->handleAttachmentAfterUpload($attachment);
    }

    #[Test]
    public function handleAttachmentAfterDelete는_attachment_delete_로그를_기록한다(): void
    {
        $attachment = $this->createMockAttachment('document.pdf', 1024, 10);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('attachment.delete', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_attachment_delete'
                    && $context['description_params']['post_id'] === 10
                    && $context['properties']['original_name'] === 'document.pdf';
            }));

        $this->listener->handleAttachmentAfterDelete($attachment);
    }

    // ═══════════════════════════════════════════
    // Report 핸들러 검증 (dataProvider 사용)
    // ═══════════════════════════════════════════

    #[Test]
    public function handleReportAfterCreate는_report_create_로그를_기록한다(): void
    {
        $report = $this->createMockReport(1, '스팸', 'post');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('report.create', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.report_create'
                    && $context['description_params']['report_id'] === 1
                    && $context['properties']['reason'] === '스팸'
                    && $context['properties']['reportable_type'] === 'post';
            }));

        $this->listener->handleReportAfterCreate($report);
    }

    #[Test]
    public function handleReportAfterUpdateStatus는_report_update_status_로그를_기록한다(): void
    {
        $report = $this->createMockReport(1, '스팸', 'post');
        // status 오버라이드 (resolved 상태)
        $report->shouldReceive('getAttribute')->with('status')->andReturn('resolved');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('report.update_status', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.report_update_status'
                    && $context['description_params']['report_id'] === 1
                    && $context['properties']['status'] === 'resolved';
            }));

        $this->listener->handleReportAfterUpdateStatus($report);
    }

    #[Test]
    public function handleReportAfterBulkUpdateStatus는_per_item_로그를_기록한다(): void
    {
        $data = ['status' => 'resolved'];
        $reports = collect([
            Report::create(['target_type' => 'post', 'target_id' => 1, 'status' => 'pending']),
            Report::create(['target_type' => 'post', 'target_id' => 2, 'status' => 'pending']),
            Report::create(['target_type' => 'post', 'target_id' => 3, 'status' => 'pending']),
        ]);
        $ids = $reports->pluck('id')->toArray();

        $loggedContexts = [];
        $this->logChannel->shouldReceive('info')
            ->times(3)
            ->with('report.bulk_update_status', Mockery::on(function (array $context) use (&$loggedContexts, $ids, $data) {
                $loggedContexts[] = $context;

                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.report_bulk_update_status'
                    && $context['description_params']['count'] === 1
                    && isset($context['loggable'])
                    && in_array($context['properties']['report_id'], $ids)
                    && $context['properties']['data'] === $data;
            }));

        $this->listener->handleReportAfterBulkUpdateStatus($ids, $data, 3);

        $this->assertCount(3, $loggedContexts);
    }

    #[Test]
    public function handleReportAfterDelete는_report_delete_로그를_기록한다(): void
    {
        $report = $this->createMockReport(1, '스팸', 'post');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('report.delete', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.report_delete'
                    && $context['description_params']['report_id'] === 1;
            }));

        $this->listener->handleReportAfterDelete($report);
    }

    /**
     * Report 콘텐츠 처리 핸들러 데이터 프로바이더 (restore_content, blind_content, delete_content)
     *
     * @return array<string, array{string, string, string}>
     */
    public static function reportContentActionDataProvider(): array
    {
        return [
            'restore_content' => [
                'handleReportAfterRestoreContent',
                'report.restore_content',
                'sirsoft-board::activity_log.description.report_restore_content',
            ],
            'blind_content' => [
                'handleReportAfterBlindContent',
                'report.blind_content',
                'sirsoft-board::activity_log.description.report_blind_content',
            ],
            'delete_content' => [
                'handleReportAfterDeleteContent',
                'report.delete_content',
                'sirsoft-board::activity_log.description.report_delete_content',
            ],
        ];
    }

    #[Test]
    #[DataProvider('reportContentActionDataProvider')]
    public function report_콘텐츠_처리_핸들러는_올바른_로그를_기록한다(
        string $method,
        string $expectedAction,
        string $expectedDescriptionKey
    ): void {
        $report = $this->createMockReport(7, '스팸', 'post');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with($expectedAction, Mockery::on(function (array $context) use ($expectedDescriptionKey) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === $expectedDescriptionKey
                    && $context['description_params']['report_id'] === 7;
            }));

        $this->listener->{$method}($report);
    }

    // ═══════════════════════════════════════════
    // 예외 처리 검증
    // ═══════════════════════════════════════════

    #[Test]
    public function logActivity_예외_발생_시_Log_error로_기록한다(): void
    {
        $board = $this->createMockBoard(1, '공지사항', 'notice');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->andThrow(new \Exception('DB connection failed'));

        Log::shouldReceive('error')
            ->once()
            ->with('Failed to record activity log', Mockery::on(function (array $context) {
                return $context['action'] === 'board.create'
                    && $context['error'] === 'DB connection failed';
            }));

        // 예외가 전파되지 않아야 한다
        $this->listener->handleBoardAfterCreate($board, []);
    }

    // ═══════════════════════════════════════════
    // handle() 기본 핸들러 검증
    // ═══════════════════════════════════════════

    #[Test]
    public function handle_기본_핸들러는_아무_작업도_수행하지_않는다(): void
    {
        // handle()은 빈 메서드 — 예외 없이 호출되어야 한다
        $this->listener->handle('arg1', 'arg2');
        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════
    // 엣지 케이스: loggable 미포함 훅 (settings, bulk_update_status)
    // ═══════════════════════════════════════════

    #[Test]
    public function handleSettingsAfterBulkApply는_loggable_없이_로그를_기록한다(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board_settings.bulk_apply', Mockery::on(function (array $context) {
                return ! array_key_exists('loggable', $context)
                    && $context['log_type'] === ActivityLogType::Admin;
            }));

        $this->listener->handleSettingsAfterBulkApply(['field1'], 3);
    }

    #[Test]
    public function handleReportAfterBulkUpdateStatus는_존재하지_않는_id는_건너뛴다(): void
    {
        $report = Report::create(['target_type' => 'post', 'target_id' => 1, 'status' => 'pending']);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('report.bulk_update_status', Mockery::on(function (array $context) use ($report) {
                return isset($context['loggable'])
                    && $context['properties']['report_id'] === $report->id;
            }));

        $this->listener->handleReportAfterBulkUpdateStatus([$report->id, 99999], ['status' => 'resolved'], 2);
    }

    // ═══════════════════════════════════════════
    // 엣지 케이스: 스냅샷 없이 update 호출
    // ═══════════════════════════════════════════

    #[Test]
    public function handleBoardAfterUpdate_스냅샷_없이_호출해도_정상_동작한다(): void
    {
        $board = $this->createMockBoard(99, '테스트', 'test');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board.update', Mockery::on(function (array $context) {
                // 스냅샷 없으면 ChangeDetector::detect는 null 반환
                return $context['changes'] === null;
            }));

        $this->listener->handleBoardAfterUpdate($board, []);
    }

    #[Test]
    public function handleBoardTypeAfterUpdate_스냅샷_없이_호출해도_정상_동작한다(): void
    {
        $boardType = $this->createMockBoardType(99, '테스트유형');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board_type.update', Mockery::on(function (array $context) {
                return $context['changes'] === null;
            }));

        $this->listener->handleBoardTypeAfterUpdate($boardType, []);
    }

    #[Test]
    public function handlePostAfterUpdate_스냅샷_없이_호출해도_정상_동작한다(): void
    {
        $board = $this->createMockBoard(1, '공지사항', 'notice');
        $post = $this->createMockPostWithBoard(99, '테스트', $board);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('post.update', Mockery::on(function (array $context) {
                return $context['changes'] === null;
            }));

        $this->listener->handlePostAfterUpdate($post, 'notice');
    }

    #[Test]
    public function handleCommentAfterUpdate_스냅샷_없이_호출해도_정상_동작한다(): void
    {
        $comment = $this->createMockComment(99, 10);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('comment.update', Mockery::on(function (array $context) {
                return $context['changes'] === null;
            }));

        $this->listener->handleCommentAfterUpdate($comment, 'notice');
    }

    // ═══════════════════════════════════════════
    // Mock 헬퍼 메서드
    // ═══════════════════════════════════════════

    /**
     * Board 모의 객체를 생성합니다.
     *
     * makePartial()로 Eloquent __get → getAttribute 체인이 정상 동작하도록 합니다.
     *
     * @param int $id 게시판 ID
     * @param string $name 게시판 이름
     * @param string $slug 게시판 슬러그
     * @return Board&\Mockery\MockInterface
     */
    private function createMockBoard(int $id, string $name, string $slug): Board
    {
        $board = Mockery::mock(Board::class)->makePartial();
        $board->shouldReceive('getAttribute')->with('id')->andReturn($id);
        $board->shouldReceive('getAttribute')->with('name')->andReturn($name);
        $board->shouldReceive('getAttribute')->with('slug')->andReturn($slug);
        $board->shouldReceive('toArray')->andReturn([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
        ]);

        return $board;
    }

    /**
     * BoardType 모의 객체를 생성합니다.
     *
     * @param int $id 유형 ID
     * @param string $name 유형 이름
     * @return BoardType&\Mockery\MockInterface
     */
    private function createMockBoardType(int $id, string $name): BoardType
    {
        $boardType = Mockery::mock(BoardType::class)->makePartial();
        $boardType->shouldReceive('getAttribute')->with('id')->andReturn($id);
        $boardType->shouldReceive('getAttribute')->with('name')->andReturn($name);
        $boardType->shouldReceive('toArray')->andReturn([
            'id' => $id,
            'name' => $name,
        ]);

        return $boardType;
    }

    /**
     * Post 모의 객체를 생성합니다 (board 관계 없음).
     *
     * @param int $id 게시글 ID
     * @param string $title 게시글 제목
     * @return Post&\Mockery\MockInterface
     */
    private function createMockPost(int $id, string $title): Post
    {
        $post = Mockery::mock(Post::class)->makePartial();
        $post->shouldReceive('getAttribute')->with('id')->andReturn($id);
        $post->shouldReceive('getAttribute')->with('title')->andReturn($title);
        $post->shouldReceive('toArray')->andReturn([
            'id' => $id,
            'title' => $title,
        ]);

        return $post;
    }

    /**
     * Post 모의 객체를 생성합니다 (board 관계 포함, loadMissing 호출 처리).
     *
     * @param int $id 게시글 ID
     * @param string $title 게시글 제목
     * @param Board&\Mockery\MockInterface $board 관련 게시판
     * @return Post&\Mockery\MockInterface
     */
    private function createMockPostWithBoard(int $id, string $title, $board): Post
    {
        $post = Mockery::mock(Post::class)->makePartial();
        $post->shouldReceive('getAttribute')->with('id')->andReturn($id);
        $post->shouldReceive('getAttribute')->with('title')->andReturn($title);
        $post->shouldReceive('getAttribute')->with('board')->andReturn($board);
        $post->shouldReceive('loadMissing')->with('board')->andReturnSelf();
        $post->shouldReceive('toArray')->andReturn([
            'id' => $id,
            'title' => $title,
        ]);

        return $post;
    }

    /**
     * Comment 모의 객체를 생성합니다.
     *
     * @param int $id 댓글 ID
     * @param int $postId 게시글 ID
     * @return Comment&\Mockery\MockInterface
     */
    private function createMockComment(int $id, int $postId, $board = null): Comment
    {
        // post mock (board 관계 포함)
        $post = Mockery::mock(Post::class)->makePartial();
        $post->shouldReceive('getAttribute')->with('id')->andReturn($postId);
        $post->shouldReceive('getAttribute')->with('board')->andReturn($board);

        $comment = Mockery::mock(Comment::class)->makePartial();
        $comment->shouldReceive('getAttribute')->with('id')->andReturn($id);
        $comment->shouldReceive('getAttribute')->with('post_id')->andReturn($postId);
        $comment->shouldReceive('getAttribute')->with('post')->andReturn($post);
        $comment->shouldReceive('loadMissing')->with('post.board')->andReturnSelf();
        $comment->shouldReceive('toArray')->andReturn([
            'id' => $id,
            'post_id' => $postId,
        ]);

        return $comment;
    }

    /**
     * Attachment 모의 객체를 생성합니다.
     *
     * @param string $originalName 원본 파일명
     * @param int $size 파일 크기
     * @return Attachment&\Mockery\MockInterface
     */
    private function createMockAttachment(string $originalName, int $size, ?int $postId = null): Attachment
    {
        $attachment = Mockery::mock(Attachment::class)->makePartial();
        $attachment->shouldReceive('getAttribute')->with('original_name')->andReturn($originalName);
        $attachment->shouldReceive('getAttribute')->with('size')->andReturn($size);
        $attachment->shouldReceive('getAttribute')->with('post_id')->andReturn($postId);

        return $attachment;
    }

    /**
     * Report 모의 객체를 생성합니다.
     *
     * @param int $id 신고 ID
     * @param string $reason 신고 사유
     * @param string $reportableType 신고 대상 타입
     * @return Report&\Mockery\MockInterface
     */
    private function createMockReport(int $id, string $reason, string $reportableType): Report
    {
        $report = Mockery::mock(Report::class)->makePartial();
        $report->shouldReceive('getAttribute')->with('id')->andReturn($id);
        $report->shouldReceive('getAttribute')->with('reason')->andReturn($reason);
        $report->shouldReceive('getAttribute')->with('reportable_type')->andReturn($reportableType);
        $report->shouldReceive('getAttribute')->with('status')->andReturn(null)->byDefault();

        return $report;
    }

}
