<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\ShippingFeeTaxPolicy;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * 배송비 과세 정책 테스트 (A-4)
 *
 * 배송비 과세 분류는 상품 분류(단계 2-b)와 달리 배송비 할인이 확정된 뒤에 계산된다.
 * 정책 3종 × 과세/면세/복합 = 9케이스 + 안분 반올림 잔차의 합계 보존을 검증한다.
 */
class ShippingFeeTaxPolicyTest extends ModuleTestCase
{
    /**
     * classifyShippingFeeTaxStatus 를 지정 정책으로 호출합니다.
     *
     * @return array{taxable_amount: int, tax_free_amount: int}
     */
    private function classify(
        ShippingFeeTaxPolicy $policy,
        int $netShippingFee,
        int $productTaxable,
        int $productTaxFree,
    ): array {
        $service = $this->getMockBuilder(OrderCalculationService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveShippingFeeTaxPolicy'])
            ->getMock();

        $service->method('resolveShippingFeeTaxPolicy')->willReturn($policy);

        $method = new ReflectionMethod(OrderCalculationService::class, 'classifyShippingFeeTaxStatus');
        $method->setAccessible(true);

        return $method->invoke($service, $netShippingFee, $productTaxable, $productTaxFree);
    }

    #[Test]
    #[DataProvider('policyMatrixProvider')]
    public function 배송비_과세_정책_9케이스(
        ShippingFeeTaxPolicy $policy,
        int $shipping,
        int $taxable,
        int $taxFree,
        int $expectedTaxable,
        int $expectedTaxFree,
    ): void {
        $result = $this->classify($policy, $shipping, $taxable, $taxFree);

        $this->assertSame($expectedTaxable, $result['taxable_amount'], '배송비 과세분');
        $this->assertSame($expectedTaxFree, $result['tax_free_amount'], '배송비 면세분');
        $this->assertSame(
            $shipping,
            $result['taxable_amount'] + $result['tax_free_amount'],
            '배송비 합계가 보존되어야 한다',
        );
    }

    /**
     * @return array<string, array{ShippingFeeTaxPolicy, int, int, int, int, int}>
     */
    public static function policyMatrixProvider(): array
    {
        return [
            // 안분 (proportional)
            '안분 × 전액과세' => [ShippingFeeTaxPolicy::PROPORTIONAL, 3000, 10000, 0, 3000, 0],
            '안분 × 전액면세' => [ShippingFeeTaxPolicy::PROPORTIONAL, 3000, 0, 10000, 0, 3000],
            '안분 × 복합(과세 6:면세 4)' => [ShippingFeeTaxPolicy::PROPORTIONAL, 3000, 6000, 4000, 1800, 1200],

            // 전액과세 (taxable)
            '전액과세 × 전액과세' => [ShippingFeeTaxPolicy::TAXABLE, 3000, 10000, 0, 3000, 0],
            '전액과세 × 전액면세' => [ShippingFeeTaxPolicy::TAXABLE, 3000, 0, 10000, 3000, 0],
            '전액과세 × 복합' => [ShippingFeeTaxPolicy::TAXABLE, 3000, 6000, 4000, 3000, 0],

            // 주된재화 (follow_main_item)
            '주된재화 × 전액과세' => [ShippingFeeTaxPolicy::FOLLOW_MAIN_ITEM, 3000, 10000, 0, 3000, 0],
            '주된재화 × 전액면세' => [ShippingFeeTaxPolicy::FOLLOW_MAIN_ITEM, 3000, 0, 10000, 0, 3000],
            '주된재화 × 복합(과세 우세)' => [ShippingFeeTaxPolicy::FOLLOW_MAIN_ITEM, 3000, 6000, 4000, 3000, 0],
        ];
    }

    #[Test]
    public function 주된재화_정책은_면세가_우세하면_배송비를_면세로_분류한다(): void
    {
        $result = $this->classify(ShippingFeeTaxPolicy::FOLLOW_MAIN_ITEM, 3000, 4000, 6000);

        $this->assertSame(0, $result['taxable_amount']);
        $this->assertSame(3000, $result['tax_free_amount']);
    }

    #[Test]
    public function 주된재화_정책은_동률이면_과세로_분류한다(): void
    {
        $result = $this->classify(ShippingFeeTaxPolicy::FOLLOW_MAIN_ITEM, 3000, 5000, 5000);

        $this->assertSame(3000, $result['taxable_amount']);
        $this->assertSame(0, $result['tax_free_amount']);
    }

    #[Test]
    #[DataProvider('roundingProvider')]
    public function 안분_반올림_잔차가_합계를_보존한다(int $shipping, int $taxable, int $taxFree): void
    {
        $result = $this->classify(ShippingFeeTaxPolicy::PROPORTIONAL, $shipping, $taxable, $taxFree);

        $this->assertSame(
            $shipping,
            $result['taxable_amount'] + $result['tax_free_amount'],
            "배송비 {$shipping} 이 과세 {$result['taxable_amount']} + 면세 {$result['tax_free_amount']} 로 보존되어야 한다",
        );
        $this->assertGreaterThanOrEqual(0, $result['taxable_amount']);
        $this->assertGreaterThanOrEqual(0, $result['tax_free_amount']);
    }

    /**
     * 나누어떨어지지 않아 반올림 잔차가 발생하는 조합들.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function roundingProvider(): array
    {
        return [
            '3000 × 1:2' => [3000, 1000, 2000],
            '2500 × 1:3' => [2500, 1000, 3000],
            '3000 × 7:3 (1/3 반복소수)' => [3000, 7000, 3000],
            '1 원 배송비' => [1, 1, 2],
            '2 원 배송비 홀수 비율' => [2, 1, 2],
            '3333 × 1:1' => [3333, 5000, 5000],
            '999 × 1:6' => [999, 1000, 6000],
        ];
    }

    #[Test]
    public function 배송비가_0원이면_과세_면세_모두_0이다(): void
    {
        foreach (ShippingFeeTaxPolicy::cases() as $policy) {
            $result = $this->classify($policy, 0, 6000, 4000);

            $this->assertSame(0, $result['taxable_amount'], $policy->value);
            $this->assertSame(0, $result['tax_free_amount'], $policy->value);
        }
    }

    #[Test]
    public function 상품금액이_0이면_안분_기준이_없어_전액_과세한다(): void
    {
        foreach (ShippingFeeTaxPolicy::cases() as $policy) {
            $result = $this->classify($policy, 3000, 0, 0);

            $this->assertSame(3000, $result['taxable_amount'], $policy->value);
            $this->assertSame(0, $result['tax_free_amount'], $policy->value);
        }
    }

    #[Test]
    public function 배송비_할인이_반영된_실질_배송비를_사용한다(): void
    {
        $method = new ReflectionMethod(OrderCalculationService::class, 'resolveNetShippingFee');
        $method->setAccessible(true);

        $service = $this->getMockBuilder(OrderCalculationService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->assertSame(1000, $method->invoke($service, [
            'total_shipping' => 3000,
            'shipping_discount' => 2000,
        ]));

        // 할인이 배송비를 초과해도 음수가 되지 않는다.
        $this->assertSame(0, $method->invoke($service, [
            'total_shipping' => 3000,
            'shipping_discount' => 5000,
        ]));
    }

    #[Test]
    public function 미설정_정책값은_안분으로_폴백한다(): void
    {
        $this->assertSame(ShippingFeeTaxPolicy::PROPORTIONAL, ShippingFeeTaxPolicy::fromValueOrDefault(null));
        $this->assertSame(ShippingFeeTaxPolicy::PROPORTIONAL, ShippingFeeTaxPolicy::fromValueOrDefault(''));
        $this->assertSame(ShippingFeeTaxPolicy::PROPORTIONAL, ShippingFeeTaxPolicy::fromValueOrDefault('bogus'));
        $this->assertSame(ShippingFeeTaxPolicy::TAXABLE, ShippingFeeTaxPolicy::fromValueOrDefault('taxable'));
    }
}
