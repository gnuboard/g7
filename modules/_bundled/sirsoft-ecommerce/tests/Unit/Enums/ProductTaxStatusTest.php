<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 상품 과세 종류 Enum 테스트 (#612)
 *
 * 영세(zero_rated)를 더한 세 값과, 옵션 지정 ?? 상품 기본값으로 실효 과세 종류를 고르는 규칙을 잠근다.
 */
class ProductTaxStatusTest extends ModuleTestCase
{
    /**
     * 과세 종류 값이 세 가지이고 라벨 키가 enums 번역을 가리키는지 확인합니다.
     *
     * @effects product_tax_status_accepts_zero_rated
     *
     * @return void 반환값 없음
     */
    public function test_values_include_zero_rated(): void
    {
        $this->assertSame(['taxable', 'tax_free', 'zero_rated'], ProductTaxStatus::values());
        $this->assertSame('sirsoft-ecommerce::enums.tax_status.zero_rated', ProductTaxStatus::ZERO_RATED->labelKey());
        $this->assertNotSame(ProductTaxStatus::ZERO_RATED->labelKey(), ProductTaxStatus::ZERO_RATED->label());
    }

    /**
     * 옵션 지정이 있으면 그 값, 없으면 상품 기본값, 둘 다 없으면 과세를 고르는지 확인합니다 (4×3 + 기본).
     *
     * @effects effective_status_option_overrides_product
     *
     * @param  ProductTaxStatus|null  $option  옵션 지정값
     * @param  ProductTaxStatus|null  $product  상품 기본값
     * @param  ProductTaxStatus  $expected  기대 실효 과세 종류
     * @return void 반환값 없음
     */
    #[DataProvider('effectiveProvider')]
    public function test_resolve_effective(?ProductTaxStatus $option, ?ProductTaxStatus $product, ProductTaxStatus $expected): void
    {
        $this->assertSame($expected, ProductTaxStatus::resolveEffective($option, $product));
    }

    /**
     * 옵션(따름·과세·면세·영세) × 상품(과세·면세·영세) 조합과 상품 미상 케이스를 제공합니다.
     *
     * @return array<string, array{ProductTaxStatus|null, ProductTaxStatus|null, ProductTaxStatus}> 케이스
     */
    public static function effectiveProvider(): array
    {
        $cases = [];
        foreach (ProductTaxStatus::cases() as $product) {
            $cases["따름 × {$product->value}"] = [null, $product, $product];
            foreach (ProductTaxStatus::cases() as $option) {
                $cases["{$option->value} × {$product->value}"] = [$option, $product, $option];
            }
        }
        $cases['따름 × 상품 미상'] = [null, null, ProductTaxStatus::TAXABLE];

        return $cases;
    }

    /**
     * 과세표준에 드는 종류가 과세·영세뿐인지 확인합니다.
     *
     * @effects effective_status_option_overrides_product
     *
     * @return void 반환값 없음
     */
    public function test_tax_base_membership(): void
    {
        $this->assertTrue(ProductTaxStatus::TAXABLE->isInTaxBase());
        $this->assertTrue(ProductTaxStatus::ZERO_RATED->isInTaxBase());
        $this->assertFalse(ProductTaxStatus::TAX_FREE->isInTaxBase());
    }
}
