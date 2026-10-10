<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

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
 *
 *     // 다국어 JSON 컬럼은 sub-key dot-path 단위 보존
 *     protected array $translatableTrackableFields = ['subject', 'body'];
 * }
 * ```
 *
 * 동작:
 * - 사용자가 trackable 필드를 수정 → user_overrides 에 자동 기록
 *   - 일반 컬럼: `['is_active']` (컬럼명)
 *   - 다국어 JSON 컬럼: `['name.ko']` (사용자가 ko 만 수정한 경우 sub-key 단위)
 * - 사용자 경로가 Eloquent 인스턴스 `->update()`, `->save()` 또는 mass update
 *   `Model::where(...)->update()` 중 어느 것이든 **모두 자동 추적**
 * - 시더/업그레이드 스텝에서 syncFromUpgrade() 호출 → 기록된 필드 보존
 *   다국어 컬럼은 보존 대상 locale 만 이전 값 유지, 나머지 locale 은 신규 값으로 갱신
 *
 * 컨테이너 플래그 `user_overrides.seeding` 으로 시더/사용자 컨텍스트를 구분합니다.
 *
 * 대량 처리(수천~수만 행) 가 필요해 이벤트 발화 비용을 피하려면
 * `DB::table('...')->update(...)` (Eloquent 우회) 사용을 권장합니다.
 *
 * @since 7.0.0-beta.2
 * @since 7.0.0-beta.4 다국어 JSON 컬럼 sub-key dot-path 단위 보존 (translatableTrackableFields)
 */
trait HasUserOverrides
{
    /**
     * 커스텀 Eloquent Builder 반환 — mass update 시에도 user_overrides 자동 기록.
     *
     * @param  Builder  $query
     * @return UserOverridesAwareBuilder
     */
    public function newEloquentBuilder($query): UserOverridesAwareBuilder
    {
        return new UserOverridesAwareBuilder($query);
    }

    /**
     * trait 부팅 — Eloquent updating 이벤트로 사용자 수정 자동 기록.
     *
     * @return void 반환값 없음
     */
    public static function bootHasUserOverrides(): void
    {
        static::updating(function (Model $model) {
            if (app()->bound('user_overrides.seeding') && app('user_overrides.seeding') === true) {
                return;
            }

            $userOverrides = $model->user_overrides ?? [];
            $original = $userOverrides;
            $translatable = $model->getTranslatableTrackableFields();

            foreach ($model->getTrackableFields() as $field) {
                if (! $model->isDirty($field)) {
                    continue;
                }

                // 표기만 다른 저장(공백·빈 문자열/null·[]/null·숫자 문자열)은 수정이 아니다 — 저장만 해도
                // "수정됨" 이 되면 새 버전 교정이 영영 막힌다(#727 §5.11).
                if (UserOverrideValueComparator::same(
                    $model->comparableTrackableValue($field, $model->getOriginal($field)),
                    $model->comparableTrackableValue($field, $model->getAttribute($field)),
                )) {
                    continue;
                }

                if (in_array($field, $translatable, true)) {
                    $userOverrides = $model->mergeTranslatableOverrides(
                        $userOverrides,
                        $field,
                        $model->getOriginal($field),
                        $model->getAttribute($field),
                    );

                    continue;
                }

                if (! in_array($field, $userOverrides, true)) {
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
     * 다국어 JSON 컬럼인 trackable 필드 목록을 반환합니다.
     *
     * 모델에서 `protected array $translatableTrackableFields = [...]` 로 오버라이드합니다.
     * 여기 등록된 필드는 sub-key dot-path 단위로 user_overrides 에 기록되며
     * (예: `['name.ko', 'name.en']`), syncFromUpgrade 시 sub-key 단위로 보존됩니다.
     *
     * trackableFields 와의 관계: translatableTrackableFields ⊂ trackableFields 이어야 합니다.
     *
     * @return array<int, string>
     */
    public function getTranslatableTrackableFields(): array
    {
        return property_exists($this, 'translatableTrackableFields') ? $this->translatableTrackableFields : [];
    }

    /**
     * 수정 여부를 가릴 때 쓸 값 — 같은 뜻을 여러 모양으로 저장하는 칸은 모델이 한 모양으로 맞춰 돌려준다.
     *
     * 기본은 값 그대로다. 예: 알림 템플릿 수신자 규칙은 역할 값을 문자열 하나(`"admin"`)로도, 목록(`["admin"]`)으로도
     * 저장하는데, 화면은 목록으로 받아 그대로 돌려보낸다 — 맞추지 않으면 저장만 해도 수정으로 기록된다.
     *
     * @param  string  $field  trackable 컬럼명
     * @param  mixed  $value  비교할 값
     * @return mixed 비교용 값
     */
    public function comparableTrackableValue(string $field, mixed $value): mixed
    {
        return $value;
    }

    /**
     * 다국어 필드 변경 비교 후 user_overrides 에 dot-path 항목을 누적 추가합니다.
     *
     * @param  array<int, string>  $userOverrides  현재 user_overrides 배열
     * @param  string  $field  컬럼명 (예: 'name')
     * @param  mixed  $original  변경 전 컬럼 값 (array 또는 null)
     * @param  mixed  $current  변경 후 컬럼 값 (array 또는 null)
     * @return array<int, string> 갱신된 user_overrides 배열
     */
    protected function mergeTranslatableOverrides(array $userOverrides, string $field, mixed $original, mixed $current): array
    {
        $originalArr = is_array($original) ? $original : [];
        $currentArr = is_array($current) ? $current : [];
        $allLocales = array_unique(array_merge(array_keys($originalArr), array_keys($currentArr)));

        foreach ($allLocales as $locale) {
            $before = $originalArr[$locale] ?? null;
            $after = $currentArr[$locale] ?? null;
            if (UserOverrideValueComparator::same($before, $after)) {
                continue;
            }
            $entry = "{$field}.{$locale}";
            if (! in_array($entry, $userOverrides, true)) {
                $userOverrides[] = $entry;
            }
        }

        return $userOverrides;
    }

    /**
     * 현재 모델 상태와 신규 입력 값을 비교하여 user_overrides 에 추가될 필드 집합을 계산합니다.
     *
     * `UserOverridesAwareBuilder` 가 mass update 시에도 user_overrides 를 누적하도록
     * 호출합니다. Eloquent save/event 경로를 우회하여도 동일한 로직을 재사용할 수 있도록
     * 공개 메서드로 노출합니다.
     *
     * @param  array<string, mixed>  $incomingAttributes  사용자 입력/mass update 값
     * @return array<int, string> 계산된 user_overrides 배열
     */
    public function calculateUserOverridesFor(array $incomingAttributes): array
    {
        $current = $this->user_overrides ?? [];
        $translatable = $this->getTranslatableTrackableFields();

        foreach ($this->getTrackableFields() as $field) {
            if (! array_key_exists($field, $incomingAttributes)) {
                continue;
            }
            $incoming = $incomingAttributes[$field];

            if (in_array($field, $translatable, true)) {
                $incomingArr = $this->normalizeTranslatableValue($incoming);
                if ($incomingArr === null) {
                    if (! UserOverrideValueComparator::same($this->{$field}, $incoming) && ! in_array($field, $current, true)) {
                        $current[] = $field;
                    }

                    continue;
                }
                $current = $this->mergeTranslatableOverrides($current, $field, $this->{$field}, $incomingArr);

                continue;
            }

            // 모델 저장(save)은 바뀐 칸을 **직렬화한 값**으로 이 빌더에 넘긴다 — 배열 캐스트 칸은 JSON 문자열이다.
            // 디코드하지 않으면 원래 값(null)과 문자열 `'[]'` 이 달라 보여, 아무것도 안 바꾼 저장이 수정으로 기록된다(#699).
            if (is_string($incoming) && $this->hasCast($field, ['array', 'json', 'object', 'collection', 'encrypted:array', 'encrypted:json'])) {
                $decoded = json_decode($incoming, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $incoming = $decoded;
                }
            }

            if (! UserOverrideValueComparator::same(
                $this->comparableTrackableValue($field, $this->{$field}),
                $this->comparableTrackableValue($field, $incoming),
            ) && ! in_array($field, $current, true)) {
                $current[] = $field;
            }
        }

        return $current;
    }

    /**
     * 다국어 값으로 정규화 — array 이면 그대로, JSON 문자열이면 디코드, 그 외 null.
     *
     * @param  mixed  $value
     * @return array<string, mixed>|null
     */
    protected function normalizeTranslatableValue(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * 업그레이드 스텝/시더에서 호출 — user_overrides 보존하며 갱신합니다.
     *
     * 갱신 값 계산은 {@see planUpgrade()} 가 한다.
     *
     * @param  array<string, mixed>  $newAttributes  시더 정의 값
     * @return void 반환값 없음
     */
    public function syncFromUpgrade(array $newAttributes): void
    {
        $updateData = $this->planUpgrade($newAttributes);

        if (empty($updateData)) {
            return;
        }

        app()->instance('user_overrides.seeding', true);
        try {
            if (array_key_exists('user_overrides', $updateData)) {
                $this->user_overrides = $updateData['user_overrides'];
                unset($updateData['user_overrides']);
            }

            if ($updateData === []) {
                $this->save();
            } else {
                $this->update($updateData);
            }
        } finally {
            app()->forgetInstance('user_overrides.seeding');
        }
    }

    /**
     * 선언값으로 갱신할 속성을 계산합니다 — 운영자가 고친 칸은 보존하고, 운영자 값과 새 선언값이 같아진 칸은 표식을 푼다.
     *
     * 일반 trackable 필드: user_overrides 에 컬럼명이 있으면 갱신 SKIP.
     * 다국어 trackable 필드 (translatableTrackableFields): sub-key 단위 머지.
     *   - user_overrides 에 `field.{locale}` 이 있으면 해당 locale 키만 기존 값 유지
     *   - 나머지 locale 키는 신규 값으로 갱신 (활성 언어팩 자동 동기화)
     *   - 호환성: user_overrides 에 컬럼명(`field`) 만 있는 legacy row 는 컬럼 전체 보존 (기존 동작)
     *
     * **합의 해제 규칙**(#727): 표식이 있는 칸이라도 지금 값과 새 선언값이 같으면 표식을 지운다 — 운영자 값과 새 버전 값이
     * 같아졌으니 더는 수정본이 아니고, 다음 버전의 변경부터 다시 들어와야 한다. 과거의 거짓 표식이 업데이트를 거치며
     * 저절로 줄어드는 경로다. 표식이 바뀌면 반환값에 `user_overrides` 키가 실린다.
     *
     * @param  array<string, mixed>  $newAttributes  시더·확장 정의 값
     * @return array<string, mixed> 갱신할 속성 (비었으면 갱신할 것이 없다)
     */
    public function planUpgrade(array $newAttributes): array
    {
        $userOverrides = $this->user_overrides ?? [];
        $remaining = $userOverrides;
        $trackable = $this->getTrackableFields();
        $translatable = $this->getTranslatableTrackableFields();
        $updateData = [];

        foreach ($newAttributes as $field => $value) {
            $isTrackable = in_array($field, $trackable, true);
            $isTranslatable = in_array($field, $translatable, true);

            // legacy 컬럼명 보존 (전체 컬럼 갱신 SKIP) — 값이 같아졌으면 표식만 푼다
            if ($isTrackable && in_array($field, $userOverrides, true)) {
                if (UserOverrideValueComparator::same(
                    $this->comparableTrackableValue($field, $this->getAttribute($field)),
                    $this->comparableTrackableValue($field, $value),
                )) {
                    $remaining = array_values(array_diff($remaining, [$field]));
                }

                continue;
            }

            if ($isTranslatable) {
                $newArr = $this->normalizeTranslatableValue($value);
                if ($newArr === null) {
                    $updateData[$field] = $value;

                    continue;
                }

                $currentArr = $this->normalizeTranslatableValue($this->getAttribute($field)) ?? [];
                $merged = $newArr;
                foreach ($newArr as $locale => $newVal) {
                    $entry = "{$field}.{$locale}";
                    if (! in_array($entry, $userOverrides, true) || ! array_key_exists($locale, $currentArr)) {
                        continue;
                    }

                    if (UserOverrideValueComparator::same($currentArr[$locale], $newVal)) {
                        $remaining = array_values(array_diff($remaining, [$entry]));

                        continue;
                    }

                    $merged[$locale] = $currentArr[$locale];
                }
                // 신규 값에 누락된 locale 키 중 사용자가 보존한 키는 유지
                foreach ($currentArr as $locale => $currentVal) {
                    if (array_key_exists($locale, $merged)) {
                        continue;
                    }
                    if (in_array("{$field}.{$locale}", $userOverrides, true)) {
                        $merged[$locale] = $currentVal;
                    }
                }

                $updateData[$field] = $merged;

                continue;
            }

            $updateData[$field] = $value;
        }

        if ($remaining !== $userOverrides) {
            $updateData['user_overrides'] = $remaining === [] ? null : $remaining;
        }

        return $updateData;
    }

    /**
     * 시더 컨텍스트에서 신규 생성 — user_overrides 자동 기록 비활성.
     *
     * @param  array<string, mixed>  $attributes  신규 생성 속성
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
     * @param  array<string, mixed>  $finder  updateOrCreate 의 첫 번째 인자
     * @param  array<string, mixed>  $attributes  시더 정의 값
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
