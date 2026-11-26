<?php

namespace App\Services;

use App\Support\TrustedProxyDiagnostic;
use App\Support\TrustedProxySetting;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * 신뢰 프록시 후보값 사전 검사
 *
 * 이 설정은 **틀려도 오류를 내지 않는다.** 잘못 저장하면 방문자 IP 가 조용히 프록시 주소로
 * 기록되거나(과소 신뢰), 방문자가 보낸 헤더를 그대로 믿게 된다(과대 신뢰). 더 나쁜 경우는
 * HTTPS 종단 구성에서 리다이렉트 루프가 나 관리자 화면 자체에 못 들어가는 것이고, 그러면
 * 되돌릴 화면도 없다. 그래서 **저장 전에** 그 값이 어떤 결과를 내는지 보여준다.
 *
 * 판정은 프레임워크의 해석기({@see TrustProxies})를 그대로 돌려서 얻는다 — 같은 규칙을 여기서
 * 다시 구현하면 검사 결과와 실제 적용이 갈리고, 그 어긋남은 이 기능이 막으려는 결함과
 * 정확히 같은 모양(오류 없이 조용히 다름)이 된다.
 *
 * **전역 상태를 건드린다.** `Request::setTrustedProxies()` 는 정적이라 프로세스 전역이다.
 * 원래 값(신뢰 목록·헤더 비트·config)을 저장했다가 `finally` 에서 되돌린다. 요청당 프로세스가
 * 분리되는 FPM/CGI 에서는 이것으로 충분하고, 상주형(Octane 등)에서도 복원이 끝난 뒤의
 * 상태는 호출 전과 같다.
 *
 * @since 7.1.0
 */
class TrustedProxyProbe
{
    /** 앞단이 감지되지 않아 판정할 것이 없음 */
    public const VERDICT_NO_HEADERS = 'no_headers';

    /** 후보값으로 방문자 IP 가 체인에서 해석됨 — 의도대로 동작한다 */
    public const VERDICT_RESOLVES = 'resolves';

    /** 후보값으로도 방문자 IP 가 그대로 — 목록이 앞단을 포함하지 않는다 */
    public const VERDICT_UNCHANGED = 'unchanged';

    /**
     * 후보 신뢰 프록시 목록을 이번 요청에 적용했을 때의 해석 결과를 반환합니다.
     *
     * @param  Request  $request  검사 기준이 될 현재 요청
     * @param  array<int, string>  $candidate  후보 태그 목록 (빈 배열이면 「신뢰하지 않음」)
     * @return array{candidate: array<int, string>, mode: string, forwarded_headers: array<int, string>, remote_addr: string|null, current: array{client_ip: string|null, is_secure: bool}, result: array{client_ip: string|null, is_secure: bool, trusted: array<int, string>}, changed: bool, verdict: string} 검사 결과
     */
    public function probe(Request $request, array $candidate): array
    {
        $candidate = TrustedProxySetting::toList($candidate);

        $remoteAddr = $request->server('REMOTE_ADDR');
        $forwarded = TrustedProxyDiagnostic::forwardedHeaders($request);

        $current = [
            'client_ip' => $request->ip(),
            'is_secure' => $request->isSecure(),
        ];

        $result = $this->resolveWith($request, $candidate);

        // 앞단이 아예 없으면 어떤 후보를 넣어도 결과가 같다 — "바뀌지 않았다" 를 실패로
        // 읽히게 두면 직접 노출 구성의 운영자가 있지도 않은 문제를 쫓게 된다.
        if ($forwarded === []) {
            $verdict = self::VERDICT_NO_HEADERS;
        } elseif ($result['client_ip'] !== null && $result['client_ip'] !== $remoteAddr) {
            $verdict = self::VERDICT_RESOLVES;
        } else {
            $verdict = self::VERDICT_UNCHANGED;
        }

        return [
            'candidate' => $candidate,
            'mode' => TrustedProxySetting::modeOf($candidate),
            'forwarded_headers' => $forwarded,
            'remote_addr' => is_string($remoteAddr) ? $remoteAddr : null,
            'current' => $current,
            'result' => $result,
            'changed' => $current['client_ip'] !== $result['client_ip']
                || $current['is_secure'] !== $result['is_secure'],
            'verdict' => $verdict,
        ];
    }

    /**
     * 후보값을 적용한 상태에서 요청이 어떻게 해석되는지 측정하고 원상 복구합니다.
     *
     * @param  Request  $request  검사 기준이 될 현재 요청
     * @param  array<int, string>  $candidate  정규화된 후보 태그 목록
     * @return array{client_ip: string|null, is_secure: bool, trusted: array<int, string>} 해석 결과
     */
    private function resolveWith(Request $request, array $candidate): array
    {
        $originalProxies = Request::getTrustedProxies();
        $originalHeaderSet = Request::getTrustedHeaderSet();
        $originalConfig = Config::get('trustedproxy.proxies');

        try {
            Config::set('trustedproxy.proxies', TrustedProxySetting::toConfigValue($candidate));

            // 측정 대상은 복제본이다 — 원본 요청 인스턴스가 들고 있는 해석 상태를 남기지 않는다.
            $probe = $request->duplicate();

            (new TrustProxies)->handle($probe, static fn (Request $r): Request => $r);

            return [
                'client_ip' => $probe->ip(),
                'is_secure' => $probe->isSecure(),
                'trusted' => array_values(Request::getTrustedProxies()),
            ];
        } finally {
            Request::setTrustedProxies($originalProxies, $originalHeaderSet);
            Config::set('trustedproxy.proxies', $originalConfig);
        }
    }
}
