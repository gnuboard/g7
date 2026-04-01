<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ScheduleResource extends BaseApiResource
{
    /**
     * 스케줄 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 스케줄 데이터 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'name' => $this->getValue('name'),
            'description' => $this->getValue('description'),
            'type' => $this->getValue('type')?->value ?? $this->getValue('type'),
            'type_label' => $this->getTypeLabel(),
            'command' => $this->getValue('command'),
            'expression' => $this->getValue('expression'),
            'frequency' => $this->getValue('frequency')?->value ?? $this->getValue('frequency'),
            'frequency_label' => $this->getFrequencyLabel(),
            'without_overlapping' => (bool) $this->getValue('without_overlapping'),
            'run_in_maintenance' => (bool) $this->getValue('run_in_maintenance'),
            'timeout' => $this->getValue('timeout'),
            'is_active' => (bool) $this->getValue('is_active'),
            'last_result' => $this->getValue('last_result')?->value ?? $this->getValue('last_result'),
            'last_result_label' => $this->getLastResultLabel(),
            'last_run_at' => $this->when(
                $this->getValue('last_run_at'),
                fn () => $this->formatDateTimeForUser($this->getValue('last_run_at'))
            ),
            'last_duration' => $this->resource->last_duration ?? null,
            'next_run_at' => $this->when(
                $this->getValue('next_run_at'),
                fn () => $this->formatDateTimeForUser($this->getValue('next_run_at'))
            ),
            'extension_type' => $this->getValue('extension_type')?->value ?? $this->getValue('extension_type'),
            'extension_identifier' => $this->getValue('extension_identifier'),

            // 관계형 데이터
            'creator' => $this->whenLoaded('creator', function () {
                $creator = $this->getValue('creator');

                return $creator ? [
                    'uuid' => $creator->uuid,
                    'name' => $creator->name,
                ] : null;
            }),

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'core.schedules.create',
            'can_update' => 'core.schedules.update',
            'can_delete' => 'core.schedules.delete',
            'can_run' => 'core.schedules.run',
        ];
    }

    /**
     * 목록용 간단한 형태의 배열을 반환합니다.
     *
     * @param  Request|null  $request  HTTP 요청
     * @return array<string, mixed> 간략한 스케줄 정보
     */
    public function toListArray(?Request $request = null): array
    {
        $request = $request ?? request();

        return [
            'id' => $this->getValue('id'),
            'name' => $this->getValue('name'),
            'type' => $this->getValue('type')?->value ?? $this->getValue('type'),
            'type_label' => $this->getTypeLabel(),
            'command' => $this->getValue('command'),
            'expression' => $this->getValue('expression'),
            'frequency' => $this->getValue('frequency')?->value ?? $this->getValue('frequency'),
            'frequency_label' => $this->getFrequencyLabel(),
            'without_overlapping' => (bool) $this->getValue('without_overlapping'),
            'run_in_maintenance' => (bool) $this->getValue('run_in_maintenance'),
            'is_active' => (bool) $this->getValue('is_active'),
            'last_result' => $this->getValue('last_result')?->value ?? $this->getValue('last_result'),
            'last_result_label' => $this->getLastResultLabel(),
            'last_run_at' => $this->getValue('last_run_at')
                ? $this->formatDateTimeForUser($this->getValue('last_run_at'))
                : null,
            'last_duration' => $this->resource->last_duration ?? null,
            'next_run_at' => $this->getValue('next_run_at')
                ? $this->formatDateTimeForUser($this->getValue('next_run_at'))
                : null,
            'creator' => $this->resource->relationLoaded('creator') && $this->resource->creator
                ? [
                    'uuid' => $this->resource->creator->uuid,
                    'name' => $this->resource->creator->name,
                ]
                : null,
            'created_at' => $this->getValue('created_at')
                ? $this->formatDateForUser($this->getValue('created_at'))
                : null,
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 작업 유형 라벨을 반환합니다.
     *
     * @return string|null 유형 라벨
     */
    protected function getTypeLabel(): ?string
    {
        $type = $this->getValue('type');

        if (! $type) {
            return null;
        }

        if (is_object($type) && method_exists($type, 'label')) {
            return $type->label();
        }

        return __('schedule.type.'.$type);
    }

    /**
     * 실행 주기 라벨을 반환합니다.
     *
     * @return string|null 주기 라벨
     */
    protected function getFrequencyLabel(): ?string
    {
        $frequency = $this->getValue('frequency');

        if (! $frequency) {
            return null;
        }

        if (is_object($frequency) && method_exists($frequency, 'label')) {
            return $frequency->label();
        }

        return __('schedule.frequency.'.$frequency);
    }

    /**
     * 마지막 실행 결과 라벨을 반환합니다.
     *
     * @return string|null 결과 라벨
     */
    protected function getLastResultLabel(): ?string
    {
        $result = $this->getValue('last_result');

        if (! $result) {
            return null;
        }

        if (is_object($result) && method_exists($result, 'label')) {
            return $result->label();
        }

        return __('schedule.result.'.$result);
    }
}
