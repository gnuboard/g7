<?php

namespace Modules\Sirsoft\Board\Enums;

/**
 * 신고 상태 Enum
 *
 * 신고의 처리 상태를 정의합니다.
 */
enum ReportStatus: string
{
    /**
     * 접수 (신고 접수됨)
     */
    case Pending = 'pending';

    /**
     * 검토 (관리자가 확인 중)
     */
    case Review = 'review';

    /**
     * 반려됨 (신고 기각)
     */
    case Rejected = 'rejected';

    /**
     * 숨김처리됨 (신고 승인 및 컨텐츠 숨김)
     */
    case Suspended = 'suspended';

    /**
     * 영구삭제됨 (신고 승인 및 컨텐츠 영구 삭제)
     */
    case Deleted = 'deleted';

    /**
     * 모든 상태 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 상태 값인지 확인합니다.
     *
     * @param  string  $value  검증할 상태 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 상태의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 상태 라벨
     */
    /**
     * 활동 로그용 다국어 라벨 키를 반환합니다.
     *
     * @return string
     */
    public function labelKey(): string
    {
        return 'sirsoft-board::enums.report_status.'.$this->value;
    }

    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 상태별 CSS 클래스(variant)를 반환합니다.
     *
     * @return string 스타일 variant
     */
    public function variant(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Review => 'info',
            self::Rejected => 'secondary',
            self::Suspended => 'danger',
            self::Deleted => 'dark',
        };
    }

    /**
     * 모든 상태를 배열로 반환합니다 (value, label 포함).
     *
     * @return array<array{value: string, label: string}>
     */
    public static function toArray(): array
    {
        return array_map(
            fn (self $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ],
            self::cases()
        );
    }

    /**
     * 모든 상태 전환 규칙을 반환합니다.
     *
     * deleted(영구삭제)만 제외하고 모든 상태 간 자유 전환 가능합니다.
     * deleted는 최종 상태로 다른 상태로 전환할 수 없습니다.
     *
     * @return array<string, array<string>> 상태별 가능한 전환 목록
     */
    public static function getTransitionRules(): array
    {
        return [
            'pending' => ['review', 'rejected', 'suspended', 'deleted'],
            'review' => ['pending', 'rejected', 'suspended', 'deleted'],
            'rejected' => ['pending', 'review', 'suspended', 'deleted'],
            'suspended' => ['pending', 'review', 'rejected', 'deleted'],
            'deleted' => [], // 최종 상태 - 전환 불가
        ];
    }

    /**
     * 현재 상태에서 특정 상태로 전환 가능한지 확인합니다.
     *
     * @param  string|ReportStatus  $targetStatus  전환하려는 상태
     * @return bool 전환 가능 여부
     */
    public function canTransitionTo(string|ReportStatus $targetStatus): bool
    {
        // deleted는 최종 상태 (전환 불가)
        if ($this === self::Deleted) {
            return false;
        }

        $targetValue = $targetStatus instanceof ReportStatus ? $targetStatus->value : $targetStatus;
        $rules = self::getTransitionRules();
        $allowedTransitions = $rules[$this->value] ?? [];

        return in_array($targetValue, $allowedTransitions, true);
    }

    /**
     * 현재 상태에서 가능한 전환 목록을 반환합니다.
     *
     * @return array<string> 가능한 상태 전환 목록
     */
    public function getAvailableTransitions(): array
    {
        $rules = self::getTransitionRules();

        return $rules[$this->value] ?? [];
    }
}
