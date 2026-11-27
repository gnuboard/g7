<?php

namespace App\Enums;

/**
 * 큐 작업 처리 기록 결과
 *
 * queue_job_logs.status 의 SSoT.
 */
enum QueueJobLogStatus: string
{
    /**
     * 성공 — 워커가 예외 없이 처리를 마쳤다(재시도 대기로 돌려보낸 경우는 기록하지 않는다).
     */
    case Processed = 'processed';

    /**
     * 실패 — 최종 실패로 failed_jobs 로 옮겨졌다.
     */
    case Failed = 'failed';

    /**
     * 모든 결과 값을 배열로 반환합니다.
     *
     * @return array<int, string> 결과 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
