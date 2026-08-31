<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Execution\CommandRunnerResolver;
use App\Execution\ExecutionMode;
use App\Helpers\TimezoneHelper;
use App\Services\SystemExecutionService;

/**
 * 예약 작업 미실행 경고 (D19 · D30).
 *
 * 예약 작업이 기준 일수 이상 돌지 않았으면 관리자 대시보드에 한 줄 경고를 띄운다.
 * 예약이 멈춘 사이트는 오류를 내지 않는다 — 정리 배치가 조용히 사라지고, 만료 토큰과
 * 로그가 무한히 쌓이며, 사이트맵이 갱신되지 않는다. 그 침묵이 이 경고의 존재 이유다.
 *
 * **발화 기준은 실행 모드와 무관하다** (D30). 서버 크론을 걸 수 있는 단독 서버에서도
 * crontab 이 고장 나면 같은 일이 벌어지므로, "무CLI 환경에서만" 으로 좁히면 그 사고가
 * 사각에 남는다. 모드에 따라 갈리는 것은 **조치 안내 문구**뿐이다 —
 * 무CLI 는 호출 주소 연결을, 서버 크론 환경은 crontab 점검을 안내한다.
 *
 * @since 7.1.0
 */
class ScheduleHealthAlertListener implements HookListenerInterface
{
    /** 이 리스너가 내보내는 알림의 subtype (화면이 배지·아이콘 색을 이 값으로 고른다). */
    public const ALERT_SUBTYPE = 'schedule_not_running';

    /**
     * @param  SystemExecutionService  $service  예약 작업 상태 판정
     * @param  CommandRunnerResolver  $resolver  실행 모드 해석기 (조치 문구 분기용)
     */
    public function __construct(
        private readonly SystemExecutionService $service,
        private readonly CommandRunnerResolver $resolver,
    ) {}

    /**
     * 구독할 훅과 메서드 매핑 반환.
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.dashboard.alerts' => [
                'method' => 'addScheduleAlert',
                'priority' => 10,
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
     * 예약 작업 미실행 경고를 대시보드에 추가합니다.
     *
     * @param  array  $alerts  기존 알림 배열
     * @return array 알림이 추가된 배열
     */
    public function addScheduleAlert(array $alerts): array
    {
        try {
            if (! $this->service->isStale()) {
                return $alerts;
            }

            $lastExecuted = $this->service->lastExecutedAt();

            $alerts[] = [
                'id' => 'schedule_not_running',
                'type' => 'warning',
                'subtype' => self::ALERT_SUBTYPE,
                'icon' => 'clock',
                'title' => __('schedule.alerts.not_running_title', [
                    'days' => $lastExecuted === null
                        ? $this->service->staleAfterDays()
                        : (int) $lastExecuted->diffInDays(now()),
                ]),
                'message' => $this->guidance(),
                'action_path' => '/admin/settings?tab=system',
                // 링크에 붙일 문구도 함께 싣는다 — 화면이 라벨을 스스로 지어내면
                // 경고를 하나 더 만들 때 그 자리가 다시 빈다.
                'action_label' => __('schedule.alerts.not_running_action'),
                'time' => $lastExecuted !== null
                    ? TimezoneHelper::toUserCarbon($lastExecuted->toMutable())?->diffForHumans()
                    : null,
                'read' => false,
            ];
        } catch (\Throwable) {
            // 경고 생성 실패가 대시보드 전체 장애로 이어지지 않게 한다 —
            // 형제 리스너(ExtensionCompatibilityAlertListener)와 같은 방침이다.
        }

        return $alerts;
    }

    /**
     * 실행 모드에 맞는 조치 안내 문구를 고릅니다 (D30 — 문구만 분기).
     *
     * @return string 안내 문구
     */
    private function guidance(): string
    {
        return $this->resolver->resolveMode() === ExecutionMode::Inprocess
            ? __('schedule.alerts.not_running_guidance_web')
            : __('schedule.alerts.not_running_guidance_cron');
    }
}
