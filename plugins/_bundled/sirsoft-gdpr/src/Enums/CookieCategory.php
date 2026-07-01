<?php

namespace Plugins\Sirsoft\Gdpr\Enums;

enum CookieCategory: string
{
    case Necessary = 'cookie_necessary';
    case Functional = 'cookie_functional';
    case Analytics = 'cookie_analytics';
    case Marketing = 'cookie_marketing';

    /**
     * 현재 locale 에 맞는 카테고리 라벨을 반환합니다.
     *
     * @return string 다국어 카테고리 라벨 (예: "필수 쿠키", "기능 쿠키")
     */
    public function label(): string
    {
        return match ($this) {
            self::Necessary => __('sirsoft-gdpr::messages.consent.category_cookie_necessary'),
            self::Functional => __('sirsoft-gdpr::messages.consent.category_cookie_functional'),
            self::Analytics => __('sirsoft-gdpr::messages.consent.category_cookie_analytics'),
            self::Marketing => __('sirsoft-gdpr::messages.consent.category_cookie_marketing'),
        };
    }

    /**
     * 필수 카테고리 여부를 반환합니다.
     *
     * @return bool Necessary 카테고리이면 true, 그 외 false
     */
    public function isRequired(): bool
    {
        return $this === self::Necessary;
    }

    /**
     * consent_key 문자열에서 Enum 인스턴스를 반환합니다.
     *
     * @param  string  $key  consent_key 값 (예: "cookie_functional")
     * @return self|null     매칭되는 Enum case, 없으면 null
     */
    public static function fromConsentKey(string $key): ?self
    {
        return self::tryFrom($key);
    }

    /**
     * 모든 카테고리의 consent_key 목록을 반환합니다.
     *
     * @return array<int, string> consent_key 문자열 배열 (예: ["cookie_necessary", "cookie_functional", ...])
     */
    public static function allKeys(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 철회 가능한 카테고리 목록을 반환합니다.
     *
     * Necessary 카테고리는 ePrivacy Art.5(3) 면제 대상이라 철회 불가.
     * 그 외 (Functional / Analytics / Marketing) 만 반환합니다.
     *
     * @return array<int, self> 철회 가능한 Enum case 배열
     */
    public static function revocable(): array
    {
        return array_filter(self::cases(), fn (self $c) => ! $c->isRequired());
    }
}
