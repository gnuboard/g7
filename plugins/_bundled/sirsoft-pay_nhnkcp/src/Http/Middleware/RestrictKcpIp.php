<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Http\Middleware;

use App\Services\PluginSettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * KCP webhook 발신 IP 화이트리스트 가드 (그누보드5 settle_kcp_common.php 참고)
 *
 * 적용 라우트: vbank-notify / escrow-common-notify
 * (결제창 콜백 authCallback 은 브라우저 POST 라 IP 검증 안 함)
 *
 * 테스트 모드 (is_test_mode=true): 우회 — 개발 환경에서 KCP testadmin 외부 발신 IP
 *   에서 오는 모의입금 webhook 또는 개발자의 직접 테스트 시도를 허용 (그누보드5
 *   settle_kcp_common.php 의 $default['de_card_test'] 분기와 동일 정책).
 * 운영 모드 (is_test_mode=false): 화이트리스트 외 요청 403 차단.
 */
class RestrictKcpIp
{
    /**
     * KCP 공식 발신 IP 목록 — 그누보드5 settle_kcp_common.php 의 화이트리스트와 동일
     */
    private const KCP_NOTIFY_IPS = [
        '203.238.36.58',
        '203.238.36.160',
        '203.238.36.161',
        '203.238.36.173',
        '203.238.36.178',
        // 판교 IDC IP (2019-04-03 추가)
        '103.215.144.173',
        '103.215.144.174',
        '103.215.145.30',
        '210.122.72.173',
    ];

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
    ) {}

    /**
     * 요청 IP 검증 — 운영 모드 시 KCP 공식 발신 IP 외 모든 요청 403 차단
     *
     * @param  Request  $request  유입 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 통과 시 다음 미들웨어 응답, 차단 시 403
     */
    public function handle(Request $request, Closure $next): Response
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];

        // 테스트 모드는 명시적으로 켜진 경우에만 우회한다.
        // 설정 누락/오설정은 운영 모드처럼 fail-closed 로 처리해 webhook 무인증 노출을 막는다.
        if (array_key_exists('is_test_mode', $settings) && (bool) $settings['is_test_mode']) {
            return $next($request);
        }

        $clientIp = $request->ip() ?? '';

        if (! in_array($clientIp, self::KCP_NOTIFY_IPS, true)) {
            Log::warning('KCP: webhook from unauthorized IP — blocked', [
                'ip' => $clientIp,
                'url' => $request->fullUrl(),
                'user_agent' => $request->userAgent(),
            ]);

            abort(403, 'Forbidden');
        }

        return $next($request);
    }
}
