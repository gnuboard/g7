<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Support\ApiDoc\ApiDocSampleService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * sirsoft-ecommerce API 문서 실측용 완전 샘플 시더 테스트.
 *
 * 이커머스 도메인 대표 샘플(상품·주문·카테고리·리뷰 등)이 생성되고, 문자열 path
 * 파라미터(product_code·slug·order_number) 실측을 위한 도메인별 path_params 맵이
 * 반환되며, 재실행 시 멱등한지 검증한다.
 */
class ApiDocSampleServiceTest extends ModuleTestCase
{
    /**
     * @var string 샘플 상품 product_code 마커
     */
    private const SAMPLE_PRODUCT_CODE = 'APIDOCSAMPLE01';

    /**
     * @var string 샘플 카테고리 slug 마커
     */
    private const SAMPLE_CATEGORY_SLUG = 'apidoc-sample-category';

    #[Test]
    public function 시더는_계약을_구현한다(): void
    {
        $this->assertInstanceOf(ApiDocSampleSeeder::class, new ApiDocSampleService);
    }

    #[Test]
    public function 이커머스_핵심_도메인_대표_샘플_맵을_반환한다(): void
    {
        // 회원 소유 도메인(orders/reviews/inquiries/addresses/mileage)은 실측 사용자를
        // 전제한다 — 실측 커맨드 컨텍스트(코어 완전 샘플 사용자)를 재현하기 위해 먼저 생성.
        User::factory()->create();

        $map = (new ApiDocSampleService)->seed();

        // 핵심 상세 GET 대상 도메인 키가 모두 존재
        foreach (['products', 'categories', 'orders', 'reviews', 'settings'] as $domain) {
            $this->assertArrayHasKey($domain, $map, "도메인 '{$domain}' 누락");
        }

        $this->assertSame(Product::class, $map['products']['model']);
        $this->assertNotEmpty($map['products']['value']);
    }

    #[Test]
    public function 문자열_path_param_실측용_맵을_제공한다(): void
    {
        $map = (new ApiDocSampleService)->seed();

        // products: 공개 show(id)·admin show(identifier/code)·상품 하위(productId)
        $product = Product::query()->where('product_code', self::SAMPLE_PRODUCT_CODE)->firstOrFail();
        $params = $map['products']['path_params'];

        $this->assertSame((string) $product->getKey(), $params['id']);
        $this->assertSame((string) $product->getKey(), $params['identifier']);
        $this->assertSame(self::SAMPLE_PRODUCT_CODE, $params['code']);
        $this->assertSame((string) $product->getKey(), $params['productId']);

        // categories: 공개 show 는 slug
        $this->assertSame(self::SAMPLE_CATEGORY_SLUG, $map['categories']['path_params']['slug']);

        // settings: 레코드 없이 유효 카테고리 문자열 고정
        $this->assertSame('basic_info', $map['settings']['path_params']['category']);
    }

    #[Test]
    public function 공개_상세_실측용_상품은_전시_상태다(): void
    {
        (new ApiDocSampleService)->seed();

        $product = Product::query()->where('product_code', self::SAMPLE_PRODUCT_CODE)->firstOrFail();

        // products/{id} 공개 show 는 display_status===visible 필터 → 실측 가능해야 함
        $this->assertSame('visible', $product->display_status->value);
        $this->assertSame('on_sale', $product->sales_status->value);
    }

    #[Test]
    public function 공개_slug_조회용_카테고리는_활성이며_slug를_갖는다(): void
    {
        (new ApiDocSampleService)->seed();

        $category = Category::query()->where('slug', self::SAMPLE_CATEGORY_SLUG)->firstOrFail();

        $this->assertTrue((bool) $category->is_active);
        $this->assertSame(self::SAMPLE_CATEGORY_SLUG, $category->slug);
    }

    #[Test]
    public function 재실행_시_샘플이_중복_생성되지_않는다(): void
    {
        $service = new ApiDocSampleService;

        $service->seed();
        $service->seed();

        $this->assertSame(
            1,
            Product::query()->where('product_code', self::SAMPLE_PRODUCT_CODE)->count()
        );
        $this->assertSame(
            1,
            Category::query()->where('slug', self::SAMPLE_CATEGORY_SLUG)->count()
        );
    }
}
