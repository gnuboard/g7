<?php

namespace Plugins\Sirsoft\HeroSection\Enums;

/**
 * 히어로섹션 노출 위치 — 위치 키는 사용자 템플릿 확장점 이름과 같다(공개 계약, 바꾸지 않는다).
 *
 * 노출 위치 목록의 SSoT 다. 다른 템플릿도 같은 이름의 확장점만 두면 이 플러그인의 배너가 붙는다.
 */
enum HeroPosition: string
{
    case HomeHero = 'home_hero';
    case ShopHero = 'shop_hero';

    /**
     * 관리자 화면 표시 이름의 다국어 키를 반환합니다.
     *
     * @return string 화면 문구 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-hero_section.admin.position.'.$this->value;
    }

    /**
     * 위치 키 목록을 반환합니다.
     *
     * @return array<int, string> 위치 키 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
