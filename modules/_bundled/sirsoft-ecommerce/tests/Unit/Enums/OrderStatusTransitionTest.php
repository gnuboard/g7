<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 주문 상태 전이 규칙(OrderStatusEnum 전이 맵 SSoT) 테스트
 *
 * A30 — 역방향/비연속 역행 전이 차단. progressOrder/allowedTransitions/canTransitionTo
 * 게이트의 전수 매트릭스(10×10=100쌍) 단언.
 */
class OrderStatusTransitionTest extends ModuleTestCase
{
    /**
     * 진행 순서 SSoT 가 취소 제외 9단계로 구성되는지 검증합니다.
     */
    public function test_progress_order_is_nine_steps_without_cancel(): void
    {
        $order = OrderStatusEnum::progressOrder();

        $this->assertCount(9, $order);
        $this->assertSame([
            OrderStatusEnum::PENDING_ORDER,
            OrderStatusEnum::PENDING_PAYMENT,
            OrderStatusEnum::PAYMENT_COMPLETE,
            OrderStatusEnum::SHIPPING_HOLD,
            OrderStatusEnum::PREPARING,
            OrderStatusEnum::SHIPPING_READY,
            OrderStatusEnum::SHIPPING,
            OrderStatusEnum::DELIVERED,
            OrderStatusEnum::CONFIRMED,
        ], $order);
        $this->assertNotContains(OrderStatusEnum::CANCELLED, $order, '취소는 진행 순서에서 제외');
    }

    /**
     * progressOrderValues 가 progressOrder 의 value 미러인지 검증합니다.
     */
    public function test_progress_order_values_mirror_enum_order(): void
    {
        $this->assertSame(
            array_map(fn ($c) => $c->value, OrderStatusEnum::progressOrder()),
            OrderStatusEnum::progressOrderValues()
        );
    }

    /**
     * 상태별 allowedTransitions 집합을 단언합니다.
     * (forward 점프 + 역방향 화이트리스트의 합집합)
     */
    public function test_allowed_transitions_per_status(): void
    {
        $cases = [
            // PENDING_ORDER → 그 이후 진행 상태 전부 (forward 점프)
            'PENDING_ORDER' => [
                OrderStatusEnum::PENDING_PAYMENT,
                OrderStatusEnum::PAYMENT_COMPLETE,
                OrderStatusEnum::SHIPPING_HOLD,
                OrderStatusEnum::PREPARING,
                OrderStatusEnum::SHIPPING_READY,
                OrderStatusEnum::SHIPPING,
                OrderStatusEnum::DELIVERED,
                OrderStatusEnum::CONFIRMED,
            ],
            'PAYMENT_COMPLETE' => [
                OrderStatusEnum::SHIPPING_HOLD,
                OrderStatusEnum::PREPARING,
                OrderStatusEnum::SHIPPING_READY,
                OrderStatusEnum::SHIPPING,
                OrderStatusEnum::DELIVERED,
                OrderStatusEnum::CONFIRMED,
            ],
            // PREPARING → forward + 역방향(SHIPPING_HOLD)
            'PREPARING' => [
                OrderStatusEnum::SHIPPING_READY,
                OrderStatusEnum::SHIPPING,
                OrderStatusEnum::DELIVERED,
                OrderStatusEnum::CONFIRMED,
                OrderStatusEnum::SHIPPING_HOLD,
            ],
            // SHIPPING → forward + 역방향(SHIPPING_READY, PREPARING)
            'SHIPPING' => [
                OrderStatusEnum::DELIVERED,
                OrderStatusEnum::CONFIRMED,
                OrderStatusEnum::SHIPPING_READY,
                OrderStatusEnum::PREPARING,
            ],
            // DELIVERED → forward(CONFIRMED) + 역방향(SHIPPING)
            'DELIVERED' => [
                OrderStatusEnum::CONFIRMED,
                OrderStatusEnum::SHIPPING,
            ],
            // CONFIRMED → 빈 배열(되돌림 전부 금지)
            'CONFIRMED' => [],
            // CANCELLED → 판매 반영 상태로만 복원 허용(결제 전 상태 제외)
            'CANCELLED' => [
                OrderStatusEnum::PAYMENT_COMPLETE,
                OrderStatusEnum::SHIPPING_HOLD,
                OrderStatusEnum::PREPARING,
                OrderStatusEnum::SHIPPING_READY,
                OrderStatusEnum::SHIPPING,
                OrderStatusEnum::DELIVERED,
                OrderStatusEnum::CONFIRMED,
            ],
        ];

        foreach ($cases as $name => $expected) {
            $from = constant(OrderStatusEnum::class.'::'.$name);
            $this->assertEqualsCanonicalizing(
                $expected,
                $from->allowedTransitions(),
                "{$name} 의 allowedTransitions 집합 불일치"
            );
        }
    }

    /**
     * SHIPPING_HOLD ↔ PREPARING 양방향이 모두 허용되는지 명시 단언합니다.
     */
    public function test_shipping_hold_and_preparing_are_bidirectional(): void
    {
        $this->assertTrue(
            OrderStatusEnum::SHIPPING_HOLD->canTransitionTo(OrderStatusEnum::PREPARING),
            'SHIPPING_HOLD → PREPARING (forward) 허용'
        );
        $this->assertTrue(
            OrderStatusEnum::PREPARING->canTransitionTo(OrderStatusEnum::SHIPPING_HOLD),
            'PREPARING → SHIPPING_HOLD (역방향 화이트리스트) 허용'
        );
    }

