<?php

namespace App\Analytics;

use App\Contracts\Repositories\Analytics\SaltRepositoryInterface;
use App\Enums\Analytics\PrivacyMode;
use App\Enums\Analytics\VisitSource;

/**
 * 방문자 식별
 *
 * 같은 사람의 여러 요청을 한 방문으로 묶으려면 방문자를 가리키는 키가 있어야 합니다.
 * 키를 만드는 방법이 곧 개인정보 수준을 정합니다.
 *
 *  - 쿠키: 브라우저에 32자 난수를 심고 그 값을 키로 쓴다. 오래 이어져 재방문을 알 수 있다.
 *  - 일자 해시: 그날의 솔트와 방문 환경(IP·OS·브라우저·언어)으로 해시를 만든다.
 *    솔트가 날마다 바뀌고 며칠 뒤 지워지므로 어제의 키와 오늘의 키를 이어 붙일 방법이
 *    원리적으로 없다 — 그래서 재방문을 셀 수 없고, 그것이 이 방식의 목적이다.
 *  - 임시 키: 쿠키 모드에서 쿠키 없는 서버 폴백이 쓰는 일자 해시. 첫 비콘이 이어받는다.
 */
class VisitorRecognizer
{
    /** 쿠키 없는 서버 폴백이 임시로 쓰는 키 출처 */
    public const KEY_TYPE_SERVER = 'server';

    /**
     * 방문자 식별기를 생성합니다.
     *
     * @param  SaltRepositoryInterface  $salts  일자별 솔트 저장소
     */
    public function __construct(private readonly SaltRepositoryInterface $salts) {}

    /**
     * 방문자 키를 정합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsConfig  $config  설정
     * @param  bool  $anonymous  익명 모드로 동작할지 여부
     * @return array{key: string, key_type: string, new_cookie: string|null, is_returning: bool|null}
     *                                                                                                방문자 키·출처·새로 심을 쿠키·재방문 여부
     */
    public function resolve(TrackingRequest $request, AnalyticsConfig $config, bool $anonymous): array
    {
        if ($anonymous) {
            return [
                'key' => $this->configId($request),
                'key_type' => 'config',
                'new_cookie' => null,
                // 익명 모드는 어제와 오늘을 이을 수 없으므로 재방문을 판정하지 않는다.
                'is_returning' => null,
            ];
        }

        // 큐 경로에서는 컨트롤러가 이미 식별값을 정하고 쿠키까지 심었다. 워커가 여기서
        // 새로 만들면 응답에 나간 쿠키와 적재된 방문이 어긋난다.
        if ($request->visitorKey !== null && $request->visitorKey !== '') {
            return [
                'key' => $request->visitorKey,
                'key_type' => 'cookie',
                'new_cookie' => null,
                'is_returning' => $request->cookie((string) config('analytics.cookies.visitor', 'g7_vid')) !== null,
            ];
        }

        $cookieName = (string) config('analytics.cookies.visitor', 'g7_vid');
        $existing = $request->cookie($cookieName);

        if (is_string($existing) && preg_match('/^[0-9a-f]{32}$/', $existing) === 1) {
            return [
                'key' => $existing,
                'key_type' => 'cookie',
                'new_cookie' => null,
                'is_returning' => true,
            ];
        }

        // 서버 폴백은 응답 뒤(terminate)에 돌아 쿠키를 심을 수 없다. 여기서 새 키를 만들면
        // 곧이어 올 첫 비콘(역시 쿠키 없음)이 또 다른 키를 만들어 한 로드가 방문 2건이 된다.
        // 대신 환경 해시를 임시 키로 써 두고, 첫 비콘이 같은 임시 키의 방문을 자기 쿠키
        // 키로 이어받는다(VisitService). 비콘이 끝내 오지 않는 방문자(스크립트 차단)는
        // 이 임시 키로 계속 묶인다.
        if ($request->source === VisitSource::Server->value) {
            return [
                'key' => $this->configId($request),
                'key_type' => self::KEY_TYPE_SERVER,
                'new_cookie' => null,
                'is_returning' => false,
            ];
        }

        $fresh = bin2hex(random_bytes(16));

        return [
            'key' => $fresh,
            'key_type' => 'cookie',
            'new_cookie' => $fresh,
            'is_returning' => false,
        ];
    }

