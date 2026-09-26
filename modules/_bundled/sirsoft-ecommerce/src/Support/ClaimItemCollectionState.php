<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use Modules\Sirsoft\Ecommerce\Enums\ClaimItemCollectionStateEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;

/**
 * 신청 상품 행의 회수 진행 판정기 (파생값 — 저장하지 않는다)
 *
 * 선교환은 상태 줄이 발송 단계만 쓰므로 헌 물건이 어디쯤 왔는지는 행의 미회수 표시·도착 시각·
 * 회수 송장으로만 알 수 있다. 일반 교환·반품은 상태 줄이 회수 단계를 쓰므로 그 상태를 우선하고,
 * 회수 단계 전이면 행 송장으로 판정한다 — 같은 함수로 판정해야 상품 칸·진행단계·구매자 화면의
 * 「회수중/회수 도착」 표기가 갈리지 않는다.
 *
 * 「일부만 회수」 는 행을 쪼개서(R4) 쪼갠 행마다 따로 판정한다 — 한 행 안에 두 상태가 섞이지 않는다.
 */
class ClaimItemCollectionState
{
    /**
     * 신청 상품 행의 회수 진행을 판정합니다.
     *
     * @param  OrderExchangeOption|OrderReturnOption  $item  신청 상품 행
     * @param  ExchangeModeEnum|null  $mode  교환 진행 방식 (반품·미지정이면 일반)
     * @return ClaimItemCollectionStateEnum 회수 진행
     */
    public static function for(OrderExchangeOption|OrderReturnOption $item, ?ExchangeModeEnum $mode = null): ClaimItemCollectionStateEnum
    {
        if ($item instanceof OrderExchangeOption && $item->unreturned_at !== null) {
            return ClaimItemCollectionStateEnum::UNRETURNED;
        }

        if ($item->collected_at !== null) {
            return ClaimItemCollectionStateEnum::COLLECTED;
        }

        if (($mode ?? ExchangeModeEnum::STANDARD) !== ExchangeModeEnum::ADVANCE) {
            $byStatus = self::fromStatus($item);

            if ($byStatus !== null) {
                return $byStatus;
            }
        }

        return self::hasCollectionTracking($item)
            ? ClaimItemCollectionStateEnum::IN_TRANSIT
            : ClaimItemCollectionStateEnum::PENDING;
    }

    /**
     * 일반 흐름의 상태 줄에서 회수 진행을 읽습니다. (회수중이면 이동 중, 회수도착 이후면 도착)
     *
     * @param  OrderExchangeOption|OrderReturnOption  $item  신청 상품 행
     * @return ClaimItemCollectionStateEnum|null 상태로 판정되면 그 값, 회수 단계 전이면 null
     */
    protected static function fromStatus(OrderExchangeOption|OrderReturnOption $item): ?ClaimItemCollectionStateEnum
    {
        $status = $item->option_status;

        if ($item instanceof OrderExchangeOption) {
            return match ($status) {
                ExchangeStatusEnum::COLLECTING => ClaimItemCollectionStateEnum::IN_TRANSIT,
                ExchangeStatusEnum::COLLECTED,
                ExchangeStatusEnum::REDELIVERY_READY,
                ExchangeStatusEnum::REDELIVERING,
                ExchangeStatusEnum::REDELIVERED => ClaimItemCollectionStateEnum::COLLECTED,
                default => null,
            };
        }

        return match ($status) {
            ReturnStatusEnum::COLLECTING => ClaimItemCollectionStateEnum::IN_TRANSIT,
            ReturnStatusEnum::COLLECTED => ClaimItemCollectionStateEnum::COLLECTED,
            default => null,
        };
    }

    /**
     * 행에 회수 송장이 있는지 반환합니다.
     *
     * @param  OrderExchangeOption|OrderReturnOption  $item  신청 상품 행
     * @return bool 회수 송장이 있으면 true
     */
    public static function hasCollectionTracking(OrderExchangeOption|OrderReturnOption $item): bool
    {
        return $item->collection_carrier_id !== null
            && trim((string) $item->collection_tracking_number) !== '';
    }
}
