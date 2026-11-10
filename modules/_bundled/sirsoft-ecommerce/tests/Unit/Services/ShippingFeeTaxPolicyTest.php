<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\ShippingFeeTaxPolicy;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\PureTestCase;

/**
 * 배송비 과세 정책 테스트 (A-4)
 *
 * 배송비 과세 분류는 상품 분류(단계 2-b)와 달리 배송비 할인이 확정된 뒤에 계산된다.
 * 정책 3종 × 과세/면세/복합 = 9케이스 + 안분 반올림 잔차의 합계 보존을 검증한다.
 * #612 — 영세 갈래가 더해진 세 갈래 분류(정책 3종 × 영세 조합)도 함께 검증한다.
 */
class ShippingFeeTaxPolicyTest extends PureTestCase
{
    /**
     * classifyShippingFeeTaxStatus 를 지정 정책으로 호출합니다.
     *
     * @param  ShippingFeeTaxPolicy  $policy  배송비 과세 정책
     * @param  int  $netShippingFee  할인 후 배송비
     * @param  int  $productTaxable  상품 과세금액 합계 (영세 제외)
     * @param  int  $productTaxFree  상품 면세금액 합계
     * @param  int  $productZeroRated  상품 영세금액 합계
     * @return array{taxable_amount: int, zero_rated_amount: int, tax_free_amount: int} 배송비 분류
     */
    private function classify(
        ShippingFeeTaxPolicy $policy,
        int $netShippingFee,
        int $productTaxable,
        int $productTaxFree,
        int $productZeroRated = 0,
    ): array {
        $service = $this->getMockBuilder(OrderCalculationService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveShippingFeeTaxPolicy'])
            ->getMock();

        $service->method('resolveShippingFeeTaxPolicy')->willReturn($policy);

        $method = new ReflectionMethod(OrderCalculationService::class, 'classifyShippingFeeTaxStatus');
        $method->setAccessible(true);

        return $method->invoke($service, $netShippingFee, $productTaxable, $productTaxFree, $productZeroRated);
    }

    /**
     * 정책 3종 × 과세/면세/복합 9케이스의 배송비 분류와 합계 보존을 확인합니다.
     *
     * @effects shipping_apportioned_by_taxable_ratio
     * @effects shipping_fully_taxable_under_taxable
     * @effects shipping_follows_dominant_item
     *
     * @param  ShippingFeeTaxPolicy  $policy  배송비 과세 정책
     * @param  int  $shipping  배송비
     * @param  int  $taxable  상품 과세금액
     * @param  int  $taxFree  상품 면세금액
     * @param  int  $expectedTaxable  기대 배송비 과세분
     * @param  int  $expectedTaxFree  기대 배송비 면세분
     * @return void 반환값 없음
     */
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
     * 정책 3종 × 과세/면세/복합 케이스를 제공합니다.
     *
     * @return array<string, array{ShippingFeeTaxPolicy, int, int, int, int, int}> 케이스 (정책·배송비·과세·면세·기대 과세·기대 면세)
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

    /**
     * 주된재화 정책에서 면세가 우세하면 배송비를 면세로 분류하는지 확인합니다.
     *
     * @effects shipping_follows_dominant_item
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 주된재화_정책은_면세가_우세하면_배송비를_면세로_분류한다(): void
    {
        $result = $this->classify(ShippingFeeTaxPolicy::FOLLOW_MAIN_ITEM, 3000, 4000, 6000);

        $this->assertSame(0, $result['taxable_amount']);
        $this->assertSame(3000, $result['tax_free_amount']);
    }

    /**
     * 주된재화 정책에서 과세·면세가 동률이면 과세로 분류하는지 확인합니다.
     *
     * @effects shipping_follows_dominant_item
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 주된재화_정책은_동률이면_과세로_분류한다(): void
    {
        $result = $this->classify(ShippingFeeTaxPolicy::FOLLOW_MAIN_ITEM, 3000, 5000, 5000);

        $this->assertSame(3000, $result['taxable_amount']);
        $this->assertSame(0, $result['tax_free_amount']);
    }

    /**
     * 안분 반올림 잔차가 배송비 합계를 보존하는지 확인합니다.
     *
     * @effects shipping_rounding_remainder_preserves_sum
     *
     * @param  int  $shipping  배송비
     * @param  int  $taxable  상품 과세금액
     * @param  int  $taxFree  상품 면세금액
     * @return void 반환값 없음
     */
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
     * @return array<string, array{int, int, int}> 케이스 (배송비·과세·면세)
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

    /**
     * 배송비가 0원이면 정책과 무관하게 모든 갈래가 0 인지 확인합니다.
     *
     * @effects shipping_fee_included_in_tax_totals
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 배송비가_0원이면_과세_면세_모두_0이다(): void
    {
        foreach (ShippingFeeTaxPolicy::cases() as $policy) {
            $result = $this->classify($policy, 0, 6000, 4000);

            $this->assertSame(0, $result['taxable_amount'], $policy->value);
            $this->assertSame(0, $result['tax_free_amount'], $policy->value);
        }
    }

    /**
     * 상품 금액이 0 이면 안분 기준이 없어 배송비 전액을 과세로 보는지 확인합니다.
     *
     * @effects shipping_fully_taxable_under_taxable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 상품금액이_0이면_안분_기준이_없어_전액_과세한다(): void
    {
        foreach (ShippingFeeTaxPolicy::cases() as $policy) {
            $result = $this->classify($policy, 3000, 0, 0);

            $this->assertSame(3000, $result['taxable_amount'], $policy->value);
            $this->assertSame(0, $result['tax_free_amount'], $policy->value);
        }
    }

    /**
     * 배송비 할인을 뺀 실질 배송비(음수 없음)를 분류 기준으로 쓰는지 확인합니다.
     *
     * @effects shipping_fee_included_in_tax_totals
     *
     * @return void 반환값 없음
     */
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

    /**
     * 정책 설정값이 없거나 잘못되면 안분 정책으로 떨어지는지 확인합니다.
     *
     * @effects shipping_apportioned_by_taxable_ratio
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 미설정_정책값은_안분으로_폴백한다(): void
    {
        $this->assertSame(ShippingFeeTaxPolicy::PROPORTIONAL, ShippingFeeTaxPolicy::fromValueOrDefault(null));
        $this->assertSame(ShippingFeeTaxPolicy::PROPORTIONAL, ShippingFeeTaxPolicy::fromValueOrDefault(''));
        $this->assertSame(ShippingFeeTaxPolicy::PROPORTIONAL, ShippingFeeTaxPolicy::fromValueOrDefault('bogus'));
        $this->assertSame(ShippingFeeTaxPolicy::TAXABLE, ShippingFeeTaxPolicy::fromValueOrDefault('taxable'));
    }

    /**
     * 영세 갈래가 섞인 주문의 배송비 분류 — 정책 3종 × 영세 조합 (#612).
     *
     * @effects shipping_split_three_ways_with_zero_rated
     *
     * @param  ShippingFeeTaxPolicy  $policy  배송비 과세 정책
     * @param  int  $shipping  배송비
     * @param  int  $taxable  상품 과세금액 (영세 제외)
     * @param  int  $zeroRated  상품 영세금액
     * @param  int  $taxFree  상품 면세금액
     * @param  array{int, int, int}  $expected  기대 [과세, 영세, 면세]
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('zeroRatedMatrixProvider')]
    public function 영세_갈래가_섞인_배송비_분류(
        ShippingFeeTaxPolicy $policy,
        int $shipping,
        int $taxable,
        int $zeroRated,
        int $taxFree,
        array $expected,
    ): void {
        $result = $this->classify($policy, $shipping, $taxable, $taxFree, $zeroRated);

        $this->assertSame(
            $expected,
            [$result['taxable_amount'], $result['zero_rated_amount'], $result['tax_free_amount']],
            '[과세, 영세, 면세]',
        );
        $this->assertSame($shipping, array_sum($expected), '배송비 합계가 보존되어야 한다');
    }

    /**
     * 정책 × 영세 조합 케이스.
     *
     * @return array<string, array{ShippingFeeTaxPolicy, int, int, int, int, array{int, int, int}}> 케이스
     */
    public static function zeroRatedMatrixProvider(): array
    {
        return [
            '안분 × 영세만' => [ShippingFeeTaxPolicy::PROPORTIONAL, 3000, 0, 10000, 0, [0, 3000, 0]],
            '안분 × 영세+과세 1:1' => [ShippingFeeTaxPolicy::PROPORTIONAL, 3000, 10000, 10000, 0, [1500, 1500, 0]],
            '안분 × 영세+면세 6:4' => [ShippingFeeTaxPolicy::PROPORTIONAL, 3000, 0, 6000, 4000, [0, 1800, 1200]],
            // 과세 먼저 반올림(1064.5→1065), 영세 반올림(967.7→968), 잔차 면세 967
            '안분 × 3갈래 11:10:10' => [ShippingFeeTaxPolicy::PROPORTIONAL, 3000, 11000, 10000, 10000, [1065, 968, 967]],
            '전액과세 × 영세만' => [ShippingFeeTaxPolicy::TAXABLE, 3000, 0, 10000, 0, [3000, 0, 0]],
            '전액과세 × 3갈래' => [ShippingFeeTaxPolicy::TAXABLE, 3000, 11000, 10000, 10000, [3000, 0, 0]],
            // 전액과세 정책은 영세가 섞여도 안분하지 않는다 — 영세 몫이 생기면 정책이 안분으로 새는 것
            '전액과세 × 영세+과세 1:1' => [ShippingFeeTaxPolicy::TAXABLE, 3000, 10000, 10000, 0, [3000, 0, 0]],
            '전액과세 × 영세+면세 6:4' => [ShippingFeeTaxPolicy::TAXABLE, 3000, 0, 6000, 4000, [3000, 0, 0]],
            // 과세 0 = 면세 0 동률을 과세로 읽으면(두 갈래 비교) 영세만 주문의 배송비가 과세로 떨어진다
            '주된재화 × 영세만' => [ShippingFeeTaxPolicy::FOLLOW_MAIN_ITEM, 3000, 0, 10000, 0, [0, 3000, 0]],
            '주된재화 × 영세 우세' => [ShippingFeeTaxPolicy::FOLLOW_MAIN_ITEM, 3000, 4000, 6000, 0, [0, 3000, 0]],
            '주된재화 × 과세=영세 동률' => [ShippingFeeTaxPolicy::FOLLOW_MAIN_ITEM, 3000, 5000, 5000, 0, [3000, 0, 0]],
            '주된재화 × 영세=면세 동률' => [ShippingFeeTaxPolicy::FOLLOW_MAIN_ITEM, 3000, 0, 5000, 5000, [0, 3000, 0]],
            '주된재화 × 면세 우세' => [ShippingFeeTaxPolicy::FOLLOW_MAIN_ITEM, 3000, 1000, 2000, 7000, [0, 0, 3000]],
        ];
    }

    /**
     * 세 갈래 안분도 반올림 잔차가 합계를 보존하는지 확인합니다.
     *
     * @effects shipping_rounding_remainder_preserves_sum
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 세_갈래_안분_반올림_잔차가_합계를_보존한다(): void
    {
        foreach ([[3333, 1000, 1000, 1000], [1, 1, 1, 1], [999, 7000, 3000, 1], [2, 1, 2, 3]] as [$fee, $t, $z, $f]) {
            $result = $this->classify(ShippingFeeTaxPolicy::PROPORTIONAL, $fee, $t, $f, $z);

            $this->assertSame($fee, $result['taxable_amount'] + $result['zero_rated_amount'] + $result['tax_free_amount']);
            $this->assertGreaterThanOrEqual(0, min($result));
        }
    }
}
