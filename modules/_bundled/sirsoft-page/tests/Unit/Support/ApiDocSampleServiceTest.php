<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Support;

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Support\ApiDoc\ApiDocSampleService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * sirsoft-page API 문서 실측용 완전 샘플 시더 테스트.
 *
 * 페이지 도메인 대표 샘플이 발행 상태로 생성되고(상세 GET 실측 가능),
 * 버전 이력·첨부가 조립되며, 재실행 시 멱등한지 검증한다.
 */
class ApiDocSampleServiceTest extends ModuleTestCase
{
    #[Test]
    public function 시더는_계약을_구현한다(): void
    {
        $this->assertInstanceOf(ApiDocSampleSeeder::class, new ApiDocSampleService);
    }

    #[Test]
    public function 페이지_도메인_대표_샘플_맵을_반환한다(): void
    {
        $map = (new ApiDocSampleService)->seed();

        $this->assertArrayHasKey('pages', $map);
        $this->assertSame(Page::class, $map['pages']['model']);
        $this->assertArrayHasKey('key', $map['pages']);
        $this->assertNotEmpty($map['pages']['value']);
    }

    #[Test]
    public function 대표_샘플_페이지는_발행_상태로_버전_이력과_첨부를_갖는다(): void
    {
        (new ApiDocSampleService)->seed();

        $page = Page::query()->where('slug', 'apidoc-sample-page')->first();

        $this->assertNotNull($page);
        $this->assertTrue($page->published);
        $this->assertNotNull($page->published_at);
        $this->assertNotNull($page->seo_meta);
        $this->assertSame(2, $page->versions()->count());
        $this->assertSame(1, $page->attachments()->count());
    }

    #[Test]
    public function 재실행_시_샘플이_중복_생성되지_않는다(): void
    {
        $service = new ApiDocSampleService;

        $service->seed();
        $countAfterFirst = Page::query()->where('slug', 'apidoc-sample-page')->count();

        $service->seed();
        $countAfterSecond = Page::query()->where('slug', 'apidoc-sample-page')->count();

        $this->assertSame(1, $countAfterFirst);
        $this->assertSame(1, $countAfterSecond);
    }
}
