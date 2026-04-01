<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 디바이스 타입 Enum (주문 경로)
 */
enum DeviceTypeEnum: string
{
    case PC = 'pc';                           // PC 웹
    case MOBILE = 'mobile';                   // 모바일 웹
    case APP_IOS = 'app_ios';                 // iOS 앱
    case APP_ANDROID = 'app_android';         // Android 앱
    case ADMIN = 'admin';                     // 관리자 대리주문
    case API = 'api';                         // 외부 API

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.device_type.'.$this->value);
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
     * 모바일 디바이스 여부를 확인합니다.
     *
     * @return bool
     */
    public function isMobile(): bool
    {
        return in_array($this, [
            self::MOBILE,
            self::APP_IOS,
            self::APP_ANDROID,
        ]);
    }

    /**
     * 앱 디바이스 여부를 확인합니다.
     *
     * @return bool
     */
    public function isApp(): bool
    {
        return in_array($this, [
            self::APP_IOS,
            self::APP_ANDROID,
        ]);
    }
}
