<?php

namespace App\Http\Resources;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Http\Requests\Schedule\UpdateScheduleRequest;
use App\Models\Schedule;
use App\Services\Schedule\ScheduleConditionEvaluator;
use App\Services\Schedule\ScheduleLookupService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Throwable;

class ScheduleResource extends BaseApiResource
{
    /**
     * 소유자 표시 이름 맵 (`type:identifier` => 이름). 컬렉션이 한 번 만들어 넘긴다.
     *
     * @var array<string, string>|null
     */
    private ?array $ownerNames = null;

    /**
     * 소유자 표시 이름 맵을 주입합니다 — 목록에서 행마다 확장 정보를 다시 읽지 않게 한다.
     *
     * @param  array<string, string>  $ownerNames  `type:identifier` => 이름
     * @return static 체이닝용 자기 자신
     */
    public function withOwnerNames(array $ownerNames): static
    {
        $this->ownerNames = $ownerNames;

        return $this;
    }

    /**
     * owners 요약에서 소유자 표시 이름 맵을 만듭니다.
     *
     * @param  string|null  $locale  표시 로케일 (null 이면 현재 로케일)
     * @return array<string, string> `type:identifier` => 이름
     */
    public static function buildOwnerNameMap(?string $locale = null): array
    {
        $map = [];

        foreach (app(ScheduleLookupService::class)->ownersSummary($locale ?? app()->getLocale()) as $owner) {
            $map[$owner['type'].':'.($owner['identifier'] ?? '')] = (string) ($owner['name'] ?? '');
        }

        return $map;
    }

    /**
     * 스케줄 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 스케줄 데이터 배열
     */
    public function toArray(Request $request): array
    {
        $nextRunAt = $this->displayNextRunAt();

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
            ...$this->registryFields(),
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
                $nextRunAt !== null,
                fn () => $this->formatDateTimeForUser($nextRunAt)
            ),
            'extension_type' => $this->getValue('extension_type')?->value ?? $this->getValue('extension_type'),
            'extension_identifier' => $this->getValue('extension_identifier'),

            // 관계형 데이터
            'creator' => $this->whenLoaded('creator', function () use ($request) {
                $creator = $this->getValue('creator');

                return $creator ? [
                    'uuid' => $creator->uuid,
                    ...$this->memberNameFields($creator, $request),
                ] : null;
            }),

            ...$this->formatTimestamps(),
            ...$this->scheduleMeta($request),
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
        $nextRunAt = $this->displayNextRunAt();

