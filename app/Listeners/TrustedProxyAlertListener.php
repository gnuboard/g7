<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\TrustedProxyDiagnostic;

/**
 * 신뢰 프록시 미설정 경고 리스너 (#124)
 *
 * 프록시 뒤에서 신뢰 프록시가 설정되지 않은 상태는 HTTPS 종단 구성에서는 화면 백지로 즉시
 * 드러나지만, HTTP 전용 사이트가 프록시 뒤에 있으면 **화면이 완전히 정상이다.** 그 상태에서도
 * webhook 403 · IP 기록 왜곡 · 로그인 제한 붕괴는 그대로 발생한다. 이 알림이 그 조용한 구성을
 * 덮는 유일한 통로다.
 *
 * 대시보드 레이아웃·컨트롤러·API 스키마는 건드리지 않는다 — 기존 `core.dashboard.alerts`
 * 필터 훅에 항목을 얹기만 한다(선례: StaticPublishFailureAlertListener).
 *
 * @since 7.0.10
 */
class TrustedProxyAlertListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환.
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.dashboard.alerts' => [
                'method' => 'addTrustedProxyAlert',
                'priority' => 15,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러).
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음
    }

    /**
     * 신뢰 프록시 미설정 경고를 대시보드에 추가합니다.
     *
     * @param  array  $alerts  기존 알림 배열
     * @return array 알림이 추가된 배열
     */
    public function addTrustedProxyAlert(array $alerts): array
    {
        $diagnostic = TrustedProxyDiagnostic::forRequest(request());

        if ($diagnostic['status'] !== TrustedProxyDiagnostic::STATUS_WARNING) {
            return $alerts;
        }

        $alerts[] = [
            'id' => 'trusted_proxy_missing',
            'type' => 'warning',
            'subtype' => 'trusted_proxy_missing',
            'icon' => 'exclamation-triangle',
            'title' => __('settings.trusted_proxy.alert_title'),
            // 원인과 조치를 함께 담는다 — 원인만 알려 주면 운영자가 어디를 고쳐야 할지 모른다.
            'message' => __('settings.trusted_proxy.alert_message', [
                'headers' => implode(', ', $diagnostic['forwarded_headers']),
                'ip' => (string) ($diagnostic['client_ip'] ?? '-'),
            ]),
            'time' => null,
            'read' => false,
        ];

        return $alerts;
    }
}
