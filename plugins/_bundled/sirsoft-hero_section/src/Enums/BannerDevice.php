<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 배너 이미지 기기 — PC 는 필수, 모바일은 없으면 PC 이미지를 쓴다.
 */
enum BannerDevice: string
{
    case Pc = 'pc';
    case Mobile = 'mobile';
}
