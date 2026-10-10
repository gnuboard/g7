<?php

namespace App\Support;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * IP 접근 제한 판정 규약 — "이 IP 의 이 경로 요청을 막는가" 를 계산하는 단일 지점.
 *
 * 차단 미들웨어(RestrictIpAccess), 설정 저장 검증(SaveSettingsRequest 의 자기 잠금 방지),
 * 구제 커맨드(security:ip-restriction)가 모두 이 결과만 소비한다. 판정식을 소비처마다 다시
 * 쓰면 한 곳만 어긋나도 "저장 검증은 통과했는데 저장 직후 관리자가 잠기는" 상태가 신호 없이
 * 생긴다 — 그래서 경로 정규화·관리자 영역 판정·항목 형식 검사까지 전부 여기에 둔다.
 *
 * 판정 순서 (첫 거부에서 멈춘다):
 *   1. 마스터 토글(`ip_restriction_enabled`)이 꺼져 있으면 통과.
 *   2. 관리자 영역이면 허용 목록(`admin_allowed_ips`)을 대조한다. 유효 항목이 하나도 없으면
 *      이 축은 적용하지 않는다(빈 목록 = 미적용).
 *      관리자 영역이 아니면 제외 경로(외부 서버가 부르는 인바운드 콜백)인지 먼저 보고,
 *      해당하면 규칙 축까지 건너뛰고 통과시킨다.
 *   3. 규칙 목록(`ip_restriction_rules`)에서 **사용 중인 첫 매칭 규칙** 하나만 대조한다.
 *
 * 경로 패턴은 `Request::is()` 와 같은 glob 이다 — `*` 는 `/` 를 포함해 무엇이든 맞고, 앞뒤
 * `/` 는 무시하며, 대소문자를 구분한다(라우터와 같은 기준이어야 우회 경로가 생기지 않는다).
 *
 * @since 7.1.0
 */
final class IpAccessMatcher
{
    /** 관리자 영역 허용 목록에서 거부됨 */
    public const AXIS_ADMIN = 'admin';

    /** 라우트별 규칙에서 거부됨 */
    public const AXIS_RULE = 'rule';

    /**
     * 확장이 자기 인바운드 콜백 경로를 제외 목록에 더하는 필터 훅.
     *
     * 값은 URI glob 문자열 배열이다. 새 결제·연동 확장이 들어올 때마다 코어의 고정 목록을
     * 고치지 않기 위한 통로이며, 관리자 영역은 이 훅으로도 빠져나갈 수 없다(isExempt 참조).
     */
    public const EXEMPT_PATHS_HOOK = 'core.security.ip_restriction_exempt_paths';

    /**
     * 코어가 고정으로 제외하는 인바운드 콜백 경로 (URI glob).
     *
     * PG 승인 콜백·가상계좌 입금통보·웹훅·본인인증 콜백은 **외부 서버가** 부른다. 발신 IP 를
     * 운영자가 미리 알 수 없으므로 규칙에 걸리면 결제가 미결로 남고, 실패는 PG 재시도 로그에만
     * 남는다. 식별자를 적지 않고 번들 확장들이 공유하는 경로 관례만 적는다 — 코어가 특정 확장을
     * 알지 않으면서 관례를 따르는 새 확장까지 함께 덮는다. 관례 밖의 경로는 EXEMPT_PATHS_HOOK 로
     * 확장이 스스로 등록한다.
     *
     * @var array<int, string>
     */
    public const FIXED_EXEMPT_PATTERNS = [
        // 결제 흐름 전반(승인 콜백·입금통보·에스크로 통보·PG 리다이렉트 복귀 페이지)
        'plugins/*/payment/*',
        // 결제·메시지 서비스의 웹훅
        'plugins/*/webhook',
        'plugins/*/webhook/*',
        'api/plugins/*/webhook',
        'api/plugins/*/webhook/*',
        // 본인인증 서비스의 결과 콜백
        'plugins/*/plugin/*/callback',
    ];

