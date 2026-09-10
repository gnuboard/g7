<?php

namespace App\Support;

use App\Extension\Storage\CoreStorageDriver;
use Illuminate\Support\Facades\Log;

/**
 * 사이트 자산 host 해석기
 *
 * "이 사이트가 스스로 자산을 내보내는 host" 를 한 지점에서 해석합니다. 저장측 규칙
 * (`App\Rules\NoExternalUrls`)이 절대 URL 을 외부로 차단할 때, 서버가 스스로 발급하는
 * 주소 — 레이아웃 첨부 API 의 프록시 서빙 URL·운영자가 선언한 공개 자산 디스크의 직접
 * URL — 까지 외부로 오판하면 업로드는 되는데 저장이 422 로 끝납니다. 그 오판을 막는
 * 근거가 되는 host 목록입니다.
 *
 * 근거는 둘뿐입니다:
 *   1. `config('app.url')` 의 host — 운영자가 설정한 사이트 주소
 *   2. `PublicAssetDisk::resolve()` 가 돌려주는 디스크의 기준 URL host — 운영자가
 *      "익명 읽기가 되는 저장소" 라고 명시 선언한 곳
 *
 * 요청의 `Host` 헤더는 근거로 쓰지 않습니다 — 신뢰 프록시 밖에서는 위조 가능하고, 위조된
 * host 가 허용되면 레이아웃에 임의 외부 주소를 실을 수 있습니다. 디스크의 `url` 설정
 * 유무도 근거가 아닙니다(`PublicAssetDisk` 와 같은 이유 — "URL 을 만들 수 있다" 는
 * "공개다" 가 아닙니다).
 *
 * 판정은 접두 문자열 비교가 아니라 브라우저와 같은 정규화(`TrustedScriptHosts::
 * normalizeForOriginCheck`) 뒤의 host 등가 비교입니다. 접두 비교는
 * `https://shop.example.test.evil.com` · `https://shop.example.test@evil.com` 을 통과시킵니다.
 */
class SiteAssetHosts
{
    /**
     * 사이트 자산 host 목록을 해석합니다.
     *
     * memoize 하지 않습니다 — `app.url`·`core.storage.public_asset_disk` 는 런타임에
     * 바뀔 수 있습니다(관리자 환경설정 저장, 테스트의 Config::set). 한 검증 안에서의
     * 반복 호출은 호출측이 캐시합니다.
     *
     * @return array<int, string> 소문자 host 목록 (중복 제거, 해석 불가면 빈 배열)
     */
    public static function hosts(): array
    {
        $hosts = [];

        $appHost = TrustedScriptHosts::hostOf((string) config('app.url', ''));
        if ($appHost !== null) {
            $hosts[] = $appHost;
        }

        $disk = PublicAssetDisk::resolve();
        if ($disk !== null) {
            $diskHost = self::publicAssetDiskHost($disk);
            if ($diskHost !== null && ! in_array($diskHost, $hosts, true)) {
                $hosts[] = $diskHost;
            }
        }

        return $hosts;
    }

    /**
     * http/https 절대 URL 이고 그 host 가 사이트 자산 host 이면 true 를 돌려줍니다.
     *
     * 경로만 있는 값(`/api/...`)은 host 가 없어 이 판정의 대상이 아니고(호출측이 경로
     * 규칙으로 다룹니다), protocol-relative(`//host/...`)와 http/https 밖의 스킴은 host 가
     * 같아도 false 입니다 — 그 축은 종전 판정을 그대로 둡니다.
     *
     * @param  string  $url  검사 대상 URL
     * @param  array<int, string>|null  $hosts  host 목록 (미지정 시 self::hosts())
     * @return bool 사이트 자산 host 의 http(s) 절대 URL 이면 true
     */
    public static function isSiteAssetUrl(string $url, ?array $hosts = null): bool
    {
        $normalized = TrustedScriptHosts::normalizeForOriginCheck(trim($url));

        if (preg_match('#^https?://#i', $normalized) !== 1) {
            return false;
        }

        $host = TrustedScriptHosts::hostOf($normalized);

        if ($host === null) {
            return false;
        }

        $hosts ??= self::hosts();

        return in_array($host, $hosts, true);
    }

    /**
     * 공개 자산 디스크의 기준 URL host 를 해석합니다.
     *
     * 첨부 API 가 직접 URL 을 만드는 것과 같은 경로(`CoreStorageDriver::url()` — 디스크 url
     * 설정과 `core.storage.filter_url` 훅을 모두 거친다)로 빈 경로의 URL 을 만들어 host 만
     * 취합니다. 설정값을 직접 읽으면 훅이 바꾼 host 를 놓칩니다.
     *
     * @param  string  $disk  PublicAssetDisk::resolve() 가 돌려준 디스크
     * @return string|null 소문자 host (직접 URL 을 만들 수 없는 디스크면 null)
     */
    private static function publicAssetDiskHost(string $disk): ?string
    {
        try {
            // StorageInterface 는 컨텍스트 바인딩만 있어 컨테이너로 못 받는다 (ExtensionBundleService 와 같은 선례).
            $base = (new CoreStorageDriver($disk))->url('', '');
        } catch (\Throwable $e) {
            Log::warning('SiteAssetHosts: 공개 자산 디스크 기준 URL 해석 실패 - '.$e->getMessage(), ['disk' => $disk]);

            return null;
        }

        return is_string($base) ? TrustedScriptHosts::hostOf($base) : null;
    }
}
