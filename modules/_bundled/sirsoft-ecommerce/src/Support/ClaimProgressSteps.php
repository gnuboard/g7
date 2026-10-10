<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Support;

use App\Helpers\TimezoneHelper;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimItemCollectionStateEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;

/**
 * 클레임 진행단계 배열의 단일 출처 (관리자 상세 · 회원 상세 · 비회원 상세 공용)
 *
 * 순서는 유형별 상태 Enum 이 소유하고(`progressSequence()`), 여기서는 이 신청서에서
 * 실제로 지나가는 단계만 남긴다:
 *
 * - 결제 두 단계(`payment_pending`/`paid`)는 **결제형 청구**에서만 그린다 — 청구액이 있어도
 *   환불금 차감이면 결제 창이 열리지 않으므로 그 두 칸이 빠진다. 금액만 보면 배송비 차감
 *   건에 영영 지나가지 않는 칸이 남는다.
 * - 보류·반려·철회는 순서에 없다. 흐름을 따라가는 단계가 아니라 멈추는 사건이라 마지막에
 *   이탈 칸 하나로 따로 붙는다.
 *
 * 도달 지점은 현재 상태의 위치와 신청서가 박아 둔 시각(승인·회수도착·재발송도착·완료) 중
 * 더 나아간 쪽이다 — 이탈 상태에서는 현재 상태만으로 어디까지 갔는지 알 수 없다.
 *
 * 관리자 응답에만 있던 이 배열을 회원·비회원 상세도 같은 자리에서 받는다 — 화면마다 순서를
 * 다시 적으면 그 사본이 어휘의 두 번째 출처가 되어, 상태가 늘어도 한 화면만 낡은 채 남는다.
 * 아이콘·색 역시 각 상태 Enum(`icon()`/`variant()`)이 정하고 여기서는 실어 나르기만 한다.
 */
final class ClaimProgressSteps
{
    /**
     * 신청서의 진행단계 배열을 만듭니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return array<int, array{value: string, label: string, state: string, icon: string, variant: string}> 진행 단계
     */
    public static function for(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): array
    {
        $sequence = self::sequenceFor($claim);
        $current = self::statusOf($claim);
        $reached = self::reachedIndex($claim, $sequence, $current);
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

        // 이탈 상태는 순서 밖이라 마지막 칸으로 붙인다 — 그래야 "어디서 멈췄는가" 와
        // "왜 멈췄는가" 가 한 줄에서 함께 읽힌다.
        if ($offPath) {
            $steps[] = self::step($current, 'off_path');
        }

        return $steps;
    }

