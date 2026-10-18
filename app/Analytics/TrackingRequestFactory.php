<?php

namespace App\Analytics;

use App\Enums\Analytics\ActionType;
use App\Extension\Consent\ConsentManager;
use App\Support\SiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 수집 요청 조립
 *
 * 비콘·서버 폴백·내부 검색 훅이 모두 여기를 거쳐 같은 DTO 가 됩니다. 각 경로가
 * 제 나름대로 요청을 해석하면 같은 방문이 경로마다 다른 값으로 적힙니다.
 *
 * 헤더와 쿠키는 판정에 쓰는 것만 골라 담습니다 — 큐에 실리는 페이로드에 세션 토큰
 * 같은 것이 섞이면 안 됩니다.
 */
class TrackingRequestFactory
{
    /**
     * 판정에 쓰는 헤더 목록
     *
     * `Accept-Language` 는 익명 방문자 키의 언어 재료다 — 브라우저 비콘과 서버가 직접 기록하는
     * 요청(로그인 전환·서버 폴백)이 **똑같이** 가진 언어 값은 이 헤더뿐이다. 비콘이 보내는
     * 설정 언어(`navigator.language`)는 화면 언어라 선호 언어 목록과 다른 언어일 수도 있다.
     *
     * @var array<int, string>
     */
    private const TRACKED_HEADERS = ['DNT', 'Sec-GPC', 'X-Purpose', 'Purpose', 'Sec-Purpose', 'X-Moz', 'Accept-Language'];

    /**
     * HTTP 요청과 페이로드로 수집 요청을 만듭니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  array<string, mixed>  $payload  비콘 페이로드 (서버 경로는 빈 배열)
     * @param  string  $source  수집 경로 (beacon, server)
     * @return TrackingRequest 수집 요청
     */
    public static function fromHttp(Request $request, array $payload = [], string $source = 'beacon'): TrackingRequest
    {
        $user = $request->user();

        return new TrackingRequest(
            type: self::resolveType($payload),
            url: self::resolveUrl($request, $payload),
            title: self::text($payload['title'] ?? null, 512),
            referrer: self::text($payload['ref'] ?? null, 1024) ?? self::text($request->headers->get('referer'), 1024),
            resolution: self::resolution($payload['res'] ?? null),
            browserLanguage: self::primaryLanguage($payload['lang'] ?? $request->headers->get('accept-language')),
            locale: self::text($payload['locale'] ?? null, 10) ?? app()->getLocale(),
            prevTimeSpent: self::timeSpent($payload['prev'] ?? null),
            init: (bool) ($payload['init'] ?? false),
            target: self::text($payload['target'] ?? null, 1024),
            name: self::text($payload['name'] ?? null, 255),
            goalKey: self::text($payload['goal'] ?? null, 100),
            goalAttributes: is_array($payload['attrs'] ?? null) ? $payload['attrs'] : [],
            dimensions: self::dimensions($payload['dims'] ?? null),
            clientHints: is_array($payload['ch'] ?? null) ? $payload['ch'] : [],
            webdriver: (bool) ($payload['wd'] ?? false),
            searchQuery: self::text($payload['q'] ?? null, 255),
            searchTab: self::text($payload['tab'] ?? null, 50),
            searchTotal: isset($payload['total']) ? max(0, (int) $payload['total']) : null,
            ip: $request->ip(),
            userAgent: self::text($request->userAgent(), 500),
            headers: self::pickHeaders($request),
            cookies: self::pickCookies($request),
            userId: $user?->getAuthIdentifier() !== null ? (int) $user->getAuthIdentifier() : null,
            isAdmin: self::isAdmin($user),
            sessionId: self::consentSessionId($request),
            source: $source,
            receivedAt: AnalyticsClock::now()->format('Y-m-d H:i:s'),
        );
    }

    /**
     * 동의 조회에 쓸 세션 식별자를 정합니다.
     *
     * 동의 관리 제공자는 게스트 동의를 자기 식별자(예: 서명된 쿠키에 담긴 UUID — 라라벨 세션과
     * 무관한 값)로 저장합니다. 그 값은 요청이 살아 있을 때만 읽을 수 있으므로(큐로 넘어가면 요청이
     * 없다) 조립 단계에서 동의 매니저(`ConsentManager::subjectKey()`)로 물어 싣습니다. 선택된 제공자가
     * 없거나 답하지 못하면 시작된 라라벨 세션 ID(없으면 null)를 씁니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null 동의 조회용 세션 식별자
     */
    public static function consentSessionId(Request $request): ?string
    {
        $default = $request->hasSession() ? $request->session()->getId() : null;

        try {
            $resolved = app(ConsentManager::class)->subjectKey($request);
        } catch (\Throwable $e) {
            // 동의 제공자의 실수가 수집을 막으면 안 된다 — 기본값으로 묻는다.
            Log::warning('방문자 통계 동의 식별자 조회 실패', ['error' => $e->getMessage()]);

            return $default;
        }

        return $resolved ?? $default;
    }

