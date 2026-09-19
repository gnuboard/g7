<?php

namespace Plugins\Sirsoft\Koreaexim\Enums;

/** 환율 ItemStatus 분류. */
enum ItemStatus: string
{
    case Applied = 'applied';
    case Unchanged = 'unchanged';
    case Unsupported = 'unsupported';
    case InvalidRate = 'invalid_rate';
    case BaseCurrency = 'base_currency';

    /**
     * 화면에 표시할 번역 라벨을 반환합니다.
     *
     * @return string 처리 결과
     */
    public function label(): string
    {
        return __('sirsoft-koreaexim::messages.ItemStatus.'.$this->value);
    }
}
