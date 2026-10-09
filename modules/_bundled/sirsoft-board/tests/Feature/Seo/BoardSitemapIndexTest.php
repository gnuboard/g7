<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Seo;

use App\Jobs\GenerateSitemapJob;
use App\Models\SitemapUrl;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Mockery;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Listeners\SeoBoardCacheListener;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * Board 리스너 사이트맵 증분 색인 테스트 (S4 ⑲)
 *
 * 검증 목적 (DoD: 공개→append / 비공개→remove):
 * - 게시(Published) + 비밀글 아님 → 색인, 블라인드/비밀글/삭제 → 색인 제거
 * - 활성 게시판 수정 → 색인, 비활성 → 색인 제거
 */
class BoardSitemapIndexTest extends ModuleTestCase
{
    private SeoBoardCacheListener $listener;

    /**
     * 리스너 테스트 환경(캐시 spy·잡 fake·활성 게시판 픽스처)을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 캐시 무효화/재생성은 이 테스트 범위 밖 — spy 로 대체
        $this->app->instance(SeoCacheManagerInterface::class, Mockery::spy(SeoCacheManagerInterface::class));
        $this->app->instance(SeoCacheRegenerator::class, Mockery::spy(SeoCacheRegenerator::class));
        Bus::fake([GenerateSitemapJob::class]);

        // 게시글 색인은 부모 게시판이 존재하고 활성일 때만 성립한다 (KVE-2026-2133) — 활성 게시판 픽스처
        Board::updateOrCreate(
            ['slug' => 'notice'],
            [
                'name' => ['ko' => '공지사항', 'en' => 'Notice'],
                'is_active' => true,
                'use_file_upload' => true,
                'secret_mode' => 'enabled',
                'blocked_keywords' => [],
            ]
        );

        $this->listener = new SeoBoardCacheListener;
    }

    /**
     * Mockery 기대값을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 게시(Published)·비밀글 아님 게시글 생성 시 색인(append)된다.
     *
     * @scenario lifecycle=post_hook
     *
     * @effects public_post_is_indexed
     *
     * @return void 반환값 없음
     */
    public function test_published_public_post_is_indexed(): void
    {
        $post = (object) ['id' => 100, 'status' => PostStatus::Published, 'is_secret' => false, 'updated_at' => now()];

        $this->listener->onPostCreate($post, 'notice');

        $row = SitemapUrl::where('resource_type', 'board_post')->where('resource_id', '100')->first();
        $this->assertNotNull($row, '공개 게시글은 색인돼야 합니다.');
        $this->assertStringEndsWith('/board/notice/100', $row->loc);
        Bus::assertDispatched(GenerateSitemapJob::class);
    }

    /**
     * 비밀글은 색인되지 않는다(전시 상태여도).
     *
     * @scenario lifecycle=post_hook
     *
     * @effects secret_post_is_not_indexed
     *
     * @return void 반환값 없음
     */
    public function test_secret_post_is_not_indexed(): void
    {
        $post = (object) ['id' => 101, 'status' => PostStatus::Published, 'is_secret' => true, 'updated_at' => now()];

        $this->listener->onPostCreate($post, 'notice');

        $this->assertSame(0, SitemapUrl::where('resource_id', '101')->count(), '비밀글은 색인되면 안 됩니다.');
    }

    /**
     * 블라인드 처리(수정)되면 색인이 제거된다.
     *
     * @scenario lifecycle=post_hook
     *
     * @effects blinded_post_is_deindexed
     *
     * @return void 반환값 없음
     */
    public function test_blinded_post_update_removes_index(): void
    {
        $this->listener->onPostCreate(
            (object) ['id' => 102, 'status' => PostStatus::Published, 'is_secret' => false, 'updated_at' => now()],
            'notice'
        );
        $this->assertSame(1, SitemapUrl::where('resource_id', '102')->count());

        $this->listener->onPostUpdate(
            (object) ['id' => 102, 'status' => PostStatus::Blinded, 'is_secret' => false, 'updated_at' => now()],
            'notice'
        );

        $this->assertSame(0, SitemapUrl::where('resource_id', '102')->count(), '블라인드 게시글은 색인에서 제거돼야 합니다.');
    }

