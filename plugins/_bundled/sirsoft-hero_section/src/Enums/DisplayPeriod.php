<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 노출 기간 방식 — 상시 또는 시작·종료 일시 지정.
 */
enum DisplayPeriod: string
{
    case Always = 'always';
    case Range = 'range';
}
