<?php

namespace Plugins\Sirsoft\Gdpr\Services;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Gdpr\Enums\CookieCategory;

/**
 * 쿠키 카테고리 설정 서비스
 *
 * cookie_categories 설정 JSON을 파싱하여 카테고리별 라벨/필수 여부를 제공합니다.
 * 카테고리 키는 4종 고정(necessary/functional/analytics/marketing) — CookieCategory enum 이 SSoT.
 */
class CookieCategoryService
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'sirsoft-gdpr';

    /**
     * CookieCategoryService 생성자
     *
     * @param PluginSettingsService $pluginSettings 플러그인 설정 서비스
     */
    public function __construct(
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 모든 카테고리 정의를 반환합니다 (운영자 편집 라벨 포함).
     *
     * @return array<int, array{key: string, required: bool, label: array, description: array}>
     */
    public function getCategories(): array
    {
        $raw = $this->pluginSettings->get(self::PLUGIN_ID, 'cookie_categories', '[]');
        $categories = json_decode((string) $raw, true);

        if (! is_array($categories) || empty($categories)) {
            return $this->getDefaultCategories();
        }

        return $categories;
    }

    /**
     * 필수 카테고리만 반환합니다.
     *
     * @return array<int, array{key: string, required: bool, label: array, description: array}>
     */
    public function getRequiredCategories(): array
    {
        return array_values(array_filter(
            $this->getCategories(),
            fn (array $c) => (bool) ($c['required'] ?? false)
        ));
    }

    /**
     * 선택 가능(철회 가능) 카테고리만 반환합니다.
     *
     * @return array<int, array{key: string, required: bool, label: array, description: array}>
     */
    public function getOptionalCategories(): array
    {
        return array_values(array_filter(
            $this->getCategories(),
            fn (array $c) => ! (bool) ($c['required'] ?? false)
        ));
    }

    /**
     * 카테고리 키가 필수 항목인지 확인합니다.
     *
     * @param string $consentKey 동의 항목 키 (예: cookie_necessary)
     * @return bool
     */
    public function isRequired(string $consentKey): bool
    {
        $category = CookieCategory::fromConsentKey($consentKey);

        if ($category !== null) {
            return $category->isRequired();
        }

        $categories = $this->getCategories();
        foreach ($categories as $cat) {
            if (($cat['key'] ?? null) === $this->stripCookiePrefix($consentKey)) {
                return (bool) ($cat['required'] ?? false);
            }
        }

        return false;
    }

    /**
     * 모든 consent_key 목록을 반환합니다 (cookie_ 접두사 포함).
     *
     * @return array<int, string>
     */
    public function getAllConsentKeys(): array
    {
        return array_map(
            fn (array $c) => 'cookie_' . ($c['key'] ?? ''),
            $this->getCategories()
        );
    }

    /**
     * cookie_ 접두사를 제거합니다.
     *
     * @param string $consentKey 동의 항목 키
     * @return string
     */
    private function stripCookiePrefix(string $consentKey): string
    {
        if (str_starts_with($consentKey, 'cookie_')) {
            return substr($consentKey, strlen('cookie_'));
        }

        return $consentKey;
    }

    /**
     * consent_key 에 대응하는 사람 친화 라벨을 현재 locale 기준으로 반환합니다.
     *
     * 마이페이지 「내 동의 현황」 등에서 cookie_analytics 같은 raw key 대신
     * 운영자가 카탈로그에 등록한 다국어 라벨을 표시하기 위함. 카탈로그에 매칭되는
     * 카테고리가 없으면 null 반환 — 호출 측이 fallback 으로 raw key 표시.
     *
     * @param string $consentKey 동의 항목 키 (예: cookie_analytics)
     * @return string|null
     */
    public function getLabelForKey(string $consentKey): ?string
    {
        $bareKey = $this->stripCookiePrefix($consentKey);
        $locale = (string) app()->getLocale();
        $fallbackLocale = (string) config('app.fallback_locale', 'en');

        foreach ($this->getCategories() as $cat) {
            if (($cat['key'] ?? null) !== $bareKey) {
                continue;
            }

            $label = $cat['label'] ?? null;
            if (is_array($label)) {
                return $label[$locale] ?? $label[$fallbackLocale] ?? reset($label) ?: null;
            }

            return is_string($label) && $label !== '' ? $label : null;
        }

        return null;
    }

    /**
     * consent_key 에 대응하는 사용자 친화 설명을 현재 locale 기준으로 반환합니다.
     *
     * 마이페이지 「내 동의 현황」 의 동의 항목 컬럼에서 영문 식별자 대신 회원이
     * 즉시 이해할 수 있는 한 줄 설명을 노출하기 위함. 카탈로그에 매칭되는 카테고리가
     * 없거나 설명이 비어 있으면 null 반환 — 호출 측이 fallback 처리.
     *
     * @param string $consentKey 동의 항목 키 (예: cookie_analytics)
     * @return string|null
     */
    public function getDescriptionForKey(string $consentKey): ?string
    {
        $bareKey = $this->stripCookiePrefix($consentKey);
        $locale = (string) app()->getLocale();
        $fallbackLocale = (string) config('app.fallback_locale', 'en');

        foreach ($this->getCategories() as $cat) {
            if (($cat['key'] ?? null) !== $bareKey) {
                continue;
            }

            $description = $cat['description'] ?? null;
            if (is_array($description)) {
                $resolved = $description[$locale] ?? $description[$fallbackLocale] ?? reset($description);

                return is_string($resolved) && $resolved !== '' ? $resolved : null;
            }

            return is_string($description) && $description !== '' ? $description : null;
        }

        return null;
    }

    /**
     * 기본 카테고리 정의를 반환합니다 (설정 부재 시 폴백).
     *
     * @return array<int, array{key: string, required: bool, label: array, description: array}>
     */
    private function getDefaultCategories(): array
    {
        return [
            [
                'key' => 'necessary',
                'required' => true,
                'label' => ['ko' => '필수 쿠키', 'en' => 'Strictly Necessary'],
                'description' => [
                    // g7_locale 은 ePrivacy Art.5(3) + WP29 Opinion 04/2012 §3.6 의 user-initiated preference
                    // 예외 (사용자 가입 시 명시 선택) 로 strictly necessary 분류. 사용자 안내에 명시.
                    'ko' => '세션·CSRF·로그인 토큰, 장바구니 식별자, 사용자가 가입 시 선택한 언어 설정, 쿠키 동의 기록 등 사이트 운영에 반드시 필요한 항목입니다. 비활성화할 수 없습니다.',
                    'en' => 'Strictly necessary for site operation: session/CSRF/auth tokens, shopping basket identifier, user-selected language preference at registration, cookie consent record. Cannot be disabled.',
                ],
            ],
            [
                // Phase 1: functional 카테고리 신설 — ICO/CNIL 4분류 체계 부합.
                // 자체 functional 키 (다크모드/통화) + 외부 functional 도구 (Crisp, Intercom 등) 분류 영역.
                'key' => 'functional',
                'required' => false,
                'label' => ['ko' => '기능 쿠키', 'en' => 'Functional'],
                'description' => [
                    'ko' => '사용자 선호도(다크모드, 표시 통화 등)를 기억하는 쿠키입니다. 거부 시 매 방문마다 기본값으로 표시됩니다.',
                    'en' => 'Cookies that remember user preferences such as dark mode and display currency. If declined, defaults are used on every visit.',
                ],
            ],
            [
                'key' => 'analytics',
                'required' => false,
                'label' => ['ko' => '분석 쿠키', 'en' => 'Analytics'],
                'description' => [
                    'ko' => '방문자가 사이트를 어떻게 이용하는지 익명으로 측정해 더 나은 서비스를 만드는 데 사용됩니다. (예: Google Analytics, Hotjar)',
                    'en' => 'Used to anonymously measure how visitors use the site so we can improve it. (e.g. Google Analytics, Hotjar)',
                ],
            ],
            [
                'key' => 'marketing',
                'required' => false,
                'label' => ['ko' => '마케팅 쿠키', 'en' => 'Marketing'],
                'description' => [
                    'ko' => '관심사에 맞는 광고를 보여주거나, 광고가 얼마나 효과적이었는지 측정하는 데 사용됩니다. SNS 영상 임베드 등도 포함됩니다. (예: Facebook 픽셀, Google 광고, YouTube 영상)',
                    'en' => 'Used to show ads relevant to your interests, measure ad performance, and embed social media content. (e.g. Facebook Pixel, Google Ads, YouTube embeds)',
                ],
            ],
        ];
    }
}
