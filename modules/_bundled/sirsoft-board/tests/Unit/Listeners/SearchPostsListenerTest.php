<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use App\Enums\TotalRelation;
use App\Models\User;
use App\Support\Query\BoundedPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Listeners\SearchPostsListener;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * SearchPostsListener 단위 테스트 — 게시판별 권한 필터링 및 날짜 포맷 검증
 */
class SearchPostsListenerTest extends ModuleTestCase
{
    /**
     * 검색 결과 페이지(BoundedPage)를 만듭니다.
     *
     * 저장소가 반환하는 계약과 같은 형태를 테스트에서도 그대로 씁니다.
     *
     * @param  Collection  $items  페이지 항목
     * @param  int  $total  총 건수
     * @return BoundedPage 페이지 결과
     */
    private function boundedPage(Collection $items, int $total): BoundedPage
    {
        return new BoundedPage(
            items: $items,
            total: $total,
            perPage: 5,
            currentPage: 1,
            totalRelation: TotalRelation::Exact,
            resultCap: 10000,
            hasMorePages: false,
        );
    }

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
    public function test_get_subscribed_hooks_returns_correct_hooks(): void
    {
        $hooks = SearchPostsListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.search.results', $hooks);
        $this->assertArrayHasKey('core.search.build_response', $hooks);
        $this->assertArrayHasKey('core.search.index_validation_rules', $hooks);

        foreach ($hooks as $hook) {
            $this->assertEquals('filter', $hook['type']);
        }
    }

    /**
     * 권한 있는 게시판만 검색 결과에 포함되는지 확인
     */
    public function test_search_posts_filters_boards_by_permission(): void
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
            ->willReturn($this->boundedPage(new Collection([
                $this->createPostStub(1, 'notice', '공지사항'),
            ]), 1));

        $this->boardService
            ->method('getActiveBoardsListForFilter')
            ->willReturn([]);

        $context = [
            'type' => 'all',
            'q' => '테스트',
            'sort' => 'relevance',
            'page' => 1,
            'per_page' => 10,
            'user' => $user,
            'request' => null,
        ];

        $result = $this->listener->searchPosts([], $context);

