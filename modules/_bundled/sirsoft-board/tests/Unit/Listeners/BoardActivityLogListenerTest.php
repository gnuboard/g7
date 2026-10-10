<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use App\ActivityLog\ChangeDetector;
use App\Enums\ActivityLogType;
use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Board\Enums\ReportType;
use Modules\Sirsoft\Board\Listeners\BoardActivityLogListener;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

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
 *
 * @effects aborted_activity_log_recorded
 */
class BoardActivityLogListenerTest extends ModuleTestCase
{
    private BoardActivityLogListener $listener;

    private $logChannel;

    /**
     * 각 테스트 전에 공통 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('request', Request::create('/api/admin/test'));

        $this->listener = app(BoardActivityLogListener::class);
        $this->logChannel = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturn($this->logChannel);
        // catch 블록의 Log::error() 호출 허용 (기본적으로 호출되지 않아야 함)
        Log::shouldReceive('error')->byDefault();
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks 검증
    // ═══════════════════════════════════════════

    /**
     * 테스트를 돕는 헬퍼입니다.
     *
     * @scenario case=subscription_contract
     *
     * @effects subscribed_hooks_map_to_existing_handlers
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 구독한_훅은_전건_실재하는_핸들러를_가리킨다(): void
    {
        $hooks = BoardActivityLogListener::getSubscribedHooks();

        // 개수를 손으로 박지 않는다 — 종전 `assertCount(N)` 은 27→28→29→30 으로 네 번
        // 낡았고 기준 커밋 221beb3a4 시점에 이미 red 였다(실제 32 ≠ 기대 30). 개수만
        // 재느라 정작 「매핑이 실재하는가」는 끝내 재지 못했다(부록 B D8).
        // 모집단은 선언 자체에서 파생하고, 재는 것을 「구독한 훅마다 그 이름의 public
        // 핸들러가 실제로 있는가」로 바꾼다.
        $this->assertNotEmpty($hooks, '구독 훅 목록이 비면 아래 루프가 공허하게 통과한다');

        foreach ($hooks as $hookName => $config) {
            $this->assertArrayHasKey('method', $config, "{$hookName} 에 method 키가 없다");
            $this->assertTrue(
                method_exists(BoardActivityLogListener::class, $config['method']),
                "{$hookName} => {$config['method']}() 가 리스너에 없다",
            );
        }
    }

    /**
     * 테스트를 돕는 헬퍼입니다.
     *
     * @scenario case=subscription_contract
     *
     * @effects subscribed_hooks_map_to_existing_handlers
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function get_subscribed_hooks는_올바른_메서드_매핑을_포함한다(): void
    {
        $hooks = BoardActivityLogListener::getSubscribedHooks();

        // Board 훅
        $this->assertEquals('handleBoardAfterCreate', $hooks['sirsoft-board.board.after_create']['method']);
        $this->assertEquals('handleBoardAfterUpdate', $hooks['sirsoft-board.board.after_update']['method']);
        $this->assertEquals('handleBoardAfterDelete', $hooks['sirsoft-board.board.after_delete']['method']);
        $this->assertEquals('handleBoardAfterAddToMenu', $hooks['sirsoft-board.board.after_add_to_menu']['method']);
        $this->assertEquals('handleSettingsAfterBulkApply', $hooks['sirsoft-board.settings.after_bulk_apply']['method']);
        $this->assertEquals('handleSettingsAfterBulkApplyAborted', $hooks['sirsoft-board.settings.after_bulk_apply_aborted']['method']);

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
        $this->assertEquals('handleAttachmentAfterDownload', $hooks['sirsoft-board.attachment.after_download']['method']);

        // Report 훅
        $this->assertEquals('handleReportAfterCreate', $hooks['sirsoft-board.report.after_create']['method']);
        $this->assertEquals('handleReportAfterUpdateStatus', $hooks['sirsoft-board.report.after_update_status']['method']);
        $this->assertEquals('handleReportAfterBulkUpdateStatus', $hooks['sirsoft-board.report.after_bulk_update_status']['method']);
        $this->assertEquals('handleReportAfterDelete', $hooks['sirsoft-board.report.after_delete']['method']);
        $this->assertEquals('handleReportAfterRestoreContent', $hooks['sirsoft-board.report.after_restore_content']['method']);
        $this->assertEquals('handleReportAfterBlindContent', $hooks['sirsoft-board.report.after_blind_content']['method']);
        $this->assertEquals('handleReportAfterDeleteContent', $hooks['sirsoft-board.report.after_delete_content']['method']);
    }

    /**
     * 테스트를 돕는 헬퍼입니다.
     *
     * @scenario case=subscription_contract
     *
     * @effects all_hooks_registered_at_priority_20
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 모든_훅은_priority_20이다(): void
    {
        $hooks = BoardActivityLogListener::getSubscribedHooks();

        // 모집단을 손으로 적지 않는다 — 구독 선언 전량을 돌려야 나중에 추가된 훅도 감시 안에 든다.
        // 손으로 고른 목록이던 시절, 이번에 늘어난 훅 4종이 검사 밖에 있었다.
        $this->assertNotEmpty($hooks, '구독 훅 모집단이 비면 이 검사는 공허하게 통과한다.');

        // 모든 훅은 로깅 훅 (priority 20)
        foreach ($hooks as $hookName => $definition) {
            $this->assertSame(
                20,
                $definition['priority'],
                "훅 {$hookName} 의 priority 가 로깅 훅 규약(20)과 다르다."
            );
        }
    }

    // ═══════════════════════════════════════════
    // Board 핸들러 검증
    // ═══════════════════════════════════════════

    /**
     * 게시판 · 생성 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=board_handlers
     *
     * @effects board_crud_and_menu_changes_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_board_after_create는_board_create_로그를_기록한다(): void
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

    /**
     * 게시판 · 수정 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=board_handlers
     *
     * @effects board_crud_and_menu_changes_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_board_after_update는_변경사항과_함께_board_update_로그를_기록한다(): void
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

    /**
     * 게시판 · 삭제 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=board_handlers
     *
     * @effects board_crud_and_menu_changes_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_board_after_delete는_board_delete_로그를_기록한다(): void
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

    /**
     * 게시판 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=board_handlers
     *
     * @effects board_crud_and_menu_changes_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_board_after_add_to_menu는_board_add_to_menu_로그를_기록한다(): void
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

    /**
     * 설정 · 일괄 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=settings_bulk_apply_handlers
     *
     * @effects bulk_apply_logs_with_and_without_loggable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_settings_after_bulk_apply는_settings_bulk_apply_로그를_기록한다(): void
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

    /**
     * 설정 · 일괄 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=settings_bulk_apply_handlers
     *
     * @effects bulk_apply_logs_with_and_without_loggable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_settings_after_bulk_apply_aborted는_권한_실패_시_게시판명을_담아_기록한다(): void
    {
        $fields = ['manager', 'per_page'];
        $context = [
            'failed_board' => ['id' => 7, 'name' => '자유게시판'],
            'failed_at' => 3,
            'total' => 5,
            'failure_reason' => 'SQLSTATE[23000]: permission write failed',
        ];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board_settings.bulk_apply_aborted', Mockery::on(function (array $log) use ($fields, $context) {
                return $log['log_type'] === ActivityLogType::Admin
                    && $log['description_key'] === 'sirsoft-board::activity_log.description.board_settings_bulk_apply_aborted'
                    // 화면/이력 문구에 실패 게시판명이 채워진다
                    && $log['description_params']['failed_board_name'] === '자유게시판'
                    && $log['description_params']['failed_at'] === 3
                    && $log['description_params']['total'] === 5
                    && $log['properties']['fields'] === $fields
                    && $log['properties']['failed_board'] === $context['failed_board']
                    // 상세 사유는 properties 에만 추적용으로 기록
                    && $log['properties']['failure_reason'] === $context['failure_reason'];
            }));

        $this->listener->handleSettingsAfterBulkApplyAborted($fields, $context);
    }

    /**
     * 설정 · 일괄 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=settings_bulk_apply_handlers
     *
     * @effects bulk_apply_logs_with_and_without_loggable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_settings_after_bulk_apply_aborted는_컬럼_실패_시_게시판명없이_기록한다(): void
    {
        // 컬럼 일괄 업데이트 실패는 특정 게시판을 짚을 수 없어 failed_board=null
        $fields = ['per_page', 'order_by'];
        $context = [
            'failed_board' => null,
            'failed_at' => null,
            'total' => 5,
            'failure_reason' => 'SQLSTATE[42S22]: column update failed',
        ];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board_settings.bulk_apply_aborted', Mockery::on(function (array $log) {
                return $log['description_params']['failed_board_name'] === '-'
                    && $log['description_params']['failed_at'] === 0
                    && $log['properties']['failed_board'] === null
                    && $log['properties']['failed_at'] === null;
            }));

        $this->listener->handleSettingsAfterBulkApplyAborted($fields, $context);
    }

    // ═══════════════════════════════════════════
    // BoardType 핸들러 검증
    // ═══════════════════════════════════════════

    /**
     * 게시판 · 유형 · 생성 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=board_type_handlers
     *
     * @effects board_type_crud_is_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_board_type_after_create는_board_type_create_로그를_기록한다(): void
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

    /**
     * 게시판 · 유형 · 수정 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=board_type_handlers
     *
     * @effects board_type_crud_is_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_board_type_after_update는_변경사항과_함께_board_type_update_로그를_기록한다(): void
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

    /**
     * 게시판 · 유형 · 삭제 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=board_type_handlers
     *
     * @effects board_type_crud_is_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_board_type_after_delete는_board_type_delete_로그를_기록한다(): void
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

    /**
     * 게시글 · 생성 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=post_handlers
     *
     * @effects post_crud_and_state_changes_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_post_after_create는_post_create_로그를_기록한다(): void
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

    /**
     * 게시글 · 수정 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=post_handlers
     *
     * @effects post_crud_and_state_changes_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_post_after_update는_변경사항과_함께_post_update_로그를_기록한다(): void
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
     * @return array<string, array{string, string, string}> array<string, 인스턴스
     */
    public static function postSimpleLogDataProvider(): array
    {
        return [
            'delete' => ['handlePostAfterDelete', 'post.delete', 'sirsoft-board::activity_log.description.post_delete'],
            'blind' => ['handlePostAfterBlind', 'post.blind', 'sirsoft-board::activity_log.description.post_blind'],
            'restore' => ['handlePostAfterRestore', 'post.restore', 'sirsoft-board::activity_log.description.post_restore'],
        ];
    }

