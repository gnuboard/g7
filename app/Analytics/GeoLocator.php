<?php

namespace App\Analytics;

use App\Services\GeoIpService;

/**
 * 지역 판정
 *
 * GeoIP 가 켜져 있으면 IP 로 국가·지역·도시를 찾고, 꺼져 있으면 브라우저 언어의
 * 지역 서브태그로 국가만 추정합니다(`ko-KR` → KR). 추정한 국가는 지역·도시가
 * 비어 있다는 점으로 실측과 구분됩니다 — 화면이 그 차이를 안내합니다.
 *
 * 마스킹을 하기 전에 조회할지는 운영자가 고릅니다. 마스킹한 IP 로 조회하면 개인을
 * 특정할 여지가 더 줄지만, 지운 옥텟이 많을수록 지역이 부정확해집니다.
 */
class GeoLocator
{
    /**
     * 지역 판정기를 생성합니다.
     *
     * @param  GeoIpService  $geoIp  GeoIP 조회 서비스
     */
    public function __construct(private readonly GeoIpService $geoIp) {}

    /**
     * 방문의 지역 정보를 판정합니다.
     *
     * @param  string|null  $rawIp  원본 IP
     * @param  string|null  $maskedIp  마스킹된 IP
     * @param  string|null  $browserLanguage  브라우저 언어 (GeoIP 꺼짐 시 국가 추정용)
     * @param  bool  $geoBeforeMask  마스킹 전 IP 로 조회할지 여부
     * @return array{country_code: string|null, region_code: string|null, city: string|null} 지역 정보
     */
    public function locate(
        ?string $rawIp,
        ?string $maskedIp,
        ?string $browserLanguage,
        bool $geoBeforeMask,
    ): array {
        $lookupIp = $geoBeforeMask ? ($rawIp ?? $maskedIp) : ($maskedIp ?? $rawIp);

        if ($lookupIp !== null) {
            $location = $this->geoIp->getLocationByIp($lookupIp);

            if ($location !== null) {
                return [
                    'country_code' => $location['country'] ?? null,
                    'region_code' => self::trimOrNull($location['region_code'] ?? null, 10),
                    'city' => self::trimOrNull($location['city'] ?? null, 100),
                ];
            }
        }

        // GeoIP 가 없어도 국가는 「가능한 범위」에서 채운다 — 지역·도시는 비운다.
        return [
            'country_code' => self::countryFromLanguage($browserLanguage),
            'region_code' => null,
            'city' => null,
        ];
    }

    /**
     * 브라우저 언어에서 국가 코드를 추정합니다.
     *
     * `ko-KR`·`ja-JP` 처럼 지역 서브태그가 있을 때만 씁니다. `ko` 처럼 언어만 있으면
     * 그 언어를 쓰는 나라가 여럿일 수 있어 추정하지 않습니다.
     *
     * @param  string|null  $browserLanguage  브라우저 언어
     * @return string|null 국가 코드 (추정 불가 시 null)
     */
    public static function countryFromLanguage(?string $browserLanguage): ?string
    {
        if ($browserLanguage === null || $browserLanguage === '') {
            return null;
        }

        // "ko-KR,ko;q=0.9,en;q=0.8" 처럼 목록으로 올 수 있다 — 첫 항목만 본다.
        $first = trim(explode(',', $browserLanguage)[0]);
        $first = trim(explode(';', $first)[0]);

        if (preg_match('/^[A-Za-z]{2,3}[-_]([A-Za-z]{2})$/', $first, $m) !== 1) {
            return null;
        }

        return strtoupper($m[1]);
    }

    /**
     * 값을 다듬고 길이를 맞춥니다.
     *
     * @param  string|null  $value  원본 값
     * @param  int  $maxLength  최대 길이
     * @return string|null 다듬은 값 (비었으면 null)
     */
    private static function trimOrNull(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
