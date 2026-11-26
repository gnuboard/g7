<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use App\Extension\HookManager;
use App\Support\ScreenUrl;
use Modules\Sirsoft\Board\Listeners\BoardNotificationDataListener;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Support\BoardScreenUrl;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 화면 주소 래퍼(BoardScreenUrl) 테스트
 *
 * 회원이 여는 주소(알림·검색·사이트맵)는 코어 화면 주소 헬퍼의 「사용자 화면 URL」·주소 필터를 따르고, 관리자 링크는
 * 사이트 주소, SEO 캐시용 기본 경로는 필터를 거치지 않는지 잠근다.
 */
class BoardScreenUrlTest extends ModuleTestCase
{
    /**
     * 사이트 주소와 사용자 화면 URL 을 다르게 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://site.example.test', 'g7_settings.core.general.user_screen_url' => 'https://front.example.test']);
    }

    /**
     * 필터를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(ScreenUrl::FILTER);

        parent::tearDown();
    }

    /**
     * 경로·절대 주소가 헬퍼를 거치고 필터가 적용되며, 기본 경로는 필터를 거치지 않습니다.
     *
     * @scenario case=wrapper
     *
     * @effects board_urls_use_helper, board_filter_applied, board_default_path_unfiltered
     *
     * @return void 반환값 없음
     */
    public function test_wrapper_paths_and_filter(): void
    {
        $this->assertSame('/boards', BoardScreenUrl::index());
        $this->assertSame('/board/free', BoardScreenUrl::list('free'));
        $this->assertSame('https://front.example.test/board/free/12', BoardScreenUrl::post('free', 12, absolute: true));

        HookManager::addFilter(ScreenUrl::FILTER, fn (string $path, string $key, array $params) => $key === 'board.post' ? '/community/'.$params['slug'].'/'.$params['id'] : $path);

        $this->assertSame('/community/free/12', BoardScreenUrl::post('free', 12));
        $this->assertSame('/board/free/12', BoardScreenUrl::defaultPath(BoardScreenUrl::POST, ['slug' => 'free', 'id' => 12]));
    }

    /**
     * 게시글 알림의 글 주소는 사용자 화면 URL, 관리자 글 주소는 사이트 주소입니다.
     *
     * @scenario case=notification
     *
     * @effects board_notification_post_url_user_screen, board_admin_url_site
     *
     * @return void 반환값 없음
     */
    public function test_notification_urls(): void
    {
        $listener = app(BoardNotificationDataListener::class);
        $board = new Board(['slug' => 'free']);
        $post = new Post;
        $post->id = 7;

        $postUrl = (new \ReflectionMethod($listener, 'buildPostUrl'))->invoke($listener, $board, $post);
        $adminUrl = (new \ReflectionMethod($listener, 'buildAdminPostUrl'))->invoke($listener, 'free', 7);

        $this->assertSame('https://front.example.test/board/free/7', $postUrl);
        $this->assertSame('https://site.example.test/admin/board/free/post/7', $adminUrl);
    }
}
