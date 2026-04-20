<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * HasUserOverrides Trait.
 *
 * 모델에 user_overrides 필드 자동 관리 기능을 추가합니다.
 *
 * 사용법:
 * ```php
 * class NotificationTemplate extends Model {
 *     use HasUserOverrides;
 *
 *     protected array $trackableFields = ['subject', 'body', 'is_active'];
 * }
 * ```
 *
 * 동작:
 * - 사용자가 trackable 필드를 수정 → user_overrides 에 자동 기록
 * - 사용자 경로가 Eloquent 인스턴스 `->update()`, `->save()` 또는 mass update
 *   `Model::where(...)->update()` 중 어느 것이든 **모두 자동 추적**
 *   (mass update 는 커스텀 Builder 가 per-row 로 분해하여 `updating` 이벤트 발화)
 * - 시더/업그레이드 스텝에서 syncFromUpgrade() 호출 → 기록된 필드 보존
 *
 * 컨테이너 플래그 `user_overrides.seeding` 으로 시더/사용자 컨텍스트를 구분합니다.
 *
 * 대량 처리(수천~수만 행) 가 필요해 이벤트 발화 비용을 피하려면
 * `DB::table('...')->update(...)` (Eloquent 우회) 사용을 권장합니다.
 *
 * @since 7.0.0-beta.2
 */
trait HasUserOverrides
{
    /**
     * 커스텀 Eloquent Builder 반환 — mass update 시에도 user_overrides 자동 기록.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return UserOverridesAwareBuilder
     */
    public function newEloquentBuilder($query): UserOverridesAwareBuilder
    {
        return new UserOverridesAwareBuilder($query);
    }

    /**
     * trait 부팅 — Eloquent updating 이벤트로 사용자 수정 자동 기록.
     */
    public static function bootHasUserOverrides(): void
    {
        static::updating(function (Model $model) {
            if (app()->bound('user_overrides.seeding') && app('user_overrides.seeding') === true) {
                return;
            }

            $userOverrides = $model->user_overrides ?? [];
            $original = $userOverrides;

            foreach ($model->getTrackableFields() as $field) {
                if ($model->isDirty($field) && ! in_array($field, $userOverrides, true)) {
                    $userOverrides[] = $field;
                }
            }

            if ($userOverrides !== $original) {
                $model->user_overrides = $userOverrides;
            }
        });
    }

    /**
     * trackable 필드 목록을 반환합니다.
     *
     * 모델에서 `protected array $trackableFields = [...]` 로 오버라이드할 수 있습니다.
     *
     * @return array<int, string>
     */
    public function getTrackableFields(): array
    {
        return property_exists($this, 'trackableFields') ? $this->trackableFields : [];
    }

    /**
     * 현재 모델 상태와 신규 입력 값을 비교하여 user_overrides 에 추가될 필드 집합을 계산합니다.
     *
     * `UserOverridesAwareBuilder` 가 mass update 시에도 user_overrides 를 누적하도록
     * 호출합니다. Eloquent save/event 경로를 우회하여도 동일한 로직을 재사용할 수 있도록
     * 공개 메서드로 노출합니다.
     *
     * @param  array<string, mixed>  $incomingAttributes  사용자 입력/mass update 값
     * @return array<int, string>  계산된 user_overrides 배열
     */
    public function calculateUserOverridesFor(array $incomingAttributes): array
    {
        $current = $this->user_overrides ?? [];

        foreach ($this->getTrackableFields() as $field) {
            if (! array_key_exists($field, $incomingAttributes)) {
                continue;
            }
            $incoming = $incomingAttributes[$field];
            if ($this->{$field} != $incoming && ! in_array($field, $current, true)) {
                $current[] = $field;
            }
        }

        return $current;
    }

    /**
     * 업그레이드 스텝/시더에서 호출 — user_overrides 보존하며 갱신합니다.
     *
     * trackable 필드 + user_overrides 에 등록된 필드는 갱신을 건너뜁니다.
     *
     * @param array<string, mixed> $newAttributes 시더 정의 값
     */
    public function syncFromUpgrade(array $newAttributes): void
    {
        $userOverrides = $this->user_overrides ?? [];
        $trackable = $this->getTrackableFields();
        $updateData = [];

        foreach ($newAttributes as $field => $value) {
            if (in_array($field, $trackable, true) && in_array($field, $userOverrides, true)) {
                continue;
            }
            $updateData[$field] = $value;
        }

        if (empty($updateData)) {
            return;
        }

        app()->instance('user_overrides.seeding', true);
        try {
            $this->update($updateData);
        } finally {
            app()->forgetInstance('user_overrides.seeding');
        }
    }

    /**
     * 시더 컨텍스트에서 신규 생성 — user_overrides 자동 기록 비활성.
     *
     * @param array<string, mixed> $attributes 신규 생성 속성
     * @return static 생성된 모델 인스턴스
     */
    public static function createFromUpgrade(array $attributes): self
    {
        app()->instance('user_overrides.seeding', true);
        try {
            return static::create(array_merge(
                $attributes,
                ['user_overrides' => null],
            ));
        } finally {
            app()->forgetInstance('user_overrides.seeding');
        }
    }

    /**
     * 시더 헬퍼 — 존재하지 않으면 생성, 존재하면 syncFromUpgrade 호출합니다.
     *
     * @param array<string, mixed> $finder updateOrCreate 의 첫 번째 인자
     * @param array<string, mixed> $attributes 시더 정의 값
     * @return static 생성/갱신된 모델 인스턴스
     */
    public static function syncOrCreateFromUpgrade(array $finder, array $attributes): self
    {
        $existing = static::where($finder)->first();
        if (! $existing) {
            return static::createFromUpgrade(array_merge($finder, $attributes));
        }
        $existing->syncFromUpgrade($attributes);

        return $existing;
    }
}
