<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Seo;

use App\Extension\HookManager;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 SEO 캐시 무효화 범위 (공개#156 D5)
 *
 * 무효화 패턴의 `?` 가 정규식 수량자로 해석되던 동안 게시판 수정은 모든 게시판의 목록·글 캐시를 지웠다.
 * 패턴을 글자 그대로 비교하도록 고친 뒤에도 지워야 할 화면은 모두 지우는지 실제 캐시로 확인한다 —
 * 그 게시판의 목록·쪽 변종·글 상세(게시판 이름을 렌더)는 지우고, 다른 게시판 캐시는 남긴다.
 */
class BoardSeoCacheInvalidationScopeTest extends BoardTestCase
{
    /**
     * 큐 작업(상세 재렌더·사이트맵 재생성)은 실행하지 않고 sync 비우기만 돌게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->instance(SeoCacheRegenerator::class, Mockery::spy(SeoCacheRegenerator::class));
    }

    /**
     * 주어진 URL 들을 SEO 캐시에 적재합니다.
     *
     * @param  array<int, string>  $urls  캐시에 넣을 URL
     * @return SeoCacheManagerInterface 캐시 관리자
     */
    private function seedSeoCache(array $urls): SeoCacheManagerInterface
    {
        $cache = app(SeoCacheManagerInterface::class);
        foreach ($urls as $url) {
            $cache->put($url, 'ko', '<html>'.$url.'</html>');
            $this->assertNotNull($cache->get($url, 'ko'), "{$url} 적재 실패");
        }

        return $cache;
    }

    /**
     * 게시판을 고치면 그 게시판의 목록·쪽 변종·글 상세 캐시만 지우고 다른 게시판 캐시는 남긴다.
     *
     * @scenario listener=seo_board, queue=database
     *
     * @effects board_update_invalidates_only_own_board_screens
     *
     * @return void 반환값 없음
     */
    public function test_board_update_invalidates_own_screens_and_keeps_other_boards(): void
    {
        $slug = $this->board->slug;
        $other = Board::updateOrCreate(['slug' => $slug.'-other'], $this->getDefaultBoardAttributes($slug.'-other'));
        $postId = $this->createTestPost(['title' => '범위 확인 글']);

        $cache = $this->seedSeoCache([
            "/board/{$slug}",
            "/board/{$slug}?page=2",
            "/board/{$slug}/{$postId}",
            "/board/{$other->slug}",
            "/board/{$other->slug}/1",
        ]);

        HookManager::doAction('sirsoft-board.board.after_update', $this->board->fresh(), ['name' => ['ko' => '새 이름']], []);

        $this->assertNull($cache->get("/board/{$slug}", 'ko'), '게시판 목록');
        $this->assertNull($cache->get("/board/{$slug}?page=2", 'ko'), '목록 쪽 변종');
        $this->assertNull($cache->get("/board/{$slug}/{$postId}", 'ko'), '글 상세는 게시판 이름을 렌더한다');
        $this->assertNotNull($cache->get("/board/{$other->slug}", 'ko'), '다른 게시판 목록은 남는다');
        $this->assertNotNull($cache->get("/board/{$other->slug}/1", 'ko'), '다른 게시판 글은 남는다');
    }

    /**
     * 글을 고치면 그 게시판 목록의 쪽 변종(`?page=2`) 캐시도 지운다.
     *
     * @scenario listener=seo_board, queue=database
     *
     * @effects post_change_invalidates_list_page_variants
     *
     * @return void 반환값 없음
     */
    public function test_post_change_invalidates_list_page_variants(): void
    {
        $slug = $this->board->slug;
        $post = Post::findOrFail($this->createTestPost(['title' => '쪽 변종 확인 글']));

        $cache = $this->seedSeoCache(["/board/{$slug}", "/board/{$slug}?page=2", "/board/{$slug}/{$post->id}"]);

        HookManager::doAction('sirsoft-board.post.after_update', $post, $slug);

        $this->assertNull($cache->get("/board/{$slug}", 'ko'));
        $this->assertNull($cache->get("/board/{$slug}?page=2", 'ko'), '목록 2쪽 캐시가 옛 목록으로 남았습니다.');
        $this->assertNull($cache->get("/board/{$slug}/{$post->id}", 'ko'));
    }
}
