<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 통화 설정 잠금 예외
 *
 * 상품이 존재하여 통화 설정 변경이 잠금되어 있을 때 발생합니다.
 */
class CurrencySettingLockedException extends Exception
{
    /**
     * @param  string  $settingType  설정 유형 (base_currency, multi_currency 등)
     * @param  int  $productCount  존재하는 상품 수
     */
    public function __construct(
        private string $settingType,
        private int $productCount
    ) {
        parent::__construct(
            __('sirsoft-ecommerce::exceptions.currency_setting_locked', [
                'setting_type' => $settingType,
                'product_count' => $productCount,
            ])
        );
    }

    /**
     * 설정 유형을 반환합니다.
     *
     * @return string
     */
    public function getSettingType(): string
    {
        return $this->settingType;
    }

    /**
     * 존재하는 상품 수를 반환합니다.
     *
     * @return int
     */
    public function getProductCount(): int
    {
        return $this->productCount;
    }
}
