<?php

namespace Modules\Sirsoft\Ecommerce\Services;

/**
 * 가입 시 통화 결정 서비스 (A4 — D-SIGNUP)
 *
 * 유저 locale 을 환경설정의 통화별 언어(currencies[].locales) 매핑과 대조해 부여 통화를 결정한다.
 *
 * 매칭 우선순위(D-SIGNUP, 정책 확정):
 *   1) locale 이 매칭되는 통화가 1개 → 그 통화
 *   2) 여러 통화에 중복 매칭 → 매칭된 통화 중 is_default 통화 우선
 *   3) 매칭 없음/모호 → is_default 통화 → 그래도 없으면 default_currency 폴백
 */
class SignupCurrencyResolver
{
    public function __construct(
        protected EcommerceSettingsService $settings
    ) {}

    /**
     * locale 에 대응하는 부여 통화 코드를 결정합니다.
     *
     * @param  string|null  $locale  유저 locale (예: 'ja', 'en')
     * @return string 부여할 통화 코드 (ISO 4217)
     */
    public function resolve(?string $locale): string
    {
        $lc = $this->settings->getSettings('language_currency');
        $currencies = is_array($lc['currencies'] ?? null) ? $lc['currencies'] : [];
        $defaultCurrency = $lc['default_currency'] ?? 'KRW';

        // locale 정규화 (ko-KR → ko)
        $normalized = $locale ? strtolower(explode('-', explode('_', $locale)[0])[0]) : null;

        // 1) locale 매칭 통화 수집
        $matched = [];
        if ($normalized !== null && $normalized !== '') {
            foreach ($currencies as $currency) {
                $locales = array_map(
                    fn ($l) => strtolower((string) $l),
                    is_array($currency['locales'] ?? null) ? $currency['locales'] : []
                );
                if (in_array($normalized, $locales, true)) {
                    $matched[] = $currency;
                }
            }
        }

        // 2) 단일 매칭 → 그 통화
        if (count($matched) === 1) {
            return $matched[0]['code'] ?? $defaultCurrency;
        }

        // 3) 중복 매칭 → 매칭된 통화 중 is_default 우선
        if (count($matched) > 1) {
            foreach ($matched as $currency) {
                if ($currency['is_default'] ?? false) {
                    return $currency['code'] ?? $defaultCurrency;
                }
            }

            // 매칭됐으나 is_default 없음 → 첫 매칭(결정적) 대신 default_currency 폴백(모호 회피)
            return $defaultCurrency;
        }

        // 4) 매칭 없음 → is_default 통화 → default_currency 폴백
        foreach ($currencies as $currency) {
            if ($currency['is_default'] ?? false) {
                return $currency['code'] ?? $defaultCurrency;
            }
        }

        return $defaultCurrency;
    }

    /**
     * 통화 코드가 환경설정에 등록된(선택 가능한) 통화인지 검사합니다.
     *
     * 가입폼이 제출한 통화의 유효성 검증에 사용한다. 기본 통화이거나 환율이 설정된
     * 통화만 유효(셀렉터 노출 규칙 §5 와 일치).
     *
     * @param  string|null  $code  검사할 통화 코드
     * @return bool 등록된 선택 가능 통화면 true
     */
    public function isRegistered(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        $lc = $this->settings->getSettings('language_currency');
        $currencies = is_array($lc['currencies'] ?? null) ? $lc['currencies'] : [];

        foreach ($currencies as $currency) {
            if (($currency['code'] ?? null) !== $code) {
                continue;
            }

            return ($currency['is_default'] ?? false) || (float) ($currency['exchange_rate'] ?? 0) > 0;
        }

        return false;
    }
}