    /**
     * 값을 문자열로 다듬고 길이를 맞춥니다.
     *
     * 컬럼 길이를 넘는 값이 들어오면 적재가 통째로 실패하므로 조립 단계에서 자릅니다.
     *
     * @param  mixed  $value  원본 값
     * @param  int  $maxLength  최대 길이
     * @return string|null 다듬은 문자열 (비었으면 null)
     */
    private static function text(mixed $value, int $maxLength): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /**
     * 브라우저 언어에서 첫 번째 언어 태그만 뽑습니다.
     *
     * `Accept-Language` 는 `ko-KR,ko;q=0.9,en;q=0.8` 처럼 선호 목록으로 옵니다. 그대로
     * 자르면 `ko-KR,ko;q` 같은 쓰레기가 저장되고, 통계 화면의 언어 목록이 그런 값으로
     * 채워집니다. 첫 항목의 언어 태그만 남깁니다.
     *
     * @param  mixed  $value  브라우저 언어 또는 Accept-Language 헤더
     * @return string|null 언어 태그 (판정 불가 시 null)
     */
    private static function primaryLanguage(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $first = trim(explode(',', (string) $value)[0]);
        $first = trim(explode(';', $first)[0]);

        // 언어 태그 형태(ko, ko-KR, zh-Hant)가 아니면 저장하지 않는다.
        if (preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})?$/', $first) !== 1) {
            return null;
        }

        return mb_substr($first, 0, 10);
    }

    /**
     * 액션 종류를 정합니다.
     *
     * 알 수 없는 값은 페이지뷰로 떨어뜨립니다 — 요청을 통째로 버리는 것보다 낫습니다.
     *
     * @param  array<string, mixed>  $payload  비콘 페이로드
     * @return string 액션 종류
     */
    private static function resolveType(array $payload): string
    {
        $type = is_scalar($payload['t'] ?? null) ? (string) $payload['t'] : ActionType::Pageview->value;

        return ActionType::tryFrom($type)?->value ?? ActionType::Pageview->value;
    }

    /**
     * 방문 URL 을 정합니다.
     *
     * 비콘은 브라우저가 보고 있던 주소를 보내고(SPA 라 서버가 아는 주소와 다를 수 있다),
     * 서버 폴백은 요청 경로를 씁니다 — 저장되는 주소의 호스트는 요청 Host 가 아니라 사이트 주소입니다
     * (위조 Host 가 통계에 섞이지 않게).
     *
     * @param  Request  $request  HTTP 요청
     * @param  array<string, mixed>  $payload  비콘 페이로드
     * @return string 방문 URL
     */
    private static function resolveUrl(Request $request, array $payload): string
    {
        $url = self::text($payload['url'] ?? null, 2048);

        return $url ?? SiteUrl::forPath($request->getRequestUri());
    }

    /**
     * 화면 해상도 표기를 검사합니다.
     *
     * @param  mixed  $value  원본 값
     * @return string|null 해상도 (형식이 아니면 null)
     */
    private static function resolution(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = (string) $value;

        return preg_match('/^\d{2,5}x\d{2,5}$/', $value) === 1 ? $value : null;
    }

    /**
     * 직전 페이지 체류 시간을 상한 안으로 자릅니다.
     *
     * 탭을 열어 둔 채 자리를 비우면 며칠이 찍힐 수 있어 평균 체류가 의미를 잃습니다.
     *
     * @param  mixed  $value  원본 값
     * @return int|null 체류 시간 (초)
     */
    private static function timeSpent(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $cap = (int) config('analytics.limits.max_time_spent_seconds', 1800);

        return max(0, min($cap, (int) $value));
    }

    /**
     * 확장 차원 값을 문자열 맵으로 맞춥니다.
     *
     * @param  mixed  $value  원본 값
     * @return array<string, string> 차원 맵
     */
    private static function dimensions(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_scalar($item)) {
                continue;
            }

            // 이름은 `{확장 식별자}:{키}` 까지 받는다 — 식별자·키가 각각 최대 50자다.
            $result[mb_substr((string) $key, 0, 101)] = mb_substr((string) $item, 0, 255);

            // 등록되지 않은 차원은 적재 단계가 버린다. 여기서는 개수만 막는다.
            if (count($result) >= 20) {
                break;
            }
        }

        return $result;
    }

    /**
     * 판정에 쓰는 헤더만 골라 담습니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, string> 소문자 이름의 헤더 맵
     */
    private static function pickHeaders(Request $request): array
    {
        $result = [];

        foreach (self::TRACKED_HEADERS as $name) {
            $value = $request->headers->get($name);

            if (is_string($value) && $value !== '') {
                $result[strtolower($name)] = mb_substr($value, 0, 100);
            }
        }

        return $result;
    }

    /**
     * 판정에 쓰는 쿠키만 골라 담습니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, string> 쿠키 맵
     */
    private static function pickCookies(Request $request): array
    {
        $result = [];

        foreach ([config('analytics.cookies.visitor', 'g7_vid'), config('analytics.cookies.opt_out', 'g7_analytics_optout')] as $name) {
            $value = $request->cookie((string) $name);

            if (is_string($value) && $value !== '') {
                $result[(string) $name] = mb_substr($value, 0, 100);
            }
        }

        return $result;
    }

    /**
     * 관리자 역할 보유 여부를 판정합니다.
     *
     * 관리자 트래픽을 따로 세어 「관리자 제외」 토글이 뺄셈으로 동작하게 합니다.
     *
     * @param  mixed  $user  인증된 사용자
     * @return bool 관리자 여부
     */
    private static function isAdmin(mixed $user): bool
    {
        if ($user === null) {
            return false;
        }

        try {
            if (method_exists($user, 'hasRole')) {
                return (bool) $user->hasRole('admin') || (bool) $user->hasRole('superadmin');
            }
        } catch (\Throwable $e) {
            // 역할 판정이 실패해도 수집은 계속된다 — 일반 회원으로 센다.
        }

        return false;
    }
}
