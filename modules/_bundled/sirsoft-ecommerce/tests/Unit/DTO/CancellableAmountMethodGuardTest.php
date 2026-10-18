<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\DTO;

use Modules\Sirsoft\Ecommerce\DTO\CancellableAmount;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 취소 가능 금액 — **수단별** 잔여 가드 (S3-D21)
 *
 * `allows()` 는 현금 잔여와 마일리지 잔여를 **더한 하나**와 비교한다. 그 합계는 "이 주문에서
 * 통틀어 얼마까지 되돌릴 수 있는가" 로는 맞지만 **어느 수단으로 나가는지는 말해 주지 않는다.**
 * 실제 지급은 수단별로 일어나므로, 합계만 보면 한쪽이 마른 채 다른 쪽 여유로 통과한다.
 *
 * 실측(주문 753 · 무통장 345,000 + 마일리지 20,000):
 * 부분환불을 여섯 번 태운 뒤 **현금 잔여 9,000 · 마일리지 잔여 20,000** 인 시점에 현금
 * 14,000 요청이 `14,000 ≤ 29,000` 으로 통과했다. 결과는 결제행 취소 누적이 결제액을 초과
 * (`cancelled_amount 350,000 > paid_amount_base 345,000` → 취소가능액 **-5,000**)하고,
 * 마일리지는 20,000 중 10,000 이 끝내 환급되지 못한 채 종료된 것이다.
 */
class CancellableAmountMethodGuardTest extends ModuleTestCase
{
    /**
     * 주문 753 의 RF37 시점을 그대로 세운 산정 결과를 만듭니다.
     *
     * @return CancellableAmount 현금 9,000 · 마일리지 20,000 이 남은 상태
     */
    private function order753AtRf37(): CancellableAmount
    {
        return new CancellableAmount(
            amount: 29000,
            domainRefundable: 29000,
            paymentCapacity: 29000,
            methods: [
                ['type' => CancellableAmount::METHOD_PAYMENT, 'method' => 'dbank', 'remaining_base' => 9000],
                ['type' => CancellableAmount::METHOD_MILEAGE, 'method' => 'mileage', 'remaining_base' => 20000],
            ],
            currency: 'KRW',
        );
    }

    /**
     * 수단별 잔여를 현금·마일리지로 나눠 낸다.
     *
     * @effects cancellable_amount_splits_remaining_by_method
     */
    public function test_remaining_is_split_into_cash_and_mileage(): void
    {
        $remaining = $this->order753AtRf37()->remainingByMethod();

        $this->assertSame(9000.0, $remaining['cash']);
        $this->assertSame(20000.0, $remaining['mileage']);
    }

    /**
     * **현금 14,000 은 거절된다** — 합계로는 통과하던 그 요청이다.
     *
     * 되돌림 축: `allowsByMethod()` 를 `allows()` 로 되돌리면 `14,000 ≤ 29,000` 이라 통과해
     * red. 그 통과가 주문 753 의 5,000 초과 환불을 만들었다.
     *
     * @effects method_guard_rejects_cash_beyond_cash_remaining
     */
    public function test_cash_request_beyond_cash_remaining_is_rejected(): void
    {
        $cancellable = $this->order753AtRf37();

        $this->assertTrue($cancellable->allows(14000), '합계 한도로는 통과한다 — 이것이 결함의 입구였다');
        $this->assertFalse($cancellable->allowsByMethod(14000, 0), '현금 잔여 9,000 을 넘으므로 수단별로는 막혀야 한다');
    }

    /**
     * 같은 14,000 이라도 **수단을 나누면** 통과한다 — 고객은 전액을 받는다.
     *
     * 가드가 "금액이 과하다" 가 아니라 "그 수단으로는 못 나간다" 를 말한다는 뜻이다.
     *
     * @effects method_guard_allows_split_across_methods
     */
    public function test_same_total_split_across_methods_passes(): void
    {
        $this->assertTrue($this->order753AtRf37()->allowsByMethod(9000, 5000));
    }

    /**
     * 마일리지도 자기 잔여를 넘으면 거절된다 — 대칭이다.
     *
     * @effects method_guard_rejects_mileage_beyond_mileage_remaining
     */
    public function test_mileage_request_beyond_mileage_remaining_is_rejected(): void
    {
        $this->assertFalse($this->order753AtRf37()->allowsByMethod(0, 20001));
    }
}
