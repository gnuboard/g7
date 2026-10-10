<?php

namespace Tests\Feature\Seo;

use App\Extension\HookManager;
use App\Models\SitemapUrl;
use App\Seo\SitemapFileStore;
use App\Seo\SitemapGenerator;
use App\Seo\SitemapIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 사이트맵 주소(loc·자식 사이트맵 주소)가 요청 Host 를 따르지 않는지 검증합니다.
 *
 * 사이트맵은 익명 `GET /sitemap.xml` 요청 안에서(기본 sync 큐) 생성될 수 있다 — 그 요청의 Host 가
 * 위조돼 있으면 그 값이 사이트맵 파일과 색인 테이블에 영구히 남아 모든 크롤러에게 나간다.
 */
class SitemapHostTrustTest extends TestCase
{
    use RefreshDatabase;

    /** 테스트 사이트 주소 */
    private const SITE = 'https://shop.example.test';

    /**
     * 사이트 주소를 고정하고 수집 필터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::SITE]);
        HookManager::clearFilter(SitemapIndexer::COLLECT_FILTER);
    }

    /**
     * 수집 필터와 신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(SitemapIndexer::COLLECT_FILTER);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /**
     * 위조 Host 변종.
     *
     * @return array<string, array{0: string, 1: array<string, string>}> 요청 주소와 추가 서버 변수
     */
    public static function forgedHosts(): array
    {
        return [
            'external' => ['https://attacker.example/sitemap.xml', []],
            'subdomain' => ['https://attacker.shop.example.test/sitemap.xml', []],
            'port' => ['https://shop.example.test:6666/sitemap.xml', []],
            'forwarded' => ['http://10.0.0.5/sitemap.xml', ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_HOST' => 'attacker.example', 'HTTP_X_FORWARDED_PROTO' => 'https']],
        ];
    }

    /**
     * URL 생성기에 위조 요청을 심고, Laravel url() 이 그것을 따르는지 전제를 확인합니다.
     *
     * @param  string  $url  위조 요청 주소
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    private function forge(string $url, array $server): void
    {
        if ($server !== []) {
            Request::setTrustedProxies(['10.0.0.5'], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
        }
        $request = Request::create($url, 'GET', [], [], [], $server);
        app()->instance('request', $request);
        app('url')->setRequest($request);

        $this->assertStringStartsNotWith(self::SITE.'/', url('/x'), '전제: Laravel url() 은 위조값을 따른다');
    }

    /**
     * 색인 테이블의 loc 은 사이트 주소로 저장된다.
     *
     * @scenario host_header=forged, sink=sitemap
     *
     * @effects sitemap_loc_uses_site_origin
     *
     * @param  string  $url  위조 요청 주소
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_indexed_loc_ignores_forged_host(string $url, array $server): void
    {
        $this->forge($url, $server);

        $this->app->make(SitemapIndexer::class)
            ->indexResource('product', 7, 'sirsoft-ecommerce', [['url' => '/shop/products/7']]);

        $this->assertSame(
            [self::SITE.'/shop/products/7'],
            SitemapUrl::query()->pluck('loc')->all(),
        );
    }

    /**
     * 생성기의 항목 정규화와 자식 사이트맵 주소도 사이트 주소를 쓴다.
     *
     * @scenario host_header=forged, sink=sitemap
     *
     * @effects sitemap_loc_uses_site_origin
     *
     * @param  string  $url  위조 요청 주소
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_generator_entry_and_child_url_ignore_forged_host(string $url, array $server): void
    {
        $this->forge($url, $server);

        $generator = $this->app->make(SitemapGenerator::class);
        $method = new \ReflectionMethod($generator, 'normalizeEntry');
        $method->setAccessible(true);

        $this->assertSame(self::SITE.'/about', $method->invoke($generator, ['url' => '/about'])['loc']);
        $this->assertSame(self::SITE.'/sitemap-2.xml', SitemapFileStore::childUrl(2));
        $this->assertSame(self::SITE.'/sitemap-2.xml.gz', SitemapFileStore::childUrl(2, true));
    }
}
