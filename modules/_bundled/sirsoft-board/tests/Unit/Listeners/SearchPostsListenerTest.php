<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Sirsoft\Board\Listeners\SearchPostsListener;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostService;
use Tests\TestCase;

/**
 * SearchPostsListener 단위 테스트 — 게시판별 권한 필터링 및 날짜 포맷 검증
 */
class SearchPostsListenerTest extends TestCase
{
    private SearchPostsListener $listener;

    private PostService $postService;

    private BoardService $boardService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postService = $this->createMock(PostService::class);
        $this->boardService = $this->createMock(BoardService::class);
        $this->listener = new SearchPostsListener($this->postService, $this->boardService);
    }

    /**
     * getSubscribedHooks()가 올바른 훅 목록을 반환하는지 확인
     */
    public function test_getSubscribedHooks_returns_correct_hooks(): void
    {
        $hooks = SearchPostsListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.search.results', $hooks);
        $this->assertArrayHasKey('core.search.build_response', $hooks);
        $this->assertArrayHasKey('core.search.validation_rules', $hooks);

        foreach ($hooks as $hook) {
            $this->assertEquals('filter', $hook['type']);
        }
    }

    /**
     * 권한 있는 게시판만 검색 결과에 포함되는지 확인
     */
    public function test_searchPosts_filters_boards_by_permission(): void
    {
        $user = User::factory()->make(['id' => 1001]);

        $boardNotice = $this->createBoardStub(1, 'notice', '공지사항');
        $boardSecret = $this->createBoardStub(2, 'secret', '비밀게시판');

        $this->boardService
            ->method('getActiveBoardsForSearch')
            ->willReturn(new Collection([$boardNotice, $boardSecret]));

        // notice 게시판만 읽기 권한 허용
        Gate::before(function ($gateUser, $ability) use ($user) {
            if ($gateUser->id !== $user->id) {
                return null;
            }
            if ($ability === 'sirsoft-board.notice.posts.read') {
                return true;
            }
            if ($ability === 'sirsoft-board.secret.posts.read') {
                return false;
            }

            return null;
        });

        // notice(id=1)만 boardIds에 포함되어 searchAcrossBoards 호출
        $this->postService
            ->method('searchAcrossBoards')
            ->with([1], '테스트', $this->anything(), $this->anything(), $this->anything())
            ->willReturn([
                'total' => 1,
                'items' => new Collection([
                    $this->createPostStub(1, 'notice', '공지사항'),
                ]),
            ]);

        $this->boardService
            ->method('getActiveBoardsListForFilter')
            ->willReturn([]);

        $context = [
            'type'     => 'all',
            'q'        => '테스트',
            'sort'     => 'relevance',
            'page'     => 1,
            'per_page' => 10,
            'user'     => $user,
            'request'  => null,
        ];

        $result = $this->listener->searchPosts([], $context);

        $this->assertArrayHasKey('posts', $result);
        $this->assertGreaterThan(0, $result['posts']['total']);
    }

    /**
     * 모든 게시판 권한이 없을 때 빈 결과를 반환하는지 확인
     */
    public function test_searchPosts_returns_empty_when_all_boards_denied(): void
    {
        $user = User::factory()->make();

        $board = $this->createBoardStub(1, 'notice', '공지사항');

        $this->boardService
            ->method('getActiveBoardsForSearch')
            ->willReturn(new Collection([$board]));

        // 모든 권한 거부
        Gate::before(fn () => false);

        $results = [];
        $context = [
            'type'    => 'all',
            'q'       => '테스트',
            'user'    => $user,
            'request' => null,
        ];

        $result = $this->listener->searchPosts($results, $context);

        if (isset($result['posts'])) {
            $this->assertEquals(0, $result['posts']['total']);
        } else {
            $this->assertArrayNotHasKey('posts', $result);
        }
    }

    /**
     * 빈 검색어일 때 스킵하는지 확인
     */
    public function test_searchPosts_skips_when_keyword_is_empty(): void
    {
        $results = [];
        $context = ['type' => 'all', 'q' => ''];

        $result = $this->listener->searchPosts($results, $context);

        $this->assertArrayNotHasKey('posts', $result);
    }

    /**
     * formatPostResult()가 created_at(요일 포함 포맷)과 created_at_formatted(표시용) 필드를 반환하는지 확인
     */
    public function test_formatPostResult_includes_created_at_and_created_at_formatted(): void
    {
        $user = User::factory()->make(['id' => 9999]);

        $board = $this->createBoardStub(1, 'notice', '공지사항');

        $this->boardService
            ->method('getActiveBoardsForSearch')
            ->willReturn(new Collection([$board]));

        $this->boardService
            ->method('getActiveBoardsListForFilter')
            ->willReturn([]);

        $this->postService
            ->method('searchAcrossBoards')
            ->willReturn([
                'total' => 1,
                'items' => new Collection([
                    $this->createPostStub(1, 'notice', '공지사항'),
                ]),
            ]);

        Gate::before(fn ($u) => $u->id === 9999 ? true : null);

        $context = [
            'type'     => 'all',
            'q'        => '테스트',
            'sort'     => 'relevance',
            'page'     => 1,
            'per_page' => 10,
            'user'     => $user,
            'request'  => null,
        ];

        $result = $this->listener->searchPosts([], $context);

        $this->assertArrayHasKey('posts', $result);
        $items = $result['posts']['items'] ?? [];
        $this->assertNotEmpty($items);

        $item = $items[0];

        // created_at: 요일 포함 전체 날짜 포맷 ("YYYY-MM-DD 요일명 HH:MM")
        $this->assertArrayHasKey('created_at', $item);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} [가-힣]+요일 \d{2}:\d{2}$/', $item['created_at']);

        // created_at_formatted: 표시용 포맷 (비어있지 않은 문자열)
        $this->assertArrayHasKey('created_at_formatted', $item);
        $this->assertNotEmpty($item['created_at_formatted']);
    }

    /**
     * id를 포함하는 Board 스텁 생성
     *
     * @param int    $id   게시판 ID
     * @param string $slug 게시판 슬러그
     * @param string $name 게시판 이름
     * @return object
     */
    private function createBoardStub(int $id, string $slug, string $name): object
    {
        return new class($id, $slug, $name)
        {
            public int $id;

            public string $slug;

            private string $name;

            public function __construct(int $id, string $slug, string $name)
            {
                $this->id = $id;
                $this->slug = $slug;
                $this->name = $name;
            }

            public function getLocalizedName(): string
            {
                return $this->name;
            }
        };
    }

    /**
     * board relation이 포함된 Post 스텁 생성
     *
     * @param int    $id        게시글 ID
     * @param string $boardSlug 게시판 슬러그
     * @param string $boardName 게시판 이름
     * @return object
     */
    private function createPostStub(int $id, string $boardSlug, string $boardName): object
    {
        $boardStub = new class($boardSlug, $boardName)
        {
            public string $slug;

            private string $name;

            public function __construct(string $slug, string $name)
            {
                $this->slug = $slug;
                $this->name = $name;
            }

            public function getLocalizedName(): string
            {
                return $this->name;
            }
        };

        return (object) [
            'id'             => $id,
            'title'          => '테스트 게시글',
            'content'        => '테스트 내용',
            'content_mode'   => 'text',
            'author_name'    => '작성자',
            'created_at'     => now(),
            'view_count'     => 5,
            'comments_count' => 2,
            'user'           => null,
            'board'          => $boardStub,
        ];
    }
}
