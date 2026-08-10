<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 현금영수증 발급 상태 Enum
 *
 * FAILED 이력은 "취소는 성공했는데 재발급만 실패한" 중간 상태를 원장에 남기기 위해 존재한다.
 * 이 상태를 조용히 넘기면 국세청 신고 누락으로 이어지므로 관리자 화면에 노출된다.
 */
enum CashReceiptIssueStatus: string
{
    /**
     * 처리 중 (프로바이더 응답 대기)
     */
    case IN_PROGRESS = 'IN_PROGRESS';

    /**
     * 완료
     */
    case COMPLETED = 'COMPLETED';

    /**
     * 실패
     */
    case FAILED = 'FAILED';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 값인지 확인합니다.
     *
     * @param  string  $value  검증할 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 성공적으로 완료된 상태인지 확인합니다.
     *
     * @return bool 완료 여부
     */
    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 라벨
     */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 번역 키를 반환합니다.
     *
     * @return string 번역 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::cash_receipt.issue_status.'.strtolower($this->value);
    }

    /**
     * UI Badge variant 를 반환합니다.
     *
     * @return string variant 명
     */
    public function variant(): string
    {
        return match ($this) {
            self::IN_PROGRESS => 'warning',
            self::COMPLETED => 'success',
            self::FAILED => 'danger',
        };
    }
}
