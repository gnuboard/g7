<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Facades\Log;

class GeoIpService
{
    private ?Reader $reader = null;

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
     * MaxMind DB에서 타임존을 조회합니다.
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
     */
    public function isAvailable(): bool
    {
        if (! config('geoip.enabled', false)) {
            return false;
        }

        return file_exists(config('geoip.database_path'));
    }
}