    /**
     * 게시글 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=post_handlers
     *
     * @effects post_crud_and_state_changes_are_logged
     *
     * @param  $method  HTTP 메서드
     * @param  $expectedAction  expectedAction 값
     * @param  $expectedDescriptionKey  expectedDescriptionKey 값
     * @return void 반환값 없음
     */
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

    /**
     * 댓글 · 생성 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=comment_handlers
     *
     * @effects comment_crud_and_state_changes_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_comment_after_create는_comment_create_로그를_기록한다(): void
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

    /**
     * 댓글 · 수정 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=comment_handlers
     *
     * @effects comment_crud_and_state_changes_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_comment_after_update는_변경사항과_함께_comment_update_로그를_기록한다(): void
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
     * @return array<string, array{string, string, string}> array<string, 인스턴스
     */
    public static function commentSimpleLogDataProvider(): array
    {
        return [
            'delete' => ['handleCommentAfterDelete', 'comment.delete', 'sirsoft-board::activity_log.description.comment_delete'],
            'blind' => ['handleCommentAfterBlind', 'comment.blind', 'sirsoft-board::activity_log.description.comment_blind'],
            'restore' => ['handleCommentAfterRestore', 'comment.restore', 'sirsoft-board::activity_log.description.comment_restore'],
        ];
    }

