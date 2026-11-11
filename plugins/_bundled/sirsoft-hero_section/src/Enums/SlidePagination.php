<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 슬라이더 페이징 모양 — 점(dots, 기본) · 진행 막대 점(progress, 현재 장 점이 길어지고 자동 넘김까지 남은 시간이 찬다) ·
 * 배너 이름 탭(tabs, 장마다 배너 제목과 진행 막대) · 번호(fraction, 「01 / 05」) ·
 * 알약 번호(pill, 문구 아래 왼쪽의 둥근 상자 안 「‹ 1 / 5 ›」 — 좌우 화살표가 상자 안으로 들어간다).
 */
enum SlidePagination: string
{
    case Dots = 'dots';
    case Progress = 'progress';
    case Tabs = 'tabs';
    case Fraction = 'fraction';
    case Pill = 'pill';
}