        return [
            'id' => $this->getValue('id'),
            'name' => $this->getValue('name'),
            'type' => $this->getValue('type')?->value ?? $this->getValue('type'),
            'type_label' => $this->getTypeLabel(),
            'command' => $this->getValue('command'),
            'expression' => $this->getValue('expression'),
            'description' => $this->getValue('description'),
            'frequency' => $this->getValue('frequency')?->value ?? $this->getValue('frequency'),
            'frequency_label' => $this->getFrequencyLabel(),
            ...$this->registryFields(),
            'run_in_maintenance' => (bool) $this->getValue('run_in_maintenance'),
            'timeout' => $this->getValue('timeout'),
            'is_active' => (bool) $this->getValue('is_active'),
            'last_result' => $this->getValue('last_result')?->value ?? $this->getValue('last_result'),
            'last_result_label' => $this->getLastResultLabel(),
            'last_run_at' => $this->getValue('last_run_at')
                ? $this->formatDateTimeForUser($this->getValue('last_run_at'))
                : null,
            'last_duration' => $this->resource->last_duration ?? null,
            'next_run_at' => $nextRunAt !== null
                ? $this->formatDateTimeForUser($nextRunAt)
                : null,
            'creator' => $this->resource->relationLoaded('creator') && $this->resource->creator
                ? [
                    'uuid' => $this->resource->creator->uuid,
                    ...$this->memberNameFields($this->resource->creator, $request),
                ]
                : null,
            'created_at' => $this->getValue('created_at')
                ? $this->formatDateForUser($this->getValue('created_at'))
                : null,
            ...$this->scheduleMeta($request),
        ];
    }

    /**
     * DB 등록제(7.2.0) 필드 — 상세·목록이 함께 쓴다.
     *
     * 소유 행(core/module/plugin)은 명령·유형·이름·조건·시간대를 화면에서 잠근다(`locked_fields`).
     *
     * @return array<string, mixed> 명칭·소유·실행 방식·조건 필드
     */
    protected function registryFields(): array
    {
        $schedule = $this->scheduleModel();
        $isOwned = $schedule?->isOwned() ?? false;

        return [
            'label' => $this->getValue('label'),
            'label_localized' => $schedule instanceof Schedule ? $schedule->localizedLabel() : $this->getValue('name'),
            'description_localized' => $schedule instanceof Schedule ? $schedule->localizedDescription() : null,
            'owner' => $this->resolveOwner(),
            'is_owned' => $isOwned,
            'locked_fields' => $isOwned ? UpdateScheduleRequest::OWNED_LOCKED_FIELDS : [],
            // 「운영자 수정됨」은 선언값을 재동기화가 덮지 않는다는 표시라 소유 행에만 있다 — 직접 등록 행은 값 전부가 운영자 것이다
            // (예전 판이 직접 등록 행에 남긴 기록은 보이지 않게 거른다)
            'overridden_fields' => $isOwned ? array_values((array) ($this->getValue('user_overrides') ?? [])) : [],
            'enabled_condition' => $this->getValue('enabled_condition') ?? [],
            'unmet_conditions' => $schedule instanceof Schedule
                ? array_values(app(ScheduleConditionEvaluator::class)->unmetConditions($schedule))
                : [],
            'dispatch_mode' => $this->getValue('dispatch_mode')?->value ?? $this->getValue('dispatch_mode'),
            'history_mode' => $this->getValue('history_mode')?->value ?? $this->getValue('history_mode'),
            // 잠금 유지 분(0 = 끔) — 옛 boolean 이 아니다
            'without_overlapping' => (int) $this->getValue('without_overlapping', 0),
            'on_one_server' => (bool) $this->getValue('on_one_server'),
            'notify_on_failure' => (bool) $this->getValue('notify_on_failure'),
            'queue_connection' => $this->getValue('queue_connection'),
            'queue_name' => $this->getValue('queue_name'),
            'timezone' => $this->getValue('timezone'),
        ];
    }

    /**
     * 감싼 원본 Schedule 모델을 돌려줍니다.
     *
     * 컬렉션은 항목을 한 번 Resource 로 감싼 뒤 넘기므로 겹친 래핑을 끝까지 벗긴다.
     *
     * @return Schedule|null 원본 모델 (배열 리소스면 null)
     */
    protected function scheduleModel(): ?Schedule
    {
        $model = $this->resource;
        while ($model instanceof JsonResource) {
            $model = $model->resource;
        }

        return $model instanceof Schedule ? $model : null;
    }

    /**
     * 소유자 정보를 반환합니다 — 직접 등록은 type `custom`, identifier null.
     *
     * @return array{type: string, identifier: string|null, name: string} 소유자 표시 정보
     */
    protected function resolveOwner(): array
    {
        $type = $this->getValue('extension_type');
        $type = $type instanceof ExtensionOwnerType ? $type->value : $type;

        if ($type === null || $type === '') {
            return [
                'type' => ScheduleRepositoryInterface::OWNER_TYPE_CUSTOM,
                'identifier' => null,
                'name' => __('schedule.owner.custom'),
            ];
        }

        $identifier = $this->getValue('extension_identifier');
        $this->ownerNames ??= self::buildOwnerNameMap();

        return [
            'type' => $type,
            'identifier' => $identifier,
            // 요약에 없으면(제거된 확장 등) 식별자를 그대로 보인다
            'name' => $this->ownerNames[$type.':'.($identifier ?? '')] ?? (string) $identifier,
        ];
    }

    /**
     * is_owner + abilities 메타와 삭제 불가 사유를 반환합니다.
     *
     * 소유 행은 확장이 선언한 것이라 삭제할 수 없다(동기화가 다시 만든다) — `can_delete` 를 끈다.
     * `can_delete` 하나로는 막힌 까닭(권한 없음·소유 잠김)을 가를 수 없어 화면 안내가 틀리므로
     * `delete_blocked_reason` 에 따로 싣는다 — `owned`(권한이 있어도 풀리지 않으므로 먼저) · `permission` · null(삭제 가능).
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 메타 데이터
     */
    protected function scheduleMeta(Request $request): array
    {
        $meta = $this->resourceMeta($request);

        if (! isset($meta['abilities']['can_delete'])) {
            return $meta;
        }

        $isOwned = $this->scheduleModel()?->isOwned() ?? false;
        $canDeleteByPermission = (bool) $meta['abilities']['can_delete'];

        if ($isOwned) {
            $meta['abilities']['can_delete'] = false;
        }

        $meta['delete_blocked_reason'] = match (true) {
            $isOwned => 'owned',
            ! $canDeleteByPermission => 'permission',
            default => null,
        };

        return $meta;
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

    /**
     * 화면에 보일 다음 실행 시각을 돌려줍니다.
     *
     * 저장된 다음 실행은 실행 뒤에만 갱신되므로 스케줄러가 멈춘 사이트에서는 지난 시각으로 남는다.
     * 활성 행의 저장값이 없거나 지났으면 주기·시간대로 지금 이후 첫 실행을 다시 계산한다(비활성 행은 저장값).
     *
     * @return Carbon|null 표시할 다음 실행 시각
     */
    private function displayNextRunAt(): ?Carbon
    {
        $stored = $this->getValue('next_run_at');
        $model = $this->scheduleModel();

        if ($model === null || ! $this->getValue('is_active')) {
            return $stored;
        }

        if ($stored !== null && $stored->isFuture()) {
            return $stored;
        }

        try {
            $next = app(ScheduleLookupService::class)->nextRunAt($model);
        } catch (Throwable) {
            return $stored;
        }

        return $next !== null ? Carbon::instance($next) : $stored;
    }
}
