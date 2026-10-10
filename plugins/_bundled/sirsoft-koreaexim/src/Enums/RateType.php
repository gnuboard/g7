<?php

namespace Plugins\Sirsoft\Koreaexim\Enums;

/** 환율 RateType 분류. */
enum RateType: string
{
    case DealBaseRate = 'deal_bas_r';
    case TelegraphicBuying = 'ttb';
    case TelegraphicSelling = 'tts';

    /**
     * 화면에 표시할 번역 라벨을 반환합니다.
     *
     * @return string 처리 결과
     */
    public function label(): string
    {
        return __('sirsoft-koreaexim::messages.RateType.'.$this->value);
    }
}
