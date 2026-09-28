<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 클레임 첨부의 업로드 주체 Enum
 *
 * 분쟁이 생기면 "이 사진을 누가 냈는가" 가 판정을 가른다. `created_by` 는 업로더의 회원
 * ID 일 뿐이라 주체를 가르지 못한다 — 비회원도 NULL 이고, 운영자 대리 업로드도 그냥 id 다.
 *
 * ## `unknown` 이 기본값인 이유
 *
 * 앞으로 이 값을 채우지 않는 경로가 생겼을 때 `customer` 가 기본값이면 **운영자가 올린
 * 사진이 고객 증빙으로 표시된다** — 조용한 거짓이고, 그것으로 분쟁 판정이 뒤집힌다.
 * `unknown` 은 화면에서 뱃지를 그리지 않으므로 거짓 대신 공백이 남는다.
 */
enum ClaimImageUploaderTypeEnum: string
{
    case CUSTOMER = 'customer';  // 고객이 신청·수정 화면에서 올림
    case ADMIN = 'admin';        // 운영자가 상세에서 대리로 올림
    case UNKNOWN = 'unknown';    // 주체 미상 (단정할 근거가 없음)

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.claim_image_uploader_type.'.$this->value);
    }

    /**
     * 뱃지 variant 를 반환합니다.
     *
     * `unknown` 은 표시하지 않으므로 호출부가 그리기 전에 걸러야 한다.
     *
     * @return string 뱃지 variant
     */
    public function variant(): string
    {
        return match ($this) {
            self::CUSTOMER => 'info',
            self::ADMIN => 'primary',
            self::UNKNOWN => 'secondary',
        };
    }

    /**
     * 화면에 뱃지를 그려도 되는지 반환합니다.
     *
     * @return bool 표시 대상이면 true
     */
    public function isDisplayable(): bool
    {
        return $this !== self::UNKNOWN;
    }

    /**
     * 모든 값 목록을 반환합니다.
     *
     * @return array<int, string> 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
