<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;

/**
 * 환불 발생 출처 유형 Enum
 *
 * 환불신청서(`ecommerce_order_refunds.refund_source_type`)가 어느 경로에서
 * 생겼는지를 기록한다. DIRECT 는 참조 대상 레코드가 없는 관리자 직접 환불이다.
 *
 * 이 축을 Eloquent 다형 관계(morphTo)로 두지 않는 이유: DIRECT 에 대응하는 모델이
 * 없어 morphTo 가 그 값을 클래스명으로 해석하다 fatal 이 난다. 대신 sourceModelClass()
 * 로 명시 해석해 DIRECT 를 null 로 처리한다.
 */
enum RefundSourceTypeEnum: string
{
    case CANCEL = 'cancel';        // 주문 취소 발
    case RETURN = 'return';        // 반품 발
    case EXCHANGE = 'exchange';    // 교환(저가 차액) 발
    case DIRECT = 'direct';        // 관리자 직접 환불 (참조 레코드 없음)

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.refund_source_type.'.$this->value);
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
     * 출처 레코드의 모델 클래스를 반환합니다.
     *
     * @return class-string<Model>|null 모델 클래스 (참조 대상이 없으면 null)
     */
    public function sourceModelClass(): ?string
    {
        return match ($this) {
            self::CANCEL => OrderCancel::class,
            self::RETURN => OrderReturn::class,
            self::EXCHANGE => OrderExchange::class,
            self::DIRECT => null,
        };
    }

    /**
     * 대응하는 클레임 유형을 반환합니다.
     *
     * @return ClaimTypeEnum|null 클레임 유형 (직접 환불이면 null)
     */
    public function claimType(): ?ClaimTypeEnum
    {
        return match ($this) {
            self::CANCEL => ClaimTypeEnum::CANCEL,
            self::RETURN => ClaimTypeEnum::RETURN,
            self::EXCHANGE => ClaimTypeEnum::EXCHANGE,
            self::DIRECT => null,
        };
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<string> 출처 유형 값 배열
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
