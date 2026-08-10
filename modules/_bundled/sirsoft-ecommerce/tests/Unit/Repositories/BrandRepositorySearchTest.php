<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Repositories\BrandRepository;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 브랜드 Repository 검색 회귀 테스트
 *
 * 결함: 브랜드명으로 검색하면 결과가 항상 0건이었다. FULLTEXT 로 이름을 맞힌 뒤
 *   Scout 의 query 콜백 안에서 `slug`/`website` 를 OR 로 덧붙여 검색 범위를 넓히려 했으나,
 *   그 콜백은 이미 맞힌 결과를 **좁히는** 자리라 실제로는 AND 로 걸렸다. 이름만 일치하고
 *   slug·website 에는 키워드가 없는 브랜드(대부분)가 전부 걸러졌다.
 *
 * 검색은 세 필드 중 하나라도 맞으면 나와야 한다 — 이름 / slug / 웹사이트.
 */
class BrandRepositorySearchTest extends ModuleTestCase
{
    protected BrandRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new BrandRepository(new Brand);
    }

    /**
     * 테스트용 브랜드를 만듭니다.
     *
     * @param  array<string, string>  $name  로케일별 이름
     * @param  string  $slug  slug
     * @param  string|null  $website  웹사이트 주소
     * @return Brand 생성된 브랜드
     */
    private function makeBrand(array $name, string $slug, ?string $website = null): Brand
    {
        return Brand::create([
            'name' => $name,
            'slug' => $slug,
            'website' => $website,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    /**
     * 이름 검색은 결과 행이 아니라 실행된 SQL 형태로 단언한다.
     *
     * InnoDB FULLTEXT 색인은 커밋 이후에만 보이므로, 트랜잭션 안에서 만든 행은 MATCH 로
     * 잡히지 않는다 (코어 DatabaseFulltextEngineTest 도 같은 이유로 SQL 을 단언한다).
     * 이번 결함의 본질은 "이름 조건이 slug·website 와 OR 로 묶이는가" 이므로 그 형태를 고정한다.
     */
    public function test_search_combines_name_slug_website_with_or(): void
    {
        $this->makeBrand(['ko' => '나이키', 'en' => 'Nike'], 'nike', 'https://www.nike.com');

        DB::enableQueryLog();
        $this->repository->getAll(['search' => '나이키']);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $sql = collect($log)->pluck('query')->implode(' | ');

        $this->assertStringContainsString(
            'match(`name`) against',
            strtolower($sql),
            '이름에 대한 FULLTEXT 조건이 쿼리에 없습니다.'
        );
        $this->assertMatchesRegularExpression(
            '/against\(\? in boolean mode\)\s+or\s+`slug`\s+like\s+\?\s+or\s+`website`\s+like\s+\?/i',
            strtolower($sql),
            '이름·slug·website 가 OR 로 묶이지 않았습니다 — 좁히는 AND 로 걸리면 이름만 일치하는 브랜드가 전부 사라집니다.'
        );
    }

    public function test_search_finds_brand_by_slug(): void
    {
        // Given: 이름은 한글, slug 는 영문
        $puma = $this->makeBrand(['ko' => '퓨마', 'en' => 'Puma'], 'puma-korea', 'https://www.puma.com');

        // When: slug 조각으로 검색
        $result = $this->repository->getAll(['search' => 'puma-korea']);

        // Then: slug 가 맞았으므로 나와야 한다
        $this->assertCount(1, $result, 'slug 가 일치하는 브랜드가 검색되지 않았습니다.');
        $this->assertSame($puma->id, $result->first()->id);
    }

    public function test_search_finds_brand_by_website(): void
    {
        // Given: 이름·slug 와 무관한 도메인
        $zara = $this->makeBrand(['ko' => '자라', 'en' => 'ZARA'], 'zara', 'https://shop.inditex-group.com');

        // When: 도메인 조각으로 검색
        $result = $this->repository->getAll(['search' => 'inditex-group']);

        // Then: website 가 맞았으므로 나와야 한다
        $this->assertCount(1, $result, 'website 가 일치하는 브랜드가 검색되지 않았습니다.');
        $this->assertSame($zara->id, $result->first()->id);
    }

    public function test_search_excludes_unrelated_brands(): void
    {
        // Given: 서로 겹치지 않는 두 브랜드
        $this->makeBrand(['ko' => '나이키', 'en' => 'Nike'], 'nike', 'https://www.nike.com');
        $this->makeBrand(['ko' => '아디다스', 'en' => 'Adidas'], 'adidas', 'https://www.adidas.com');

        // When: 한쪽만 가리키는 키워드로 검색
        $result = $this->repository->getAll(['search' => 'adidas']);

        // Then: 나머지는 섞이지 않는다
        $this->assertCount(1, $result);
        $this->assertSame('adidas', $result->first()->slug);
    }

    public function test_search_respects_active_filter(): void
    {
        // Given: 같은 키워드를 가진 활성/비활성 브랜드
        $active = $this->makeBrand(['ko' => '나이키', 'en' => 'Nike'], 'nike-korea', null);
        $inactive = $this->makeBrand(['ko' => '나이키 키즈', 'en' => 'Nike Kids'], 'nike-kids', null);
        $inactive->update(['is_active' => false]);

        // When: 활성만 요청
        $result = $this->repository->getAll(['search' => 'nike-korea', 'is_active' => true]);

        // Then: 검색과 필터가 함께 적용된다
        $this->assertCount(1, $result);
        $this->assertSame($active->id, $result->first()->id);
    }

    public function test_search_keeps_active_filter_and_keyword_grouped(): void
    {
        // Given: 비활성 브랜드가 키워드를 담고 있다
        $this->makeBrand(['ko' => '나이키', 'en' => 'Nike'], 'nike-korea', null)
            ->update(['is_active' => false]);

        // When: 활성만 요청
        $result = $this->repository->getAll(['search' => 'nike-korea', 'is_active' => true]);

        // Then: OR 그룹이 괄호로 묶여야 활성 조건이 무력화되지 않는다
        $this->assertCount(0, $result, 'is_active 조건이 OR 로 흡수돼 비활성 브랜드가 새어 나왔습니다.');
    }
}
