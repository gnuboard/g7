<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 클레임 청구 방식 Enum
 *
 * 클레임 배송비·차액을 언제 청구할지를 정하는 축이다. 결제 수단(PG/마일리지)은
 * 이와 독립된 별개 축이며, 두 축의 조합을 관리자가 설정에서 허용하고 유저가
 * 그 안에서 고른다(D6).
 *
 * `deduct_from_refund` 는 환불이 발생하는 건(반품·저가 교환)에서만 선택 가능하다.
 */
enum ClaimChargeMethodEnum: string
{
    case PAY_ON_REQUEST = 'pay_on_request';            // 신청 시점 결제
    case PAY_ON_APPROVAL = 'pay_on_approval';          // 승인 시점 결제
    case DEDUCT_FROM_REFUND = 'deduct_from_refund';    // 환불액에서 차감

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.claim_charge_method.'.$this->value);
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 활동 로그용 다국어 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.claim_charge_method.'.$this->value;
    }

    /**
     * 별도 결제 건(claim_payments)을 발생시키는 방식인지 반환합니다.
     *
     * 환불 차감은 환불 실행 시점에 상계되므로 결제 건을 만들지 않는다.
     *
     * @return bool 결제 건 발생 여부
     */
    public function requiresPayment(): bool
    {
        return $this !== self::DEDUCT_FROM_REFUND;
    }

    /**
     * 환불이 발생하는 건에서만 선택 가능한 방식인지 반환합니다.
     *
     * @return bool 환불 발생 건 전용 여부
     */
    public function requiresRefundSource(): bool
    {
        return $this === self::DEDUCT_FROM_REFUND;
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<string> 청구 방식 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array<array{value: string, label: string}> 셀렉트 옵션 배열
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
