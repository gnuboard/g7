<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 배너 파일 종류 — 이미지(대표 이미지) 또는 영상. 기기(PC·모바일)마다 종류별로 한 개씩 붙는다.
 */
enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';

    /**
     * 기기·종류 조합의 선택 키(등록·수정 요청의 `<키>_id`, 응답 묶음 키)를 반환합니다.
     *
     * 이미지는 기존 키(`pc`·`mobile`)를 그대로 쓰고, 영상은 `pc_video`·`mobile_video` 다.
     *
     * @param  BannerDevice  $device  기기
     * @return string 선택 키
     */
    public function slot(BannerDevice $device): string
    {
        return $this === self::Image ? $device->value : $device->value.'_video';
    }

    /**
     * 모든 선택 키와 그 기기·종류를 반환합니다.
     *
     * @return array<string, array{0: BannerDevice, 1: MediaKind}> 선택 키 => [기기, 종류]
     */
    public static function slots(): array
    {
        $slots = [];
        foreach (self::cases() as $kind) {
            foreach (BannerDevice::cases() as $device) {
                $slots[$kind->slot($device)] = [$device, $kind];
            }
        }

        return $slots;
    }
}
