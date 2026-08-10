<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * 결제수단별 현금성 금액 산정 규칙 테스트 (A-3)
 *
 * 이 규칙은 주문 생성(OrderProcessingService)과 취소 재계산(OrderAdjustmentService)이
 * 공유하는 SSoT 다. 한쪽만 바뀌면 부분환불 후 현금영수증 재발급액이 틀어진다.
 */
class PaymentMethodCashEquivalentTest extends ModuleTestCase
{
    /**
     * @return array<string, array{PaymentMethodEnum, int, int}>
     */
    public static function cashEquivalentCases(): array
    {
        return [
            // 무통장만 구매자가 직접 현금을 입금한다
            '무통장 = 실결제액 전액' => [PaymentMethodEnum::DBANK, 11000, 11000],
            // 가상계좌는 PG 가 현금영수증을 자동 발급한다
            '가상계좌 = 0' => [PaymentMethodEnum::VBANK, 11000, 0],
            '카드 = 0' => [PaymentMethodEnum::CARD, 11000, 0],
            '마일리지 = 0' => [PaymentMethodEnum::POINT, 11000, 0],
            '무료 = 0' => [PaymentMethodEnum::FREE, 0, 0],
        ];
    }

    #[Test]
    #[DataProvider('cashEquivalentCases')]
    public function 결제수단별_현금성_금액을_산정한다(
        PaymentMethodEnum $method,
        int $finalAmount,
        int $expected,
    ): void {
        $this->assertSame($expected, $method->resolveCashEquivalentAmount($finalAmount));
    }

    #[Test]
    public function 음수_실결제액은_0_으로_보정된다(): void
    {
        $this->assertSame(0, PaymentMethodEnum::DBANK->resolveCashEquivalentAmount(-1));
    }

    #[Test]
    public function 전액_마일리지_무통장주문의_현금성_금액은_0_이다(): void
    {
        // $isZeroPayable 판정은 결제수단과 무관하므로 "무통장 + 전액 마일리지" 0원 주문이 성립한다.
        // 그 주문에 0원 현금영수증을 발급하지 않으려면 여기서 0이 나와야 한다.
        $this->assertSame(0, PaymentMethodEnum::DBANK->resolveCashEquivalentAmount(0));
    }
}
