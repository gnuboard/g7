<?php

namespace Tests\Feature\Seo;

use Tests\TestCase;

/**
 * SEO 레이아웃 JSON 구조 검증 테스트
 *
 * 주요 레이아웃 JSON 파일이 올바른 meta.seo 속성을 포함하고 있는지 확인합니다.
 * 실제 파일을 읽어 구조적 정합성을 검증하는 정적 분석 테스트입니다.
 */
class SeoPageRenderingTest extends TestCase
{
    /** @var string 레이아웃 파일 기본 경로 */
    private string $layoutBasePath;

    /**
     * 테스트 초기화 - 레이아웃 파일 기본 경로를 설정합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->layoutBasePath = base_path('templates/_bundled/sirsoft-basic/layouts');
    }

    /**
     * 레이아웃 JSON 파일을 읽어 배열로 반환합니다.
     *
     * @param  string  $relativePath  레이아웃 기본 경로 기준 상대 경로
     * @return array 디코딩된 레이아웃 배열
     */
    private function readLayout(string $relativePath): array
    {
        $filePath = $this->layoutBasePath.'/'.$relativePath;

        $this->assertFileExists($filePath, "레이아웃 파일이 존재해야 합니다: {$relativePath}");

        $content = file_get_contents($filePath);
        $this->assertNotFalse($content, "레이아웃 파일을 읽을 수 있어야 합니다: {$relativePath}");

        $decoded = json_decode($content, true);
        $this->assertNotNull($decoded, "레이아웃 파일이 유효한 JSON이어야 합니다: {$relativePath}");

        return $decoded;
    }

    /**
     * home.json 레이아웃에 SEO 메타가 올바르게 설정되어 있는지 확인합니다.
     */
    public function test_home_layout_has_seo_meta(): void
    {
        $layout = $this->readLayout('home.json');

        // meta.seo 존재 확인
        $this->assertArrayHasKey('meta', $layout, 'home.json에 meta 키가 존재해야 합니다');
        $this->assertArrayHasKey('seo', $layout['meta'], 'home.json에 meta.seo 키가 존재해야 합니다');

        $seo = $layout['meta']['seo'];

        // enabled 확인
        $this->assertArrayHasKey('enabled', $seo, 'home.json seo에 enabled 키가 존재해야 합니다');
        $this->assertTrue($seo['enabled'], 'home.json의 SEO는 활성화되어야 합니다');

        // priority 확인 (홈페이지는 최우선)
        $this->assertArrayHasKey('priority', $seo, 'home.json seo에 priority 키가 존재해야 합니다');
        $this->assertSame(1.0, $seo['priority'], 'home.json의 SEO priority는 1.0이어야 합니다');

        // changefreq 확인
        $this->assertArrayHasKey('changefreq', $seo, 'home.json seo에 changefreq 키가 존재해야 합니다');

        // og 확인
        $this->assertArrayHasKey('og', $seo, 'home.json seo에 og 키가 존재해야 합니다');
        $this->assertSame('website', $seo['og']['type'], 'home.json og type은 website여야 합니다');

        // structured_data 확인 (WebSite 타입)
        $this->assertArrayHasKey('structured_data', $seo, 'home.json seo에 structured_data 키가 존재해야 합니다');
        $this->assertSame('WebSite', $seo['structured_data']['@type'], 'home.json structured_data @type은 WebSite여야 합니다');

        // data_sources 확인
        $this->assertArrayHasKey('data_sources', $seo, 'home.json seo에 data_sources 키가 존재해야 합니다');
        $this->assertIsArray($seo['data_sources']);
    }

    /**
     * shop/show.json 레이아웃에 Product structured_data가 설정되어 있는지 확인합니다.
     */
    public function test_shop_show_layout_has_product_structured_data(): void
    {
        $layout = $this->readLayout('shop/show.json');

        // meta.seo 존재 확인
        $this->assertArrayHasKey('meta', $layout, 'shop/show.json에 meta 키가 존재해야 합니다');
        $this->assertArrayHasKey('seo', $layout['meta'], 'shop/show.json에 meta.seo 키가 존재해야 합니다');

        $seo = $layout['meta']['seo'];

        // SEO 활성화 확인
        $this->assertTrue($seo['enabled'], 'shop/show.json의 SEO는 활성화되어야 합니다');

        // structured_data @type 확인 (Product)
        $this->assertArrayHasKey('structured_data', $seo, 'shop/show.json seo에 structured_data 키가 존재해야 합니다');
        $this->assertSame('Product', $seo['structured_data']['@type'], 'shop/show.json structured_data @type은 Product여야 합니다');

        // Product structured_data 필수 필드 확인
        $this->assertArrayHasKey('name', $seo['structured_data'], 'Product structured_data에 name이 존재해야 합니다');
        $this->assertArrayHasKey('description', $seo['structured_data'], 'Product structured_data에 description이 존재해야 합니다');

        // offers (Offer 타입) 확인
        $this->assertArrayHasKey('offers', $seo['structured_data'], 'Product structured_data에 offers가 존재해야 합니다');
        $this->assertSame('Offer', $seo['structured_data']['offers']['@type'], 'offers @type은 Offer여야 합니다');
        $this->assertArrayHasKey('price', $seo['structured_data']['offers'], 'Offer에 price가 존재해야 합니다');
        $this->assertArrayHasKey('priceCurrency', $seo['structured_data']['offers'], 'Offer에 priceCurrency가 존재해야 합니다');

        // og 타입 확인
        $this->assertArrayHasKey('og', $seo, 'shop/show.json seo에 og 키가 존재해야 합니다');
        $this->assertSame('product', $seo['og']['type'], 'shop/show.json og type은 product여야 합니다');

        // data_sources 확인 (product 데이터소스 참조)
        $this->assertArrayHasKey('data_sources', $seo, 'shop/show.json seo에 data_sources 키가 존재해야 합니다');
        $this->assertContains('product', $seo['data_sources'], 'shop/show.json seo data_sources에 product가 포함되어야 합니다');
    }

