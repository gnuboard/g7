<?php

namespace App\Analytics;

use App\Enums\Analytics\DeviceType;

/**
 * User-Agent 분류기
 *
 * 브라우저·운영체제·기기를 판정합니다. 외부 라이브러리를 쓰지 않는 이유는 이 통계가
 * 필요로 하는 정밀도가 「브라우저 10종·OS 8종·기기 3종」 수준이고, 그 대가로 의존성과
 * 라이선스를 들이는 편이 득이 아니기 때문입니다.
 *
 * 브라우저가 클라이언트 힌트를 보내면 그쪽을 먼저 믿습니다 — User-Agent 문자열은
 * 호환을 위해 다른 브라우저 이름을 섞어 담아서 순서를 잘못 보면 엉뚱하게 잡힙니다.
 */
class UserAgentClassifier
{
    /**
     * 브라우저 판정 순서
     *
     * 순서가 중요합니다. 예를 들어 Edge 의 UA 에는 "Chrome" 이 함께 들어 있어
     * Chrome 을 먼저 보면 Edge 가 영영 잡히지 않습니다. 파생 브라우저를 앞에 둡니다.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    public const BROWSER_PATTERNS = [
        ['KAKAOTALK', 'KakaoTalk'],
        ['NAVER(', 'Naver App'],
        ['Whale', 'Whale'],
        ['SamsungBrowser', 'Samsung Internet'],
        ['Edg', 'Edge'],
        ['OPR', 'Opera'],
        ['Opera', 'Opera'],
        ['Firefox', 'Firefox'],
        ['Chrome', 'Chrome'],
        ['Safari', 'Safari'],
    ];

    /**
     * 운영체제 판정 순서
     *
     * iPadOS 는 iPad 표기를, Android 는 Linux 표기를 함께 담으므로 좁은 쪽을 앞에 둡니다.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    public const OS_PATTERNS = [
        ['iPad', 'iPadOS'],
        ['iPhone', 'iOS'],
        ['iPod', 'iOS'],
        ['Android', 'Android'],
        ['CrOS', 'ChromeOS'],
        ['Windows', 'Windows'],
        ['Macintosh', 'macOS'],
        ['Mac OS X', 'macOS'],
        ['Linux', 'Linux'],
    ];

    /**
     * 클라이언트 힌트 브랜드 → 브라우저 이름
     *
     * 힌트의 brands 에는 위장용 항목("Not;A=Brand" 등)이 섞여 오므로 아는 이름만 고릅니다.
     *
     * @var array<string, string>
     */
    public const HINT_BRANDS = [
        'Microsoft Edge' => 'Edge',
        'Google Chrome' => 'Chrome',
        'Opera' => 'Opera',
        'Samsung Internet' => 'Samsung Internet',
        'Whale' => 'Whale',
        'Firefox' => 'Firefox',
    ];

    /**
     * User-Agent 와 클라이언트 힌트로 방문 환경을 판정합니다.
     *
     * @param  string|null  $userAgent  User-Agent 문자열
     * @param  array<string, mixed>  $clientHints  클라이언트 힌트 (brands, platform, mobile)
     * @return array{browser_name: string, browser_version: string|null, os_name: string, os_version: string|null, device_type: DeviceType}
     *                                                                                                                                      판정 결과
     */
    public static function classify(?string $userAgent, array $clientHints = []): array
    {
        $ua = $userAgent ?? '';

        // 판정표는 확장이 필터(`core.analytics.ua_rules`)로 보탤 수 있다 — 국내 인앱 브라우저나
        // 새 브라우저를 코어 릴리스를 기다리지 않고 잡게 한다.
        $rules = AnalyticsTables::uaRules([
            'browsers' => self::BROWSER_PATTERNS,
            'os' => self::OS_PATTERNS,
            'hint_brands' => self::HINT_BRANDS,
        ]);

        $browser = self::browserFromHints($clientHints, $rules['hint_brands'])
            ?? self::browserFromUserAgent($ua, $rules['browsers']);
        $os = self::osFromHints($clientHints) ?? self::osFromUserAgent($ua, $rules['os']);

        return [
            'browser_name' => $browser['name'],
            'browser_version' => $browser['version'],
            'os_name' => $os['name'],
            'os_version' => $os['version'],
            'device_type' => self::deviceType($ua, $os['name'], $clientHints),
        ];
    }

    /**
     * 클라이언트 힌트에서 브라우저를 판정합니다.
     *
     * 힌트의 brands 에는 위장용 항목("Not;A=Brand" 등)이 섞여 오므로 아는 이름만 고릅니다.
     *
     * @param  array<string, mixed>  $hints  클라이언트 힌트
     * @param  array<string, string>  $known  힌트 브랜드 → 브라우저 이름
     * @return array{name: string, version: string|null}|null 판정 결과 (판정 불가 시 null)
     */
    private static function browserFromHints(array $hints, array $known = self::HINT_BRANDS): ?array
    {
        $brands = $hints['brands'] ?? null;

        if (! is_array($brands)) {
            return null;
        }

        foreach ($brands as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $brand = is_string($entry['brand'] ?? null) ? $entry['brand'] : '';
            $version = is_scalar($entry['version'] ?? null) ? (string) $entry['version'] : null;

            foreach ($known as $needle => $name) {
                if ($brand === $needle) {
                    return ['name' => $name, 'version' => $version];
                }
            }
        }

        // Chromium 계열임은 알지만 어느 브랜드인지 못 고르면 UA 판정에 맡긴다.
        return null;
    }

