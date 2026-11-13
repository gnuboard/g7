<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 배너 미디어 종류 — 이미지(기본), 직접 올린 영상 파일.
 *
 * 어느 종류든 PC 이미지는 대표 이미지로 필수다 — 영상은 받는 동안·움직임 줄이기에서 대표 이미지를 보인다.
 * 유튜브 등 제3자 영상 플랫폼은 받지 않는다(추후 보완 출시). 옛 유튜브 배너는 업그레이드 때 이미지 배너로 바뀐다.
 */
enum BannerMediaType: string
{
    case Image = 'image';
    case Video = 'video';
}
