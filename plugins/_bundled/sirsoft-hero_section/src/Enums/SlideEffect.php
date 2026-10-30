<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 슬라이더 전환 효과 — 값은 Swiper 효과 이름과 같고, None 은 전환 시간 0 인 slide 다.
 */
enum SlideEffect: string
{
    case Slide = 'slide';
    case Fade = 'fade';
    case Cube = 'cube';
    case Flip = 'flip';
    case Coverflow = 'coverflow';
    case Creative = 'creative';
    case None = 'none';
}
