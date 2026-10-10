<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 취소 옵션 상태 Enum
 */
enum CancelOptionStatusEnum: string
{
    case REQUESTED = 'requested';    // 취소 신청
    case COMPLETED = 'completed';    // 처리 완료

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.cancel_option_status.'.$this->value);
    }

    /**
     * 다국어 라벨 **키**를 반환합니다. (활동 로그의 전/후 라벨 해석용)
     *
     * 활동 로그는 값을 원값으로 저장하고 라벨은 **표시 시점 로케일**로 해석한다 — 그래서
     * `label()`(이미 번역된 문자열)이 아니라 키가 필요하다. 이 메서드가 없으면
     * `BuildsEnumFieldChange::enumFieldChange()` 가 정의되지 않은 메서드를 불러 리스너 잡이
     * 통째로 죽는다. 리스너는 큐 잡으로 돌아가므로 그 실패는 화면 어디에도 드러나지 않고
     * **해당 전이의 활동로그만 조용히 사라진다**(취소 신청상품 상태 축이 그 상태였다).
     *
     * @return string 다국어 라벨 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.cancel_option_status.'.$this->value;
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string
     */
    public function variant(): string
    {
        return match ($this) {
            self::REQUESTED => 'secondary',
            self::COMPLETED => 'success',
        };
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
