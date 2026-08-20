<?php

namespace Tests\Unit\Support;

use App\Support\OutboundProxy;
use Tests\TestCase;

/**
 * 아웃바운드 프록시 적용 판정을 고정합니다.
 *
 * 이 판정은 코어가 바깥으로 내보내는 모든 HTTP 요청의 경로를 정합니다. 잘못 열리면 결제 승인
 * 요청까지 운영자가 지정한 제3의 서버를 경유하게 되고, 그 사실이 예외나 로그로 드러나지
 * 않습니다 — 요청은 정상 성공하고 상대편에 보이는 출발지 IP 만 달라집니다.
 *
 * 그래서 관리자 화면에서 입력칸을 감추는 것은 게이트가 아닙니다. 저장 API 를 직접 호출하면
 * 값은 그대로 저장되므로, 실질 게이트는 이 판정 하나입니다.
 *
 * 축 조합은 각 테스트 메서드의 @scenario 주석이 담당한다.
 */
class OutboundProxyTest extends TestCase
{
    /**
     * 디버그 모드가 꺼져 있으면 저장값이 남아 있어도 적용하지 않습니다.
     *
     * @scenario debug_mode=off, proxy_value=valid, bypass_list=empty
     *
     * @effects proxy_not_applied_when_debug_mode_off
     */
    public function test_proxy_is_not_applied_when_debug_mode_is_off(): void
    {
        $resolved = OutboundProxy::resolve([
            'mode' => false,
            'outbound_proxy' => 'socks5h://127.0.0.1:1080',
            'outbound_proxy_bypass' => ['internal.example'],
        ]);

        $this->assertNull(
            $resolved,
            '디버그 모드가 꺼진 상태에서 프록시가 적용되었습니다 — 화면 조건부 렌더링은 저장 API 직접 호출을 막지 못하므로 이 판정이 유일한 게이트입니다.'
        );
    }

    /**
     * mode 키 자체가 없는 설정도 미적용으로 판정합니다.
     *
     * @scenario debug_mode=off, proxy_value=valid, bypass_list=empty
     *
     * @effects proxy_not_applied_when_debug_mode_off
     */
    public function test_proxy_is_not_applied_when_mode_key_is_absent(): void
    {
        $this->assertNull(
            OutboundProxy::resolve(['outbound_proxy' => 'http://proxy.internal:3128']),
            'mode 키 부재를 디버그 모드 ON 으로 해석했습니다 — 판정은 fail-closed 여야 합니다.'
        );
    }

    /**
     * 디버그 모드가 켜져 있고 주소가 유효하면 http/https 양쪽에 적용합니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=empty
     *
     * @effects proxy_applied_when_debug_mode_on
     */
    public function test_proxy_is_applied_for_both_schemes_when_debug_mode_is_on(): void
    {
        $resolved = OutboundProxy::resolve([
            'mode' => true,
            'outbound_proxy' => 'socks5h://127.0.0.1:1080',
        ]);

        $this->assertSame([
            'http' => 'socks5h://127.0.0.1:1080',
            'https' => 'socks5h://127.0.0.1:1080',
            'no' => [],
        ], $resolved);
    }

    /**
     * 값이 비어 있으면 프록시를 쓰지 않는 상태로 판정합니다.
     *
     * @scenario debug_mode=on, proxy_value=empty, bypass_list=empty
     *
     * @effects proxy_applied_when_debug_mode_on
     */
    public function test_empty_proxy_value_disables_proxy(): void
    {
        $this->assertNull(OutboundProxy::resolve(['mode' => true, 'outbound_proxy' => '']));
        $this->assertNull(OutboundProxy::resolve(['mode' => true, 'outbound_proxy' => '   ']));
        $this->assertNull(OutboundProxy::resolve(['mode' => true]));
    }

    /**
     * 허용 목록에 없는 스킴과 호스트 없는 주소는 적용하지 않습니다.
     *
     * @scenario debug_mode=on, proxy_value=invalid, bypass_list=empty
     *
     * @effects proxy_rejects_disallowed_scheme
     *
     * @dataProvider invalidProxyUrls
     */
    public function test_invalid_proxy_url_is_not_applied(string $url, string $why): void
    {
        $this->assertNull(
            OutboundProxy::resolve(['mode' => true, 'outbound_proxy' => $url]),
            $why
        );
        $this->assertFalse(OutboundProxy::isValidUrl($url), $why);
    }

    /**
     * 적용 불가 주소 목록.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidProxyUrls(): array
    {
        return [
            '스킴 없음' => ['127.0.0.1:1080', '스킴 없는 주소가 통과했습니다 — cURL 이 프록시로 해석하지 못합니다.'],
            '파일 스킴' => ['file:///etc/passwd', 'file 스킴이 통과했습니다.'],
            'ftp 스킴' => ['ftp://proxy.internal:21', '허용 목록에 없는 스킴이 통과했습니다.'],
            '호스트 없음' => ['http://', '호스트 없는 주소가 통과했습니다.'],
            '빈 문자열' => ['', '빈 값이 유효 주소로 판정됐습니다.'],
        ];
    }

    /**
     * 허용 스킴은 모두 적용 가능해야 합니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=empty
     *
     * @effects proxy_applied_when_debug_mode_on
     */
    public function test_every_allowed_scheme_is_accepted(): void
    {
        foreach (OutboundProxy::ALLOWED_SCHEMES as $scheme) {
            $this->assertTrue(
                OutboundProxy::isValidUrl($scheme.'://proxy.internal:1080'),
                "허용 목록의 스킴 {$scheme} 이 거부됐습니다 — 목록과 판정이 어긋나면 저장은 되는데 적용되지 않습니다."
            );
        }
    }

