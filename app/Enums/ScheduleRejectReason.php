<?php

namespace App\Enums;

/**
 * 스케줄 실행 거절 사유 Enum
 *
 * 큐로 보낸 수동 실행(「지금 실행」)을 워커가 시작하지 않고 돌려보낸 사유다. 실행 이력의 「거절됨」 행
 * (`ScheduleResultStatus::Rejected`)이 `reject_reason` 칸에 싣는다. 값은 실행기가 던지는 거절의 사유 코드
 * (`ScheduleExecutionException::$reasonCode`, 경고 로그의 `reason`)와 같다.
 */
enum ScheduleRejectReason: string
{
    /**
     * 앞선 실행이 중복 방지 잠금을 잡고 있다
     */
    case AlreadyRunning = 'already_running';

    /**
     * 중복 방지 잠금 저장소(캐시)가 오류를 냈다
     */
    case RunLockUnavailable = 'run_lock_unavailable';

    /**
     * 소유 확장이 비활성이다
     */
    case OwnerInactive = 'owner_inactive';

    /**
     * 모든 사유 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 사유의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 사유 문구
     */
    public function label(): string
    {
        return __('schedule.reject_reason.'.$this->value);
    }
}
