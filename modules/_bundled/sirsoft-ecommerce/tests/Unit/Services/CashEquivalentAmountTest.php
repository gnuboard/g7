<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * 현금성 금액 산정 테스트 (A-3)
 *
 * 현금성 금액 = 현금영수증 발급 대상이 되는 실제 현금 입금액.
 * 무통장만 실입금액(마일리지 차감 후) 전액이고 나머지는 0 이다.
 */
class CashEquivalentAmountTest extends ModuleTestCase
{
    /**
     * resolveCashEquivalentAmount 를 호출합니다.
     */
    private function resolve(?string $paymentMethod, int $finalAmount): int
    {
        $service = $this->getMockBuilder(OrderProcessingService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $method = new ReflectionMethod(OrderProcessingService::class, 'resolveCashEquivalentAmount');
        $method->setAccessible(true);

        return $method->invoke($service, $paymentMethod, $finalAmount);
    }

    #[Test]
    #[DataProvider('paymentMethodProvider')]
    public function 결제수단별_현금성_금액(?string $method, int $finalAmount, int $expected): void
    {
        $this->assertSame($expected, $this->resolve($method, $finalAmount));
    }

    /**
     * @return array<string, array{?string, int, int}>
     */
    public static function paymentMethodProvider(): array
    {
        return [
            '무통장 = 실입금액 전액' => [PaymentMethodEnum::DBANK->value, 11000, 11000],
            '가상계좌 = 0 (PG 자동발급)' => [PaymentMethodEnum::VBANK->value, 11000, 0],
            '카드 = 0' => [PaymentMethodEnum::CARD->value, 11000, 0],
            '계좌이체 = 0' => [PaymentMethodEnum::BANK->value, 11000, 0],
            '휴대폰 = 0' => [PaymentMethodEnum::PHONE->value, 11000, 0],
            '결제수단 미지정 = 0' => [null, 11000, 0],
        ];
    }

    #[Test]
    public function 무통장에서_마일리지를_사용하면_차감된_실입금액이_현금성이다(): void
    {
        // 주문 20000, 마일리지 8000 사용 → finalAmount 12000 이 실제 계좌 입금액
        $this->assertSame(12000, $this->resolve(PaymentMethodEnum::DBANK->value, 12000));
    }

    #[Test]
    public function 전액_마일리지_결제면_현금성이_0이다(): void
    {
        $this->assertSame(0, $this->resolve(PaymentMethodEnum::DBANK->value, 0));
    }

    #[Test]
    public function 음수_실입금액은_0으로_보정된다(): void
    {
        $this->assertSame(0, $this->resolve(PaymentMethodEnum::DBANK->value, -500));
    }

    #[Test]
    public function 옵션별_현금성_안분합이_주문_현금성과_일치한다(): void
    {
        // 옵션 finalAmount 합 = 주문 finalAmount 이므로 안분 잔차가 발생하지 않는다.
        $optionFinalAmounts = [3333, 3333, 3334];
        $orderFinalAmount = array_sum($optionFinalAmounts);

        $optionCashSum = 0;
        foreach ($optionFinalAmounts as $amount) {
            $optionCashSum += $this->resolve(PaymentMethodEnum::DBANK->value, $amount);
        }

        $this->assertSame(
            $this->resolve(PaymentMethodEnum::DBANK->value, $orderFinalAmount),
            $optionCashSum,
            '옵션별 현금성 안분 합 = 주문 현금성 금액',
        );
    }

    #[Test]
    public function 무통장이_아니면_옵션별_현금성도_모두_0이다(): void
    {
        foreach ([3333, 3333, 3334] as $amount) {
            $this->assertSame(0, $this->resolve(PaymentMethodEnum::VBANK->value, $amount));
            $this->assertSame(0, $this->resolve(PaymentMethodEnum::CARD->value, $amount));
        }
    }
}
