<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 배너 문구 등장 효과 — 활성 장이 될 때마다 머리글→제목→설명→버튼 순으로 0.2초씩 늦게 재생한다.
 * 방문자가 움직임 줄이기를 켰으면 어느 값이든 효과 없이 보인다.
 */
enum TextAnimation: string
{
    case None = 'none';
    case Rise = 'rise';
    case Fade = 'fade';
    case Left = 'left';
    case Right = 'right';
    case Zoom = 'zoom';
    case Blur = 'blur';
    case Letters = 'letters';
    case Sequence = 'sequence';
}
