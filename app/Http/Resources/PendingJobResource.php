<?php

namespace App\Http\Resources;

use App\Services\JobPayloadParser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 큐 대기 작업(jobs 행) 리소스
 *
 * jobs 표의 시각 컬럼은 unix 초다. 상태는 reserved_at(워커가 집음)·available_at(지연)으로 판정한다.
 */
class PendingJobResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        $parsed = JobPayloadParser::parse($this->getValue('payload'));
        $reservedAt = $this->timestamp($this->getValue('reserved_at'));
        $availableAt = $this->timestamp($this->getValue('available_at'));

        return [
            'id' => (int) $this->getValue('id'),
            'uuid' => $parsed['uuid'],
            'queue' => $this->getValue('queue'),
            'display_name' => $parsed['display_name'],
            'job_class' => $parsed['job_class'],
            'schedule_id' => $parsed['schedule_id'],
            'attempts' => (int) $this->getValue('attempts', 0),
            'max_tries' => $parsed['max_tries'],
            'state' => $reservedAt !== null ? 'running' : ($availableAt !== null && $availableAt->isFuture() ? 'delayed' : 'waiting'),
            'reserved_at' => $this->formatDateTimeStringForUser($reservedAt),
            'available_at' => $this->formatDateTimeStringForUser($availableAt),
            'created_at' => $this->formatDateTimeStringForUser($this->timestamp($this->getValue('created_at'))),
            // 대기 작업에는 재시도·삭제 API 가 없다 — 화면이 버튼을 그리지 않도록 고정값으로 알린다.
            'abilities' => ['can_retry' => false, 'can_delete' => false],
        ];
    }

    /**
     * unix 초를 시각으로 바꿉니다.
     *
     * @param  mixed  $value  unix 초
     * @return Carbon|null 시각 (값이 없으면 null)
     */
    private function timestamp(mixed $value): ?Carbon
    {
        return is_numeric($value) && (int) $value > 0 ? Carbon::createFromTimestamp((int) $value) : null;
    }
}
