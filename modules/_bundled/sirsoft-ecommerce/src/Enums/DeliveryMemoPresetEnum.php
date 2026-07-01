<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 배송 메모 프리셋 Enum
 *
 * 주문서에서 선택 가능한 배송 메모 프리셋 키와 표시 라벨의 단일 SSoT 입니다.
 * 프리셋이 아닌 자유 텍스트(custom)는 enum case 가 아니므로 원문이 그대로 보존됩니다.
 */
enum DeliveryMemoPresetEnum: string
{
    case DOOR = 'door';               // 문 앞에 놓아주세요
    case SECURITY = 'security';       // 경비실에 맡겨주세요
    case PARCEL_BOX = 'parcel_box';   // 택배함에 넣어주세요
    case CALL = 'call';               // 배송 전 연락 부탁드립니다

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.delivery_memo_preset.'.$this->value);
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

    /**
     * 배송 메모 키를 표시 라벨로 변환합니다.
     *
     * enum case(프리셋)이면 대상 로케일 라벨을, 그 외(자유 텍스트)면 원문을 반환합니다.
     * 생성/수정/백필 전 경로가 이 단일 진입점만 호출하여 변환 일관성을 보장합니다.
     *
     * @param  string|null  $memo  저장된 메모 키 또는 자유 텍스트
     * @param  string|null  $locale  대상 로케일 (null = 현재 app locale)
     * @return string|null null/'' 이면 null
     */
    public static function resolveLabel(?string $memo, ?string $locale = null): ?string
    {
        if ($memo === null || $memo === '') {
            return null;
        }

        $case = self::tryFrom($memo);

        return $case
            ? __('sirsoft-ecommerce::enums.delivery_memo_preset.'.$case->value, [], $locale ?? app()->getLocale())
            : $memo; // custom 자유 텍스트 원문 보존
    }
}
