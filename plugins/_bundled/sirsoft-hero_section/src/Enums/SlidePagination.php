<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 슬라이더 페이징 모양 — 점(dots, 기본) · 진행 막대 점(progress, 현재 장 점이 길어지고 자동 넘김까지 남은 시간이 찬다) ·
 * 배너 이름 탭(tabs, 장마다 배너 제목과 진행 막대) · 번호(fraction, 「01 / 05」).
 */
enum SlidePagination: string
{
    case Dots = 'dots';
    case Progress = 'progress';
    case Tabs = 'tabs';
    case Fraction = 'fraction';
}
