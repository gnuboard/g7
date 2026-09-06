<?php

namespace App\Support;

/**
 * 서버가 외부로 나가는(outbound) HTTP 요청의 목적지 URL 을 검증하는 순수 유틸.
 *
 * 사용자·관리자·외부 콜백이 제어할 수 있는 값이 그대로 목적지가 되면 서버는
 * 내부망(사설 IP, 루프백, 클라우드 메타데이터 169.254.169.254)으로 요청을 대신
 * 보내는 도구가 된다(SSRF). 본 유틸은 두 계층의 판정을 제공한다:
 *
 *  1. `isHostAllowed()` — 신뢰 도메인 목록이 정해진 경우(결제/본인인증 게이트웨이 등).
 *     host 를 **완전 일치**로 검증한다. 접두사 매칭(`str_starts_with`)은 금지 —
 *     `https://trusted.example@127.0.0.1/`(userinfo)와
 *     `https://trusted.example.attacker.com/`(접미사 확장)이 모두 통과하기 때문이다.
 *
 *  2. `isPublicHttpUrl()` — 신뢰 목록이 없고 "임의의 공개 URL 은 허용하되 내부망
 *     타격만 막는" 경우(외부 API 연동, 원격 다운로드 등).
 *
 * 두 메서드 모두 예외를 던지지 않고 bool 만 반환한다. 차단 시 어떤 예외/응답을
 * 낼지는 각 호출 지점이 자신의 반환 계약에 맞춰 결정한다.
 *
 * 방어 범위: **host 레벨**이다. DNS rebinding(검증 시점과 실제 연결 시점 사이에
 * 같은 호스트명이 내부 IP 로 재해석되는 공격)은 막지 못한다. 이를 막으려면 요청
 * 직전에 실제 해석된 IP 를 재검증해야 하며, 이는 본 유틸의 범위 밖이다.
 */
class OutboundUrlValidator
{
    /** 공개 인터넷 호스트로 취급하지 않는 내부 도메인 접미사 */
    private const INTERNAL_HOST_SUFFIXES = [
        '.local',
        '.localhost',
        '.internal',
        '.intranet',
        '.lan',
        '.home.arpa',
    ];

    /** 그 자체로 내부를 가리키는 호스트명 */
    private const INTERNAL_HOST_NAMES = [
        'localhost',
        'ip6-localhost',
        'ip6-loopback',
    ];

