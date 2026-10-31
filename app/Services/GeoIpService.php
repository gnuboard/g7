<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Facades\Log;

class GeoIpService
{
    private ?Reader $reader = null;

    /**
     * GeoIP 서비스를 생성합니다.
     *
     * @param  CacheInterface  $cache  조회 결과 캐시
     */
    public function __construct(private CacheInterface $cache) {}

    /**
     * IP 주소로부터 타임존을 조회합니다.
     *
     * @param  string  $ip  IP 주소
     * @param  array  $supportedTimezones  지원하는 타임존 목록
     * @return string|null 타임존 문자열 또는 null
     */
    public function getTimezoneByIp(string $ip, array $supportedTimezones): ?string
    {
        // GeoIP 비활성화 상태
        if (! config('geoip.enabled', false)) {
            return null;
        }

        // 캐시 확인 (g7_core_settings 우선, fallback config)
        $cacheEnabled = (bool) g7_core_settings('cache.geoip_enabled', config('geoip.cache.enabled', true));
        $cacheKey = 'geoip.timezone.'.$ip;

        if ($cacheEnabled) {
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                // 빈 문자열은 이전 조회 실패를 의미
                if ($cached === '') {
                    return null;
                }

                // 캐시된 값이 지원 타임존에 포함되면 반환
                return in_array($cached, $supportedTimezones) ? $cached : null;
            }
        }

        // GeoIP 조회
        $timezone = $this->lookupTimezone($ip);

        // 캐시 저장 (조회 실패 시에도 빈 문자열 저장하여 반복 조회 방지)
        if ($cacheEnabled) {
            $ttl = (int) g7_core_settings('cache.geoip_ttl', config('geoip.cache.ttl', 86400));
            $this->cache->put($cacheKey, $timezone ?? '', $ttl);
        }

        // 지원 타임존 확인
        if ($timezone && in_array($timezone, $supportedTimezones)) {
            return $timezone;
        }