    /**
     * 방문 환경으로 그날치 식별 해시를 만듭니다.
     *
     * 원본 IP 를 재료로 쓰되 저장하지는 않습니다 — 해시는 되돌릴 수 없고 솔트가
     * 사라지면 같은 IP 로도 같은 값을 다시 만들 수 없습니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @return string 32자 해시
     */
    public function configId(TrackingRequest $request): string
    {
        // 재료는 서버 폴백 요청과 브라우저 비콘이 **똑같이** 가진 값만 쓴다. 화면 해상도와
        // 클라이언트 힌트는 비콘에만 있어, 넣으면 두 경로가 영원히 다른 키를 얻어 매 로드가
        // 방문 2건이 된다. 같은 이유로 환경 판정도 힌트 없이 User-Agent 만으로 한다.
        $environment = UserAgentClassifier::classify($request->userAgent);
        $salt = $this->salts->forDate(AnalyticsClock::today());

        $material = implode('|', [
            $salt,
            $request->ip ?? '',
            $environment['os_name'],
            $environment['browser_name'],
            self::baseLanguage($request->header('accept-language') ?? $request->browserLanguage),
        ]);

        return substr(hash('sha256', $material), 0, 32);
    }

    /**
     * 방문자 키 재료로 쓸 기본 언어를 돌려줍니다.
     *
     * 같은 브라우저라도 언어를 얻는 경로가 둘이다 — 브라우저 비콘은 설정 언어(화면 언어,
     * `ko-KR`·`en-US`), 서버가 직접 기록하는 요청(로그인 전환·서버 폴백)은 언어 헤더뿐이다.
     * 두 값은 자세함도, 때로는 언어 자체도 달라 같은 사람이 두 키로 갈렸다(#682 S4 라운드 8).
     * 그래서 호출부는 두 경로가 똑같이 가진 `Accept-Language` 를 넘기고, 여기서는 그 첫
     * 항목의 기본 언어만 소문자로 남긴다(헤더가 없던 옛 요청만 설정 언어로 대신한다).
     * 방문에 저장·표시하는 브라우저 언어는 이 값이 아니다.
     *
     * @param  string|null  $language  언어 헤더 또는 언어 태그 (`ko,en;q=0.9`·`ko-KR`·`en_US` 등)
     * @return string 기본 언어 (없으면 빈 문자열)
     */
    public static function baseLanguage(?string $language): string
    {
        $first = trim(explode(';', explode(',', (string) $language)[0])[0]);
        $base = explode('-', str_replace('_', '-', $first))[0];

        return strtolower($base);
    }

    /**
     * 익명 모드에서 저장할 IP 해시를 만듭니다.
     *
     * 원본 IP 대신 이 값을 남깁니다. 정보주체가 자기 방문 기록을 찾아달라고 하면
     * 그날의 솔트로 같은 계산을 해 맞춰 봅니다 — 솔트가 지워진 기간은 찾을 수 없고,
     * 그 한계를 화면이 안내합니다.
     *
     * @param  string|null  $rawIp  원본 IP
     * @param  string|null  $date  사이트 표준시 기준 일자 (기본: 오늘)
     * @return string|null IP 해시 (IP 가 없으면 null)
     */
    public function ipHash(?string $rawIp, ?string $date = null): ?string
    {
        if ($rawIp === null || $rawIp === '') {
            return null;
        }

        $salt = $this->salts->forDate($date ?? AnalyticsClock::today());

        return hash_hmac('sha256', $rawIp, $salt);
    }

    /**
     * 지난 날짜의 솔트로 IP 해시를 다시 계산합니다.
     *
     * 정보주체 조회에서만 씁니다. 솔트가 없는 날은 계산 자체가 불가능하므로 null 을
     * 돌려주고, 호출한 쪽이 「그 기간은 찾을 수 없음」을 화면에 알립니다.
     *
     * @param  string  $rawIp  원본 IP
     * @param  string  $date  사이트 표준시 기준 일자
     * @return string|null IP 해시 (그날 솔트가 없으면 null)
     */
    public function ipHashForPastDate(string $rawIp, string $date): ?string
    {
        $salt = $this->salts->findForDate($date);

        return $salt === null ? null : hash_hmac('sha256', $rawIp, $salt);
    }

    /**
     * 회원 ID 가명화 해시를 만듭니다.
     *
     * @param  int  $userId  회원 ID
     * @return string 64자 해시
     */
    public static function userHash(int $userId): string
    {
        return hash_hmac('sha256', (string) $userId, (string) config('app.key'));
    }

    /**
     * 개인정보 모드에 따라 쿠키를 심어야 하는지 판정합니다.
     *
     * @param  PrivacyMode  $mode  개인정보 수집 모드
     * @param  bool  $anonymous  익명 모드로 동작하는지 여부
     * @return bool 쿠키 발급 여부
     */
    public static function shouldIssueCookie(PrivacyMode $mode, bool $anonymous): bool
    {
        return ! $anonymous && $mode->usesCookie();
    }
}
