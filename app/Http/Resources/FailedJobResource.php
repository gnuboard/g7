<?php

namespace App\Http\Resources;

use App\Services\JobPayloadParser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 큐 실패 작업 목록 행 리소스 (#17)
 *
 * 목록 조회는 예외 앞부분만 싣는다(JobQueueRepository::EXCEPTION_PREVIEW_LENGTH). 전문은
 * {@see FailedJobDetailResource} 가 준다.
 */
class FailedJobResource extends BaseApiResource
{
    /** 예외 요약 길이(자) */
    private const SUMMARY_LENGTH = 300;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->baseFields(),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 목록·상세 공통 필드를 반환합니다.
     *
     * @return array<string, mixed> 공통 필드
     */
    protected function baseFields(): array
    {
        $parsed = JobPayloadParser::parse($this->getValue('payload'));
        $exception = (string) $this->getValue('exception', '');

        return [
            'id' => (int) $this->getValue('id'),
            'uuid' => $this->getValue('uuid'),
            'connection' => $this->getValue('connection'),
            'queue' => $this->getValue('queue'),
            'display_name' => $parsed['display_name'],
            'job_class' => $parsed['job_class'],
            'schedule_id' => $parsed['schedule_id'],
            'attempts' => $parsed['attempts'],
            'max_tries' => $parsed['max_tries'],
            'exception_summary' => Str::limit(trim(strtok($exception, "\n") ?: ''), self::SUMMARY_LENGTH),
            'failed_at' => $this->formatDateTimeStringForUser($this->resource->failed_at),
        ];
    }

    /**
     * 리소스별 능력 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 => 권한
     */
    protected function abilityMap(): array
    {
        return [
            'can_retry' => 'core.queue_jobs.retry',
            'can_delete' => 'core.queue_jobs.delete',
        ];
    }
}