    /**
     * 선교환의 「회수」 줄을 만듭니다. (진행단계 카드의 발송 줄 아래 — 일반 교환·반품은 null)
     *
     * 선교환의 상태 줄에는 회수 단계가 없다. 헌 물건이 어디쯤 왔는지는 상품 행의 회수 진행
     * (`ClaimItemCollectionState`)을 **수량**으로 센다 — 「3개 중 2개 도착」. 한 행이 송장마다 갈리면
     * 행 수가 실제 개수와 어긋나므로(3개 중 2개를 보내면 행 2개) 행마다 교환 수량을 더한다(S1-D15).
     * 반송 기한과 지연 여부를 함께 싣는다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return array{total: int, pending: int, in_transit: int, collected: int, unreturned: int, state: string, state_label: string, state_variant: string, due_date: string|null, is_overdue: bool, overdue_days: int}|null 회수 줄
     */
    public static function collectionLane(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ?array
    {
        if (! $claim instanceof OrderExchange || $claim->mode() !== ExchangeModeEnum::ADVANCE) {
            return null;
        }

        $counts = ['pending' => 0, 'in_transit' => 0, 'collected' => 0, 'unreturned' => 0];

        foreach ($claim->loadMissing('exchangeOptions')->exchangeOptions as $item) {
            if (in_array($item->option_status, [ExchangeStatusEnum::REJECTED, ExchangeStatusEnum::WITHDRAWN], true)) {
                continue;
            }

            $counts[ClaimItemCollectionState::for($item, ExchangeModeEnum::ADVANCE)->value] += max(1, (int) $item->exchange_quantity);
        }

        $total = array_sum($counts);

        // 줄 전체의 상태는 가장 뒤처진 행을 따른다 — 하나라도 대기면 대기, 이동 중이 있으면 회수중.
        $state = match (true) {
            $total === 0 => ClaimItemCollectionStateEnum::PENDING,
            $counts['pending'] > 0 => ClaimItemCollectionStateEnum::PENDING,
            $counts['in_transit'] > 0 => ClaimItemCollectionStateEnum::IN_TRANSIT,
            $counts['collected'] > 0 => ClaimItemCollectionStateEnum::COLLECTED,
            default => ClaimItemCollectionStateEnum::UNRETURNED,
        };

        $overdue = ClaimTrackingGroups::overdueOf($claim);

        return $counts + [
            'total' => $total,
            'state' => $state->value,
            'state_label' => $state->label(),
            'state_variant' => $state->variant(),
            // 보는 사람의 타임존 날짜 — 같은 화면의 지연 배너(`collection_due_date_formatted`)와 한 날을 가리킨다.
            'due_date' => TimezoneHelper::toUserDateString($claim->collection_due_at),
            'is_overdue' => $overdue['is_overdue'],
            'overdue_days' => $overdue['days'],
        ];
    }

    /**
     * 단계 한 칸을 만듭니다.
     *
     * @param  ExchangeStatusEnum|ReturnStatusEnum|RefundStatusEnum|CancelStatusEnum  $case  상태
     * @param  string  $state  칸 상태 (done / current / upcoming / off_path)
     * @return array{value: string, label: string, state: string, icon: string, variant: string} 단계
     */
    private static function step(
        ExchangeStatusEnum|ReturnStatusEnum|RefundStatusEnum|CancelStatusEnum $case,
        string $state,
    ): array {
        return [
            'value' => $case->value,
            'label' => $case->label(),
            'state' => $state,
            'icon' => $case->icon(),
            'variant' => $case->variant(),
        ];
    }

    /**
     * 유형별 진행 순서를 이 신청서에 맞게 추립니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return array<int, ExchangeStatusEnum|ReturnStatusEnum|RefundStatusEnum|CancelStatusEnum> 진행 순서
     */
    private static function sequenceFor(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): array
    {
        if ($claim instanceof OrderRefund) {
            return RefundStatusEnum::progressSequence();
        }

        if ($claim instanceof OrderCancel) {
            return CancelStatusEnum::progressSequence();
        }

        // 선교환은 회수 3단계 없이 승인 → 발송 단계로 간다 — 헌 물건의 회수는 `collectionLane()` 이 따로 그린다.
        $sequence = $claim instanceof OrderExchange
            ? ExchangeStatusEnum::progressSequenceIn($claim->mode())
            : ReturnStatusEnum::progressSequence();

        $paymentStages = ['payment_pending', 'paid'];

        // 결제 두 단계는 **결제형 청구**(신청 시 결제·승인 후 결제)에서만 지나간다. 청구액이
        // 있어도 환불금 차감이면 결제 창이 열리지 않으므로, 금액이 아니라 청구 방식을 본다 —
        // 금액만 보면 반품 배송비 차감 건에 영영 지나가지 않는 결제 칸 두 개가 남는다.
        if ($claim->requiresAdditionalPayment()) {
            return array_values($sequence);
        }

        // 다만 판정은 **지금** 값으로 하는데 신청서는 이미 그 칸에 서 있을 수 있다 — 결제
        // 대기·결제 완료에서 운영자가 청구를 없애면(왕복 배송비 면제 등) 현재 상태가 순서
        // 밖으로 밀려 도달 인덱스가 0 으로 접히고, 스텝퍼가 '신청 접수' 를 현재 칸으로
        // 그린다. 지나간 칸을 지우는 것은 이 추림의 목적이 아니므로 그때는 남긴다.
        if (in_array(self::statusOf($claim)?->value, $paymentStages, true)) {
            return array_values($sequence);
        }

        return array_values(array_filter(
            $sequence,
            fn ($case) => ! in_array($case->value, $paymentStages, true),
        ));
    }

    /**
     * 신청서의 현재 상태 Enum 을 반환합니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return ExchangeStatusEnum|ReturnStatusEnum|RefundStatusEnum|CancelStatusEnum|null 현재 상태
     */
    private static function statusOf(
        OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim,
    ): ExchangeStatusEnum|ReturnStatusEnum|RefundStatusEnum|CancelStatusEnum|null {
        $status = match (true) {
            $claim instanceof OrderExchange => $claim->exchange_status,
            $claim instanceof OrderReturn => $claim->return_status,
            $claim instanceof OrderRefund => $claim->refund_status,
            default => $claim->cancel_status,
        };

        if (
            $status instanceof ExchangeStatusEnum
            || $status instanceof ReturnStatusEnum
            || $status instanceof RefundStatusEnum
            || $status instanceof CancelStatusEnum
        ) {
            return $status;
        }

        if (! is_string($status)) {
            return null;
        }

        return match (true) {
            $claim instanceof OrderExchange => ExchangeStatusEnum::tryFrom($status),
            $claim instanceof OrderReturn => ReturnStatusEnum::tryFrom($status),
            $claim instanceof OrderRefund => RefundStatusEnum::tryFrom($status),
            default => CancelStatusEnum::tryFrom($status),
        };
    }

    /**
     * 진행 순서에서 도달한 칸의 인덱스를 구합니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @param  array<int, ExchangeStatusEnum|ReturnStatusEnum|RefundStatusEnum|CancelStatusEnum>  $sequence  진행 순서
     * @param  ExchangeStatusEnum|ReturnStatusEnum|RefundStatusEnum|CancelStatusEnum|null  $current  현재 상태
     * @return int 도달 인덱스 (신청 접수는 언제나 0)
     */
    private static function reachedIndex(
        OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim,
        array $sequence,
        ExchangeStatusEnum|ReturnStatusEnum|RefundStatusEnum|CancelStatusEnum|null $current,
    ): int {
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

        // 신청서가 박아 둔 시각은 그 단계를 실제로 지났다는 증거다 — 이탈 상태에서도 남는다.
        $stamps = match (true) {
            $claim instanceof OrderCancel => [
                'completed' => $claim->cancelled_at ?? null,
            ],
            $claim instanceof OrderRefund => [
                'approved' => $claim->approved_at ?? null,
                'completed' => $claim->refunded_at ?? null,
            ],
            default => [
                'approved' => $claim->approved_at ?? null,
                'collected' => $claim->collection_completed_at ?? null,
                'redelivered' => $claim instanceof OrderExchange ? ($claim->redelivered_at ?? null) : null,
                'completed' => $claim->completed_at ?? null,
            ],
        };

        foreach ($stamps as $value => $stamp) {
            if ($stamp !== null) {
                $reached = max($reached, $indexOf($value));
            }
        }

        return max(0, $reached);
    }
}
