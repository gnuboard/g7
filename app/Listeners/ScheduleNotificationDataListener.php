<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Services\Schedule\ScheduleFailureNotifier;
use App\Services\Schedule\ScheduleLookupService;
use App\Support\ScreenUrl;
use App\Support\SiteUrl;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 예약 작업 알림 데이터 필터 리스너
 *
 * 알림 정의 `schedule_failed` 가 본문에 쓴 `{schedule_label}` 같은 자리를 실제 값으로 바꾸고,
 * 보내지 않을 실패(수동 실행·알림 꺼짐·오늘 이미 보냄)는 context.skip 으로 발송을 멈춥니다.
 * 판정 규칙은 ScheduleFailureNotifier 가 가진다.
 *
 * 수신자는 알림 정의의 `recipients`(관리자 역할)가 정합니다 — 여기서는 값만 만듭니다.
 */
class ScheduleNotificationDataListener implements HookListenerInterface
{
    /**
     * @param  ScheduleFailureNotifier  $notifier  실패 알림 판정
     * @param  ScheduleLookupService  $lookup  소유자 이름 해석
     */
    public function __construct(
        private readonly ScheduleFailureNotifier $notifier,
        private readonly ScheduleLookupService $lookup,
    ) {}

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array 훅 이름 → 메서드/우선순위/타입 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.schedule.notification.extract_data' => [
                'method' => 'extractData',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다 (HookListenerInterface 필수 메서드).
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void 없음 — 이 리스너는 필터로만 동작한다
     */
    public function handle(...$args): void {}

    /**
     * 알림 유형에 따라 데이터를 추출합니다.
     *
     * @param  array  $default  기본 extract_data 구조
     * @param  string  $type  알림 정의 유형
     * @param  array  $args  훅에서 전달된 원본 인수 (Schedule, ?ScheduleHistory, string 오류)
     * @return array{notifiable: null, notifiables: null, data: array, context: array} 추출 결과
     */
    public function extractData(array $default, string $type, array $args): array
    {
        if ($type !== 'schedule_failed') {
            return $default;
        }

        $schedule = $args[0] ?? null;
        $history = ($args[1] ?? null) instanceof ScheduleHistory ? $args[1] : null;

        if (! $schedule instanceof Schedule || ! $this->notifier->shouldNotify($schedule, $history)) {
            return $this->skip();
        }

        return $this->wrap($this->scheduleFailed($schedule, $history, $args[2] ?? null));
    }

    /**
     * 실패 알림 값을 만듭니다.
     *
     * @param  Schedule  $schedule  실패한 스케줄
     * @param  ScheduleHistory|null  $history  실패 이력
     * @param  mixed  $error  실패 사유 (문자열, 큐 직렬화를 거치면 Throwable 은 null)
     * @return array<string, string> 치환 값
     */
    private function scheduleFailed(Schedule $schedule, ?ScheduleHistory $history, mixed $error): array
    {
        $message = match (true) {
            is_string($error) && $error !== '' => $error,
            $error instanceof Throwable => $error->getMessage(),
            default => (string) ($history?->error_output ?? ''),
        };

        $failedAt = Carbon::instance($history?->ended_at ?? $history?->started_at ?? Carbon::now())
            ->setTimezone((string) (config('app.schedule_timezone') ?? config('app.timezone', 'UTC')))
            // audit:allow datetime-display-user-timezone reason: 바로 위 줄에서 스케줄 시간대(사이트 설정 시간대)로 옮긴 Carbon — 변환을 마친 값
            ->format('Y-m-d H:i');

        return [
            'schedule_label' => $schedule->localizedLabel(),
            'schedule_name' => (string) $schedule->name,
            'owner' => $this->lookup->ownerName(
                $schedule->extension_type?->value ?? ScheduleLookupService::CUSTOM_OWNER_TYPE,
                $schedule->extension_identifier,
            ),
            // 메일 본문이 길어지지 않게 자른다 — 전체 출력은 실행 이력에 있다.
            'error' => mb_substr($message, 0, 500),
            'failed_at' => $failedAt,
            // 운영자가 여는 화면 주소 — 화면 키를 거쳐 주소 필터(core.screen_url.filter_path)를 받는다(#792 계약).
            'schedule_url' => ScreenUrl::absolute(
                'admin.schedules',
                '/admin/schedules?search_field=identifier&search_keyword={name}',
                ['name' => (string) $schedule->name],
                ScreenUrl::SCOPE_ADMIN,
            ),
        ];
    }

    /**
     * 발송을 멈추는 결과를 반환합니다.
     *
     * @return array{notifiable: null, notifiables: null, data: array, context: array{skip: true}} 건너뜀 결과
     */
    private function skip(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => ['skip' => true]];
    }

    /**
     * 치환 값에 공통 항목을 붙여 알림 체계가 기대하는 모양으로 감쌉니다.
     *
     * @param  array<string, string>  $data  치환 값
     * @return array{notifiable: null, notifiables: null, data: array<string, string>, context: array<string, mixed>} 추출 결과
     */
    private function wrap(array $data): array
    {
        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => array_merge([
                'app_name' => (string) config('app.name'),
                'name' => '{recipient_name}',
                'site_url' => SiteUrl::base(),
            ], $data),
            'context' => [],
        ];
    }
}