    /**
     * board/show.json 레이아웃에 Article structured_data가 설정되어 있는지 확인합니다.
     */
    public function test_board_show_layout_has_article_structured_data(): void
    {
        $layout = $this->readLayout('board/show.json');

        // meta.seo 존재 확인
        $this->assertArrayHasKey('meta', $layout, 'board/show.json에 meta 키가 존재해야 합니다');
        $this->assertArrayHasKey('seo', $layout['meta'], 'board/show.json에 meta.seo 키가 존재해야 합니다');

        $seo = $layout['meta']['seo'];

        // SEO 활성화 확인
        $this->assertTrue($seo['enabled'], 'board/show.json의 SEO는 활성화되어야 합니다');

        // structured_data @type 확인 (Article)
        $this->assertArrayHasKey('structured_data', $seo, 'board/show.json seo에 structured_data 키가 존재해야 합니다');
        $this->assertSame('Article', $seo['structured_data']['@type'], 'board/show.json structured_data @type은 Article이어야 합니다');

        // Article structured_data 필수 필드 확인
        $this->assertArrayHasKey('headline', $seo['structured_data'], 'Article structured_data에 headline이 존재해야 합니다');
        $this->assertArrayHasKey('datePublished', $seo['structured_data'], 'Article structured_data에 datePublished가 존재해야 합니다');
        $this->assertArrayHasKey('dateModified', $seo['structured_data'], 'Article structured_data에 dateModified가 존재해야 합니다');

        // og 타입 확인
        $this->assertArrayHasKey('og', $seo, 'board/show.json seo에 og 키가 존재해야 합니다');
        $this->assertSame('article', $seo['og']['type'], 'board/show.json og type은 article이어야 합니다');

        // data_sources 확인 (post 데이터소스 참조)
        $this->assertArrayHasKey('data_sources', $seo, 'board/show.json seo에 data_sources 키가 존재해야 합니다');
        $this->assertContains('post', $seo['data_sources'], 'board/show.json seo data_sources에 post가 포함되어야 합니다');
    }

    /**
     * search/index.json 레이아웃에 SEO 설정이 최소화(낮은 priority)되어 있는지 확인합니다.
     */
    public function test_search_layout_has_seo_with_minimal_priority(): void
    {
        $layout = $this->readLayout('search/index.json');

        // meta.seo 존재 확인
        $this->assertArrayHasKey('meta', $layout, 'search/index.json에 meta 키가 존재해야 합니다');
        $this->assertArrayHasKey('seo', $layout['meta'], 'search/index.json에 meta.seo 키가 존재해야 합니다');

        $seo = $layout['meta']['seo'];

        // priority 확인 (검색 페이지는 낮은 우선순위)
        $this->assertArrayHasKey('priority', $seo, 'search/index.json seo에 priority 키가 존재해야 합니다');
        $this->assertLessThanOrEqual(0.5, $seo['priority'], '검색 페이지 SEO priority는 0.5 이하여야 합니다');

        // data_sources 확인 (검색 결과는 SEO 데이터소스 불필요)
        $this->assertArrayHasKey('data_sources', $seo, 'search/index.json seo에 data_sources 키가 존재해야 합니다');
        $this->assertEmpty($seo['data_sources'], '검색 페이지의 SEO data_sources는 비어있어야 합니다');

        // structured_data 확인 (SearchResultsPage)
        $this->assertArrayHasKey('structured_data', $seo, 'search/index.json seo에 structured_data 키가 존재해야 합니다');
        $this->assertSame(
            'SearchResultsPage',
            $seo['structured_data']['@type'],
            'search/index.json structured_data @type은 SearchResultsPage여야 합니다'
        );
    }

    /**
     * 모든 주요 레이아웃의 SEO priority 값이 유효한 범위(0~1)인지 확인합니다.
     */
    public function test_all_layouts_have_valid_seo_priority_range(): void
    {
        $layoutFiles = [
            'home.json',
            'shop/show.json',
            'board/show.json',
            'search/index.json',
        ];

        foreach ($layoutFiles as $file) {
            $layout = $this->readLayout($file);

            if (isset($layout['meta']['seo']['priority'])) {
                $priority = $layout['meta']['seo']['priority'];
                $this->assertGreaterThanOrEqual(0, $priority, "{$file}의 SEO priority는 0 이상이어야 합니다");
                $this->assertLessThanOrEqual(1, $priority, "{$file}의 SEO priority는 1 이하여야 합니다");
            }
        }
    }

    /**
     * 모든 주요 레이아웃의 SEO changefreq 값이 유효한 값인지 확인합니다.
     */
    public function test_all_layouts_have_valid_seo_changefreq(): void
    {
        $validValues = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];
        $layoutFiles = [
            'home.json',
            'shop/show.json',
            'board/show.json',
            'search/index.json',
        ];

        foreach ($layoutFiles as $file) {
            $layout = $this->readLayout($file);

            if (isset($layout['meta']['seo']['changefreq'])) {
                $changefreq = $layout['meta']['seo']['changefreq'];
                $this->assertContains(
                    $changefreq,
                    $validValues,
                    "{$file}의 SEO changefreq '{$changefreq}'는 유효한 값이어야 합니다"
                );
            }
        }
    }
}
