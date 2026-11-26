<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\ProductSummary;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\ProductDirectory;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 조회 공개 계약 
 *
 * 다른 확장(히어로섹션 상품 위치)은 상품 Model·저장소 대신 이 계약으로 상품을 찾는다. 이름·사이트 경로·id 묶음 조회가 전환 전 히어로
 * 해석기가 직접 만들던 값과 같아야 한다.
 */
class ProductDirectoryTest extends ModuleTestCase
{
    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 계약이 컨테이너에 바인딩되고, 코드·id·id 묶음으로 찾은 요약에 현재 언어 이름과 사이트 경로가 담긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=product_directory_find
     *
     * @effects product_directory_finds_by_code_id_and_ids
     */
    public function test_finds_by_code_id_and_ids(): void
    {
        app()->setLocale('ko');
        $product = Product::factory()->create(['product_code' => 'FX916-P1', 'name' => ['ko' => '무선 마우스', 'en' => 'Wireless mouse']]);
        $directory = app(ProductDirectory::class);

        $byCode = $directory->findByCode('FX916-P1');
        $this->assertInstanceOf(ProductSummary::class, $byCode);
        $this->assertSame($product->id, $byCode->id);
        $this->assertSame('FX916-P1', $byCode->code);
        $this->assertSame('무선 마우스', $byCode->label);
        $this->assertStringEndsWith('/products/FX916-P1', $byCode->url);

        $this->assertSame('FX916-P1', $directory->findById($product->id)?->code);
        $this->assertNull($directory->findById(0));
        $this->assertNull($directory->findByCode('FX916-NONE'));
        $this->assertNull($directory->findByCode(''));

        $many = $directory->findByIds([$product->id, 999999]);
        $this->assertSame([$product->id], array_keys($many));
        $this->assertSame(['FX916-P1' => '무선 마우스'], $directory->labelsByCodes(['FX916-P1', 'FX916-NONE']));
    }

    /**
     * 이름 일괄 조회는 코드 수와 무관하게 한 번에 읽고, 결과는 요청 순서대로 코드마다 찾던 값과 같다(없는 코드, 빈 값, 중복은 빠진다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=product_directory_labels_batch
     *
     * @effects product_directory_labels_read_in_one_query
     */
    public function test_labels_by_codes_read_in_one_query_and_match_single_lookups(): void
    {
        app()->setLocale('ko');
        Product::factory()->create(['product_code' => 'FX916-L1', 'name' => ['ko' => '키보드', 'en' => 'Keyboard']]);
        Product::factory()->create(['product_code' => 'FX916-L2', 'name' => ['ko' => '마우스', 'en' => 'Mouse']]);
        Product::factory()->create(['product_code' => 'FX916-L3', 'name' => ['ko' => '모니터', 'en' => 'Monitor']]);
        $directory = app(ProductDirectory::class);
        $codes = ['FX916-L3', ' FX916-L1 ', 'FX916-NONE', '', 'FX916-L3', 'FX916-L2'];

        $expected = [];
        foreach (['FX916-L3', 'FX916-L1', 'FX916-L2'] as $code) {
            $expected[$code] = $directory->findByCode($code)?->label;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $labels = $directory->labelsByCodes($codes);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($expected, $labels);
        $this->assertSame(1, $queries, '코드마다 조회하지 않고 한 번에 읽어야 합니다');
        $this->assertSame([], $directory->labelsByCodes(['', '  ']));
    }

    /**
     * 검색은 상품 저장소의 키워드 검색(전시 상품)에 위임하고 건수를 지킨다. 0 건 요청은 묻지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=product_directory_search
     *
     * @effects product_directory_search_delegates_to_repository_within_limit
     */
    public function test_search_delegates_and_respects_limit(): void
    {
        $directory = app(ProductDirectory::class);

        $this->assertSame([], $directory->search('anything', 0));
        foreach ($directory->search('', 2) as $summary) {
            $this->assertInstanceOf(ProductSummary::class, $summary);
        }
        $this->assertLessThanOrEqual(2, count($directory->search('', 2)));
    }
}
