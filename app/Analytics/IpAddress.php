<?php

namespace App\Analytics;

/**
 * IP 주소 유틸
 *
 * 마스킹·대역 비교·이진 변환을 한곳에 모읍니다. 수집 파이프라인은 같은 IP 를 여러
 * 목적(제외 판정·지역 조회·저장)으로 다루는데, 각 지점이 제 나름의 문자열 처리를
 * 하면 「어떤 형태의 IP 를 비교했는가」가 갈려 규칙이 조용히 어긋납니다.
 */
class IpAddress
{
    /**
     * IP 의 하위 부분을 지웁니다.
     *
     * IPv4 는 요청한 옥텟 수만큼 뒤에서부터 0 으로 바꾸고, IPv6 는 같은 수의 16비트
     * 블록을 0 으로 바꿉니다. 마스킹은 개인을 특정하기 어렵게 만드는 대신 위치 정확도를
     * 떨어뜨리므로, 몇 칸을 지울지는 운영자가 고릅니다.
     *
     * @param  string  $ip  IP 문자열
     * @param  int  $octets  지울 옥텟(IPv4) 또는 블록(IPv6) 수 (0~3)
     * @return string|null 마스킹된 IP (형식이 잘못됐으면 null)
     */
    public static function mask(string $ip, int $octets): ?string
    {
        $binary = self::toBinary($ip);

        if ($binary === null) {
            return null;
        }

        if ($octets <= 0) {
            return self::fromBinary($binary);
        }

        $length = strlen($binary);

        if ($length === 4) {
            // IPv4: 뒤에서부터 $octets 바이트를 0 으로. 전부 지우면 주소가 아니게 되므로 3 까지만.
            $keep = max(1, 4 - min($octets, 3));
            $masked = substr($binary, 0, $keep).str_repeat("\0", 4 - $keep);

            return self::fromBinary($masked);
        }

        // IPv6: 블록 하나가 2바이트. 옥텟 수를 블록 수로 읽어 같은 만큼 지운다.
        $blocksToClear = min($octets, 7);
        $keep = 16 - ($blocksToClear * 2);
        $masked = substr($binary, 0, $keep).str_repeat("\0", 16 - $keep);

        return self::fromBinary($masked);
    }

    /**
     * 저장된 주소가 지금 마스킹 설정대로 가려진 값인지 판정합니다.
     *
     * 원장에는 행마다 「가렸는가」가 남지 않는다. 설정을 켜면서 소급 적용을 건너뛰면 그 전 기록은 원래
     * 주소 그대로라, 화면이 설정만 보고 「마스킹됨」이라 적으면 원래 주소를 가려진 값으로 읽게 된다
     * (7.1.0 실측 O-E8). 값을 지금 설정으로 다시 가려 봐서 그대로면 그 값은 설정이 남기는 것 이상을
     * 드러내지 않는다 — 그것을 「가려진 값」으로 본다.
     *
     * @param  string|null  $ip  저장된 IP 문자열
     * @param  int  $octets  현재 마스킹 옥텟 수
     * @return bool|null 가려졌으면 true, 원래 주소면 false, 판정할 수 없으면(주소 없음·마스킹 꺼짐) null
     */
    public static function isMaskedBy(?string $ip, int $octets): ?bool
    {
        if ($ip === null || $ip === '' || $octets <= 0) {
            return null;
        }

        $binary = self::toBinary($ip);

        if ($binary === null) {
            return null;
        }

        return self::mask($ip, $octets) === self::fromBinary($binary);
    }

    /**
     * IP 가 CIDR 대역 안에 있는지 판정합니다.
     *
     * 대역 표기가 아니면(`/` 없음) 주소가 정확히 같은지만 봅니다.
     *
     * @param  string  $ip  검사할 IP
     * @param  string  $cidr  대역 표기 (예: 192.168.0.0/16, 2001:db8::/32) 또는 단일 IP
     * @return bool 대역 포함 여부
     */
    public static function inCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);

        if ($cidr === '') {
            return false;
        }

        if (! str_contains($cidr, '/')) {
            $left = self::toBinary($ip);
            $right = self::toBinary($cidr);

            return $left !== null && $left === $right;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipBinary = self::toBinary($ip);
        $subnetBinary = self::toBinary(trim($subnet));

        if ($ipBinary === null || $subnetBinary === null) {
            return false;
        }

        // IPv4 와 IPv6 는 서로의 대역에 들어가지 않는다.
        if (strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $bits = (int) $bits;
        $maxBits = strlen($ipBinary) * 8;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $fullBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask);
    }

    /**
     * IP 문자열을 이진값으로 바꿉니다.
     *
     * @param  string  $ip  IP 문자열
     * @return string|null 이진값 (IPv4 4바이트 · IPv6 16바이트, 형식이 잘못됐으면 null)
     */
    public static function toBinary(string $ip): ?string
    {
        $binary = @inet_pton(trim($ip));

        return $binary === false ? null : $binary;
    }

    /**
     * 이진값을 IP 문자열로 되돌립니다.
     *
     * @param  string  $binary  이진값
     * @return string|null IP 문자열 (복원 불가 시 null)
     */
    public static function fromBinary(string $binary): ?string
    {
        $length = strlen($binary);

        if ($length !== 4 && $length !== 16) {
            return null;
        }

        $ip = @inet_ntop($binary);

        return $ip === false ? null : $ip;
    }

    /**
     * 목록 중 하나라도 IP 를 포함하는지 판정합니다.
     *
     * @param  string  $ip  검사할 IP
     * @param  array<int, string>  $cidrs  대역 또는 단일 IP 목록
     * @return bool 하나라도 포함하면 true
     */
    public static function matchesAny(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (is_string($cidr) && self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }
}