    /**
     * 댓글 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=comment_handlers
     *
     * @effects comment_crud_and_state_changes_are_logged
     *
     * @param  $method  HTTP 메서드
     * @param  $expectedAction  expectedAction 값
     * @param  $expectedDescriptionKey  expectedDescriptionKey 값
     * @return void 반환값 없음
     */
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

    /**
     * 테스트를 돕는 헬퍼입니다.
     *
     * @scenario case=attachment_handlers
     *
     * @effects attachment_upload_delete_download_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_attachment_after_upload은_attachment_upload_로그를_기록한다(): void
    {
        $attachment = $this->createMockAttachment('document.pdf', 1024, 10);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('attachment.upload', Mockery::on(function (array $context) {
                // #413-58: original_filename 으로 정정 (이전 original_name 은 모델 필드 부재로 항상 빈값)
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_attachment_upload'
                    && $context['description_params']['post_id'] === 10
                    && $context['properties']['original_filename'] === 'document.pdf'
                    && $context['properties']['size'] === 1024;
            }));

        $this->listener->handleAttachmentAfterUpload($attachment);
    }

    /**
     * 삭제 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=attachment_handlers
     *
     * @effects attachment_upload_delete_download_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_attachment_after_delete는_attachment_delete_로그를_기록한다(): void
    {
        $attachment = $this->createMockAttachment('document.pdf', 1024, 10);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('attachment.delete', Mockery::on(function (array $context) {
                // #413-58: original_filename 으로 정정
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_attachment_delete'
                    && $context['description_params']['post_id'] === 10
                    && $context['properties']['original_filename'] === 'document.pdf';
            }));

        $this->listener->handleAttachmentAfterDelete($attachment);
    }

    /**
     * 테스트를 돕는 헬퍼입니다.
     *
     * @scenario case=attachment_handlers
     *
     * @effects attachment_upload_delete_download_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_attachment_after_download은_attachment_download_로그를_기록한다(): void
    {
        // #413-58: 첨부 다운로드 시 활동이력 기록
        $attachment = $this->createMockAttachment('document.pdf', 1024, 10);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('attachment.download', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.board_attachment_download'
                    && $context['description_params']['post_id'] === 10
                    && $context['properties']['original_filename'] === 'document.pdf'
                    && $context['properties']['context'] === 'user';
            }));

        $this->listener->handleAttachmentAfterDownload($attachment, 'user');
    }

    /**
     * 테스트를 돕는 헬퍼입니다.
     *
     * @scenario case=snapshotless_and_null_inputs
     *
     * @effects handlers_tolerate_missing_snapshot_and_null_models
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_attachment_after_download은_null_첨부에_안전하다(): void
    {
        // #413-58: 큐 워커 시점 모델 소멸 등 — null 이면 로그 미기록
        $this->logChannel->shouldNotReceive('info');

        $this->listener->handleAttachmentAfterDownload(null, 'user');

        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════
    // Report 핸들러 검증 (dataProvider 사용)
    // ═══════════════════════════════════════════

    /**
     * 신고 · 생성 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=report_handlers
     *
     * @effects report_lifecycle_events_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_report_after_create는_report_create_로그를_기록한다(): void
    {
        $report = $this->createMockReport(1, 'post');
        $reportLog = new ReportLog(['reason_type' => 'spam']);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('report.create', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-board::activity_log.description.report_create'
                    && $context['description_params']['report_id'] === 1
                    && $context['properties']['reason'] === 'spam'
                    && $context['properties']['reportable_type'] === 'post';
            }));

        $this->listener->handleReportAfterCreate($report, $reportLog);
    }

    /**
     * 신고 · 수정 · 상태 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=report_handlers
     *
     * @effects report_lifecycle_events_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_report_after_update_status는_report_update_status_로그를_기록한다(): void
    {
        $report = $this->createMockReport(1, 'post');
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

    /**
     * 신고 · 일괄 · 수정 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=report_handlers
     *
     * @effects report_lifecycle_events_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_report_after_bulk_update_status는_per_item_로그를_기록한다(): void
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

    /**
     * 신고 · 삭제 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=report_handlers
     *
     * @effects report_lifecycle_events_are_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_report_after_delete는_report_delete_로그를_기록한다(): void
    {
        $report = $this->createMockReport(1, 'post');

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
     * @return array<string, array{string, string, string}> array<string, 인스턴스
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

    /**
     * 신고 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=report_handlers
     *
     * @effects report_lifecycle_events_are_logged
     *
     * @param  $method  HTTP 메서드
     * @param  $expectedAction  expectedAction 값
     * @param  $expectedDescriptionKey  expectedDescriptionKey 값
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('reportContentActionDataProvider')]
    public function report_콘텐츠_처리_핸들러는_올바른_로그를_기록한다(
        string $method,
        string $expectedAction,
        string $expectedDescriptionKey
    ): void {
        $report = $this->createMockReport(7, 'post');

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

    /**
     * 로그 · 활동 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=logging_failure_is_absorbed
     *
     * @effects logging_exception_is_absorbed_into_log_error
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function log_activity_예외_발생_시_log_error로_기록한다(): void
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

    /**
     * 테스트를 돕는 헬퍼입니다.
     *
     * @scenario case=snapshotless_and_null_inputs
     *
     * @effects handlers_tolerate_missing_snapshot_and_null_models
     *
     * @return void 반환값 없음
     */
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

