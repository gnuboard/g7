<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 그림(미디어) 등장 효과 — 없음(none, 기본) · 서서히(fade) · 오른쪽에서(right, 15px 옆에서 들어온다). 문구 등장 효과와 따로 재생되고,
 * 속도·시작 지연은 문구와 같은 값을 따른다. 움직임 줄이기에서는 재생하지 않는다.
 */
enum MediaAnimation: string
{
    case None = 'none';
    case Fade = 'fade';
    case Right = 'right';
}
