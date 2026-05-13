<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ScheduleHistoryResource extends BaseApiResource
{
    /**
     * 스케줄 실행 이력 리소스를 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청 객체
     * @return array<string, mixed> 변환된 이력 데이터 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'schedule_id' => $this->getValue('schedule_id'),
            'started_at' => $this->when(
                $this->getValue('started_at'),
                fn () => $this->formatDateTimeForUser($this->getValue('started_at'))
            ),
            'ended_at' => $this->when(
                $this->getValue('ended_at'),
                fn () => $this->formatDateTimeForUser($this->getValue('ended_at'))
            ),
            'duration' => $this->getValue('duration'),
            'duration_formatted' => $this->resource->duration_formatted ?? null,
            'status' => $this->getValue('status')?->value ?? $this->getValue('status'),
            'status_label' => $this->getStatusLabel(),
            'exit_code' => $this->getValue('exit_code'),
            'memory_usage' => $this->getValue('memory_usage'),
            'memory_usage_formatted' => $this->resource->memory_usage_formatted ?? null,
            'output' => $this->getValue('output'),
            'error_output' => $this->getValue('error_output'),
            'trigger_type' => $this->getValue('trigger_type')?->value ?? $this->getValue('trigger_type'),
            'trigger_type_label' => $this->getTriggerTypeLabel(),

            // 관계형 데이터
            'triggered_by' => $this->whenLoaded('triggeredBy', function () {
                $user = $this->getValue('triggeredBy');
                return $user ? [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                ] : null;
            }),

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 목록용 간단한 형태의 배열을 반환합니다.
     *
     * @return array<string, mixed> 간략한 이력 정보
     */
    public function toListArray(): array
    {
        return [
            'id' => $this->getValue('id'),
            'schedule_id' => $this->getValue('schedule_id'),
            'started_at' => $this->getValue('started_at')
                ? $this->formatDateTimeForUser($this->getValue('started_at'))
                : null,
            'ended_at' => $this->getValue('ended_at')
                ? $this->formatDateTimeForUser($this->getValue('ended_at'))
                : null,
            'duration' => $this->getValue('duration'),
            'duration_formatted' => $this->resource->duration_formatted ?? null,
            'status' => $this->getValue('status')?->value ?? $this->getValue('status'),
            'status_label' => $this->getStatusLabel(),
            'exit_code' => $this->getValue('exit_code'),
            'memory_usage_formatted' => $this->resource->memory_usage_formatted ?? null,
            'trigger_type' => $this->getValue('trigger_type')?->value ?? $this->getValue('trigger_type'),
            'trigger_type_label' => $this->getTriggerTypeLabel(),
            'triggered_by' => $this->resource->relationLoaded('triggeredBy') && $this->resource->triggeredBy
                ? [
                    'uuid' => $this->resource->triggeredBy->uuid,
                    'name' => $this->resource->triggeredBy->name,
                ]
                : null,
        ];
    }

    /**
     * 상태 라벨을 반환합니다.
     *
     * @return string|null 상태 라벨
     */
    protected function getStatusLabel(): ?string
    {
        $status = $this->getValue('status');

        if (!$status) {
            return null;
        }

        if (is_object($status) && method_exists($status, 'label')) {
            return $status->label();
        }

        return __('schedule.result.' . $status);
    }

    /**
     * 트리거 유형 라벨을 반환합니다.
     *
     * @return string|null 유형 라벨
     */
    protected function getTriggerTypeLabel(): ?string
    {
        $type = $this->getValue('trigger_type');

        if (!$type) {
            return null;
        }

        if (is_object($type) && method_exists($type, 'label')) {
            return $type->label();
        }

        return __('schedule.trigger_type.' . $type);
    }
}