    /**
     * URL 의 host 가 화이트리스트와 완전 일치하는지 검증한다.
     *
     * scheme 허용 목록·userinfo 부재·포트 표기까지 함께 강제하므로, 호출부는
     * 이 메서드 하나만 통과시키면 목적지 위조를 막을 수 있다.
     *
     * @param  string  $url  검증 대상 URL (외부 입력)
     * @param  array<int, string>  $allowedHosts  허용 host 목록 (예: ['kssa.inicis.com'])
     * @param  array{schemes?: array<int, string>, allowPort?: bool}  $options
     *                                                                          schemes: 허용 scheme (기본 ['https'])
     *                                                                          allowPort: 명시 포트 허용 여부 (기본 false)
     * @return bool 화이트리스트 host 와 완전 일치하면 true
     */
    public static function isHostAllowed(string $url, array $allowedHosts, array $options = []): bool
    {
        $host = self::extractSafeHost($url, $options);

        if ($host === null) {
            return false;
        }

        foreach ($allowedHosts as $allowed) {
            if ($host === strtolower(trim($allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * URL 의 host 가 공개 인터넷 주소인지(= 내부망이 아닌지) 검증한다.
     *
     * 사설 IP(10/172.16/192.168), 루프백(127.0.0.1, ::1), 링크로컬 및 클라우드
     * 메타데이터(169.254.169.254), `localhost`·`*.local` 등 내부 도메인을 차단한다.
     *
     * @param  string  $url  검증 대상 URL (외부 입력)
     * @param  array{schemes?: array<int, string>, allowPort?: bool}  $options
     *                                                                          schemes: 허용 scheme (기본 ['https'])
     *                                                                          allowPort: 명시 포트 허용 여부 (기본 true — 외부 API 는 비표준 포트를 쓸 수 있다)
     * @return bool 공개 인터넷 host 이면 true
     */
    public static function isPublicHttpUrl(string $url, array $options = []): bool
    {
        $host = self::extractSafeHost($url, $options + ['allowPort' => true]);

        if ($host === null) {
            return false;
        }

        return self::isPublicHost($host);
    }

    /**
     * URL 이 구조적으로 안전한지만 판정한다 (내부망 여부는 보지 않는다).
     *
     * 내부 주소 호출을 의도적으로 허용한 환경에서도 userinfo(`@`) 위장·미허용 scheme·
     * 제어문자 주입은 여전히 막아야 하므로, 그 최소 방어선을 제공한다.
     *
     * @param  string  $url  검증 대상 URL
     * @param  array{schemes?: array<int, string>, allowPort?: bool}  $options  검증 옵션
     * @return bool 구조적으로 안전하면 true
     */
    public static function isStructurallySafeUrl(string $url, array $options = []): bool
    {
        return self::extractSafeHost($url, $options + ['allowPort' => true]) !== null;
    }

    /**
     * host 문자열을 실제 연결 계층과 같은 규칙으로 정규화한다.
     *
     * 이 메서드가 정규화의 SSoT 다. 검증기 밖에서 host 를 대조하는 소비자(결제 콜백
     * URL 의 도메인 접미사 확인 등)도 이 메서드를 거쳐야 판정이 갈리지 않는다.
     *
     * 정규화 내용:
     *  - 점(.) 동등 유니코드 문자(U+3002·U+FF0E·U+FF61)를 ASCII 점으로 치환.
     *    검증기가 ASCII 점만 구분자로 보면 `localhost。` 는 단일 라벨(공개 도메인)로
     *    읽히지만 libcurl/libidn2 는 UTS#46 정규화로 `localhost` 에 연결한다.
     *  - IDNA/UTS#46 A-label(punycode) 변환 — 유니코드 표기와 ASCII 표기를 한 형태로 모은다.
     *  - 소문자화 및 완전한 DNS 이름의 후행 점 제거.
     *
     * @param  string  $host  정규화할 host 문자열 (IPv6 는 대괄호 없이)
     * @return string|null 정규화된 host, 정규화할 수 없으면 null
     */
    public static function normalizeHost(string $host): ?string
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return null;
        }

        // 점 동등 문자 사전 치환 — idn_to_ascii 는 구현에 따라 이들을 남길 수 있으므로
        // 라벨 분리 자체를 여기서 확정한다.
        $host = strtr($host, [
            "\u{3002}" => '.',   // IDEOGRAPHIC FULL STOP
            "\u{FF0E}" => '.',   // FULLWIDTH FULL STOP
            "\u{FF61}" => '.',   // HALFWIDTH IDEOGRAPHIC FULL STOP
        ]);

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            } elseif (preg_match('/[^\x20-\x7E]/', $host) === 1) {
                // 변환에 실패했는데 비-ASCII 가 남아 있으면 연결 계층이 어떤 host 로
                // 해석할지 알 수 없다 — 판정 불가는 거부로 처리한다.
                return null;
            }
        } elseif (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            // polyfill 부재 환경 fail-safe: 비-ASCII host 는 판정 불가로 보고 거부.
            return null;
        }

        $host = strtolower($host);

        // 완전한 DNS 이름 표기(`example.com.`)의 후행 점 제거 — 남겨 두면 최상위 라벨이
        // 빈 문자열이 되어 정상 도메인이 차단되고, `localhost.` 가 내부 이름 대조를 빠져나간다.
        while (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        // 정규화 결과가 host 로 성립하는지 확인한다. UTS#46 은 전각 문자를 ASCII 로
        // 매핑하므로 `127.0.0.1／.example.com`(U+FF0F) 은 `127.0.0.1/.example.com` 이 된다.
        // parse_url 은 전각 문자를 구분자로 보지 않아 이 전체를 host 로 넘기지만, 연결
        // 계층은 정규화 후 첫 구분자 앞(`127.0.0.1`)까지만 host 로 읽는다. 즉 접미사·
        // 화이트리스트 대조는 뒤쪽 도메인으로 통과하는데 실제 접속은 앞쪽 주소로 간다.
        // punycode(A-label)와 IP 리터럴은 항상 letter/digit/hyphen/dot 뿐이므로, 그 밖의
        // 문자가 남았다면 어느 host 로 해석될지 알 수 없다 — 판정 불가는 거부로 처리한다.
        if (preg_match('/^[a-z0-9._-]+$/', $host) !== 1) {
            return null;
        }

        return $host === '' ? null : $host;
    }

    /**
     * host 문자열(URL 이 아닌 host 단독)이 공개 인터넷 주소인지 판정한다.
     *
     * @param  string  $host  host 문자열 (예: 'example.com', '127.0.0.1', '[::1]')
     * @return bool 공개 인터넷 host 이면 true
     */
    public static function isPublicHost(string $host): bool
    {
        $host = strtolower(trim($host));

        // parse_url 이 IPv6 를 대괄호째 반환하므로 벗겨낸다
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        // 직접 호출자(URL 을 거치지 않는 경로)도 같은 정규화를 받아야 한다.
        // 단 IP 리터럴은 IDNA 라벨 구조가 없어 정규화 대상이 아니다 — 특히 IPv6 의 ':' 는
        // 정규화의 호스트명 문자 집합 검사에 걸려 공개 IPv6 까지 차단된다.
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $host = self::normalizeHost($host) ?? '';
        }

        if ($host === '' || in_array($host, self::INTERNAL_HOST_NAMES, true)) {
            return false;
        }

        // IP 리터럴 — 사설/예약 대역 차단 (루프백·링크로컬·메타데이터 포함)
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        // 10진수·8진수·16진수로 인코딩된 IP 우회(예: http://2130706433/ = 127.0.0.1) 차단.
        // 공개 도메인은 최상위 라벨이 숫자로만 이루어질 수 없다.
        $lastLabel = substr(strrchr('.'.$host, '.') ?: '', 1);
        if ($lastLabel === '' || ctype_digit($lastLabel) || str_starts_with($host, '0x')) {
            return false;
        }

        foreach (self::INTERNAL_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * URL 을 파싱해 구조적으로 안전한 host 를 소문자로 반환한다.
     *
     * scheme 미허용, userinfo(`@`) 포함, 미허용 포트, host 부재이면 null 을 반환해
     * 상위 판정을 즉시 실패시킨다.
     *
     * @param  string  $url  검증 대상 URL
     * @param  array{schemes?: array<int, string>, allowPort?: bool}  $options  검증 옵션
     * @return string|null 소문자 host, 구조적으로 안전하지 않으면 null
     */
    private static function extractSafeHost(string $url, array $options): ?string
    {
        $schemes = $options['schemes'] ?? ['https'];
        $allowPort = $options['allowPort'] ?? false;

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // 개행·제어문자가 섞인 URL 은 헤더/요청 분리 시도로 간주하고 즉시 거부
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        // userinfo(`user:pass@host`) 는 host 위조의 핵심 벡터 — 존재만으로 거부
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $normalizedSchemes = array_map(
            static fn (string $s): string => strtolower(trim($s)),
            $schemes,
        );

        if (! in_array($scheme, $normalizedSchemes, true)) {
            return null;
        }

        if (! $allowPort && isset($parts['port'])) {
            return null;
        }

        $host = strtolower(trim($parts['host']));

        // IPv6 리터럴은 대괄호째 유지한다 — 라벨 구조가 없어 IDNA 정규화 대상이 아니다.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return $host === '[]' ? null : $host;
        }

        // 실제 연결 계층(libcurl/libidn2)과 같은 규칙으로 정규화한 뒤 상위 판정에 넘긴다.
        // 정규화 없이 넘기면 `localhost。` 처럼 검증 시점과 연결 시점의 host 가 달라진다.
        return self::normalizeHost($host);
    }
}
