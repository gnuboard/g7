<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Listeners\Concerns;

use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;

/**
 * 클레임 신청서 3종의 공통 식별 축(번호·유형·상태)을 해석합니다.
 *
 * 교환·반품·환불은 번호와 상태 컬럼 이름이 각각 다르다(`exchange_number`/`exchange_status`
 * 등). 이 매핑을 소비 지점마다 다시 적으면 유형이 늘거나 컬럼명이 바뀔 때 한 곳만 낡고,
 * 그 지점의 응답·로그에서만 값이 조용히 null 이 된다. 알림·활동 로그 두 리스너가 같은
 * 매핑을 쓰므로 여기에 모은다.
 */
trait ResolvesClaimIdentity
{
    /**
     * 신청서의 클레임 번호를 반환합니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return string|null 클레임 번호 (미채번 시 null)
     */
    protected function claimNumberOf(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ?string
    {
        return match (true) {
            $claim instanceof OrderExchange => $claim->exchange_number,
            $claim instanceof OrderReturn => $claim->return_number,
            $claim instanceof OrderCancel => $claim->cancel_number,
            default => $claim->refund_number,
        };
    }

    /**
     * 신청서의 현재 상태 Enum 을 반환합니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return \BackedEnum|null 상태 Enum
     */
    protected function claimStatusOf(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ?\BackedEnum
    {
        $status = match (true) {
            $claim instanceof OrderExchange => $claim->exchange_status,
            $claim instanceof OrderReturn => $claim->return_status,
            $claim instanceof OrderCancel => $claim->cancel_status,
            default => $claim->refund_status,
        };

        return $status instanceof \BackedEnum ? $status : null;
    }

    /**
     * 신청서의 현재 상태 값을 반환합니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return string|null 상태 값
     */
    protected function claimStatusValue(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ?string
    {
        $status = $this->claimStatusOf($claim);

        return $status?->value;
    }

    /**
     * 신청서의 현재 상태 라벨(현재 로케일)을 반환합니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return string 상태 라벨 (해석 불가 시 빈 문자열)
     */
    protected function claimStatusLabel(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): string
    {
        $status = $this->claimStatusOf($claim);

        return $status !== null && method_exists($status, 'label') ? $status->label() : '';
    }

    /**
     * 주어진 상태 값의 라벨(현재 로케일)을 신청서 유형에 맞춰 반환합니다.
     *
     * 큐에서 실행되는 소비자는 모델의 "지금" 상태가 아니라 **발행 시점에 캡처된 값**으로 라벨을
     * 만들어야 한다 — 과거 사건의 알림·기록에 현재 상태가 실리면 오류 없이 내용만 틀린다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서 (유형 판별용)
     * @param  string|null  $statusValue  상태 값
     * @return string 상태 라벨 (해석 불가 시 빈 문자열)
     */
    protected function claimStatusLabelFor(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim, ?string $statusValue): string
    {
        if ($statusValue === null || $statusValue === '') {
            return '';
        }

        // 상태 Enum 은 모델 캐스트가 정본이다 — 여기서 유형별 Enum 을 다시 나열하면 캐스트가
        // 바뀔 때 이 매핑만 낡는다.
        $enumClass = $claim->getCasts()[$this->claimStatusColumnOf($claim)] ?? null;

        if (! is_string($enumClass) || ! enum_exists($enumClass)) {
            return '';
        }

        $status = $enumClass::tryFrom($statusValue);

        return $status !== null && method_exists($status, 'label') ? $status->label() : '';
    }

    /**
     * 신청서 유형별 상태 컬럼 이름을 반환합니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return string 상태 컬럼 이름
     */
    protected function claimStatusColumnOf(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): string
    {
        return match (true) {
            $claim instanceof OrderExchange => 'exchange_status',
            $claim instanceof OrderReturn => 'return_status',
            $claim instanceof OrderCancel => 'cancel_status',
            default => 'refund_status',
        };
    }

    /**
     * 신청서의 사유 코드를 반환합니다.
     *
     * 취소신청서만 컬럼 이름이 다르다(`cancel_reason_type`) — 취소는 클레임 3종보다 먼저
     * 만들어진 테이블이라 어휘가 갈렸다. 소비 지점마다 분기를 적으면 한 곳만 낡아 그
     * 경로의 사유가 조용히 null 이 된다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return string|null 사유 코드
     */
    protected function claimReasonCodeOf(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ?string
    {
        return $claim instanceof OrderCancel ? $claim->cancel_reason_type : $claim->reason_code;
    }

    /**
     * 신청서의 클레임 유형을 반환합니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return ClaimTypeEnum 클레임 유형
     */
    protected function claimTypeOf(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ClaimTypeEnum
    {
        return $claim->claimType();
    }
}