    /**
     * 설정 · 일괄 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=settings_bulk_apply_handlers
     *
     * @effects bulk_apply_logs_with_and_without_loggable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_settings_after_bulk_apply는_loggable_없이_로그를_기록한다(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board_settings.bulk_apply', Mockery::on(function (array $context) {
                return ! array_key_exists('loggable', $context)
                    && $context['log_type'] === ActivityLogType::Admin;
            }));

        $this->listener->handleSettingsAfterBulkApply(['field1'], 3);
    }

    /**
     * 신고 · 일괄 · 수정 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=snapshotless_and_null_inputs
     *
     * @effects handlers_tolerate_missing_snapshot_and_null_models
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_report_after_bulk_update_status는_존재하지_않는_id는_건너뛴다(): void
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

    /**
     * 게시판 · 수정 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=snapshotless_and_null_inputs
     *
     * @effects handlers_tolerate_missing_snapshot_and_null_models
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_board_after_update_스냅샷_없이_호출해도_정상_동작한다(): void
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

    /**
     * 게시판 · 유형 · 수정 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=snapshotless_and_null_inputs
     *
     * @effects handlers_tolerate_missing_snapshot_and_null_models
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_board_type_after_update_스냅샷_없이_호출해도_정상_동작한다(): void
    {
        $boardType = $this->createMockBoardType(99, '테스트유형');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('board_type.update', Mockery::on(function (array $context) {
                return $context['changes'] === null;
            }));

        $this->listener->handleBoardTypeAfterUpdate($boardType, []);
    }

    /**
     * 게시글 · 수정 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=snapshotless_and_null_inputs
     *
     * @effects handlers_tolerate_missing_snapshot_and_null_models
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_post_after_update_스냅샷_없이_호출해도_정상_동작한다(): void
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

    /**
     * 댓글 · 수정 검증을 돕는 헬퍼입니다.
     *
     * @scenario case=snapshotless_and_null_inputs
     *
     * @effects handlers_tolerate_missing_snapshot_and_null_models
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function handle_comment_after_update_스냅샷_없이_호출해도_정상_동작한다(): void
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
     * @param  int  $id  게시판 ID
     * @param  string  $name  게시판 이름
     * @param  string  $slug  게시판 슬러그
     * @return Board&MockInterface Board&MockInterface 인스턴스
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
     * @param  int  $id  유형 ID
     * @param  string  $name  유형 이름
     * @return BoardType&MockInterface BoardType&MockInterface 인스턴스
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
     * @param  int  $id  게시글 ID
     * @param  string  $title  게시글 제목
     * @return Post&MockInterface Post&MockInterface 인스턴스
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
     * @param  int  $id  게시글 ID
     * @param  string  $title  게시글 제목
     * @param  Board&MockInterface  $board  관련 게시판
     * @return Post&MockInterface Post&MockInterface 인스턴스
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
     * @param  int  $id  댓글 ID
     * @param  int  $postId  게시글 ID
     * @param  $board  board 값
     * @return Comment&MockInterface Comment&MockInterface 인스턴스
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
     * @param  string  $originalName  원본 파일명
     * @param  int  $size  파일 크기
     * @param  $postId  postId 값
     * @return Attachment&MockInterface Attachment&MockInterface 인스턴스
     */
    private function createMockAttachment(string $originalName, int $size, ?int $postId = null): Attachment
    {
        // #413-58: 실제 컬럼은 original_filename (모델에 original_name 필드/accessor 없음).
        $attachment = Mockery::mock(Attachment::class)->makePartial();
        $attachment->shouldReceive('getAttribute')->with('original_filename')->andReturn($originalName);
        $attachment->shouldReceive('getAttribute')->with('size')->andReturn($size);
        $attachment->shouldReceive('getAttribute')->with('post_id')->andReturn($postId);

        return $attachment;
    }

    /**
     * Report 모의 객체를 생성합니다.
     *
     * 케이스 모델에는 사유가 없다(사유는 신고자 로그에 있다) — 실재하는 `target_type` 만 모의한다.
     *
     * @param  int  $id  신고 ID
     * @param  string  $targetType  신고 대상 타입 (post|comment)
     * @return Report&MockInterface Report&MockInterface 인스턴스
     */
    private function createMockReport(int $id, string $targetType): Report
    {
        $report = Mockery::mock(Report::class)->makePartial();
        $report->shouldReceive('getAttribute')->with('id')->andReturn($id);
        $report->shouldReceive('getAttribute')->with('target_type')->andReturn(ReportType::from($targetType));
        $report->shouldReceive('getAttribute')->with('status')->andReturn(null)->byDefault();

        return $report;
    }
}
