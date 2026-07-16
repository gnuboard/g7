<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * KG 이니시스 일본 결제 (DEVCBT, 테스트 모드) 호스트 연결 진단 컨트롤러
 *
 * 진단 대상:
 *   - devcbt.inicis.com (개발계 / 테스트 모드) — KG 이니시스가 별도로 머천트
 *     서버 IP 를 화이트리스트 등록해야 접근 가능
 *
 * 운영계 cbt.inicis.com 은 IP 화이트리스트 제약이 없어 진단 대상에서 제외.
 *
 * 반환 정보:
 *   - 서버 egress IP (외부 통신 시 사용되는 IP, 화이트리스트 등록 대상)
 *   - DNS 해석 결과 + TCP 443 연결 가능 여부 (3초 timeout)
 *
 * 운영자가 KG 이니시스 측에 IP 등록 요청 시 어느 IP 를 알려줘야 하는지,
 * 그리고 등록 후 실제로 통신이 가능해졌는지 확인하는 셀프 진단 도구.
 */
class AdminCbtConnectivityCheckController extends AdminBaseController
{
    /** TCP 연결 timeout 초 — KG 이니시스 서버가 응답 안 하는 경우 화면 차단 방지 */
    private const TCP_TIMEOUT_SECONDS = 3;

    /** Egress IP 조회 외부 서비스 timeout */
    private const EGRESS_LOOKUP_TIMEOUT = 3;

    /**
     * 진단 대상 호스트 목록 — devcbt 만 포함.
     *
     * cbt.inicis.com (운영계) 은 IP 화이트리스트 제약이 없어 화이트리스트
     * 진단의 의미가 없음. 운영계 도달성은 일반 결제 흐름 자체로 검증됨.
     */
    private const TARGET_HOSTS = [
        ['name' => 'devcbt.inicis.com', 'env' => 'test'],
    ];

    /** Egress IP 조회용 외부 서비스 (순차 시도) */
    private const EGRESS_LOOKUP_URLS = [
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://icanhazip.com',
    ];

    /**
     * 진단 실행 — 서버 IP + 호스트별 DNS / TCP 443 결과 반환
     *
     * @return JsonResponse
     */
    public function check(): JsonResponse
    {
        try {
            $egressIp = $this->detectEgressIp();
            $serverIp = (string) ($_SERVER['SERVER_ADDR'] ?? '');

            $hosts = [];
            foreach (self::TARGET_HOSTS as $target) {
                $hostname = $target['name'];
                $dns = @gethostbyname($hostname);
                $resolved = ($dns !== $hostname) ? $dns : null;

                $tcpResult = $this->checkTcp443($hostname);

                $hosts[] = [
                    'name' => $hostname,
                    'env' => $target['env'],
                    'dns_resolved_ip' => $resolved,
                    'tcp_443_reachable' => $tcpResult['reachable'],
                    'tcp_443_error' => $tcpResult['error'],
                    'tcp_443_latency_ms' => $tcpResult['latency_ms'],
                ];
            }

            return ResponseHelper::success(
                'sirsoft-pay_kginicis::messages.cbt_connectivity.checked',
                [
                    'egress_ip' => $egressIp,
                    'server_ip' => $serverIp,
                    'hosts' => $hosts,
                    'callback' => $this->buildCallbackDiagnostics(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('KG Inicis CBT connectivity check failed', [
                'message' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-pay_kginicis',
                'messages.cbt_connectivity.check_failed',
                500
            );
        }
    }

    /**
     * 서버 egress IP 조회. 외부 echo 서비스를 순차 시도.
     *
     * @return string|null IP 또는 null (모든 서비스 실패 시)
     */
    private function detectEgressIp(): ?string
    {
        foreach (self::EGRESS_LOOKUP_URLS as $url) {
            $ip = $this->fetchEgressIpFrom($url);
            if ($ip !== null) {
                return $ip;
            }
        }
        return null;
    }

    private function fetchEgressIpFrom(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::EGRESS_LOOKUP_TIMEOUT,
            CURLOPT_TIMEOUT => self::EGRESS_LOOKUP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        if (! is_string($body)) {
            return null;
        }
        $body = trim($body);
        return filter_var($body, FILTER_VALIDATE_IP) !== false ? $body : null;
    }

    /**
     * TCP 443 연결 가능 여부 확인.
     *
     * @return array{reachable: bool, error: ?string, latency_ms: ?int}
     */
    private function checkTcp443(string $host): array
    {
        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, 443, $errno, $errstr, self::TCP_TIMEOUT_SECONDS);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if ($fp === false) {
            return [
                'reachable' => false,
                'error' => $errstr !== '' ? $errstr : 'connect failed',
                'latency_ms' => $latencyMs,
            ];
        }

        fclose($fp);
        return [
            'reachable' => true,
            'error' => null,
            'latency_ms' => $latencyMs,
        ];
    }

    private function buildCallbackDiagnostics(): array
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $callbackUrl = url('/plugins/sirsoft-pay_kginicis/payment/cbt/callback');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $callbackHost = parse_url($callbackUrl, PHP_URL_HOST);

        return [
            'app_url' => $appUrl,
            'callback_url' => $callbackUrl,
            'app_url_https' => parse_url($appUrl, PHP_URL_SCHEME) === 'https',
            'callback_url_https' => parse_url($callbackUrl, PHP_URL_SCHEME) === 'https',
            'app_url_public' => $this->isPublicHostname(is_string($appHost) ? $appHost : ''),
            'callback_url_public' => $this->isPublicHostname(is_string($callbackHost) ? $callbackHost : ''),
            'host_matches_app_url' => $appHost !== null && $callbackHost !== null && $appHost === $callbackHost,
        ];
    }

    private function isPublicHostname(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || $host === 'localhost') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return ! str_ends_with($host, '.local');
    }
}
