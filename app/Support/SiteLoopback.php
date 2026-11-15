<?php

namespace App\Support;

/**
 * 이 서버가 자기 사이트 주소를 부를 때의 전송 옵션
 *
 * 사이트 주소가 루프백으로 풀리는 서버(자기 도메인을 hosts 로 127.0.0.1 에 묶은 구성, 로컬 개발의 자가서명
 * 인증서)에서는 그 연결을 가로챌 수 있는 경로가 없으므로 TLS 검증이 지키는 것이 없고, 켜 두면 자가서명
 * 인증서에서 내부 호출이 모두 실패한다. 그 밖의 경우(사이트 주소가 외부 IP 로 풀림)에는 검증을 켠다.
 * 판정한 주소로 연결을 고정(CURLOPT_RESOLVE)해 판정과 실제 연결 사이에 이름 해석이 바뀌지 않게 한다.
 *
 * @since 7.1.6
 */
final class SiteLoopback
{
    /**
     * 목적지가 이 서버 자신(루프백)이면 TLS 검증을 생략하고 연결을 그 주소로 고정하는 옵션을 반환합니다.
     *
     * @param  string  $url  호출 URL (사이트 주소 기준으로 검증된 값)
     * @return array<string, mixed> HTTP 클라이언트 옵션 (루프백이 아니면 빈 배열)
     */
    public static function transportOptions(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            return [];
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return self::isLoopbackIp($literal) ? ['verify' => false] : [];
        }

        $addresses = gethostbynamel($host);
        if (! is_array($addresses) || $addresses === []) {
            return [];
        }

        foreach ($addresses as $address) {
            if (! self::isLoopbackIp($address)) {
                return [];
            }
        }

        $ports = array_unique(array_filter([$parts['port'] ?? null, 80, 443]));

        return [
            'verify' => false,
            'curl' => [
                CURLOPT_RESOLVE => array_map(
                    static fn (int $port): string => "{$host}:{$port}:{$addresses[0]}",
                    $ports,
                ),
            ],
        ];
    }

    /**
     * IP 가 루프백 대역(127.0.0.0/8, ::1)인지 판정합니다.
     *
     * @param  string  $ip  IP 주소
     * @return bool 루프백이면 true
     */
    public static function isLoopbackIp(string $ip): bool
    {
        return $ip === '::1' || str_starts_with($ip, '127.');
    }
}
