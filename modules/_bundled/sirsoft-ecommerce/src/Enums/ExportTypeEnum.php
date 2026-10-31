<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 이 모듈이 기본 제공하는 내보내기 유형
 *
 * 유형은 레지스트리(`ExportTypeRegistry`)가 정본이다 — 확장이 필터 훅으로 유형을 더하면 이 Enum 에
 * 없는 키도 유효하다. 이 Enum 은 기본 3종의 키와 라벨을 한곳에 두는 용도다.
 */
enum ExportTypeEnum: string
{
    case ORDERS = 'orders';     // 주문내역
    case PRODUCTS = 'products'; // 상품내역
    case MILEAGE = 'mileage';   // 마일리지 내역

    /**
     * 화면 표시 라벨을 반환합니다.
     *
     * @return string 현재 로케일 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.export_type.'.$this->value);
    }

    /**
     * 모든 값 목록을 반환합니다.
     *
     * @return array<int, string> 유형 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
