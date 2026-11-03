<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 배너 바탕 밝기 — 위치 설정에서 메뉴 겹치기를 켜면 사이트 상단 메뉴가 이 값에 맞춰 글자색을 바꾼다
 * (dark 바탕이면 흰 글자, light 바탕이면 검은 글자).
 */
enum BannerTone: string
{
    case Dark = 'dark';
    case Light = 'light';
}
