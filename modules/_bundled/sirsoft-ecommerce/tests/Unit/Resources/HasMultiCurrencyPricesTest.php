<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * HasMultiCurrencyPrices Trait 단위 테스트
 *
 * 부동소수점 정밀도 문제 검증:
 * - applyRounding 결과의 IEEE 754 오차가 decimal_places로 제거되는지 확인
 * - 예: 4505 * 0.01 = 45.050000000000004 → round(_, 2) → 45.05
 */
class HasMultiCurrencyPricesTest extends TestCase
{
    /**
     * Trait 메서드를 테스트할 수 있는 익명 클래스 인스턴스 생성
     *
     * @param  array  $currencies  통화 설정
     * @return object
     */
    protected function createTraitInstance(array $currencies = []): object
    {
        $defaultCurrencies = [
            [
                'code' => 'KRW',
                'name' => ['ko' => 'KRW (원)'],
                'exchange_rate' => null,
                'rounding_unit' => '1',
                'rounding_method' => 'floor',
                'decimal_places' => 0,
                'is_default' => true,
            ],
            [
                'code' => 'USD',
                'name' => ['ko' => 'USD (달러)'],
                'exchange_rate' => 0.85,
                'rounding_unit' => '0.01',
                'rounding_method' => 'round',
                'decimal_places' => 2,
                'is_default' => false,
            ],
            [
                'code' => 'JPY',
                'name' => ['ko' => 'JPY (엔)'],
                'exchange_rate' => 115,
                'rounding_unit' => '1',
                'rounding_method' => 'floor',
                'decimal_places' => 0,
                'is_default' => false,
            ],
            [
                'code' => 'CNY',
                'name' => ['ko' => 'CNY (위안)'],
                'exchange_rate' => 5.8,
                'rounding_unit' => '0.1',
                'rounding_method' => 'round',
                'decimal_places' => 2,
                'is_default' => false,
            ],
            [
                'code' => 'EUR',
                'name' => ['ko' => 'EUR (유로)'],
                'exchange_rate' => 0.78,
                'rounding_unit' => '0.01',
                'rounding_method' => 'ceil',
                'decimal_places' => 2,
                'is_default' => false,
            ],
        ];

        $currencySettings = ! empty($currencies) ? $currencies : $defaultCurrencies;

        return new class($currencySettings)
        {
            use \Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

            private array $testCurrencies;

            public function __construct(array $currencies)
            {
                $this->testCurrencies = $currencies;
            }

            /**
             * getCurrencySettings 오버라이드 (DB 접근 없이 테스트)
             */
            protected function getCurrencySettings(): array
            {
                return $this->testCurrencies;
            }

            /**
             * formatCurrencyPrice 오버라이드 (번역 함수 없이 테스트)
             */
            protected function formatCurrencyPrice(float|int $price, string $code): string
            {
                $decimalPlaces = $this->getDecimalPlacesForCurrency($code);

                return number_format($price, $decimalPlaces).' '.$code;
            }

            /**
             * 테스트용 공개 래퍼
             */
            public function testBuildMultiCurrencyPrices(float|int $basePrice): array
            {
                return $this->buildMultiCurrencyPrices($basePrice);
            }

            public function testApplyRounding(float $price, string $unit, string $method): float
            {
                return $this->applyRounding($price, $unit, $method);
            }
        };
    }

    /**
     * @test
     * 부동소수점 오차가 decimal_places round()로 제거되는지 검증
     */
    public function price_값에_부동소수점_오차가_없어야_한다(): void
    {
        $instance = $this->createTraitInstance();
        $result = $instance->testBuildMultiCurrencyPrices(53000);

        // USD: (53000/1000) * 0.85 = 45.05 (45.050000000000004가 아님)
        $this->assertSame(45.05, $result['USD']['price']);

        // JPY: (53000/1000) * 115 = 6095 (정수)
        $this->assertSame(6095.0, $result['JPY']['price']);

        // CNY: (53000/1000) * 5.8 = 307.4 (307.4000000000000x가 아님)
        $this->assertSame(307.4, $result['CNY']['price']);

        // EUR: (53000/1000) * 0.78 = 41.34
        $this->assertSame(41.34, $result['EUR']['price']);
    }

    /**
     * @test
     * KRW 기본통화는 원래 가격 그대로 반환
     */
    public function 기본통화는_원래_가격을_반환한다(): void
    {
        $instance = $this->createTraitInstance();
        $result = $instance->testBuildMultiCurrencyPrices(53000);

        $this->assertTrue($result['KRW']['is_default']);
        $this->assertSame(53000, $result['KRW']['price']);
    }

    /**
     * @test
     * decimal_places 0인 통화(JPY)는 정수값을 반환
     */
    public function decimal_places_0인_통화는_정수를_반환한다(): void
    {
        $instance = $this->createTraitInstance();
        $result = $instance->testBuildMultiCurrencyPrices(53500);

        // JPY: (53500/1000) * 115 = 6152.5 → floor → 6152
        $jpyPrice = $result['JPY']['price'];
        $this->assertEquals(6152, $jpyPrice);
        $this->assertTrue($jpyPrice == (int) $jpyPrice);
    }

    /**
     * @test
     * rounding_method ceil 적용 검증 (EUR)
     */
    public function ceil_절사_방법이_올바르게_적용된다(): void
    {
        $instance = $this->createTraitInstance();

        // 51500원 → EUR: (51500/1000) * 0.78 = 40.17
        $result = $instance->testBuildMultiCurrencyPrices(51500);
        $this->assertSame(40.17, $result['EUR']['price']);
    }

    /**
     * @test
     * rounding_unit 0.1 적용 검증 (CNY)
     */
    public function rounding_unit_0_1이_올바르게_적용된다(): void
    {
        $instance = $this->createTraitInstance();

        // 51000원 → CNY: (51000/1000) * 5.8 = 295.8
        $result = $instance->testBuildMultiCurrencyPrices(51000);
        $this->assertSame(295.8, $result['CNY']['price']);
    }

    /**
     * @test
     * 다양한 가격에서 부동소수점 오차가 발생하지 않는지 검증
     */
    public function 다양한_가격에서_부동소수점_오차가_없다(): void
    {
        $instance = $this->createTraitInstance();

        $testPrices = [10000, 25000, 53000, 57000, 99000, 150000];

        foreach ($testPrices as $price) {
            $result = $instance->testBuildMultiCurrencyPrices($price);

            foreach (['USD', 'JPY', 'CNY', 'EUR'] as $code) {
                $priceValue = $result[$code]['price'];
                $asString = (string) $priceValue;

                // 소수점 이하 자릿수가 해당 통화의 decimal_places를 초과하지 않아야 함
                if (str_contains($asString, '.')) {
                    $decimalPart = substr($asString, strpos($asString, '.') + 1);
                    $this->assertLessThanOrEqual(
                        2,
                        strlen($decimalPart),
                        "가격 {$price}원 → {$code}: {$priceValue}에 부동소수점 오차 발생"
                    );
                }
            }
        }
    }

    /**
     * @test
     * applyRounding 내부 함수의 부동소수점 문제 직접 검증
     */
    public function applyRounding_결과에_부동소수점_오차_확인(): void
    {
        $instance = $this->createTraitInstance();

        // 4505 * 0.01 → 이전에는 45.050000000000004 발생
        $rounded = $instance->testApplyRounding(45.05, '0.01', 'round');
        // applyRounding 자체는 여전히 부동소수점 오차가 있을 수 있지만
        // buildMultiCurrencyPrices에서 round()로 제거됨
        $this->assertEqualsWithDelta(45.05, $rounded, 0.0001);
    }
}
