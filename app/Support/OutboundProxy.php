<?php

namespace App\Support;

/**
 * 코어 아웃바운드 HTTP 프록시 판정 규약.
 *
 * 운영자가 환경설정(고급 탭)에 지정한 프록시를 코어의 모든 외부 HTTP 호출에 적용할지 결정한다.
 * 프록시가 걸리면 결제 승인 요청, 코어 업데이트 조회, GeoIP 내려받기, 알림 웹훅 등 코어가
 * 바깥으로 내보내는 트래픽 전부가 그 서버를 경유한다 — 그래서 판정을 이 클래스 한 곳에 모으고,
 * 설정 주입 지점(SettingsServiceProvider)과 적용 지점(AppServiceProvider)은 결과만 소비한다.
 *
 * 게이트는 디버그 모드다. 디버그 모드가 꺼져 있으면 저장된 값이 남아 있어도 프록시를 적용하지
 * 않는다 — 관리자 화면에서 입력칸을 감추는 것만으로는 저장 API 를 직접 호출하는 경로를 막지
 * 못하므로, 화면이 아니라 이 판정이 실질 게이트다.
 *
 * @since 7.0.8
 */
final class OutboundProxy
{
    /**
     * 프록시 URL 로 허용하는 스킴.
     *
     * cURL 이 프록시로 이해하는 형태만 받는다. `socks5h` / `socks4a` 는 이름 해석을 프록시
     * 서버에 맡기는 형태로, 로컬에서 해석되지 않는 원격 전용 호스트를 다룰 때 필요하다.
     */
    public const ALLOWED_SCHEMES = [
        'http',
        'https',
        'socks4',
        'socks4a',
        'socks5',
        'socks5h',
    ];

    /**
     * 디버그 설정으로부터 적용할 프록시 옵션을 판정합니다.
     *
     * 반환값은 Guzzle 의 `proxy` 옵션 형태이며, 적용하지 않을 때는 null 입니다.
     *
     * @param  array<string, mixed>  $debugSettings  debug 카테고리 설정 배열
     * @return array{http: string, https: string, no: array<int, string>}|null 적용할 프록시 옵션 (미적용 시 null)
     */
    public static function resolve(array $debugSettings): ?array
    {
        // 게이트: 디버그 모드가 꺼져 있으면 저장값이 있어도 적용하지 않는다.
        if (empty($debugSettings['mode'])) {
            return null;
        }

        return self::options(
            $debugSettings['outbound_proxy'] ?? null,
            $debugSettings['outbound_proxy_bypass'] ?? []
        );
    }

    /**
     * 프록시 주소와 예외 목록을 Guzzle 의 `proxy` 옵션 형태로 조립합니다.
     *
     * 게이트(디버그 모드)는 보지 않습니다 — 저장 전 연결 테스트처럼 아직 적용 대상이 아닌
     * 값을 그대로 검사해야 하는 경로가 있기 때문입니다. 게이트 판정은 `resolve()` 가 맡습니다.
     *
     * 조립을 이 한 곳에 모으는 이유는 정규화 때문입니다. 테스트가 손으로 배열을 만들면 예외
     * 목록의 공백·빈 항목·중복 처리가 실제 적용분과 어긋나, 저장 전에 확인한 구성과 저장 후
     * 적용되는 구성이 달라집니다.
     *
     * @param  mixed  $url  프록시 주소
     * @param  mixed  $bypass  예외 목록
     * @return array{http: string, https: string, no: array<int, string>}|null 조립된 옵션 (주소가 부적합하면 null)
     */
    public static function options(mixed $url, mixed $bypass = []): ?array
    {
        $normalized = self::normalizeUrl($url);

        if ($normalized === null) {
            return null;
        }

        return [
            'http' => $normalized,
            'https' => $normalized,
            'no' => self::normalizeBypass($bypass),
        ];
    }

    /**
     * 프록시 URL 이 적용 가능한 형태인지 판정합니다.
     *
     * @param  mixed  $value  검사할 값
     * @return bool 허용 스킴과 호스트를 갖춘 URL 이면 true
     */
    public static function isValidUrl(mixed $value): bool
    {
        return self::normalizeUrl($value) !== null;
    }

    /**
     * 현재 적용 중인 프록시를 curl 옵션 형태로 돌려줍니다.
     *
     * `Http::` 파사드를 쓰지 못하는 호출 지점(외부 SDK 규약상 curl 핸들을 직접 다뤄야 하는
     * 경우 등)이 같은 프록시를 타도록 하기 위한 통로입니다. 판정은 여기서 다시 하지 않고
     * 이미 주입된 `g7.outbound_proxy` 를 읽습니다 — 게이트는 한 곳에만 둔다.
     *
     * 적용 대상이 없으면 빈 배열이므로 `curl_setopt_array()` 에 그대로 넘겨도 무해합니다.
     *
     * @return array<int, mixed> curl 옵션 배열 (미적용 시 빈 배열)
     */
    public static function curlOptions(): array
    {
        $proxy = config('g7.outbound_proxy');

        if (! is_array($proxy) || empty($proxy['https'])) {
            return [];
        }

        $options = [CURLOPT_PROXY => $proxy['https']];

        if (! empty($proxy['no']) && is_array($proxy['no'])) {
            $options[CURLOPT_NOPROXY] = implode(',', $proxy['no']);
        }

        return $options;
    }

    /**
     * 프록시 URL 을 정규화합니다.
     *
     * 허용 스킴과 호스트를 모두 갖추지 못한 값은 null 을 돌려줍니다.
     *
     * @param  mixed  $value  원본 값
     * @return string|null 정규화된 URL (부적합 시 null)
     */
    private static function normalizeUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        return $url;
    }

    /**
     * 프록시 예외 목록을 정규화합니다.
     *
     * 빈 항목과 중복을 걸러내고 순번을 다시 매깁니다 — 비연속 키는 JSON 직렬화 시 객체가 되어
     * Guzzle 이 목록으로 읽지 못합니다.
     *
     * @param  mixed  $value  원본 예외 목록
     * @return array<int, string> 정규화된 호스트 목록
     */
    private static function normalizeBypass(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $hosts = [];

        foreach ($value as $host) {
            if (! is_string($host)) {
                continue;
            }

            $host = trim($host);

            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}