    /**
     * 예외 목록은 공백 제거·빈 항목 제거·중복 제거 후 순번을 다시 매깁니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=normalizable
     *
     * @effects proxy_bypass_list_normalized
     */
    public function test_bypass_list_is_normalized(): void
    {
        $resolved = OutboundProxy::resolve([
            'mode' => true,
            'outbound_proxy' => 'http://proxy.internal:3128',
            'outbound_proxy_bypass' => [' g7.dev ', '', 'g7.dev', 'localhost', 42, null],
        ]);

        $this->assertSame(['g7.dev', 'localhost'], $resolved['no']);
        $this->assertSame(
            [0, 1],
            array_keys($resolved['no']),
            '예외 목록 키가 비연속입니다 — JSON 직렬화 시 객체가 되어 목록으로 읽히지 않습니다.'
        );
    }

    /**
     * 예외 목록이 배열이 아니면 빈 목록으로 취급합니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=normalizable
     *
     * @effects proxy_bypass_list_normalized
     */
    public function test_non_array_bypass_list_becomes_empty(): void
    {
        $resolved = OutboundProxy::resolve([
            'mode' => true,
            'outbound_proxy' => 'http://proxy.internal:3128',
            'outbound_proxy_bypass' => 'g7.dev',
        ]);

        $this->assertSame([], $resolved['no']);
    }

    /**
     * 적용 중인 프록시는 curl 옵션 형태로도 제공됩니다.
     *
     * `Http::` 파사드를 쓰지 못하는 호출 지점(외부 SDK 규약상 curl 핸들을 직접 다뤄야 하는
     * 경우)이 같은 프록시를 타려면 이 통로가 필요합니다. 이 통로가 없으면 그 경로만 조용히
     * 직접 나가고, 요청은 정상 성공하므로 아무 신호도 남지 않습니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=normalizable
     *
     * @effects proxy_exposed_as_curl_options
     */
    public function test_resolved_proxy_is_exposed_as_curl_options(): void
    {
        config(['g7.outbound_proxy' => OutboundProxy::resolve([
            'mode' => true,
            'outbound_proxy' => 'socks5h://127.0.0.1:1080',
            'outbound_proxy_bypass' => ['g7.dev', 'localhost'],
        ])]);

        $this->assertSame([
            CURLOPT_PROXY => 'socks5h://127.0.0.1:1080',
            CURLOPT_NOPROXY => 'g7.dev,localhost',
        ], OutboundProxy::curlOptions());
    }

    /**
     * 프록시 미적용 상태에서는 빈 배열이라 curl_setopt_array 에 그대로 넘겨도 무해합니다.
     *
     * @scenario debug_mode=off, proxy_value=valid, bypass_list=empty
     *
     * @effects proxy_exposed_as_curl_options
     */
    public function test_curl_options_are_empty_when_proxy_is_not_applied(): void
    {
        config(['g7.outbound_proxy' => OutboundProxy::resolve([
            'mode' => false,
            'outbound_proxy' => 'socks5h://127.0.0.1:1080',
        ])]);

        $this->assertSame([], OutboundProxy::curlOptions());
    }

    /**
     * 저장 전 연결 테스트와 실제 적용이 같은 조립·정규화를 거칩니다.
     *
     * 이 테스트의 목적은 "저장 전에 확인한다" 는 기능의 전제를 지키는 것입니다. 조립을 두 곳에서
     * 각각 하면 예외 목록의 공백·빈 항목·중복 처리가 어긋나, 운영자가 확인한 구성과 저장 후
     * 실제로 적용되는 구성이 달라집니다. 두 구성 모두 정상 동작하므로 어긋남 자체는 아무런
     * 오류도 남기지 않습니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=normalizable
     *
     * @effects proxy_bypass_list_normalized
     */
    public function test_connection_test_and_applied_config_share_the_same_assembly(): void
    {
        $url = '  socks5h://127.0.0.1:1080  ';
        $bypass = ['  g7.dev  ', '', 'g7.dev', 'localhost'];

        // 실제 적용 경로 (SettingsServiceProvider → config('g7.outbound_proxy'))
        $applied = OutboundProxy::resolve([
            'mode' => true,
            'outbound_proxy' => $url,
            'outbound_proxy_bypass' => $bypass,
        ]);

        // 저장 전 연결 테스트 경로 (OutboundProxyTester)
        $tested = OutboundProxy::options($url, $bypass);

        $this->assertSame($applied, $tested);
        $this->assertSame(['g7.dev', 'localhost'], $tested['no']);
        $this->assertSame('socks5h://127.0.0.1:1080', $tested['https']);
    }
}
