<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Services\GeoIpService;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;

/**
 * 배송국가 결정 서비스 (MP08 후속 — D2)
 *
 * 사용자(로그인/비로그인)의 배송국가를 결정한다. 우선순위(D2):
 *   1) 유저 저장 배송국가(preferred_shipping_country)
 *   2) GeoIP 국가 추정(코어 GeoIpService)
 *   3) shipping.default_country
 * 각 후보는 "활성 배송가능 국가(available_countries[].is_active)" 에 속할 때만 채택한다.
 *
 * 해외배송 OFF 시 available_countries 활성은 KR 만 남으므로 자연스럽게 KR 로 collapse 한다.
 * 코어 bump 지연(GeoIpService::getCountryByIp 미배포) 시 method_exists 로 degrade 한다.
 */
class ShippingCountryResolver
{
    public function __construct(
        protected EcommerceSettingsService $settings,
        protected EcommerceUserProfileRepositoryInterface $profileRepository,
        protected GeoIpService $geoIpService,
    ) {}

    /**
     * 활성(배송 가능) 국가 코드 목록을 반환합니다.
     *
     * shipping.available_countries 중 is_active=true 인 국가 코드(대문자)를 추린다.
     * 해외배송 OFF 면 KR 만 활성이므로 [KR] 로 collapse 된다(설정 정책에 위임).
     *
     * @return array<int, string> 활성 국가 코드 목록 (예: ['KR', 'US'])
     */
    public function allowedShippingCountryCodes(): array
    {
        $shipping = $this->settings->getSettings('shipping');
        $countries = is_array($shipping['available_countries'] ?? null) ? $shipping['available_countries'] : [];

        $codes = [];
        foreach ($countries as $country) {
            if (($country['is_active'] ?? false) && ! empty($country['code'])) {
                $codes[] = strtoupper((string) $country['code']);
            }
        }

        // 활성 국가가 하나도 없으면 default_country 를 최소 보장(레이아웃/검증 붕괴 방지)
        if ($codes === []) {
            $codes[] = strtoupper((string) ($shipping['default_country'] ?? 'KR'));
        }

        return array_values(array_unique($codes));
    }

    /**
     * 국가 코드가 활성 배송가능 국가인지 검사합니다.
     *
     * @param  string|null  $countryCode  검사할 국가 코드
     * @return bool 활성 배송가능 국가면 true
     */
    public function isAllowed(?string $countryCode): bool
    {
        if ($countryCode === null || $countryCode === '') {
            return false;
        }

        return in_array(strtoupper($countryCode), $this->allowedShippingCountryCodes(), true);
    }

    /**
     * shipping.default_country 를 반환합니다(활성 보장).
     *
     * default_country 가 비활성이면 활성 목록의 첫 국가로 폴백한다.
     *
     * @return string 기본 배송국가 코드
     */
    public function defaultCountry(): string
    {
        $allowed = $this->allowedShippingCountryCodes();
        $default = strtoupper((string) ($this->settings->getSetting('shipping.default_country', 'KR') ?? 'KR'));

        return in_array($default, $allowed, true) ? $default : ($allowed[0] ?? 'KR');
    }

    /**
     * 국가 코드의 현지화된 이름을 반환합니다.
     *
     * shipping.available_countries[].name 의 현재 로케일 값을 사용하며, 없으면 en → 코드 폴백.
     *
     * @param  string|null  $countryCode  국가 코드
     * @return string|null 현지화 국가명 (코드 미존재 시 코드 자체, null 입력 시 null)
     */
    public function countryName(?string $countryCode): ?string
    {
        if ($countryCode === null || $countryCode === '') {
            return null;
        }

        $code = strtoupper($countryCode);
        $shipping = $this->settings->getSettings('shipping');
        $countries = is_array($shipping['available_countries'] ?? null) ? $shipping['available_countries'] : [];
        $locale = app()->getLocale();

        foreach ($countries as $country) {
            if (strtoupper((string) ($country['code'] ?? '')) !== $code) {
                continue;
            }

            $name = $country['name'] ?? null;
            if (is_array($name)) {
                return $name[$locale] ?? $name['en'] ?? $name['ko'] ?? $code;
            }

            return is_string($name) && $name !== '' ? $name : $code;
        }

        return $code;
    }

    /**
     * 사용자의 배송국가를 결정합니다 (D2 우선순위).
     *
     * 저장 국가 → GeoIP → default 순으로 평가하며, 각 후보가 활성 배송가능 국가일 때만 채택한다.
     *
     * @param  int|null  $userId  로그인 사용자 ID (비로그인 시 null)
     * @param  string|null  $ip  요청 IP (GeoIP 추정용, 없으면 GeoIP 단계 스킵)
     * @return string 결정된 배송국가 코드 (항상 활성 국가)
     */
    public function resolve(?int $userId, ?string $ip): string
    {
        // 1) 유저 저장 배송국가
        if ($userId !== null) {
            $saved = $this->profileRepository->getPreferredShippingCountry($userId);
            if ($this->isAllowed($saved)) {
                return strtoupper((string) $saved);
            }
        }

        // 2) GeoIP 추정 (코어 bump 지연 대비 method_exists degrade)
        if ($ip !== null && $ip !== '' && method_exists($this->geoIpService, 'getCountryByIp')) {
            $geo = $this->geoIpService->getCountryByIp($ip);
            if ($this->isAllowed($geo)) {
                return strtoupper((string) $geo);
            }
        }

        // 3) default_country (활성 보장)
        return $this->defaultCountry();
    }
}
