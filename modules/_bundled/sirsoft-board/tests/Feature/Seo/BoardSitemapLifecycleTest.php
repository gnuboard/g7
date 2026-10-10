<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Seo;

use App\Jobs\GenerateSitemapJob;
use App\Models\SitemapUrl;
use App\Models\User;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Sirsoft\Board\Listeners\SeoBoardCacheListener;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Seo\BoardSitemapContributor;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판(부모)-게시글(자식) 사이트맵 생명주기 회귀 테스트 (KVE-2026-2133)
 *
 * 수정 전: 게시판을 비활성화·삭제해도 그 게시판 게시글의 sitemap_urls(board_post) 행이 남아
 * Auto→Incremental 재생성이 그 URL 을 계속 공개 사이트맵에 내보냈다. 게시판 삭제는 게시글을
 * 일괄 하드삭제하면서 게시글별 after_delete 훅을 발화하지 않고, SEO 리스너는
 * board.after_delete·board.posts.before_force_delete 를 구독하지 않았다.
 */
class BoardSitemapLifecycleTest extends BoardTestCase
{
    private SeoBoardCacheListener $listener;

    private Board $otherBoard;

    /**
     * @var array<string, int> 역할별 게시글 ID (public_a, public_b, secret, blinded, deleted, other)
     */
    private array $posts = [];

    /**
     * 테스트 게시판 slug
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'kve-sitemap-lifecycle';
    }

    /**
     * 게시판 2개와 상태별 게시글을 만들고 공개 게시글을 색인합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SeoCacheManagerInterface::class, Mockery::spy(SeoCacheManagerInterface::class));
        $this->app->instance(SeoCacheRegenerator::class, Mockery::spy(SeoCacheRegenerator::class));
        Bus::fake([GenerateSitemapJob::class]);
        Storage::fake('attachments');

        $this->listener = new SeoBoardCacheListener;

        $this->otherBoard = Board::updateOrCreate(
            ['slug' => 'kve-sitemap-other'],
            $this->getDefaultBoardAttributes('kve-sitemap-other')
        );
        DB::table('board_posts')->where('board_id', $this->otherBoard->id)->delete();
        $this->cleanSitemapRows();

        $this->posts = [
            'public_a' => $this->createTestPost(['title' => '공개 A']),
            'public_b' => $this->createTestPost(['title' => '공개 B']),
            'secret' => $this->createTestPost(['title' => '비밀', 'is_secret' => true]),
            'blinded' => $this->createTestPost(['title' => '블라인드', 'status' => 'blinded']),
            'deleted' => $this->createTestPost(['title' => '삭제', 'deleted_at' => now()]),
            'other' => $this->createTestPost(['title' => '다른 게시판', 'board_id' => $this->otherBoard->id]),
        ];

        // 공개 게시글만 증분 색인 (실제 게시글 훅 경로)
        $this->listener->onPostCreate(Post::find($this->posts['public_a']), $this->board->slug);
        $this->listener->onPostCreate(Post::find($this->posts['public_b']), $this->board->slug);
        $this->listener->onPostCreate(Post::find($this->posts['other']), $this->otherBoard->slug);
        $this->listener->onBoardUpdate($this->board->fresh(), [], $this->board->toArray());
    }

    /**
     * 테스트가 만든 사이트맵 행과 보조 게시판을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanSitemapRows();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 게시판 비활성화 시 그 게시판의 게시글 색인도 모두 빠지고, 다른 게시판 색인은 그대로다.
     *
     * @scenario lifecycle=deactivate
     *
     * @effects deactivated_board_posts_are_deindexed, other_board_index_is_untouched
     *
     * @return void 반환값 없음
     */
    public function test_deactivating_board_deindexes_its_posts(): void
    {
        $this->assertSame([$this->posts['public_a'], $this->posts['public_b']], $this->indexedPostIds($this->board));

        $snapshot = $this->board->fresh()->toArray();
        $this->board->update(['is_active' => false]);
        $this->listener->onBoardUpdate($this->board->fresh(), ['is_active' => false], $snapshot);

        $this->assertSame([], $this->indexedPostIds($this->board), '비활성 게시판의 게시글 URL 이 색인에 남으면 안 됩니다.');
        $this->assertSame(0, $this->rowCount('board', (string) $this->board->id));
        $this->assertSame([$this->posts['other']], $this->indexedPostIds($this->otherBoard), '다른 게시판 색인은 그대로여야 합니다.');
    }