        return null;
    }

    /**
     * IP 주소로부터 국가 코드(ISO 3166-1 alpha-2)를 조회합니다.
     *
     * getTimezoneByIp 미러: 동일한 MaxMind GeoLite2-City Reader 를 재사용한다.
     * 비로그인 사용자의 기본 배송국가 추정 등에 사용된다(커머스 ShippingCountryResolver).
     *
     * @param  string  $ip  IP 주소
     * @return string|null 국가 코드 (대문자 2자, 조회 실패 시 null)
     */
    public function getCountryByIp(string $ip): ?string
    {
        // GeoIP 비활성화 상태
        if (! config('geoip.enabled', false)) {
            return null;
        }

        // 캐시 확인 (g7_core_settings 우선, fallback config)
        $cacheEnabled = (bool) g7_core_settings('cache.geoip_enabled', config('geoip.cache.enabled', true));
        $cacheKey = 'geoip.country.'.$ip;

        if ($cacheEnabled) {
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                // 빈 문자열은 이전 조회 실패를 의미 (음성 캐시)
                return $cached === '' ? null : $cached;
            }
        }

        // GeoIP 조회
        $country = $this->lookupCountry($ip);

        // 캐시 저장 (조회 실패 시에도 빈 문자열 저장하여 반복 조회 방지)
        if ($cacheEnabled) {
            $ttl = (int) g7_core_settings('cache.geoip_ttl', config('geoip.cache.ttl', 86400));
            $this->cache->put($cacheKey, $country ?? '', $ttl);
        }

        return $country;
    }

    /**
     * IP 주소로부터 국가·지역·도시를 한 번에 조회합니다.
     *
     * getCountryByIp 가 국가 코드만 돌려주는데, 방문자 통계의 지역 화면은 지역·도시까지
     * 필요합니다. 같은 Reader 가 이미 그 값을 읽어 오므로 세 번 조회하는 대신 한 번에
     * 받습니다. 캐시 키만 다르고 정책(활성 여부·TTL·음성 캐시)은 국가 조회와 같습니다.
     *
     * `city_names` 는 로케일별 도시명입니다 — 지역 화면이 보는 사람의 언어로 도시를
     * 보여 줄 때 씁니다(사전에 없는 언어는 호출한 쪽이 영문으로 되돌립니다).
     *
     * @param  string  $ip  IP 주소
     * @return array{country: string|null, region_code: string|null, region: string|null, city: string|null, city_names: array<string, string>}|null
     *                                                                                                                                               조회 결과 (GeoIP 비활성·DB 부재·조회 실패 시 null)
     */
    public function getLocationByIp(string $ip): ?array
    {
        if (! config('geoip.enabled', false)) {
            return null;
        }

        $cacheEnabled = (bool) g7_core_settings('cache.geoip_enabled', config('geoip.cache.enabled', true));
        $cacheKey = 'geoip.location.'.$ip;

        if ($cacheEnabled) {
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                // 빈 배열은 이전 조회 실패를 의미 (음성 캐시)
                return $cached === [] ? null : $cached;
            }
        }

        $location = $this->lookupLocation($ip);

        if ($cacheEnabled) {
            $ttl = (int) g7_core_settings('cache.geoip_ttl', config('geoip.cache.ttl', 86400));
            $this->cache->put($cacheKey, $location ?? [], $ttl);
        }

        return $location;
    }

    /**
     * MaxMind DB에서 국가·지역·도시를 조회합니다.
     *
     * @param  string  $ip  IP 주소
     * @return array{country: string|null, region_code: string|null, region: string|null, city: string|null, city_names: array<string, string>}|null
     *                                                                                                                                               조회 결과 (실패 시 null)
     */
    private function lookupLocation(string $ip): ?array
    {
        try {
            $reader = $this->getReader();

            if ($reader === null) {
                return null;
            }

            $record = $reader->city($ip);
            $isoCode = $record->country->isoCode;

            // 국가조차 못 읽으면 지역·도시도 의미가 없다.
            if ($isoCode === null) {
                return null;
            }

            // 지역은 나라마다 계층 수가 달라 가장 큰 단위(첫 번째)를 쓴다.
            $subdivision = $record->subdivisions[0] ?? null;

            return [
                'country' => strtoupper($isoCode),
                'region_code' => $subdivision?->isoCode,
                'region' => $subdivision?->name,
                'city' => $record->city->name,
                'city_names' => $record->city->names ?? [],
            ];
        } catch (AddressNotFoundException $e) {
            // IP를 찾을 수 없음 (정상적인 케이스 — 사설 IP·미등록 대역)
            return null;
        } catch (\Exception $e) {
            Log::warning('GeoIP location lookup failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * MaxMind DB에서 국가 코드를 조회합니다.
     *
     * @param  string  $ip  IP 주소
     * @return string|null 국가 코드 (대문자 2자, 조회 실패 시 null)
     */
    private function lookupCountry(string $ip): ?string
    {
        try {
            $reader = $this->getReader();

            if ($reader === null) {
                return null;
            }

            $isoCode = $reader->city($ip)->country->isoCode;

            return $isoCode !== null ? strtoupper($isoCode) : null;
        } catch (AddressNotFoundException $e) {
            // IP를 찾을 수 없음 (정상적인 케이스)
            return null;
        } catch (\Exception $e) {
            Log::warning('GeoIP country lookup failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * MaxMind DB에서 타임존을 조회합니다.
     *
     * @param  string  $ip  IP 주소
     * @return string|null 타임존 문자열 (조회 실패 시 null)
     */
    private function lookupTimezone(string $ip): ?string
    {
        try {
            $reader = $this->getReader();

            if ($reader === null) {
                return null;
            }

            $record = $reader->city($ip);

            return $record->location->timeZone;
        } catch (AddressNotFoundException $e) {
            // IP를 찾을 수 없음 (정상적인 케이스)
            return null;
        } catch (\Exception $e) {
            Log::warning('GeoIP lookup failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * MaxMind Reader 인스턴스를 반환합니다.
     *
     * @return Reader|null Reader (DB 파일 부재·초기화 실패 시 null)
     */
    private function getReader(): ?Reader
    {
        if ($this->reader !== null) {
            return $this->reader;
        }

        $dbPath = config('geoip.database_path');

        if (! file_exists($dbPath)) {
            return null;
        }

        try {
            $this->reader = new Reader($dbPath);

            return $this->reader;
        } catch (\Exception $e) {
            Log::error('Failed to initialize GeoIP reader', [
                'path' => $dbPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * GeoIP 데이터베이스가 사용 가능한지 확인합니다.
     *
     * @return bool GeoIP 활성화 및 DB 파일 존재 여부
     */
    public function isAvailable(): bool
    {
        if (! config('geoip.enabled', false)) {
            return false;
        }

        return file_exists(config('geoip.database_path'));
    }
}
