<?php

namespace App\Services;

use App\Support\OutboundProxy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 아웃바운드 프록시 연결 테스트.
 *
 * 운영자가 입력한 프록시가 실제로 동작하는지, 그리고 그 프록시를 거쳐 나갔을 때 상대편에
 * 어떤 IP 로 보이는지 확인합니다. 출발지 IP 는 결제사·외부 서비스에 등록해야 하는 값이라
 * 테스트의 핵심 산출물입니다 — 프록시를 켜기 전에 어떤 IP 를 등록해야 하는지 알 수 있어야
 * 저장하고 나서 되짚는 일이 없습니다.
 *
 * 검사 대상은 **저장된 설정이 아니라 이번에 제출된 값**입니다. 그래서 전역 옵션에 의존하지
 * 않고 요청마다 프록시를 명시합니다 — 저장 전에 확인하는 것이 목적이기 때문입니다.
 *
 * @since 7.0.8
 */
class OutboundProxyTester
{
    /**
     * 제출된 프록시 설정으로 외부 연결을 시도합니다.
     *
     * @param  string  $proxyUrl  검사할 프록시 주소
     * @param  array<int, string>  $bypass  프록시 예외 목록
     * @return array{success: bool, message_key: string, egress_ip: string|null, elapsed_ms: int, error: string|null} 검사 결과
     */
    public function test(string $proxyUrl, array $bypass = []): array
    {
        // 적용 시와 같은 조립·정규화를 거친다 — 여기서 손으로 배열을 만들면 저장 전에
        // 확인한 구성과 저장 후 실제로 적용되는 구성이 달라진다.
        $proxyOptions = OutboundProxy::options($proxyUrl, $bypass);

        if ($proxyOptions === null) {
            return $this->result(false, 'settings.outbound_proxy_test_invalid_url', null, 0);
        }

        $urls = (array) config('core.outbound_proxy.egress_lookup_urls', []);

        if ($urls === []) {
            return $this->result(false, 'settings.outbound_proxy_test_no_lookup_url', null, 0);
        }

        $timeout = (int) config('core.outbound_proxy.test_timeout_seconds', 10);
        $startedAt = microtime(true);
        $lastError = null;

        foreach ($urls as $url) {
            try {
                $response = Http::withOptions(['proxy' => $proxyOptions])
                    ->withHeaders(['User-Agent' => 'curl/8'])
                    ->timeout($timeout)
                    ->get($url);

                if (! $response->successful()) {
                    $lastError = 'HTTP '.$response->status();

                    continue;
                }

                $ip = trim($response->body());

                if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    $lastError = 'unexpected response body';

                    continue;
                }

                return $this->result(true, 'settings.outbound_proxy_test_success', $ip, $this->elapsedMs($startedAt));
            } catch (\Throwable $e) {
                // 개별 조회처 실패는 다음 후보로 넘어간다 — 한 곳이 죽어 있다고 프록시가
                // 잘못됐다고 단정할 수 없기 때문이다.
                $lastError = $e->getMessage();
            }
        }

        Log::warning('아웃바운드 프록시 연결 테스트 실패', ['error' => $lastError]);

        return $this->result(false, 'settings.outbound_proxy_test_failed', null, $this->elapsedMs($startedAt), $lastError);
    }

    /**
     * 경과 시간을 밀리초로 환산합니다.
     *
     * @param  float  $startedAt  시작 시각 (microtime)
     * @return int 경과 밀리초
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * 결과 배열을 구성합니다.
     *
     * @param  bool  $success  성공 여부
     * @param  string  $messageKey  다국어 메시지 키
     * @param  string|null  $egressIp  프록시를 거친 출발지 IP
     * @param  int  $elapsedMs  경과 밀리초
     * @param  string|null  $error  실패 원인 원문 (관리자 진단용)
     * @return array{success: bool, message_key: string, egress_ip: string|null, elapsed_ms: int, error: string|null}
     */
    private function result(bool $success, string $messageKey, ?string $egressIp, int $elapsedMs, ?string $error = null): array
    {
        return [
            'success' => $success,
            'message_key' => $messageKey,
            'egress_ip' => $egressIp,
            'elapsed_ms' => $elapsedMs,
            'error' => $error,
        ];
    }
}