    /**
     * 관리자 블라인드·복구 훅이 색인과 검색엔진용 화면 캐시를 함께 맞춘다.
     *
     * 2026-10-07 실측(K4-D3): 실제 블라인드는 `post.after_update` 가 아니라 `post.after_blind` 를
     * 발화하는데 리스너가 그 훅을 구독하지 않아, 블라인드한 글이 사이트맵에 남고 검색엔진·SNS 용으로
     * 캐시해 둔 상세 화면이 원문을 계속 내보냈다. 복구(`after_restore`)도 다시 실리지 않았다.
     *
     * @scenario lifecycle=post_hook
     *
     * @effects blind_and_restore_hooks_sync_index_and_seo_cache
     *
     * @return void 반환값 없음
     */
    public function test_blind_and_restore_hooks_sync_index_and_seo_cache(): void
    {
        $hooks = SeoBoardCacheListener::getSubscribedHooks();
        $this->assertArrayHasKey('sirsoft-board.post.after_blind', $hooks, '블라인드 훅을 구독하지 않는다');
        $this->assertArrayHasKey('sirsoft-board.post.after_restore', $hooks, '복구 훅을 구독하지 않는다');

        $this->listener->onPostCreate(
            (object) ['id' => 110, 'status' => PostStatus::Published, 'is_secret' => false, 'updated_at' => now()],
            'notice'
        );
        $this->assertSame(1, SitemapUrl::where('resource_id', '110')->count());

        $blindMethod = $hooks['sirsoft-board.post.after_blind']['method'];
        $this->listener->{$blindMethod}(
            (object) ['id' => 110, 'status' => PostStatus::Blinded, 'is_secret' => false, 'updated_at' => now()],
            'notice'
        );
        $this->assertSame(0, SitemapUrl::where('resource_id', '110')->count(), '블라인드한 글이 사이트맵 색인에 남았다');
        app(SeoCacheManagerInterface::class)->shouldHaveReceived('invalidateByUrl')->with('/board/notice/110')->atLeast()->once();

        $restoreMethod = $hooks['sirsoft-board.post.after_restore']['method'];
        $this->listener->{$restoreMethod}(
            (object) ['id' => 110, 'status' => PostStatus::Published, 'is_secret' => false, 'updated_at' => now()],
            'notice'
        );
        $this->assertSame(1, SitemapUrl::where('resource_id', '110')->count(), '복구한 글이 사이트맵에 다시 실리지 않았다');
    }

    /**
     * 게시글 삭제 시 색인이 제거된다.
     *
     * @scenario lifecycle=post_hook
     *
     * @effects deleted_post_is_deindexed
     *
     * @return void 반환값 없음
     */
    public function test_post_delete_removes_index(): void
    {
        $this->listener->onPostCreate(
            (object) ['id' => 103, 'status' => PostStatus::Published, 'is_secret' => false, 'updated_at' => now()],
            'notice'
        );
        $this->assertSame(1, SitemapUrl::where('resource_id', '103')->count());

        $this->listener->onPostDelete((object) ['id' => 103], 'notice');

        $this->assertSame(0, SitemapUrl::where('resource_type', 'board_post')->where('resource_id', '103')->count());
    }

    /**
     * 활성/비활성 게시판 수정이 색인을 토글한다.
     *
     * @scenario lifecycle=deactivate
     *
     * @effects board_row_follows_active_state
     *
     * @return void 반환값 없음
     */
    public function test_board_visibility_toggles_index(): void
    {
        $this->listener->onBoardUpdate((object) ['id' => 5, 'slug' => 'free', 'is_active' => true, 'updated_at' => now()]);
        $row = SitemapUrl::where('resource_type', 'board')->where('resource_id', '5')->first();
        $this->assertNotNull($row);
        $this->assertStringEndsWith('/board/free', $row->loc);

        $this->listener->onBoardUpdate((object) ['id' => 5, 'slug' => 'free', 'is_active' => false, 'updated_at' => now()]);
        $this->assertSame(0, SitemapUrl::where('resource_type', 'board')->where('resource_id', '5')->count());
    }

    /**
     * 'SEO 제공 페이지(게시글 상세)' 토글이 OFF 면 공개 게시글이어도 색인이 제거된다.
     *
     * 리스너의 공개상태 판정은 게시 상태·비밀글 여부 AND 토글을 함께 본다(기여자 getUrlsLazy 와 일치).
     *
     * @scenario lifecycle=post_hook
     *
     * @effects post_detail_toggle_off_deindexes
     *
     * @return void 반환값 없음
     */
    public function test_post_detail_toggle_off_removes_index_even_when_public(): void
    {
        // 토글 ON 상태로 먼저 색인
        $this->listener->onPostCreate(
            (object) ['id' => 110, 'status' => PostStatus::Published, 'is_secret' => false, 'updated_at' => now()],
            'notice'
        );
        $this->assertSame(1, SitemapUrl::where('resource_id', '110')->count());

        // 토글 OFF → 공개 게시글이어도 색인 제거
        Config::set('g7_settings.modules.sirsoft-board.seo.seo_post_detail', false);
        $this->listener->onPostUpdate(
            (object) ['id' => 110, 'status' => PostStatus::Published, 'is_secret' => false, 'updated_at' => now()],
            'notice'
        );

        $this->assertSame(0, SitemapUrl::where('resource_id', '110')->count(), 'seo_post_detail OFF 면 공개 게시글도 색인에서 제거돼야 합니다.');
    }
}
