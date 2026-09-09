<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Support;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 주문 진행단계 배열의 단일 출처 (관리자 상세 · 회원 상세 · 비회원 상세 공용)
 *
 * 순서는 `OrderStatusEnum::progressSequence()`(7단계)가 소유하고, 여기서는 이 주문이 어디까지
 * 갔는지를 정한다. 클레임의 `ClaimProgressSteps` 와 같은 형태(`value/label/state/icon/variant`)를
 * 내보내 세 화면이 같은 스텝퍼 partial 로 그린다.
 *
 * - 배송보류·주문취소·교환/반품 진행·교환/반품 완료는 순서에 없다. 흐름을 따라가는 단계가
 *   아니라 멈추는 사건이라 마지막에 이탈 칸 하나로 따로 붙는다.
 * - 주문대기(결제창 진입 전)는 첫 칸 앞이다 — 첫 칸(결제대기)을 현재로 그린다.
 *
 * 도달 지점은 현재 상태의 위치와 주문이 박아 둔 시각(결제 `paid_at` · 상품 배송완료
 * `delivered_at` · 구매확정 `confirmed_at`) 중 더 나아간 쪽이다 — 이탈 상태에서는 현재 상태만으로
 * 어디까지 갔는지 알 수 없다(취소가 배송 뒤에 일어났는지, 결제 직후였는지).
 *
 * 한 주문 안에서 상품마다 상태가 갈릴 수 있지만 이 배열은 **주문 대표 상태** 한 줄이다.
 * 상품별 상태는 상품 표의 뱃지가 아이콘과 함께 보여 준다.
 */
final class OrderProgressSteps
{
    /**
     * 주문의 진행단계 배열을 만듭니다.
     *
     * @param  Order  $order  주문
     * @return array<int, array{value: string, label: string, state: string, icon: string, variant: string}> 진행 단계
     */
    public static function for(Order $order): array
    {
        $sequence = OrderStatusEnum::progressSequence();
        $current = self::statusOf($order);
        $reached = self::reachedIndex($order, $sequence, $current);
        $offPath = $current !== null && $current->isOffProgressPath();

        $steps = [];

        foreach ($sequence as $index => $case) {
            $steps[] = self::step($case, match (true) {
                // 이탈 상태에서는 순서 안에 '현재' 칸이 없다 — 멈춘 지점까지는 지나간 것이다.
                $offPath => $index <= $reached ? 'done' : 'upcoming',
                $index < $reached => 'done',
                $index === $reached => 'current',
                default => 'upcoming',
            });
        }

        // 이탈 상태는 순서 밖이라 마지막 칸으로 붙인다 — "어디서 멈췄는가" 와 "왜 멈췄는가" 가
        // 한 줄에서 함께 읽힌다.
        if ($offPath) {
            $steps[] = self::step($current, 'off_path');
        }

        return $steps;
    }

    /**
     * 단계 한 칸을 만듭니다.
     *
     * @param  OrderStatusEnum  $case  상태
     * @param  string  $state  칸 상태 (done / current / upcoming / off_path)
     * @return array{value: string, label: string, state: string, icon: string, variant: string} 단계
     */
    private static function step(OrderStatusEnum $case, string $state): array
    {
        return [
            'value' => $case->value,
            'label' => $case->label(),
            'state' => $state,
            'icon' => $case->icon(),
            'variant' => $case->variant(),
        ];
    }

    /**
     * 주문의 현재 상태 Enum 을 반환합니다.
     *
     * @param  Order  $order  주문
     * @return OrderStatusEnum|null 현재 상태
     */
    private static function statusOf(Order $order): ?OrderStatusEnum
    {
        $status = $order->order_status;

        if ($status instanceof OrderStatusEnum) {
            return $status;
        }

        return is_string($status) ? OrderStatusEnum::tryFrom($status) : null;
    }

    /**
     * 진행 순서에서 도달한 칸의 인덱스를 구합니다.
     *
     * @param  Order  $order  주문
     * @param  array<int, OrderStatusEnum>  $sequence  진행 순서
     * @param  OrderStatusEnum|null  $current  현재 상태
     * @return int 도달 인덱스 (결제대기는 언제나 0)
     */
    private static function reachedIndex(Order $order, array $sequence, ?OrderStatusEnum $current): int
    {
        $indexOf = static function (string $value) use ($sequence): int {
            foreach ($sequence as $index => $case) {
                if ($case->value === $value) {
                    return $index;
                }
            }

            return -1;
        };

        $reached = 0;

        if ($current !== null) {
            $reached = max($reached, $indexOf($current->value));
        }

        // 주문이 박아 둔 시각은 그 단계를 실제로 지났다는 증거다 — 이탈 상태에서도 남는다.
        // 배송완료 시각은 상품 행에만 있으므로, 로드된 상품 중 하나라도 있으면 지난 것으로 본다.
        $delivered = $order->relationLoaded('options')
            ? $order->options->contains(fn ($option) => ($option->delivered_at ?? null) !== null)
            : false;

        $stamps = [
            OrderStatusEnum::PAYMENT_COMPLETE->value => $order->paid_at ?? null,
            OrderStatusEnum::DELIVERED->value => $delivered ? true : null,
            OrderStatusEnum::CONFIRMED->value => $order->confirmed_at ?? null,
        ];

        foreach ($stamps as $value => $stamp) {
            if ($stamp !== null) {
                $reached = max($reached, $indexOf($value));
            }
        }

        return max(0, $reached);
    }
}
