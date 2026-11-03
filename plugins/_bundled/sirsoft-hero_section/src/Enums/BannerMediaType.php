<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 배너 미디어 종류 — 이미지(기본), 유튜브 영상, 직접 올린 영상 파일.
 *
 * 어느 종류든 PC 이미지는 대표 이미지로 필수다 — 유튜브는 방문자 동의(마케팅) 전에, 영상은 받는 동안·움직임 줄이기에서 대신 보인다.
 */
enum BannerMediaType: string
{
    case Image = 'image';
    case Youtube = 'youtube';
    case Video = 'video';
}
