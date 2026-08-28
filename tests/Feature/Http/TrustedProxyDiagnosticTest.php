<?php

namespace Tests\Feature\Http;

use App\Listeners\TrustedProxyAlertListener;
use App\Support\TrustedProxyDiagnostic;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 신뢰 프록시 진단 판정 (#124).
 *
 * 판정식은 "HTTPS 인식 실패" 가 아니라 다음이다:
 *
 *     X-Forwarded-* 헤더를 수신 중  AND  신뢰 프록시가 비어 있음
 *
 * HTTPS 기준으로 잡으면 **HTTP 전용 사이트가 프록시 뒤에 있는 구성**을 덮지 못한다. 그
 * 구성에서는 혼합 콘텐츠가 없어 화면이 완전히 정상 렌더되지만 webhook 403 · IP 왜곡 ·
 * 로그인 제한 붕괴는 그대로 발생하며, 능동 경고가 없으면 영구 미발견이다. 그래서 이
 * 테스트가 그 축을 명시적으로 잠근다.
 */
class TrustedProxyDiagnosticTest extends TestCase
{
    /**
     * 전달 헤더를 붙인 요청을 만듭니다.
     *
     * @param  array<string, string>  $server  추가 서버 변수
     * @return Request 생성된 요청
     */
    private function request(array $server = []): Request
    {
        return Request::create('http://g7_2.dev/', 'GET', [], [], [], array_merge([
            'REMOTE_ADDR' => '10.0.0.5',
        ], $server));
    }

    /**
     * 프록시 헤더가 없고 설정도 없으면 정상(직접 노출 구성)입니다.
     *
     * @scenario proxies_setting=unset, forwarded_proto=absent, forwarded_for=absent
     *
     * @effects direct_exposure_is_not_a_warning
     */
    public function test_프록시_헤더가_없으면_경고하지_않는다(): void
    {
        config(['trustedproxy.proxies' => null]);

        $result = TrustedProxyDiagnostic::forRequest($this->request());

        $this->assertSame(TrustedProxyDiagnostic::STATUS_OK, $result['status']);
        $this->assertSame([], $result['forwarded_headers']);
        $this->assertFalse($result['trusted_configured']);
    }

    /**
     * 프록시 헤더를 받는데 설정이 없으면 경고입니다.
     *
     * @scenario proxies_setting=unset, forwarded_proto=https, forwarded_for=single
     *
     * @effects forwarded_without_config_is_warning
     */
    public function test_프록시_헤더를_받는데_설정이_없으면_경고한다(): void
    {
        config(['trustedproxy.proxies' => null]);

        $result = TrustedProxyDiagnostic::forRequest($this->request([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
        ]));

        $this->assertSame(TrustedProxyDiagnostic::STATUS_WARNING, $result['status']);
        $this->assertContains('X-Forwarded-Proto', $result['forwarded_headers']);
        $this->assertContains('X-Forwarded-For', $result['forwarded_headers']);
        // 신뢰하지 않으므로 방문자 IP 가 직전 호출 IP 와 같다 = 모두 한 사람으로 기록된다.
        $this->assertSame($result['remote_addr'], $result['client_ip']);
    }

    /**
     * HTTP 전용 사이트가 프록시 뒤에 있어도 경고합니다 (조용한 구성).
     *
     * 이 케이스가 "HTTPS 인식 실패" 판정식과 갈라지는 유일한 지점이다. 화면은 정상이라
     * 사용자도 운영자도 이상을 감지할 단서가 없다.
     *
     * @scenario proxies_setting=unset, forwarded_proto=http, forwarded_for=single
     *
     * @effects http_only_behind_proxy_still_warns
     */
    public function test_htt_p_전용_사이트가_프록시_뒤에_있어도_경고한다(): void
    {
        config(['trustedproxy.proxies' => null]);

        $result = TrustedProxyDiagnostic::forRequest($this->request([
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
        ]));

        $this->assertFalse($result['is_secure'], '이 구성은 HTTPS 가 아니다 — 그럼에도 경고해야 한다.');
        $this->assertSame(TrustedProxyDiagnostic::STATUS_WARNING, $result['status']);
    }

    /**
     * 설정이 있으면 프록시 헤더를 받아도 정상입니다.
     *
     * @scenario proxies_setting=star, forwarded_proto=https, forwarded_for=single
     *
     * @effects configured_proxy_is_ok
     */
    public function test_설정이_있으면_정상으로_판정한다(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $result = TrustedProxyDiagnostic::forRequest($this->request([
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));

        $this->assertSame(TrustedProxyDiagnostic::STATUS_OK, $result['status']);
        $this->assertTrue($result['trusted_configured']);
        $this->assertSame('*', $result['configured_proxies']);
    }

    /**
     * 빈 문자열 설정은 미설정과 같게 다룹니다.
     *
     * `.env` 에 `TRUSTED_PROXIES=` 만 남긴 상태가 "설정됨" 으로 판정되면 경고가 조용히
     * 사라지는데, 미들웨어는 여전히 아무것도 신뢰하지 않는다.
     *
     * @effects empty_string_counts_as_unset
     */
    public function test_빈_문자열은_미설정과_같다(): void
    {
        config(['trustedproxy.proxies' => '   ']);

        $result = TrustedProxyDiagnostic::forRequest($this->request([
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
        ]));

        $this->assertFalse($result['trusted_configured']);
        $this->assertNull($result['configured_proxies']);
        $this->assertSame(TrustedProxyDiagnostic::STATUS_WARNING, $result['status']);
    }

    /**
     * 요청이 없는 맥락(콘솔)은 "값 없음" 이 아니라 "판정 불가" 입니다.
     *
     * @effects console_context_is_not_applicable
     */
    public function test_요청이_없으면_판정_불가로_구분한다(): void
    {
        config(['trustedproxy.proxies' => null]);

        $result = TrustedProxyDiagnostic::forRequest(null);

        $this->assertSame(TrustedProxyDiagnostic::STATUS_NOT_APPLICABLE, $result['status']);
        $this->assertNull($result['is_secure']);
        $this->assertNull($result['client_ip']);
        $this->assertNull($result['remote_addr']);
    }

    /**
     * 대시보드 알림은 경고 상태에서만, 심각도 `warning` 으로 추가됩니다.
     *
     * 알림 카드의 색상 분기는 `alert.type` 을 읽으므로, 여기서 `warning` 이 아니면 경고가
     * 회색 안내로 렌더되어 경고로 보이지 않는다.
     *
     * @effects dashboard_alert_only_on_warning_with_warning_type
     */
    public function test_대시보드_알림은_경고_상태에서만_추가된다(): void
    {
        $listener = new TrustedProxyAlertListener;

        config(['trustedproxy.proxies' => '*']);
        $this->assertSame([], $listener->addTrustedProxyAlert([]), '설정이 있으면 알림을 추가하지 않아야 합니다.');

        config(['trustedproxy.proxies' => null]);
        app()->instance('request', $this->request([
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
        ]));

        $alerts = $listener->addTrustedProxyAlert([]);

        $this->assertCount(1, $alerts);
        $this->assertSame('trusted_proxy_missing', $alerts[0]['id']);
        $this->assertSame('warning', $alerts[0]['type'], '심각도가 warning 이 아니면 대시보드에서 경고로 렌더되지 않습니다.');
        $this->assertNotSame('', $alerts[0]['title']);
        $this->assertStringNotContainsString(':headers', $alerts[0]['message'], '치환 자리가 그대로 남아 있습니다.');
        $this->assertStringNotContainsString(':ip', $alerts[0]['message'], '치환 자리가 그대로 남아 있습니다.');
        $this->assertStringContainsString('TRUSTED_PROXIES', $alerts[0]['message'], '원인만이 아니라 조치도 담아야 합니다.');
    }

    /**
     * 진단 엔드포인트는 값 편집 경로를 제공하지 않습니다 (읽기 전용 계약).
     *
     * @effects diagnostic_endpoint_is_read_only
     */
    public function test_진단_엔드포인트는_읽기_전용이다(): void
    {
        $routes = app('router')->getRoutes();
        $names = [];

        foreach ($routes as $route) {
            if (str_contains((string) $route->uri(), 'settings/trusted-proxy')) {
                $names[] = implode('|', $route->methods());
            }
        }

        $this->assertNotSame([], $names, '진단 엔드포인트가 등록되어 있어야 합니다.');

        foreach ($names as $methods) {
            $this->assertStringNotContainsString('POST', $methods, '신뢰 프록시 값은 .env 전용입니다 — 쓰기 라우트를 두지 않습니다.');
            $this->assertStringNotContainsString('PUT', $methods);
            $this->assertStringNotContainsString('PATCH', $methods);
            $this->assertStringNotContainsString('DELETE', $methods);
        }
    }
}
