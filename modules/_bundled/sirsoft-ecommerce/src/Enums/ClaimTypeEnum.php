<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 클레임 유형 Enum
 *
 * 교환/반품/환불/취소 4종을 횡단으로 다루는 지점(유저·관리자 목록의 유형 서브탭,
 * 배송비 산정, 공용 첨부·추가결제의 다형 참조)이 공유하는 유형 축이다.
 */
enum ClaimTypeEnum: string
{
    case EXCHANGE = 'exchange';  // 교환
    case RETURN = 'return';      // 반품
    case REFUND = 'refund';      // 환불
    case CANCEL = 'cancel';      // 취소

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.claim_type.'.$this->value);
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
        return 'sirsoft-ecommerce::enums.claim_type.'.$this->value;
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string 뱃지 variant
     */
    public function variant(): string
    {
        return match ($this) {
            self::EXCHANGE => 'info',
            self::RETURN => 'warning',
            self::REFUND => 'primary',
            self::CANCEL => 'danger',
        };
    }

    /**
     * 공용 부속물(첨부 이미지·추가결제)이 참조할 수 있는 유형인지 반환합니다.
     *
     * 취소는 신청서를 별도로 받지 않고 즉시취소 파이프라인으로 처리되므로
     * 다형 참조 대상이 아니다.
     *
     * @return bool 다형 참조 가능 여부
     */
    public function isAttachable(): bool
    {
        return in_array($this, [self::EXCHANGE, self::RETURN, self::REFUND], true);
    }

    /**
     * 추가결제(claim_payments)를 발생시킬 수 있는 유형인지 반환합니다.
     *
     * @return bool 추가결제 대상 여부
     */
    public function isPayable(): bool
    {
        return in_array($this, [self::EXCHANGE, self::RETURN], true);
    }

    /**
     * 관리자 라우트 경로 세그먼트(`exchanges`/`returns`/`refunds`)를 유형으로 해석합니다.
     *
     * 취소는 관리자 유형 스코프 라우트가 없다(전이·상세 대상이 아님 — 취소 이력은
     * 주문 상세 소관). 라우트의 `whereIn` 제약과 이 매핑이 단일 출처를 이뤄야 하므로
     * 세그먼트 목록은 `adminPathSegments()` 에서 파생한다.
     *
     * @param  string  $segment  경로 세그먼트
     * @return self|null 유형 (해석 불가 시 null)
     */
    public static function fromAdminPathSegment(string $segment): ?self
    {
        return match ($segment) {
            'exchanges' => self::EXCHANGE,
            'returns' => self::RETURN,
            'refunds' => self::REFUND,
            default => null,
        };
    }

    /**
     * 관리자 유형 스코프 라우트가 허용하는 경로 세그먼트 목록을 반환합니다.
     *
     * @return array<string> 경로 세그먼트 목록
     */
    public static function adminPathSegments(): array
    {
        return ['exchanges', 'returns', 'refunds'];
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<string> 유형 값 배열
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
