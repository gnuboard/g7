<?php

namespace Plugins\Sirsoft\Koreaexim\Enums;

/** 환율 RunTrigger 분류. */
enum RunTrigger: string
{
    case Scheduled = 'scheduled';
    case Retry = 'retry';
    case Manual = 'manual';
    case DryRun = 'dry_run';
    case CurrencyChanged = 'currency_changed';

    /**
     * 화면에 표시할 번역 라벨을 반환합니다.
     *
     * @return string 처리 결과
     */
    public function label(): string
    {
        return __('sirsoft-koreaexim::messages.RunTrigger.'.$this->value);
    }
}