    /**
     * User-Agent 문자열에서 브라우저를 판정합니다.
     *
     * @param  string  $ua  User-Agent 문자열
     * @param  array<int, array{0: string, 1: string}>  $patterns  판정 순서
     * @return array{name: string, version: string|null} 판정 결과
     */
    private static function browserFromUserAgent(string $ua, array $patterns = self::BROWSER_PATTERNS): array
    {
        if ($ua === '') {
            return ['name' => 'Other', 'version' => null];
        }

        foreach ($patterns as [$needle, $name]) {
            if (! str_contains($ua, $needle)) {
                continue;
            }

            return ['name' => $name, 'version' => self::versionNear($ua, $needle)];
        }

        return ['name' => 'Other', 'version' => null];
    }

    /**
     * 클라이언트 힌트에서 운영체제를 판정합니다.
     *
     * @param  array<string, mixed>  $hints  클라이언트 힌트
     * @return array{name: string, version: string|null}|null 판정 결과 (판정 불가 시 null)
     */
    private static function osFromHints(array $hints): ?array
    {
        $platform = $hints['platform'] ?? null;

        if (! is_string($platform) || $platform === '') {
            return null;
        }

        $map = [
            'Windows' => 'Windows',
            'macOS' => 'macOS',
            'Android' => 'Android',
            'iOS' => 'iOS',
            'Chrome OS' => 'ChromeOS',
            'Chromium OS' => 'ChromeOS',
            'Linux' => 'Linux',
        ];

        $name = $map[$platform] ?? null;

        if ($name === null) {
            return null;
        }

        $version = is_scalar($hints['platformVersion'] ?? null) ? (string) $hints['platformVersion'] : null;

        return ['name' => $name, 'version' => $version];
    }

    /**
     * User-Agent 문자열에서 운영체제를 판정합니다.
     *
     * @param  string  $ua  User-Agent 문자열
     * @param  array<int, array{0: string, 1: string}>  $patterns  판정 순서
     * @return array{name: string, version: string|null} 판정 결과
     */
    private static function osFromUserAgent(string $ua, array $patterns = self::OS_PATTERNS): array
    {
        if ($ua === '') {
            return ['name' => 'Other', 'version' => null];
        }

        foreach ($patterns as [$needle, $name]) {
            if (! str_contains($ua, $needle)) {
                continue;
            }

            return ['name' => $name, 'version' => self::osVersion($ua, $name)];
        }

        return ['name' => 'Other', 'version' => null];
    }

    /**
     * 기기 종류를 판정합니다.
     *
     * @param  string  $ua  User-Agent 문자열
     * @param  string  $osName  판정된 운영체제 이름
     * @param  array<string, mixed>  $hints  클라이언트 힌트
     * @return DeviceType 기기 종류
     */
    private static function deviceType(string $ua, string $osName, array $hints): DeviceType
    {
        if ($osName === 'iPadOS' || str_contains($ua, 'Tablet')) {
            return DeviceType::Tablet;
        }

        // 안드로이드는 "Mobile" 표기가 없으면 태블릿으로 본다 (구글 권고 판정).
        if ($osName === 'Android') {
            return str_contains($ua, 'Mobile') ? DeviceType::Mobile : DeviceType::Tablet;
        }

        if ($osName === 'iOS') {
            return DeviceType::Mobile;
        }

        if (array_key_exists('mobile', $hints)) {
            return $hints['mobile'] ? DeviceType::Mobile : DeviceType::Desktop;
        }

        if (str_contains($ua, 'Mobile')) {
            return DeviceType::Mobile;
        }

        if (in_array($osName, ['Windows', 'macOS', 'Linux', 'ChromeOS'], true)) {
            return DeviceType::Desktop;
        }

        return DeviceType::Other;
    }

    /**
     * 표기 바로 뒤에 붙은 버전의 주 번호를 뽑습니다.
     *
     * @param  string  $ua  User-Agent 문자열
     * @param  string  $needle  브라우저 표기
     * @return string|null 버전 (없으면 null)
     */
    private static function versionNear(string $ua, string $needle): ?string
    {
        $escaped = preg_quote($needle, '/');

        if (preg_match('/'.$escaped.'[\/ ]?([0-9]+)/', $ua, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * 운영체제 버전을 뽑습니다.
     *
     * @param  string  $ua  User-Agent 문자열
     * @param  string  $osName  판정된 운영체제 이름
     * @return string|null 버전 (없으면 null)
     */
    private static function osVersion(string $ua, string $osName): ?string
    {
        $pattern = match ($osName) {
            'Windows' => '/Windows NT ([0-9.]+)/',
            'macOS' => '/Mac OS X ([0-9_.]+)/',
            'Android' => '/Android ([0-9.]+)/',
            'iOS', 'iPadOS' => '/OS ([0-9_]+)/',
            default => null,
        };

        if ($pattern === null || preg_match($pattern, $ua, $m) !== 1) {
            return null;
        }

        return str_replace('_', '.', $m[1]);
    }
}
