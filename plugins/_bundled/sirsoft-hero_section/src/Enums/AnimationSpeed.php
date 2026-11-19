<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 문구 등장 효과 한 줄의 재생 시간 — 느리게 1초 · 보통 0.6초 · 빠르게 0.35초.
 */
enum AnimationSpeed: string
{
    case Slow = 'slow';
    case Normal = 'normal';
    case Fast = 'fast';
}
