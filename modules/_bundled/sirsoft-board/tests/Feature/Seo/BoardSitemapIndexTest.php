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

    protected function setUp(): void
    {
        parent::setUp();

        // 캐시 무효화/재생성은 이 테스트 범위 밖 — spy 로 대체
        $this->app->instance(SeoCacheManagerInterface::class, Mockery::spy(SeoCacheManagerInterface::class));
        $this->app->instance(SeoCacheRegenerator::class, Mockery::spy(SeoCacheRegenerator::class));
        Bus::fake([GenerateSitemapJob::class]);

        $this->listener = new SeoBoardCacheListener;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 게시(Published)·비밀글 아님 게시글 생성 시 색인(append)된다.
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
     */
    public function test_secret_post_is_not_indexed(): void
    {
        $post = (object) ['id' => 101, 'status' => PostStatus::Published, 'is_secret' => true, 'updated_at' => now()];

        $this->listener->onPostCreate($post, 'notice');

        $this->assertSame(0, SitemapUrl::where('resource_id', '101')->count(), '비밀글은 색인되면 안 됩니다.');
    }

    /**
     * 블라인드 처리(수정)되면 색인이 제거된다.
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
     * 게시글 삭제 시 색인이 제거된다.
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