    /**
     * 자기 잠금 판정이 반드시 함께 대조하는 경로 (정규화된 URI — 앞뒤 `/` 없음).
     *
     * 저장 요청은 API 로 오지만 관리자가 실제로 서 있는 곳과 되돌아올 길은 다르다 — 설정 화면(웹 셸),
     * 그 화면이 쓰는 설정 API, 그리고 세션이 끊겼을 때 다시 들어올 **관리자 로그인 API** 셋이다.
     * 셋 중 하나라도 막히면 화면에서 되돌릴 방법이 없어지고 남는 수단이 서버 셸뿐이 된다.
     * 이 목록을 판정과 같은 곳에 두는 것은 관리자 영역 판정식이 바뀔 때 함께 보기 위해서다.
     *
     * @var array<int, string>
     */
    public const SELF_LOCKOUT_PATHS = [
        'admin/settings',
        'api/admin/settings',
        'api/auth/admin/login',
    ];

    /**
     * 관리자 영역 URI 판정식 (정규화된 경로 — 앞뒤 `/` 없음).
     *
     * 코어 관리자 셸·API 뿐 아니라 **확장 관리자 API**(`api/{modules|plugins}/{id}/admin/*`)와
     * **관리자 로그인 API**(`api/auth/admin/*`)를 함께 덮는다. 확장 관리자 API 는 `api/admin/*`
     * 아래가 아니므로, 이 둘을 빼면 확장 관리 화면만 IP 보호 밖에 남는다. 확장 식별자 자리는
     * 세그먼트 하나(`[^/]+`)로 한정해 사용자 경로 깊숙한 `admin` 세그먼트를 삼키지 않게 한다.
     */
    private const ADMIN_AREA_REGEX = '#^(?:admin|api/admin|api/auth/admin|api/(?:modules|plugins)/[^/]+/admin)(?:/|$)#';

    /**
     * 마스터 토글이 켜져 있는지 판정합니다.
     *
     * 저장값이 boolean 이 아닐 수 있어(직접 편집·구버전 저장) 느슨하게 참 판정한다.
     *
     * @param  array<string, mixed>  $security  security 카테고리 설정 배열
     * @return bool 켜져 있으면 true
     */
    public static function isEnabled(array $security): bool
    {
        return filter_var(
            $security['ip_restriction_enabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) === true;
    }

    /**
     * 허용 목록 항목이 IP 또는 CIDR 로 유효한지 판정합니다.
     *
     * 호스트명·범위 표기(`a-b`)·와일드카드(`192.168.*`)는 받지 않는다 — 매칭 규칙이 없어
     * 저장만 되고 아무도 통과시키지 못하는 항목이 된다.
     *
     * @param  mixed  $entry  검사할 항목
     * @return bool 유효하면 true
     */
    public static function isValidEntry(mixed $entry): bool
    {
        return self::canonicalEntry($entry) !== null;
    }

    /**
     * 허용 목록 항목을 비교용 정규형으로 바꿉니다 (유효하지 않으면 null).
     *
     * 접두 길이는 정수로 다시 쓴다 — `IpUtils` 는 `/00` 같은 표기를 `/0` 과 다르게 다뤄
     * 같은 뜻의 항목이 매칭되지 않는다. 단일 IPv4-mapped 주소는 IPv4 로 내린다.
     *
     * @param  mixed  $entry  허용 목록 항목
     * @return string|null 정규형 (유효하지 않으면 null)
     */
    public static function canonicalEntry(mixed $entry): ?string
    {
        if (! is_string($entry)) {
            return null;
        }

        $entry = trim($entry);

        if ($entry === '') {
            return null;
        }

        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false ? self::normalizeIp($entry) : null;
        }

        [$address, $prefix] = explode('/', $entry, 2);

        if ($prefix === '' || ! ctype_digit($prefix)) {
            return null;
        }

        $length = (int) $prefix;

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $length <= 32 ? $address.'/'.$length : null;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $length <= 128 ? $address.'/'.$length : null;
        }

        return null;
    }

    /**
     * 목록에서 유효한 항목만 정규형으로 추립니다.
     *
     * @param  mixed  $entries  허용 목록
     * @return array<int, string> 정규형 항목 목록 (중복 제거)
     */
    public static function canonicalEntries(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $canonical = [];

        foreach ($entries as $entry) {
            $normalized = self::canonicalEntry($entry);

            if ($normalized !== null) {
                $canonical[$normalized] = $normalized;
            }
        }

        return array_values($canonical);
    }

