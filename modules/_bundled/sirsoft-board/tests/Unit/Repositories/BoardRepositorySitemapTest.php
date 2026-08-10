<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Repositories;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\LazyCollection;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * BoardRepository::streamActiveForSitemap 단위 테스트
 *
 * 검증 목적:
 * - 순회자(LazyCollection) 를 반환한다 (전체 적재 금지)
 * - sitemap 에 필요한 컬럼만 적재한다 (전컬럼 적재 회귀 차단)
 * - 활성 게시판만 반환한다
 */
#[Group('board')]
#[Group('unit')]
#[Group('seo')]
class BoardRepositorySitemapTest extends BoardTestCase
{
    private BoardRepositoryInterface $repository;

    protected function getTestBoardSlug(): string
    {
        return 'repo-sitemap-test';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '레포지토리 사이트맵 테스트', 'en' => 'Repository Sitemap Test'],
            'is_active' => true,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->app->make(BoardRepositoryInterface::class);
    }

    /**
     * 테스트 정리 - 이 클래스가 생성한 게시판을 제거합니다.
     *
     * BoardTestCase 는 트랜잭션 롤백 대신 수동 삭제 전략을 쓰며 게시판 자체는
     * 지우지 않는다. 추가 게시판을 만든 쪽이 정리하지 않으면 활성 게시판이
     * 다른 테스트 클래스로 누출되어 게시판 목록 단언을 깨뜨린다.
     */
    protected function tearDown(): void
    {
        Board::where('slug', 'like', 'repo-sitemap-%')->forceDelete();

        parent::tearDown();
    }

    /**
     * streamActiveForSitemap: 전체 적재가 아닌 순회자를 반환한다
     */
    public function test_stream_active_for_sitemap_returns_lazy_collection(): void
    {
        $this->assertInstanceOf(
            LazyCollection::class,
            $this->repository->streamActiveForSitemap(),
            'lazyById 순회자여야 전체 적재를 피할 수 있습니다.'
        );
    }

    /**
     * streamActiveForSitemap: sitemap 에 필요한 컬럼만 적재한다 (회귀)
     *
     * 범용 getActiveBoards() 는 전컬럼을 적재하므로 sitemap 경로가 그것을 재사용하면
     * 메모리가 후퇴한다. select 가 유지되는지 속성 키로 검증한다.
     */
    public function test_stream_active_for_sitemap_selects_only_sitemap_columns(): void
    {
        $board = $this->repository->streamActiveForSitemap()->first();

        $this->assertNotNull($board);
        $this->assertSame(
            ['id', 'slug', 'updated_at'],
            array_keys($board->getAttributes()),
            'sitemap 조회는 id/slug/updated_at 만 적재해야 합니다.'
        );
    }

    /**
     * streamActiveForSitemap: 비활성 게시판은 반환하지 않는다
     */
    public function test_stream_active_for_sitemap_excludes_inactive_boards(): void
    {
        $this->updateBoardSettings(['is_active' => false]);

        $slugs = collect($this->repository->streamActiveForSitemap())
            ->pluck('slug')
            ->all();

        $this->assertNotContains($this->board->slug, $slugs);
    }

    /**
     * streamActiveForSitemap: 활성 게시판이 포함된다
     */
    public function test_stream_active_for_sitemap_includes_active_boards(): void
    {
        $slugs = collect($this->repository->streamActiveForSitemap())
            ->pluck('slug')
            ->all();

        $this->assertContains($this->board->slug, $slugs);
    }

    /**
     * streamActiveForSitemap: 청크 크기보다 많은 게시판도 전부 순회한다
     */
    public function test_stream_active_for_sitemap_traverses_beyond_chunk_size(): void
    {
        foreach (range(1, 3) as $i) {
            Board::create([
                'slug' => "repo-sitemap-extra-{$i}",
                'name' => ['ko' => "추가 게시판 {$i}", 'en' => "Extra Board {$i}"],
                'is_active' => true,
            ]);
        }

        // 청크 크기 1 → 여러 번 쿼리해도 누락 없이 전부 순회해야 한다
        $slugs = collect($this->repository->streamActiveForSitemap(1))
            ->pluck('slug')
            ->all();

        foreach (range(1, 3) as $i) {
            $this->assertContains("repo-sitemap-extra-{$i}", $slugs);
        }
    }
}
