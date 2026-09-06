<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Helpers\TimezoneHelper;
use App\Services\ExtensionStaticCacheService;
use Carbon\Carbon;

/**
 * 부트스트랩 리소스 정적 게시 실패 알림 리스너 (#122)
 *
 * 게시 실패는 사이트를 멈추지 않는다 — API 폴백으로 넘어가 화면은 정상이고, 서버 로그에만
 * warning 이 쌓인다. 그래서 쓰기 불가 환경에서 정적 fast path 가 **영구히 꺼진 채로**
 * 운영되는 상태를 아무도 눈치채지 못했다(제보 본건).
 *
 * 관리자 대시보드가 그 사실이 운영자에게 도달하는 통로다. 대시보드 레이아웃·컨트롤러·
 * API 스키마는 건드리지 않는다 — 기존 `core.dashboard.alerts` 필터 훅에 항목을 얹기만 한다.
 *
 * 표시 조건: 실패 마커가 존재하고 **연속 실패가 2회 이상**. 1회는 배포 중 일시적 경합일 수
 * 있고, 그 경우 다음 렌더의 자가 치유가 해소한다 — 매번 알리면 알림이 소음이 된다.
 *
 * @since 7.0.10
 */
class StaticPublishFailureAlertListener implements HookListenerInterface
{
    /**
     * 알림을 띄우기 시작하는 연속 실패 횟수.
     */
    private const ALERT_THRESHOLD = 2;

    /**
     * 구독할 훅과 메서드 매핑 반환.
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.dashboard.alerts' => [
                'method' => 'addStaticPublishAlert',
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
     * 정적 게시 실패 알림을 대시보드에 추가합니다.
     *
     * @param  array  $alerts  기존 알림 배열
     * @return array 알림이 추가된 배열
     */
    public function addStaticPublishAlert(array $alerts): array
    {
        $marker = ExtensionStaticCacheService::failureMarker();

        if ($marker === null) {
            return $alerts;
        }

        $count = (int) ($marker['count'] ?? 0);

        if ($count < self::ALERT_THRESHOLD) {
            return $alerts;
        }

        $reason = (string) ($marker['reason'] ?? 'write_failed');

        $alerts[] = [
            'id' => 'static_publish_failure',
            'type' => 'warning',
            // 원인별로 조치가 다르다 — 뭉뚱그리면 운영자가 어디를 봐야 할지 알 수 없다.
            'subtype' => 'static_publish_'.$reason,
            'icon' => 'exclamation-triangle',
            'title' => __('extensions.alerts.static_publish_failed_title'),
            'message' => __('extensions.alerts.static_publish_failed_'.$this->messageKeySuffix($reason), [
                'count' => $count,
            ]),
            'time' => isset($marker['at'])
                ? TimezoneHelper::toUserCarbon(Carbon::parse($marker['at']))?->diffForHumans()
                : null,
            'read' => false,
            // 알림에서 바로 복구한다 (#651 D11) — 운영자가 결함을 처음 만나는 곳이 알림이므로 그 자리에서
            // 끝나야 한다. 대시보드 렌더러는 `recover_endpoint` 가 있는 알림에 버튼을 그리고 POST 한다
            // (기존 재호환 알림과 같은 배선). 성공 문구는 재호환 알림의 기본 문구가 이 알림에는 맞지
            // 않으므로 리스너가 함께 싣는다.
            'recover_endpoint' => '/api/admin/settings/static-cache/republish',
            'recover_label' => __('extensions.alerts.static_publish_recover_label'),
            'recover_success_message' => __('extensions.alerts.static_publish_recovered'),
        ];

        return $alerts;
    }

    /**
     * 사유 코드를 다국어 키 접미사로 변환합니다.
     *
     * 알 수 없는 사유는 일반 문구로 떨어뜨린다 — 키가 없으면 번역기가 키 문자열을 그대로
     * 화면에 내보낸다.
     *
     * @param  string  $reason  사유 코드
     * @return string 다국어 키 접미사
     */
    private function messageKeySuffix(string $reason): string
    {
        return match ($reason) {
            'parent_not_writable', 'write_failed', 'lock_unavailable' => $reason,
            default => 'write_failed',
        };
    }
}