    /**
     * 취소 → 판매 반영 상태 복원(reactivation)은 허용, 결제 전 상태 복귀는 차단됨을 명시 단언합니다.
     * (취소 주문/옵션을 다시 판매 진행 상태로 되살리는 운영 기능 — 재고 재차감 동반. 2026-06-23)
     */
    public function test_cancelled_reactivation_to_sales_status_allowed_but_pre_payment_blocked(): void
    {
        // 판매 반영 상태로의 복원 → 허용
        foreach (OrderStatusEnum::salesEligibleStatuses() as $target) {
            $this->assertTrue(
                OrderStatusEnum::CANCELLED->canTransitionTo($target),
                "CANCELLED → {$target->value} (판매 복원) 허용이어야 함"
            );
        }

        // 결제 전 상태로의 복귀 → 차단
        $this->assertFalse(
            OrderStatusEnum::CANCELLED->canTransitionTo(OrderStatusEnum::PENDING_ORDER),
            'CANCELLED → pending_order (결제 전 복귀) 차단이어야 함'
        );
        $this->assertFalse(
            OrderStatusEnum::CANCELLED->canTransitionTo(OrderStatusEnum::PENDING_PAYMENT),
            'CANCELLED → pending_payment (결제 전 복귀) 차단이어야 함'
        );

        // 목표=취소(자기 자신)는 self-transition 으로 통과
        $this->assertTrue(OrderStatusEnum::CANCELLED->canTransitionTo(OrderStatusEnum::CANCELLED));
    }

    /**
     * canTransitionTo 10×10 매트릭스 전수 단언.
     */
    #[DataProvider('transitionMatrixProvider')]
    public function test_can_transition_matrix(string $fromName, string $toName, bool $expected): void
    {
        $from = constant(OrderStatusEnum::class.'::'.$fromName);
        $to = constant(OrderStatusEnum::class.'::'.$toName);

        $this->assertSame(
            $expected,
            $from->canTransitionTo($to),
            sprintf('전이 %s → %s 기대=%s', $fromName, $toName, $expected ? '허용' : '차단')
        );
    }

    /**
     * 10×10 = 100쌍 전이 매트릭스 데이터.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function transitionMatrixProvider(): array
    {
        // 진행 순서 인덱스 (CANCELLED 제외)
        $progress = [
            'PENDING_ORDER' => 0,
            'PENDING_PAYMENT' => 1,
            'PAYMENT_COMPLETE' => 2,
            'SHIPPING_HOLD' => 3,
            'PREPARING' => 4,
            'SHIPPING_READY' => 5,
            'SHIPPING' => 6,
            'DELIVERED' => 7,
            'CONFIRMED' => 8,
        ];

        // 역방향 화이트리스트 (from => [to, ...])
        $reverseWhitelist = [
            'PREPARING' => ['SHIPPING_HOLD'],
            'SHIPPING' => ['SHIPPING_READY', 'PREPARING'],
            'DELIVERED' => ['SHIPPING'],
        ];

        // 취소 → 판매 반영 상태 복원(reactivation) 허용 집합 (결제 전 상태 제외)
        $salesEligible = [
            'PAYMENT_COMPLETE', 'SHIPPING_HOLD', 'PREPARING',
            'SHIPPING_READY', 'SHIPPING', 'DELIVERED', 'CONFIRMED',
        ];

        $all = array_keys($progress);
        $all[] = 'CANCELLED';

        $data = [];
        foreach ($all as $from) {
            foreach ($all as $to) {
                $expected = self::computeExpected($from, $to, $progress, $reverseWhitelist, $salesEligible);
                $key = "{$from} -> {$to}";
                $data[$key] = [$from, $to, $expected];
            }
        }

        return $data;
    }

    /**
     * 기대 전이 허용 여부를 계산합니다 (Enum 구현과 독립적인 reference 모델).
     *
     * @param  array<string, int>  $progress
     * @param  array<string, array<string>>  $reverseWhitelist
     * @param  array<string>  $salesEligible  취소 → 복원 허용 대상 판매 반영 상태
     */
    private static function computeExpected(
        string $from,
        string $to,
        array $progress,
        array $reverseWhitelist,
        array $salesEligible
    ): bool {
        // 1) self-transition → true
        if ($from === $to) {
            return true;
        }

        // 2) 목표가 취소(CANCELLED) → true (게이트 책임 밖)
        if ($to === 'CANCELLED') {
            return true;
        }

        // 3) 현재가 CANCELLED → 판매 반영 상태로의 복원(reactivation)만 허용, 결제 전 상태는 차단
        if ($from === 'CANCELLED') {
            return in_array($to, $salesEligible, true);
        }

        // forward 점프: from 인덱스 < to 인덱스
        if ($progress[$to] > $progress[$from]) {
            return true;
        }

        // 역방향 화이트리스트
        if (in_array($to, $reverseWhitelist[$from] ?? [], true)) {
            return true;
        }

        return false;
    }
}
