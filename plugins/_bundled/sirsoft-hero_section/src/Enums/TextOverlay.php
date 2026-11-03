<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/** 문구 뒤에 깔 선택적 그라데이션 배경 */
enum TextOverlay: string
{
    case None = 'none';
    case Dark = 'dark';
    case Light = 'light';
}
