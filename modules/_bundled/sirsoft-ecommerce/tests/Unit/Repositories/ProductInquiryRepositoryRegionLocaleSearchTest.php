<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductInquiryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 내 상품문의 상품명 검색 — 하이픈 로케일 JSON 경로 (#148)
 *
 * `'$.fil-PH'` 는 잘못된 JSON 경로라 MySQL 3143 으로 검색 전체가 500 이었다 — 키를 따옴표로 감싼다.
 */
class ProductInquiryRepositoryRegionLocaleSearchTest extends ModuleTestCase
{
    protected ProductInquiryRepositoryInterface $repository;

    protected Product $product;

    /**
     * 저장소와 문의 대상 상품을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ProductInquiryRepositoryInterface::class);
        $this->product = Product::factory()->create();
    }

    /**
     * 하이픈 로케일(fil-PH)이 번역 언어로 켜져 있어도 내 문의 상품명 검색이 실패하지 않는다.
     *
     * @scenario shape=region_tagged
     *
     * @effects json_path_search_quotes_hyphen_locale
     *
     * @return void 반환값 없음
     */
    public function test_find_by_user_id_search_handles_region_tagged_locale(): void
    {
        config(['app.translatable_locales' => ['ko', 'en', 'fil-PH']]);

        $user = User::factory()->create();
        ProductInquiry::factory()->create([
            'product_id' => $this->product->id,
            'user_id' => $user->id,
            'product_name_snapshot' => ['ko' => '운동화', 'en' => 'Sneakers', 'fil-PH' => 'Sapatos'],
        ]);

        $result = $this->repository->findByUserId($user->id, ['search' => 'Sapatos']);

        $this->assertSame(1, $result->total());
    }
}
