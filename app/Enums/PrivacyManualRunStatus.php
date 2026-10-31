<?php

namespace App\Enums;

/**
 * 개인정보 조치 직권 실행 기록 상태 Enum
 *
 * 큐에 접수된 작업이 처리 대기 → 처리 중 → 완료/실패로 옮겨 간다.
 */
enum PrivacyManualRunStatus: string
{
    /**
     * 처리 대기 (큐에 접수됨)
     */
    case Queued = 'queued';

    /**
     * 처리 중
     */
    case Running = 'running';

    /**
     * 완료
     */
    case Completed = 'completed';

    /**
     * 실패
     */
    case Failed = 'failed';

    /**
     * 모든 상태 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 진행 중(아직 끝나지 않은) 상태 값 목록을 반환합니다.
     *
     * @return array<string> 처리 대기·처리 중
     */
    public static function activeValues(): array
    {
        return [self::Queued->value, self::Running->value];
    }

    /**
     * 아직 끝나지 않은 상태인지 반환합니다.
     *
     * @return bool 처리 대기·처리 중이면 true
     */
    public function isActive(): bool
    {
        return in_array($this->value, self::activeValues(), true);
    }

    /**
     * 상태의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 상태 라벨
     */
    public function label(): string
    {
        return __('privacy.manual_run.status.'.$this->value);
    }
}
