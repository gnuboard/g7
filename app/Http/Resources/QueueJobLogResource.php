<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 큐 작업 처리 기록 리소스
 */
class QueueJobLogResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getValue('id'),
            'uuid' => $this->getValue('uuid'),
            'connection' => $this->getValue('connection'),
            'queue' => $this->getValue('queue'),
            'display_name' => $this->getValue('display_name'),
            'job_class' => $this->getValue('job_class'),
            'schedule_id' => $this->getValue('schedule_id'),
            'status' => $this->getValue('status')?->value ?? $this->getValue('status'),
            'attempts' => (int) $this->getValue('attempts', 0),
            'duration_ms' => $this->getValue('duration_ms'),
            'exception_class' => $this->getValue('exception_class'),
            'exception_message' => $this->getValue('exception_message'),
            'started_at' => $this->formatDateTimeStringForUser($this->resource->started_at),
            'finished_at' => $this->formatDateTimeStringForUser($this->resource->finished_at),
            'created_at' => $this->formatDateTimeStringForUser($this->resource->created_at),
            // 처리 기록은 읽기 전용 원장이다(정리는 보관 기간 명령만) — 행 단위 동작이 없다.
            'abilities' => ['can_retry' => false, 'can_delete' => false],
        ];
    }
}
