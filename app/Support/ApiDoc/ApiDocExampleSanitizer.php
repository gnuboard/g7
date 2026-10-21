<?php

namespace App\Support\ApiDoc;

/**
 * API 문서 실측 응답의 민감값·식별정보 마스킹기
 *
 * `api:docgen` 은 개발 DB 에 대고 실측한 응답을 공개 문서(JSON 예시 블록과 필드 표 샘플값)에
 * 그대로 옮긴다. 실측 응답에는 토큰·비밀번호 같은 민감 필드뿐 아니라 실제 메일 주소, 설치 경로
 * (`base_path()`·`storage_path()`), 사이트 호스트(`APP_URL`), 공인 IP 가 섞여 나온다. 문서에 쓰기
 * 전에 이 값들을 공개 문서용 자리표시자로 바꾼다.
 *
 * - 민감 키(token·password·secret 등) 스칼라 값 → `{MASKED}`
 * - 예약 도메인이 아닌 메일 주소 → `user@example.com`
 * - 설치 경로 접두(구분자 `\`·`/` 혼용 무관) → `<설치 경로>`
 * - `APP_URL` 호스트와 추가로 넘긴 실측 호스트(하위 도메인 포함) → `example.com`
 * - URL 안의 밑줄 호스트(공개 DNS 이름이 아닌 개발 환경 호스트, 목록에 없어도) → `example.com`
 * - 값 전체가 공인 IPv4 인 문자열 → `203.0.113.10` (RFC 5737 문서용 대역)
 */
class ApiDocExampleSanitizer
{
    /**
     * 민감 키 값 자리표시자.
     */
    public const MASKED = '{MASKED}';

    /**
     * 메일 주소 자리표시자.
     */
    public const EMAIL_PLACEHOLDER = 'user@example.com';

    /**
     * 설치 경로 자리표시자.
     */
    public const PATH_PLACEHOLDER = '<설치 경로>';

    /**
     * 호스트 자리표시자.
     */
    public const HOST_PLACEHOLDER = 'example.com';

    /**
     * 공인 IP 자리표시자 (RFC 5737 TEST-NET-3).
     */
    public const IP_PLACEHOLDER = '203.0.113.10';

