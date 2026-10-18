<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\DTO;

use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * AdjustmentResult DTO 취소 차단 판정 테스트
 *
 * isCancelBlocked()/hasActualPayment() 의 실결제 게이트 + 추가결제 판정을 검증합니다.
 */
class AdjustmentResultTest extends ModuleTestCase
{
    /**
     * 스냅샷을 받아 AdjustmentResult 를 구성합니다.
     *
     * @param  bool  $hasActualPayment  실결제 발생 신호
     * @param  float  $originalPaid  원 결제금액
     * @param  float  $originalPoints  원 포인트 사용액
     * @param  float  $recalcPaid  재계산 결제금액
     * @param  float  $recalcPoints  재계산 포인트 사용액
     * @return AdjustmentResult 구성된 DTO
     */
    private function makeResult(
        bool $hasActualPayment,
        float $originalPaid,
        float $originalPoints,
        float $recalcPaid,
        float $recalcPoints,
    ): AdjustmentResult {
        return new AdjustmentResult(
            originalSnapshot: [
                'total_paid_amount' => $originalPaid,
                'total_points_used_amount' => $originalPoints,
                'has_actual_payment' => $hasActualPayment,
            ],
            recalculatedSnapshot: [
                'total_paid_amount' => $recalcPaid,
                'total_points_used_amount' => $recalcPoints,
            ],
        );
    }

    /**
     * 실결제 0원(미입금·운영자 0원 결제완료)이면 재계산금액이 커져도 차단하지 않습니다.
     */
    public function test_not_blocked_when_no_actual_payment_even_if_recalc_exceeds(): void
    {
        // 원결제 0 → 재계산 13000(쿠폰 조건 깨져 잔여금액 증가) 이지만 실결제 0원이므로 허용
        $result = $this->makeResult(
            hasActualPayment: false,
            originalPaid: 0,
            originalPoints: 0,
            recalcPaid: 13000,
            recalcPoints: 0,
        );

        $this->assertFalse($result->hasActualPayment());
        $this->assertFalse($result->isCancelBlocked(), '실결제 0원 주문은 무조건 허용되어야 합니다');
    }

    /**
     * 실결제 주문에서 재계산금액이 원 결제금액을 초과하면 차단합니다.
     */
    public function test_blocked_when_actual_payment_and_recalc_exceeds(): void
    {
        $result = $this->makeResult(
            hasActualPayment: true,
            originalPaid: 20000,
            originalPoints: 0,
            recalcPaid: 25000,
            recalcPoints: 0,
        );

        $this->assertTrue($result->hasActualPayment());
        $this->assertTrue($result->isCancelBlocked(), '실결제 주문에서 추가결제가 필요하면 차단되어야 합니다');
    }

    /**
     * 실결제 주문에서 재계산금액이 원 결제금액 이하이면 차단하지 않습니다.
     */
    public function test_not_blocked_when_actual_payment_and_recalc_within(): void
    {
        $result = $this->makeResult(
            hasActualPayment: true,
            originalPaid: 20000,
            originalPoints: 0,
            recalcPaid: 12000,
            recalcPoints: 0,
        );

        $this->assertFalse($result->isCancelBlocked(), '환불 발생(정상 부분취소)은 차단되면 안 됩니다');
    }

    /**
     * 재계산금액이 원 결제금액과 같으면(증감 없음) 차단하지 않습니다.
     */
    public function test_not_blocked_when_equal(): void
    {
        $result = $this->makeResult(
            hasActualPayment: true,
            originalPaid: 30000,
            originalPoints: 0,
            recalcPaid: 30000,
            recalcPoints: 0,
        );

        $this->assertFalse($result->isCancelBlocked());
    }

    /**
     * 포인트가 이중 계산되지 않습니다(결제+포인트 합산 일관성).
     */
    public function test_points_are_not_double_counted(): void
    {
        // 원: 결제10000 + 포인트5000 = 15000, 재계산: 결제12000 + 포인트5000 = 17000 → 초과 → 차단
        $blocked = $this->makeResult(
            hasActualPayment: true,
            originalPaid: 10000,
            originalPoints: 5000,
            recalcPaid: 12000,
            recalcPoints: 5000,
        );
        $this->assertTrue($blocked->isCancelBlocked());

        // 원: 결제10000 + 포인트5000 = 15000, 재계산: 결제8000 + 포인트5000 = 13000 → 환불 → 허용
        $allowed = $this->makeResult(
            hasActualPayment: true,
            originalPaid: 10000,
            originalPoints: 5000,
            recalcPaid: 8000,
            recalcPoints: 5000,
        );
        $this->assertFalse($allowed->isCancelBlocked());
    }

    /**
     * 반올림 오차(epsilon 0.01) 이내 차이는 차단하지 않습니다.
     */
    public function test_epsilon_rounding_difference_not_blocked(): void
    {
        $result = $this->makeResult(
            hasActualPayment: true,
            originalPaid: 30000,
            originalPoints: 0,
            recalcPaid: 30000.005,
            recalcPoints: 0,
        );

        $this->assertFalse($result->isCancelBlocked(), '0.01 이내 반올림 오차는 차단되면 안 됩니다');
    }

    /**
     * has_actual_payment 신호가 없으면(스냅샷 미설정) 기본 false 로 허용 측에 둡니다.
     */
    public function test_missing_signal_defaults_to_no_actual_payment(): void
    {
        $result = new AdjustmentResult(
            originalSnapshot: ['total_paid_amount' => 0, 'total_points_used_amount' => 0],
            recalculatedSnapshot: ['total_paid_amount' => 5000, 'total_points_used_amount' => 0],
        );

        $this->assertFalse($result->hasActualPayment());
        $this->assertFalse($result->isCancelBlocked());
    }
}