        $this->assertArrayHasKey('posts', $result);
        $this->assertGreaterThan(0, $result['posts']['total']);
    }

    /**
     * 모든 게시판 권한이 없을 때 빈 결과를 반환하는지 확인
     */
    public function test_search_posts_returns_empty_when_all_boards_denied(): void
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
            'type' => 'all',
            'q' => '테스트',
            'user' => $user,
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
    public function test_search_posts_skips_when_keyword_is_empty(): void
    {
        $results = [];
        $context = ['type' => 'all', 'q' => ''];

        $result = $this->listener->searchPosts($results, $context);

        $this->assertArrayNotHasKey('posts', $result);
    }

    /**
     * formatPostResult()가 created_at(Y-m-d H:i:s 포맷)과 created_at_formatted(표시용) 필드를 반환하는지 확인
     */
    public function test_format_post_result_includes_created_at_and_created_at_formatted(): void
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
            ->willReturn($this->boundedPage(new Collection([
                $this->createPostStub(1, 'notice', '공지사항'),
            ]), 1));

        Gate::before(fn ($u) => $u->id === 9999 ? true : null);

        $context = [
            'type' => 'all',
            'q' => '테스트',
            'sort' => 'relevance',
            'page' => 1,
            'per_page' => 10,
            'user' => $user,
            'request' => null,
        ];

        $result = $this->listener->searchPosts([], $context);

        $this->assertArrayHasKey('posts', $result);
        $items = $result['posts']['items'] ?? [];
        $this->assertNotEmpty($items);

        $item = $items[0];

        // created_at: 전체 날짜+시간 포맷 ("YYYY-MM-DD HH:MM:SS", 사용자 타임존)
        $this->assertArrayHasKey('created_at', $item);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $item['created_at']);

        // created_at_formatted: 표시용 포맷 (비어있지 않은 문자열)
        $this->assertArrayHasKey('created_at_formatted', $item);
        $this->assertNotEmpty($item['created_at_formatted']);
    }

    /**
     * 검색 실패가 "결과 0건" 으로 위장되지 않고 failed 페이로드로 표면화되는지 확인 (#103)
     *
     * 수정 전에는 catch 가 로그만 남기고 카테고리 키를 설정하지 않아, 화면이
     * "검색 결과가 없습니다" 를 그렸다.
     *
     * @effects failed_flag_in_response, exception_stack_logged
     */
    public function test_search_failure_surfaces_failed_payload_and_logs_exception(): void
    {
        Log::spy();

        $exception = new \RuntimeException('DB 오류 재현');
        $this->boardService
            ->method('getActiveBoardsForSearch')
            ->willThrowException($exception);

        $context = [
            'type' => 'posts',
            'q' => '문의',
            'sort' => 'relevance',
            'page' => 1,
            'per_page' => 10,
            'user' => null,
            'request' => null,
        ];

        $result = $this->listener->searchPosts([], $context);

        $this->assertArrayHasKey('posts', $result);
        $this->assertTrue($result['posts']['failed'] ?? false, '실패 카테고리에는 failed 플래그가 실려야 합니다.');
        $this->assertFalse($result['posts']['total_is_exact'], '실패한 0건을 "정확한 0건" 으로 말하면 안 됩니다.');
        $this->assertSame([], $result['posts']['items']);
        $this->assertSame([], $result['posts']['available_boards'], '기존 응답 키 집합(available_boards)은 유지되어야 합니다.');

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $ctx = []) => ($ctx['exception'] ?? null) === $exception)
            ->once();
    }

    /**
     * 제목/본문에 삽입된 태그가 하이라이트 필드에서 이스케이프되는지 확인 (⑧/N-8)
     *
     * @scenario case=search_highlight_escape
     *
     * @effects highlighted_fields_escaped
     */
    public function test_format_post_result_escapes_markup_in_highlighted_fields(): void
    {
        $user = User::factory()->make(['id' => 9999]);

        $board = $this->createBoardStub(1, 'notice', '공지사항');

        $this->boardService
            ->method('getActiveBoardsForSearch')
            ->willReturn(new Collection([$board]));

        $this->boardService
            ->method('getActiveBoardsListForFilter')
            ->willReturn([]);

        $post = (object) [
            'id' => 1,
            'title' => '<img src=x onerror=alert(1)> 테스트',
            // 엔티티로 인코딩된 태그가 html 모드 프리뷰에서 부활하면 안 된다 (N-8).
            'content' => '&lt;script&gt;alert(1)&lt;/script&gt; 테스트 본문',
            'content_mode' => 'html',
            'author_name' => '작성자',
            'created_at' => now(),
            'view_count' => 0,
            'comments_count' => 0,
            'user' => null,
            'board' => $this->createBoardStub(1, 'notice', '공지사항'),
        ];

        $this->postService
            ->method('searchAcrossBoards')
            ->willReturn($this->boundedPage(new Collection([$post]), 1));

        Gate::before(fn ($u) => $u->id === 9999 ? true : null);

        $result = $this->listener->searchPosts([], [
            'type' => 'all',
            'q' => '테스트',
            'sort' => 'relevance',
            'page' => 1,
            'per_page' => 10,
            'user' => $user,
            'request' => null,
        ]);

        $item = $result['posts']['items'][0];

        // 제목 하이라이트: 태그 이스케이프 + 검색어만 <mark>
        $this->assertStringNotContainsString('<img', $item['title_highlighted']);
        $this->assertStringContainsString('&lt;img', $item['title_highlighted']);
        $this->assertStringContainsString('<mark>테스트</mark>', $item['title_highlighted']);

        // 본문 프리뷰: 부활한 <script> 없음
        $this->assertStringNotContainsString('<script>', $item['content_preview']);
        $this->assertStringNotContainsString('<script>', $item['content_preview_highlighted']);
    }

    /**
     * id를 포함하는 Board 스텁 생성
     *
     * @param  int  $id  게시판 ID
     * @param  string  $slug  게시판 슬러그
     * @param  string  $name  게시판 이름
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
     * @param  int  $id  게시글 ID
     * @param  string  $boardSlug  게시판 슬러그
     * @param  string  $boardName  게시판 이름
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
            'id' => $id,
            'title' => '테스트 게시글',
            'content' => '테스트 내용',
            'content_mode' => 'text',
            'author_name' => '작성자',
            'created_at' => now(),
            'view_count' => 5,
            'comments_count' => 2,
            'user' => null,
            'board' => $boardStub,
        ];
    }
}