    /**
     * IPv4-mapped IPv6 주소(`::ffff:a.b.c.d`)를 IPv4 로 내립니다.
     *
     * 이중 스택 소켓은 IPv4 접속을 mapped 형태로 보고한다. `IpUtils::checkIp()` 는 콜론 수로
     * v4/v6 비교를 가르므로 그대로 두면 IPv4 허용 항목과 **절대 매칭되지 않아** 관리자가
     * 잠긴다. 표기 방식(`::ffff:`, `0:0:0:0:0:ffff:` 등)과 무관하게 바이트로 판정한다.
     *
     * @param  string  $ip  원래 IP
     * @return string 정규화된 IP (mapped 가 아니면 원래 값)
     */
    public static function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        $packed = @inet_pton($ip);

        if ($packed !== false && strlen($packed) === 16
            && substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            $ipv4 = inet_ntop(substr($packed, 12));

            return $ipv4 !== false ? $ipv4 : $ip;
        }

        return $ip;
    }

    /**
     * IP 가 허용 목록 중 하나에 해당하는지 판정합니다.
     *
     * 원래 표기와 mapped 해제 표기를 모두 대조한다. IP 를 알 수 없으면(빈 값·형식 오류)
     * 어떤 목록에도 해당하지 않는다.
     *
     * @param  string|null  $ip  대상 IP
     * @param  mixed  $entries  허용 목록 (IP·CIDR)
     * @return bool 해당하면 true
     */
    public static function ipMatches(?string $ip, mixed $entries): bool
    {
        if ($ip === null || trim($ip) === '') {
            return false;
        }

        $canonical = self::canonicalEntries($entries);

        if ($canonical === []) {
            return false;
        }

        foreach (array_unique([trim($ip), self::normalizeIp($ip)]) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            if (IpUtils::checkIp($candidate, $canonical)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 요청 경로를 판정용 형태로 정규화합니다 (URL 디코드 + 앞뒤 `/` 제거, 루트는 빈 문자열).
     *
     * 라우터는 디코드한 경로로 매칭하므로(`/%61dmin` 도 관리자 셸로 간다) 판정도 디코드한
     * 경로로 해야 인코딩 우회가 생기지 않는다.
     *
     * @param  string  $path  요청 경로 (`Request::path()` 결과 또는 임의 URI 경로)
     * @return string 정규화된 경로
     */
    public static function normalizePath(string $path): string
    {
        return trim(rawurldecode($path), '/');
    }

    /**
     * 경로가 관리자 영역인지 판정합니다.
     *
     * @param  string  $path  요청 경로
     * @return bool 관리자 영역이면 true
     */
    public static function isAdminArea(string $path): bool
    {
        return preg_match(self::ADMIN_AREA_REGEX, self::normalizePath($path)) === 1;
    }

    /**
     * URI glob 패턴이 경로에 맞는지 판정합니다.
     *
     * 패턴의 앞뒤 `/` 는 무시한다 — 화면에는 `/api/admin/users*` 처럼 슬래시를 붙여 적는
     * 것이 자연스럽고, 경로는 슬래시 없이 정규화되어 있다. `/` 하나만 적으면 루트만 맞는다.
     *
     * @param  string  $pattern  URI glob 패턴
     * @param  string  $path  요청 경로
     * @return bool 맞으면 true
     */
    public static function pathMatches(string $pattern, string $path): bool
    {
        return Str::is(trim(trim($pattern), '/'), self::normalizePath($path));
    }

    /**
     * 경로가 제외 경로(인바운드 콜백)에 해당하는지 판정합니다.
     *
     * **관리자 영역은 어떤 제외 경로로도 빠져나갈 수 없다.** 제외는 외부 서버가 부르는
     * 경로를 위한 것이다 — 확장 하나의 잘못된 등록이 관리자 IP 보호 전체를 무력화하게 두지 않는다.
     *
     * @param  string  $path  요청 경로
     * @param  array<int, mixed>  $extraPatterns  확장이 훅으로 등록한 패턴
     * @return bool 제외 대상이면 true
     */
    public static function isExempt(string $path, array $extraPatterns = []): bool
    {
        if (self::isAdminArea($path)) {
            return false;
        }

        foreach ([...self::FIXED_EXEMPT_PATTERNS, ...$extraPatterns] as $pattern) {
            if (is_string($pattern) && trim($pattern, " \t\n\r\0\x0B/") !== '' && self::pathMatches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 규칙이 사용 중인지 판정합니다 (`enabled` 미지정은 사용 중).
     *
     * @param  array<string, mixed>  $rule  규칙
     * @return bool 사용 중이면 true
     */
    public static function isRuleEnabled(array $rule): bool
    {
        if (! array_key_exists('enabled', $rule) || $rule['enabled'] === null) {
            return true;
        }

        return filter_var($rule['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * 경로에 적용될 규칙을 찾습니다 — 사용 중이고 유효 IP 가 있는 **첫 매칭 규칙**.
     *
     * 형식이 깨진 규칙(패턴 없음·유효 IP 0개)은 건너뛴다. 저장 검증을 거친 값에는 생기지
     * 않지만, 설정 파일을 직접 고친 경우 한 규칙의 손상이 전원 차단으로 번지지 않게 한다.
     *
     * @param  string  $path  요청 경로
     * @param  mixed  $rules  규칙 목록
     * @return array<string, mixed>|null 적용 규칙 (없으면 null)
     */
    public static function findRule(string $path, mixed $rules): ?array
    {
        if (! is_array($rules)) {
            return null;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule) || ! self::isRuleEnabled($rule)) {
                continue;
            }

            $pattern = $rule['pattern'] ?? null;

            if (! is_string($pattern) || trim($pattern) === '') {
                continue;
            }

            if (! self::pathMatches($pattern, $path)) {
                continue;
            }

            if (self::canonicalEntries($rule['ips'] ?? []) === []) {
                continue;
            }

            return $rule;
        }

        return null;
    }

    /**
     * 요청 하나를 막을지 판정합니다.
     *
     * 목록 자리에 배열이 아닌 값이 있으면(설정 파일 손상) 예외를 던진다 — 호출자가 그 상황을
     * 어떻게 다룰지 정한다(차단 미들웨어는 통과 + 오류 기록).
     *
     * @param  string|null  $ip  요청 IP (신뢰 프록시 해석 후 값)
     * @param  string  $path  요청 경로
     * @param  array<string, mixed>  $security  security 카테고리 설정 배열
     * @param  array<int, mixed>  $extraExemptPatterns  확장이 훅으로 등록한 제외 패턴
     * @return array{axis: string, pattern: string|null}|null 거부 사유 (통과면 null)
     *
     * @throws \UnexpectedValueException 허용 목록·규칙 목록이 배열이 아닐 때
     */
    public static function decide(?string $ip, string $path, array $security, array $extraExemptPatterns = []): ?array
    {
        if (! self::isEnabled($security)) {
            return null;
        }

        $adminAllowedIps = $security['admin_allowed_ips'] ?? [];
        $rules = $security['ip_restriction_rules'] ?? [];

        if (! is_array($adminAllowedIps) || ! is_array($rules)) {
            // 운영 로그 전용 진단 문구다(화면·API 응답으로 나가지 않는다) — 호출자가 잡아 기록한다.
            throw new \UnexpectedValueException('security.admin_allowed_ips and security.ip_restriction_rules must be arrays.');
        }

        if (self::isAdminArea($path)) {
            if (self::canonicalEntries($adminAllowedIps) !== [] && ! self::ipMatches($ip, $adminAllowedIps)) {
                return ['axis' => self::AXIS_ADMIN, 'pattern' => null];
            }
        } elseif (self::isExempt($path, $extraExemptPatterns)) {
            return null;
        }

        $rule = self::findRule($path, $rules);

        if ($rule !== null && ! self::ipMatches($ip, $rule['ips'] ?? [])) {
            return ['axis' => self::AXIS_RULE, 'pattern' => (string) $rule['pattern']];
        }

        return null;
    }
}
