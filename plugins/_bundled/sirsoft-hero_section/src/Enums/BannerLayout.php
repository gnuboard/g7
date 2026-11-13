<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 배너 구성 — 배경형(background, 기본·기존 배너: 미디어가 장 전체를 덮고 문구가 위에 겹친다) · 프로모션형(promo: 바탕색 위에 문구 칸과
 * 그림 칸을 나눠 놓는다 — PC 는 좌우, 휴대폰은 위아래).
 */
enum BannerLayout: string
{
    case Background = 'background';
    case Promo = 'promo';
}
