<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Repositories;

use App\Models\User;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Repositories\PageRepository;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * PageRepository 정렬 단위 테스트
 *
 * paginate()에서 sort_by/sort_order 필터가 올바르게 적용되는지 검증합니다.
 */
class PageRepositorySortTest extends ModuleTestCase
{
    private PageRepository $repository;

    private User $testUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(PageRepository::class);
        $this->testUser = User::factory()->create();
    }

    /**
     * 기본 정렬은 created_at desc인지 확인
     */
    public function test_default_sort_is_created_at_desc(): void
    {
        $older = $this->createPage('오래된 페이지', 'test-sort-older', now()->subDays(2));
        $newer = $this->createPage('최신 페이지', 'test-sort-newer', now());

        $result = $this->repository->paginate([], 10);

        $this->assertEquals($newer->id, $result->items()[0]->id);
        $this->assertEquals($older->id, $result->items()[1]->id);
    }

    /**
     * created_at asc 정렬이 작동하는지 확인
     */
    public function test_sort_by_created_at_asc(): void
    {
        $older = $this->createPage('오래된 페이지', 'test-sort-asc-older', now()->subDays(2));
        $newer = $this->createPage('최신 페이지', 'test-sort-asc-newer', now());

        $result = $this->repository->paginate([
            'sort_by' => 'created_at',
            'sort_order' => 'asc',
        ], 10);

        $this->assertEquals($older->id, $result->items()[0]->id);
        $this->assertEquals($newer->id, $result->items()[1]->id);
    }

    /**
     * published_at desc 정렬이 작동하는지 확인
     */
    public function test_sort_by_published_at_desc(): void
    {
        $earlierPublished = $this->createPage('먼저 발행', 'test-sort-pub-earlier', now()->subDays(5), now()->subDays(3));
        $laterPublished = $this->createPage('나중 발행', 'test-sort-pub-later', now()->subDays(5), now());

        $result = $this->repository->paginate([
            'sort_by' => 'published_at',
            'sort_order' => 'desc',
        ], 10);

        $this->assertEquals($laterPublished->id, $result->items()[0]->id);
        $this->assertEquals($earlierPublished->id, $result->items()[1]->id);
    }

    /**
     * published_at asc 정렬이 작동하는지 확인
     */
    public function test_sort_by_published_at_asc(): void
    {
        $earlierPublished = $this->createPage('먼저 발행', 'test-sort-pub-asc-earlier', now()->subDays(5), now()->subDays(3));
        $laterPublished = $this->createPage('나중 발행', 'test-sort-pub-asc-later', now()->subDays(5), now());

        $result = $this->repository->paginate([
            'sort_by' => 'published_at',
            'sort_order' => 'asc',
        ], 10);

        $this->assertEquals($earlierPublished->id, $result->items()[0]->id);
        $this->assertEquals($laterPublished->id, $result->items()[1]->id);
    }

    /**
     * 허용되지 않은 정렬 방향은 기본값(desc)으로 폴백하는지 확인
     */
    public function test_invalid_sort_direction_falls_back_to_desc(): void
    {
        $older = $this->createPage('오래된 페이지', 'test-sort-dir-older', now()->subDays(2));
        $newer = $this->createPage('최신 페이지', 'test-sort-dir-newer', now());

        $result = $this->repository->paginate([
            'sort_by' => 'created_at',
            'sort_order' => 'INVALID',
        ], 10);

        // 기본값 desc로 폴백
        $this->assertEquals($newer->id, $result->items()[0]->id);
        $this->assertEquals($older->id, $result->items()[1]->id);
    }

    // ─── 헬퍼 ────────────────────────────────────────────

    /**
     * 테스트 페이지를 생성합니다.
     *
     * @param  string  $titleKo  한국어 제목
     * @param  string  $slug  슬러그
     * @param  \Illuminate\Support\Carbon|null  $createdAt  생성일시
     * @param  \Illuminate\Support\Carbon|null  $publishedAt  발행일시
     * @return Page 생성된 페이지
     */
    private function createPage(string $titleKo, string $slug, $createdAt = null, $publishedAt = null): Page
    {
        return Page::unguarded(function () use ($titleKo, $slug, $createdAt, $publishedAt) {
            $page = new Page();
            $page->slug = $slug;
            $page->title = ['ko' => $titleKo, 'en' => ''];
            $page->content = ['ko' => '테스트 내용', 'en' => ''];
            $page->published = true;
            $page->published_at = $publishedAt ?? $createdAt ?? now();
            $page->created_by = $this->testUser->id;
            $page->updated_by = $this->testUser->id;

            if ($createdAt) {
                $page->timestamps = false;
                $page->created_at = $createdAt;
                $page->updated_at = $createdAt;
            }

            $page->save();
            $page->timestamps = true;

            return $page->fresh();
        });
    }
}
