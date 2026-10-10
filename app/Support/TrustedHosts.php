<?php

namespace App\Support;

/**
 * 요청 Host 헤더 허용 목록 (Laravel TrustHosts 미들웨어에 넘기는 패턴)
 *
 * Host 는 방문자가 마음대로 적어 보낼 수 있는 값이다. 이 값으로 절대 URL 을 만드는 코드가
 * 하나라도 남아 있으면 위조 Host 가 캐시 오염·외부 리다이렉트·요청 위조(SSRF)의 입력이
 * 된다(KVE-2026-2333/2334). 코드가 Host 를 쓰지 않게 고치는 것이 근본이고, 이 목록은
 * 그 위에 얹는 마지막 겹이다 — 사이트 주소(`APP_URL`)와 그 하위 도메인, 사이트 주소의
 * www 쌍, 운영자가 `TRUSTED_HOSTS` 에 적은 도메인이 아닌 Host 로 온 요청은 앱에 들어오지 못한다.
 *
 * www 쌍: 사이트 주소 host 가 `www.X` 면 `X` 를 정확히 일치로 허용한다(반대로 `X` 면 `www.X` 는
 * 하위 도메인 허용에 이미 들어 있다). 사이트 주소를 www 주소로 둔 사이트에서 www 없이 들어온
 * 방문자를 404 로 막지 않기 위해서다 — 그 화면 요청은 이어서 사이트 주소 이동
 * (RedirectToCanonicalHost)이 사이트 주소로 301 시킨다. 쌍은 맨 앞 `www.` 한 단계를 붙이고
 * 떼는 것뿐이고(`www.shop.example.com` ↔ `shop.example.com`, `example.com` 은 아님), IP 주소·
 * 점 없는 이름(`localhost`)·떼고 나면 점이 없어지는 이름(`www.localhost`)에는 만들지 않는다.
 *
 * 포트는 대조하지 않는다(Symfony 의 신뢰 호스트 판정이 host 만 본다). 같은 도메인에 포트만
 * 바꾼 Host 는 여기서 걸러지지 않으므로 코드 쪽 방어가 여전히 필요하다.
 *
 * 설치 완료 전(인스톨러가 사이트 주소를 아직 모름)과 `TRUSTED_HOSTS_ENABLED=false` 일 때는
 * 빈 목록을 돌려 제한하지 않는다. 로컬 개발(`APP_ENV=local`)·단위 테스트에서는 Laravel
 * 미들웨어가 스스로 적용을 건너뛴다.
 */
class TrustedHosts
{
    /**
     * 신뢰하는 Host 정규식 패턴 목록을 반환합니다.
     *
     * @return array<int, string> Symfony `Request::setTrustedHosts()` 형식 패턴 (빈 배열 = 제한 없음)
     */
    public static function patterns(): array
    {
        if (! config('trustedhosts.enabled', true) || ! config('app.installer_completed')) {
            return [];
        }

        $siteHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        // 사이트 주소를 모르면 무엇을 허용할지 알 수 없다 — 사이트 전체를 막는 대신 제한하지 않는다.
        if (! is_string($siteHost) || $siteHost === '') {
            return [];
        }

        $siteHost = strtolower($siteHost);
        $patterns = ['^(.+\.)?'.preg_quote($siteHost).'$'];

        // www 쌍 중 `www.` 를 뗀 쪽만 따로 넣는다 — 붙인 쪽은 위 하위 도메인 패턴에 이미 맞는다.
        // 뗀 쪽인지는 사이트 주소로 가른다(뗀 결과가 다시 `www.` 로 시작하는 `www.www.X` 도 `www.X` 를 넣는다).
        $counterpart = str_starts_with($siteHost, 'www.') ? self::wwwCounterpart($siteHost) : null;
        if ($counterpart !== null) {
            $patterns[] = '^'.preg_quote($counterpart).'$';
        }

        foreach (self::extraHosts() as $host) {
            $patterns[] = '^'.preg_quote($host).'$';
        }

        return array_values(array_unique($patterns));
    }

    /**
     * host 의 www 쌍(맨 앞 `www.` 를 떼거나 붙인 host)을 반환합니다.
     *
     * IP 주소·점 없는 이름·`www.` 를 떼면 점이 없어지는 이름은 쌍이 없다.
     *
     * @param  string  $host  host (대소문자 무관, IPv6 는 대괄호 포함 가능)
     * @return string|null 소문자 www 쌍 host, 쌍이 없으면 null
     */
    public static function wwwCounterpart(string $host): ?string
    {
        $host = strtolower(trim($host));

        if (! str_contains($host, '.') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (! str_starts_with($host, 'www.')) {
            return 'www.'.$host;
        }

        $bare = substr($host, 4);

        if (! str_contains($bare, '.') || filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return $bare;
    }

    /**
     * 운영자가 `TRUSTED_HOSTS` 에 적은 추가 도메인을 반환합니다.
     *
     * 사이트 주소와 다른 도메인(별칭 도메인·IP 접속 등)으로도 서빙할 때 쓴다. 정확히 일치하는
     * host 만 허용한다(하위 도메인까지 열지 않는다).
     *
     * @return array<int, string> 소문자 host 목록
     */
    private static function extraHosts(): array
    {
        $raw = config('trustedhosts.hosts');
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);

        $hosts = [];
        foreach ($items as $item) {
            $host = strtolower(trim((string) $item));

            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }
}
