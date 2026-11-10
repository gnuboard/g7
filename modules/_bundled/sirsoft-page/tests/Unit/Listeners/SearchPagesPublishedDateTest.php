<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Listeners;

use App\Enums\TotalRelation;
use App\Support\Query\BoundedPage;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Mockery;
use Modules\Sirsoft\Page\Listeners\SearchPagesListener;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 통합 검색 페이지 결과의 발행일 테스트 (#160)
 *
 * 검색은 웹 요청이라 발행일은 보는 사람 시간대의 날짜다 — UTC 날짜면 한국 시간 새벽 0~9시 발행분이 전날로 보인다.
 */
class SearchPagesPublishedDateTest extends ModuleTestCase
{
    /**
     * 모의 객체를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * UTC 11-01 16:00:19 발행 → 서울 보는 사람에게 2026-11-02.
     *
     * @scenario surface=search_published_at
     *
     * @effects search_published_date_uses_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_published_at_is_the_viewer_date(): void
    {
        app()->instance('user_timezone', 'Asia/Seoul');

        $page = new class
        {
            public int $id = 1;

            public string $slug = 'guide';

            public array $content = ['ko' => '이용안내 본문', 'en' => ''];

            public ?Carbon $published_at;

            /**
             * 발행 시각을 UTC 저녁(서울 다음 날 새벽)으로 둡니다.
             */
            public function __construct()
            {
                $this->published_at = Carbon::parse('2026-11-01 16:00:19', 'UTC');
            }

            /**
             * 현재 로케일 제목을 돌려줍니다.
             *
             * @return string 제목
             */
            public function getLocalizedTitle(): string
            {
                return '이용안내';
            }
        };

        $service = Mockery::mock(PageService::class);
        $service->shouldReceive('searchByKeywordWithCursor')->andReturnNull()->byDefault();
        $service->shouldReceive('searchByKeyword')->once()->andReturn(new BoundedPage(
            items: new Collection([$page]),
            total: 1,
            perPage: 5,
            currentPage: 1,
            totalRelation: TotalRelation::Exact,
            resultCap: 10000,
            hasMorePages: false,
        ));

        $result = (new SearchPagesListener($service))->searchPages([], [
            'q' => '이용',
            'type' => 'all',
            'all_tab_limit' => 5,
        ]);

        $this->assertSame('2026-11-02', $result['pages']['items'][0]['published_at']);
    }
}