    /**
     * 재활성화 시 공개 게시글(게시 상태·비밀글 아님·미삭제)만 다시 색인된다.
     *
     * @scenario lifecycle=reactivate
     *
     * @effects reactivated_board_reindexes_public_posts_only
     *
     * @return void 반환값 없음
     */
    public function test_reactivating_board_reindexes_only_public_posts(): void
    {
        $snapshot = $this->board->fresh()->toArray();
        $this->board->update(['is_active' => false]);
        $this->listener->onBoardUpdate($this->board->fresh(), ['is_active' => false], $snapshot);
        $this->assertSame([], $this->indexedPostIds($this->board));

        $snapshot = $this->board->fresh()->toArray();
        $this->board->update(['is_active' => true]);
        $this->listener->onBoardUpdate($this->board->fresh(), ['is_active' => true], $snapshot);

        $this->assertSame(
            [$this->posts['public_a'], $this->posts['public_b']],
            $this->indexedPostIds($this->board),
            '비밀글·블라인드·삭제글은 재색인되면 안 됩니다.'
        );
    }

    /**
     * 재활성화 재색인은 글마다가 아니라 묶음으로 저장한다 — 공개 글 2건이 게시글 행 삽입 쿼리 1회로 들어간다.
     *
     * 글마다 색인하면 공개 글 수만 건 게시판에서 큐 작업 시간 제한을 넘겨 앞쪽 글만 색인된 채 실패한다.
     *
     * @scenario lifecycle=reactivate
     *
     * @effects reactivation_reindexes_in_batches, reactivated_board_reindexes_public_posts_only
     *
     * @return void 반환값 없음
     */
    public function test_reactivation_reindexes_posts_in_batches(): void
    {
        $snapshot = $this->board->fresh()->toArray();
        $this->board->update(['is_active' => false]);
        $this->listener->onBoardUpdate($this->board->fresh(), ['is_active' => false], $snapshot);

        $postInserts = 0;
        DB::listen(function ($query) use (&$postInserts) {
            if (str_starts_with(strtolower($query->sql), 'insert into')
                && str_contains($query->sql, 'sitemap_urls')
                && in_array('board_post', $query->bindings, true)) {
                $postInserts++;
            }
        });

        $snapshot = $this->board->fresh()->toArray();
        $this->board->update(['is_active' => true]);
        $this->listener->onBoardUpdate($this->board->fresh(), ['is_active' => true], $snapshot);

        $this->assertSame([$this->posts['public_a'], $this->posts['public_b']], $this->indexedPostIds($this->board));
        $this->assertSame(1, $postInserts, '공개 글 재색인은 묶음 삽입 1회여야 합니다(글마다 삽입 금지).');
    }

    /**
     * 활성 상태가 그대로인 게시판 수정은 게시글 색인을 전량 재작성하지 않는다.
     *
     * @scenario lifecycle=update_same_state
     *
     * @effects board_info_update_does_not_mass_reindex_posts
     *
     * @return void 반환값 없음
     */
    public function test_board_update_without_active_change_does_not_touch_post_index(): void
    {
        // 표지: 한 행을 지워 두고, 전량 재색인이 일어나면 되살아나는지 본다
        SitemapUrl::where('resource_type', 'board_post')->where('resource_id', (string) $this->posts['public_b'])->delete();

        $snapshot = $this->board->fresh()->toArray();
        $this->board->update(['name' => ['ko' => '이름만 변경', 'en' => 'Renamed']]);
        $this->listener->onBoardUpdate($this->board->fresh(), ['name' => '이름만 변경'], $snapshot);

        $this->assertSame([$this->posts['public_a']], $this->indexedPostIds($this->board));
    }

    /**
     * 게시판 삭제(실제 서비스 경로)는 게시판·게시글 색인을 모두 걷어낸다.
     *
     * @scenario lifecycle=delete
     *
     * @effects deleted_board_and_posts_are_deindexed, other_board_index_is_untouched
     *
     * @return void 반환값 없음
     */
    public function test_board_delete_removes_board_and_post_index(): void
    {
        $this->assertGreaterThan(0, $this->rowCount('board', (string) $this->board->id));
        $boardId = $this->board->id;

        $this->actingAs(User::factory()->create(['is_super' => true, 'status' => 'active']));
        app(BoardService::class)->deleteBoard($boardId);

        $this->assertSame(0, Post::withTrashed()->where('board_id', $boardId)->count());
        $this->assertSame(0, SitemapUrl::whereIn('resource_type', ['board', 'board_post'])
            ->where('loc', 'like', '%/board/'.$this->getTestBoardSlug().'%')->count(), '삭제된 게시판의 URL 이 색인에 남으면 안 됩니다.');
        $this->assertSame([$this->posts['other']], $this->indexedPostIds($this->otherBoard));
        Bus::assertDispatched(GenerateSitemapJob::class);
    }

