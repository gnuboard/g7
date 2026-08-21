<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;

/**
 * 클레임 배송비 산정 결과 DTO
 *
 * `ClaimShippingFeeService::calculate()` 의 반환값이며, 클레임 헤더의
 * `shipping_fee_mode` / `claim_shipping_fee` / `shipping_fee_snapshot` 세 컬럼이
 * 이 DTO 하나에서 나온다.
 *
 * 금액은 주문 기본 통화 기준 정수다. 다통화 표현(`mc_claim_shipping_fee`)과
 * 근거 스냅샷의 확장(도서산간·수량 안분)은 S2b2 가 이 DTO 위에 덧붙인다.
 *
 * 패턴은 이 모듈의 다른 DTO 와 같은 **Data Carrier** 다 — 산정 이후 다통화 금액이 덧붙고,
 * 동결된 `shipping_fee_snapshot` 을 화면에서 되읽는 재구성 경로가 뒤따르기 때문이다.
 */
class ClaimShippingFee
{
    /** 정상 청구 (고객 귀책 + 청구 대상 유형) */
    public const REASON_CHARGED = 'charged';

    /** 판매자·배송사 귀책이라 청구하지 않음 */
    public const REASON_FAULT_NOT_CUSTOMER = 'fault_not_customer';

    /** 환불·취소 유형이라 회수 배송이 없어 청구하지 않음 */
    public const REASON_CLAIM_TYPE_NOT_CHARGEABLE = 'claim_type_not_chargeable';

    /** 클레임 대상 아이템이 없음 */
    public const REASON_NO_ITEMS = 'no_items';

    /** 배송정책 또는 국가별 설정을 해석하지 못해 0원 처리 */
    public const REASON_NO_SHIPPING_POLICY = 'no_shipping_policy';

    /**
     * @param  int  $fee  청구 배송비 합계 (주문 기본 통화)
     * @param  ClaimShippingFeeModeEnum  $mode  클레임 헤더에 동결될 산정 모드
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @param  ClaimReasonFaultTypeEnum  $fault  신청 시점 귀책
     * @param  bool  $charged  실제 청구가 발생했는지 여부
     * @param  string  $reason  산정 결과 사유 키 (REASON_* 상수)
     * @param  array<int, array>  $groups  배송정책 그룹별 산정 근거
     */
    public function __construct(
        public int $fee = 0,
        public ClaimShippingFeeModeEnum $mode = ClaimShippingFeeModeEnum::FIXED,
        public ClaimTypeEnum $claimType = ClaimTypeEnum::RETURN,
        public ClaimReasonFaultTypeEnum $fault = ClaimReasonFaultTypeEnum::CUSTOMER,
        public bool $charged = false,
        public string $reason = self::REASON_CHARGED,
        public array $groups = [],
    ) {}

    /**
     * 청구가 발생하지 않은 결과를 만듭니다.
     *
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @param  ClaimReasonFaultTypeEnum  $fault  신청 시점 귀책
     * @param  string  $reason  사유 키 (REASON_* 상수)
     * @param  ClaimShippingFeeModeEnum  $mode  헤더에 기록할 산정 모드
     * @param  array<int, array>  $groups  배송정책 그룹별 근거
     * @return self 청구 0원 결과
     */
    public static function notCharged(
        ClaimTypeEnum $claimType,
        ClaimReasonFaultTypeEnum $fault,
        string $reason,
        ClaimShippingFeeModeEnum $mode = ClaimShippingFeeModeEnum::FIXED,
        array $groups = [],
    ): self {
        return new self(
            fee: 0,
            mode: $mode,
            claimType: $claimType,
            fault: $fault,
            charged: false,
            reason: $reason,
            groups: $groups,
        );
    }

    /**
     * `shipping_fee_snapshot` 컬럼에 동결할 배열을 반환합니다.
     *
     * @return array 산정 근거 스냅샷
     */
    public function toArray(): array
    {
        return [
            'fee' => $this->fee,
            'mode' => $this->mode->value,
            'claim_type' => $this->claimType->value,
            'fault_type' => $this->fault->value,
            'charged' => $this->charged,
            'reason' => $this->reason,
            'groups' => $this->groups,
        ];
    }
}
