<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as SupportCollection;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductAdditionalOptionValueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 추가옵션 선택지 저장소의 컬렉션 타입 계약 테스트.
 *
 * 회귀 배경: `getActiveByProductIdsKeyed()` 는 `id => Collection` 중첩 맵을 만드는데
 * 반환 타입 선언이 `Eloquent\Collection` 이었다. `Eloquent\Collection::map()` 은 결과 항목 중
 * 하나라도 Model 이 아니면 `toBase()` 로 강등하므로(모델 컬렉션이라는 불변식을 지키는 의도된
 * 방어) 이 메서드는 **항상** Support 를 반환했고, 추가옵션 선택지가 하나라도 있는 상품의
 * 장바구니 담기·바로구매가 TypeError 로 500 이 됐다. 선택지가 없는 상품은 `groupBy` 결과가
 * 비어 `map()` 이 강등하지 않아 통과했기 때문에 일부 상품에서만 죽는 형태로 나타났다.
 *
 * 계약 요약: **평면 `id => Model` 맵은 Eloquent, 중첩 맵은 Support.**
 */
class ProductAdditionalOptionValueRepositoryTest extends ModuleTestCase
{
    protected ProductAdditionalOptionValueRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ProductAdditionalOptionValueRepositoryInterface::class);
    }

    /**
     * 추가옵션 그룹 + 선택지를 생성합니다.
     *
     * @param  Product  $product  상품
     * @param  bool  $isActive  활성 여부
     * @return ProductAdditionalOptionValue 생성된 선택지
     */
    protected function createValue(Product $product, bool $isActive = true): ProductAdditionalOptionValue
    {
        $group = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '각인', 'en' => 'Engraving'],
            'is_required' => false,
            'sort_order' => 0,
        ]);

        return ProductAdditionalOptionValue::create([
            'additional_option_id' => $group->id,
            'name' => ['ko' => '추가', 'en' => 'Add'],
            'price_adjustment' => 1000,
            'is_default' => false,
            'is_active' => $isActive,
            'allow_custom_text' => false,
            'sort_order' => 0,
        ]);
    }

    /**
     * 상품을 생성합니다.
     *
     * @return Product 생성된 상품
     */
    protected function createProduct(): Product
    {
        return ProductFactory::new()->create(['selling_price' => 10000, 'list_price' => 10000]);
    }

    /**
     * 중첩 맵의 외곽은 Support, 각 그룹은 Eloquent 여야 한다.
     *
     * 수정 전에는 첫 단언에 닿기 전에 반환 타입 TypeError 로 죽는다.
     */
    public function test_batch_keyed_returns_support_collection_of_eloquent_groups(): void
    {
        $productA = $this->createProduct();
        $productB = $this->createProduct();

        $valueA1 = $this->createValue($productA);
        $valueA2 = $this->createValue($productA);
        $valueB1 = $this->createValue($productB);

        $result = $this->repository->getActiveByProductIdsKeyed([$productA->id, $productB->id]);

        $this->assertInstanceOf(SupportCollection::class, $result);
        $this->assertNotInstanceOf(
            EloquentCollection::class,
            $result,
            '중첩 맵의 항목은 Model 이 아니므로 Eloquent\Collection 계약(load/modelKeys/fresh 가 '
            .'instanceof Model 을 전제)을 만족할 수 없습니다. 외곽은 Support 여야 합니다.',
        );

        $groupA = $result->get($productA->id);
        $this->assertInstanceOf(EloquentCollection::class, $groupA);
        $this->assertTrue($groupA->has($valueA1->id));
        $this->assertTrue($groupA->has($valueA2->id));

        $groupB = $result->get($productB->id);
        $this->assertInstanceOf(EloquentCollection::class, $groupB);
        $this->assertTrue($groupB->has($valueB1->id));
        $this->assertFalse($groupB->has($valueA1->id), '상품 경계를 넘어 섞이면 안 됩니다.');
    }

    /**
     * 빈 입력의 early return 분기도 같은 타입이어야 한다.
     *
     * 이 분기를 고치지 않으면 정정이 반쪽이 된다 — 빈 입력에서만 Eloquent 를 돌려주는
     * 분기별 타입 불일치가 남는다.
     */
    public function test_batch_keyed_returns_empty_support_collection_for_empty_input(): void
    {
        // [0] 은 array_filter 로 걸러져 결과적으로 빈 배열이 되는 입력이다.
        foreach ([[], [0]] as $input) {
            $result = $this->repository->getActiveByProductIdsKeyed($input);

            $this->assertInstanceOf(SupportCollection::class, $result);
            $this->assertNotInstanceOf(EloquentCollection::class, $result);
            $this->assertTrue($result->isEmpty());
        }
    }

    /**
     * 단일 상품 조회는 진짜 모델 컬렉션이므로 Eloquent 가 정당하다.
     */
    public function test_single_product_keyed_returns_eloquent_collection_keyed_by_value_id(): void
    {
        $product = $this->createProduct();
        $value = $this->createValue($product);

        $result = $this->repository->getActiveByProductKeyed($product->id);

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertInstanceOf(ProductAdditionalOptionValue::class, $result->get($value->id));
        $this->assertTrue(
            $result->get($value->id)->relationLoaded('additionalOption'),
            'additionalOption 이 eager load 되어야 소속 검증에서 N+1 이 나지 않습니다.',
        );
    }

    /**
     * 선택지가 없는 상품은 위임의 `?? new Collection` 분기를 탄다.
     */
    public function test_single_product_keyed_returns_empty_eloquent_collection_when_no_values(): void
    {
        $product = $this->createProduct();

        $result = $this->repository->getActiveByProductKeyed($product->id);

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    /**
     * 비활성 선택지는 제외된다 — 타입 리팩터가 필터를 조용히 날리지 못하게 하는 동작 가드.
     */
    public function test_batch_keyed_excludes_inactive_values(): void
    {
        $product = $this->createProduct();
        $active = $this->createValue($product);
        $inactive = $this->createValue($product, isActive: false);

        $group = $this->repository->getActiveByProductIdsKeyed([$product->id])->get($product->id);

        $this->assertTrue($group->has($active->id));
        $this->assertFalse($group->has($inactive->id));
    }
}
