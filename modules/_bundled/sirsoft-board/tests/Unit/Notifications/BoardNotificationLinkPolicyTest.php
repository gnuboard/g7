<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Notifications;

use App\Extension\ModuleManager;
use App\Notifications\GuestNotifiable;
use Modules\Sirsoft\Board\Listeners\BoardNotificationDataListener;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 알림 링크·유발자 제외 정책
 *
 * 검사 축:
 * - 새 글 관리자 통보의 링크가 **수신자에 따라** 관리자 화면으로 올라간다
 *   (공개 글 주소는 블라인드 글에서 아무것도 보여 주지 못한다)
 * - 게시판 알림 정의 전수가 `exclude_trigger_user` 를 선언한다
 *   (관리자가 자기 글을 직권 처리하고 자기가 통보받는 일이 없게)
 */
class BoardNotificationLinkPolicyTest extends ModuleTestCase
{
    private const BOARD_READ_PERMISSION = 'sirsoft-board.boards.read';

    private BoardNotificationDataListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(BoardNotificationDataListener::class);
    }

    /**
     * @effects new_post_admin_link_follows_the_recipient
     */
    public function test_new_post_admin_link_follows_the_recipient(): void
    {
        $staff = $this->createAdminUser([self::BOARD_READ_PERMISSION]);
        $member = $this->createUser();
        $guest = new GuestNotifiable('guest@example.test', '비회원');

        $data = ['post_url' => config('app.url').'/board/notice/42'];
        $context = ['board_slug' => 'notice', 'post_id' => 42];

        $staffData = $this->listener->personalizeBoardData($data, $staff, 'new_post_admin', $context);
        $this->assertStringEndsWith(
            '/admin/board/notice/post/42',
            $staffData['post_url'],
            '블라인드 글이면 공개 주소에서는 운영자가 아무것도 할 수 없습니다.'
        );

        foreach (['회원' => $member, '비회원' => $guest] as $label => $recipient) {
            $result = $this->listener->personalizeBoardData($data, $recipient, 'new_post_admin', $context);
            $this->assertStringNotContainsString(
                '/admin/',
                $result['post_url'],
                "{$label} 수신자에게 관리자 주소를 보내면 열리지 않는 화면으로 보내게 됩니다."
            );
        }
    }

    /**
     * 답글·댓글 알림의 수신자는 글쓴이 본인이므로 승격 대상이 아니다.
     *
     * @effects only_the_admin_notification_type_is_promoted
     */
    public function test_only_the_admin_notification_type_is_promoted(): void
    {
        $staff = $this->createAdminUser([self::BOARD_READ_PERMISSION]);

        $data = ['post_url' => config('app.url').'/board/notice/42'];
        $context = ['board_slug' => 'notice', 'post_id' => 42];

        $result = $this->listener->personalizeBoardData($data, $staff, 'new_comment', $context);

        $this->assertSame($data['post_url'], $result['post_url']);
    }

    /**
     * 게시판 알림 정의 전수가 유발자 제외를 선언한다.
     *
     * 선언이 빠지면 **관리자가 자기 글을 직권 처리했을 때 자기가 통보받는다.**
     *
     * @effects every_board_definition_excludes_the_trigger_user
     */
    public function test_every_board_definition_excludes_the_trigger_user(): void
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-board');
        $this->assertNotNull($module, '게시판 모듈 로드 실패');

        $missing = [];

        foreach ($module->getNotificationDefinitions() as $definition) {
            foreach ($definition['templates'] ?? [] as $template) {
                foreach ($template['recipients'] ?? [] as $recipient) {
                    if (empty($recipient['exclude_trigger_user'])) {
                        $missing[] = "{$definition['type']}({$template['channel']})";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "게시판 알림은 모두 \"남이 한 일\" 을 알리는 것이라 유발자 본인은 대상이 아닙니다:\n - "
            .implode("\n - ", $missing)
        );
    }
}