    /**
     * 필드명에 포함되면 값을 마스킹하는 민감 키워드.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEYS = [
        'token', 'access_token', 'refresh_token', 'plaintexttoken', 'plain_text_token',
        'password', 'secret', 'api_key', 'apikey', 'private_key', 'client_secret',
        'authorization', 'remember_token', 'app_key',
    ];

    /**
     * `@` 뒤가 도메인처럼 보여도 파일명인 확장자 (예: `logo@2x.png`).
     *
     * @var array<int, string>
     */
    private const FILE_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp',
        'js', 'mjs', 'css', 'json', 'php', 'md', 'html', 'txt', 'woff', 'woff2', 'ttf',
    ];

    /**
     * 실측 응답 값을 재귀로 마스킹합니다.
     *
     * @param  mixed  $value  실측 응답 값 (배열/스칼라)
     * @param  array<int, string>  $extraHosts  `APP_URL` 외에 가릴 실측 호스트 (예: 실측 base URL 호스트)
     * @return mixed 마스킹된 값
     */
    public function sanitize(mixed $value, array $extraHosts = []): mixed
    {
        return $this->walk($value, $this->pathPrefixes(), $this->hosts($extraHosts));
    }

    /**
     * 배열은 키별로 내려가며 민감 키 값은 `{MASKED}`, 문자열은 식별정보를 치환합니다.
     *
     * @param  mixed  $value  값
     * @param  array<string, string>  $prefixes  설치 경로 접두 → 치환값 (긴 것부터)
     * @param  array<int, string>  $hosts  가릴 호스트 목록
     * @return mixed 마스킹된 값
     */
    private function walk(mixed $value, array $prefixes, array $hosts): mixed
    {
        if (is_string($value)) {
            return $this->scrubString($value, $prefixes, $hosts);
        }

        if (! is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && ! is_array($v) && $v !== null && $this->isSensitiveKey($k)) {
                // 타입 표기용(token_type) 은 값이 아니라 종류이므로 그대로 둔다.
                $result[$k] = strtolower($k) === 'token_type' ? $v : self::MASKED;

                continue;
            }

            $result[$k] = $this->walk($v, $prefixes, $hosts);
        }

        return $result;
    }

    /**
     * 필드명이 민감 키워드를 포함하는지 판별합니다.
     *
     * @param  string  $key  필드명
     * @return bool 민감 키 여부
     */
    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 문자열 하나에서 설치 경로·메일 주소·호스트·공인 IP 를 자리표시자로 바꿉니다.
     *
     * @param  string  $text  원문
     * @param  array<string, string>  $prefixes  설치 경로 접두 → 치환값
     * @param  array<int, string>  $hosts  가릴 호스트 목록
     * @return string 치환된 문자열
     */
    private function scrubString(string $text, array $prefixes, array $hosts): string
    {
        foreach ($prefixes as $prefix => $replacement) {
            $text = (string) preg_replace_callback(
                $this->pathPattern($prefix),
                static fn (): string => $replacement,
                $text
            );
        }

        $text = (string) preg_replace_callback(
            '/[A-Za-z0-9._%+-]+@((?:[A-Za-z0-9-]+\.)+([A-Za-z]{2,}))\b/',
            fn (array $m): string => $this->isReservedDomain($m[1]) || in_array(strtolower($m[2]), self::FILE_EXTENSIONS, true)
                ? $m[0]
                : self::EMAIL_PLACEHOLDER,
            $text
        );

        foreach ($hosts as $host) {
            $text = (string) preg_replace_callback(
                '/(?<![A-Za-z0-9-])'.preg_quote($host, '/').'(?![A-Za-z0-9-])/i',
                static fn (): string => self::HOST_PLACEHOLDER,
                $text
            );
        }

        // URL 안의 밑줄 호스트(`https://dev_box.dev`·이스케이프된 `https:\/\/dev_box.dev`)는 목록에 없어도 가린다.
        // 밑줄은 공개 DNS 호스트 이름에 쓰이지 않으므로 개발 환경 호스트다 — 개발 DB 설정값(사이트 주소 등)에
        // 다른 개발 환경의 호스트가 남아 있으면 `APP_URL`·실측 호스트 목록만으로는 가리지 못했다.
        $text = (string) preg_replace(
            '#(?<=//|\\\\/\\\\/)[A-Za-z0-9.-]*_[A-Za-z0-9_.-]*?\.[A-Za-z]{2,}(?![A-Za-z0-9_.-])#',
            self::HOST_PLACEHOLDER,
            $text
        );

        if ($this->isPublicIpv4($text)) {
            return self::IP_PLACEHOLDER;
        }

        return $text;
    }

    /**
     * 설치 경로 접두를 구분자(`\`·`/`) 혼용과 대소문자에 무관하게 찾는 정규식을 만듭니다.
     *
     * 접두 뒤가 경로 구분자·끝·공백·따옴표일 때만 일치시켜 `g7` 이 `g7_old` 를 잡지 않게 한다.
     *
     * @param  string  $prefix  설치 경로 접두
     * @return string 정규식
     */
    private function pathPattern(string $prefix): string
    {
        $segments = preg_split('#[\\\\/]+#', $prefix, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $body = implode('[\\\\/]+', array_map(static fn (string $s): string => preg_quote($s, '#'), $segments));
        $lead = preg_match('#^[\\\\/]#', $prefix) === 1 ? '[\\\\/]+' : '';

        return '#'.$lead.$body.'(?=$|[\\\\/\s"\'`])#i';
    }

    /**
     * 가릴 설치 경로 접두 목록을 만듭니다 (긴 것부터 치환해야 storage 가 base 보다 먼저 잡힌다).
     *
     * @return array<string, string> 접두 → 치환값
     */
    private function pathPrefixes(): array
    {
        $map = [];
        $base = rtrim(base_path(), '\\/');
        $storage = rtrim(storage_path(), '\\/');

        if (strlen($storage) > 3) {
            $map[$storage] = self::PATH_PLACEHOLDER.'/storage';
        }
        if (strlen($base) > 3) {
            $map[$base] = self::PATH_PLACEHOLDER;
        }

        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $map;
    }

    /**
     * 가릴 호스트 목록을 만듭니다 (`APP_URL` 호스트 + 추가 호스트, 예약·로컬·IP 호스트 제외).
     *
     * @param  array<int, string>  $extraHosts  추가 호스트
     * @return array<int, string> 호스트 목록
     */
    private function hosts(array $extraHosts): array
    {
        $candidates = $extraHosts;
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost)) {
            $candidates[] = $appHost;
        }

        $hosts = [];
        foreach ($candidates as $host) {
            $host = strtolower(trim((string) $host));
            if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.')) {
                continue;
            }
            if ($this->isReservedDomain($host)) {
                continue;
            }
            $hosts[$host] = $host;
        }

        $hosts = array_values($hosts);
        usort($hosts, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $hosts;
    }

    /**
     * 문서용으로 예약된 도메인(example.* · .test · .example · .invalid · .localhost · .local)인지 판별합니다.
     *
     * @param  string  $domain  도메인
     * @return bool 예약 도메인 여부
     */
    private function isReservedDomain(string $domain): bool
    {
        return preg_match('/(^|\.)(example\.(com|net|org)|[^.]+\.(test|example|invalid|localhost|local)|localhost)$/i', $domain) === 1;
    }

    /**
     * 문자열 전체가 공인 IPv4 주소인지 판별합니다 (사설·루프백·예약·문서용 대역 제외).
     *
     * @param  string  $text  문자열
     * @return bool 공인 IPv4 여부
     */
    private function isPublicIpv4(string $text): bool
    {
        if (filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        if (preg_match('/^(192\.0\.2|198\.51\.100|203\.0\.113)\./', $text) === 1) {
            return false;
        }

        return filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