    /**
     * 비활성·삭제된 게시판의 게시글은 게시글 훅(대기 중이던 잡 포함)으로도 다시 색인되지 않는다.
     *
     * @scenario lifecycle=post_hook
     *
     * @effects post_hook_does_not_reindex_under_inactive_or_missing_board
     *
     * @return void 반환값 없음
     */
    public function test_post_hook_does_not_index_under_inactive_or_missing_board(): void
    {
        $snapshot = $this->board->fresh()->toArray();
        $this->board->update(['is_active' => false]);
        $this->listener->onBoardUpdate($this->board->fresh(), ['is_active' => false], $snapshot);

        $this->listener->onPostUpdate(Post::find($this->posts['public_a']), $this->board->slug);
        $this->assertSame([], $this->indexedPostIds($this->board), '비활성 게시판 게시글이 게시글 수정으로 재색인되면 안 됩니다.');

        // 게시판이 사라진 뒤 실행되는 대기 잡(속성 스냅샷으로 복원된 게시글)
        $this->listener->onPostUpdate(Post::find($this->posts['public_a']), 'kve-sitemap-missing-board');
        $this->assertSame(0, $this->rowCount('board_post', (string) $this->posts['public_a']));
    }

    /**
     * 생명주기 이벤트 후 증분 저장소의 게시판 URL 집합이 전체 재생성 기여자 결과와 같다.
     *
     * @scenario lifecycle=reactivate
     *
     * @effects incremental_store_matches_full_contributor
     *
     * @return void 반환값 없음
     */
    public function test_incremental_store_matches_full_contributor_after_lifecycle(): void
    {
        $snapshot = $this->board->fresh()->toArray();
        $this->board->update(['is_active' => false]);
        $this->listener->onBoardUpdate($this->board->fresh(), ['is_active' => false], $snapshot);
        $this->assertSame($this->contributorLocs(), $this->storedLocs(), '비활성 직후 증분·전체 집합이 달라서는 안 됩니다.');

        $snapshot = $this->board->fresh()->toArray();
        $this->board->update(['is_active' => true]);
        $this->listener->onBoardUpdate($this->board->fresh(), ['is_active' => true], $snapshot);
        $this->assertSame($this->contributorLocs(), $this->storedLocs(), '재활성 직후 증분·전체 집합이 달라서는 안 됩니다.');
    }

    /**
     * 게시판에 색인된 게시글 ID 목록을 반환합니다 (오름차순).
     *
     * @param  Board  $board  게시판
     * @return array<int, int> 게시글 ID 목록
     */
    private function indexedPostIds(Board $board): array
    {
        return SitemapUrl::where('resource_type', 'board_post')
            ->where('loc', 'like', '%/board/'.$board->slug.'/%')
            ->pluck('resource_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * 리소스 행 개수를 셉니다.
     *
     * @param  string  $type  리소스 유형
     * @param  string  $id  리소스 ID
     * @return int 행 개수
     */
    private function rowCount(string $type, string $id): int
    {
        return SitemapUrl::where('resource_type', $type)->where('resource_id', $id)->count();
    }

    /**
     * 증분 저장소에 있는 이 게시판 URL(board·board_post) 집합을 반환합니다.
     *
     * @return array<int, string> 정렬된 loc 목록
     */
    private function storedLocs(): array
    {
        return SitemapUrl::whereIn('resource_type', ['board', 'board_post'])
            ->where('loc', 'like', '%/board/'.$this->getTestBoardSlug().'%')
            ->pluck('loc')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * 전체 재생성 기여자가 이 게시판에 대해 내보내는 URL 집합을 반환합니다.
     *
     * @return array<int, string> 정렬된 loc 목록
     */
    private function contributorLocs(): array
    {
        $locs = [];
        foreach (app(BoardSitemapContributor::class)->getUrlsLazy() as $entry) {
            $url = (string) ($entry['url'] ?? '');
            if (str_starts_with($url, '/board/'.$this->getTestBoardSlug())) {
                $locs[] = url($url);
            }
        }
        sort($locs);

        return $locs;
    }

    /**
     * 이 테스트의 두 게시판 slug 에 해당하는 사이트맵 행을 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function cleanSitemapRows(): void
    {
        SitemapUrl::whereIn('resource_type', ['board', 'board_post'])
            ->where(function ($q) {
                $q->where('loc', 'like', '%/board/kve-sitemap-%');
            })
            ->delete();
    }
}
