<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * user_overrides 인지 Eloquent Builder.
 *
 * `HasUserOverrides` trait 이 적용된 모델의 mass update 를 가로채 user_overrides
 * 컬럼에 수정 필드를 자동 누적합니다.
 *
 * 동작 흐름:
 *  - `Model::where(...)->update([...])` 호출 시
 *  - 대상 행을 preload (1 SELECT)
 *  - 각 행별로 `calculateUserOverridesFor($values)` 호출하여 user_overrides 계산
 *  - per-row UPDATE 를 parent::update() 로 수행 (재귀 없이)
 *  - 시더 컨텍스트(`user_overrides.seeding`) 에서는 기본 경로 유지
 *  - 입력 $values 에 trackable 필드가 하나도 없으면 계산 건너뛰고 기본 경로 (성능 최적화)
 *
 * 성능 트레이드오프:
 *  - 기본: 1 UPDATE
 *  - 추적 활성: 1 SELECT + N UPDATE (trackable 필드 포함 시에만)
 *  - 관리자 UI 일괄 저장(수십 행 이하) 대상에는 차이 무시 가능.
 *  - 대량 처리는 `DB::table()->update()` (Eloquent 우회) 권장.
 *
 * @since 7.0.0-beta.2
 */
class UserOverridesAwareBuilder extends Builder
{
    /**
     * 쿼리 조건에 부합하는 모델을 업데이트합니다.
     *
     * @param  array<string, mixed>  $values
     * @return int 영향받은 행 수
     */
    public function update(array $values)
    {
        // 시더 컨텍스트에서는 trait 이벤트 handler 가 어차피 early return 하므로
        // 성능을 위해 기본 mass update 경로 사용.
        if (app()->bound('user_overrides.seeding') && app('user_overrides.seeding') === true) {
            return parent::update($values);
        }

        $model = $this->getModel();
        $trackable = method_exists($model, 'getTrackableFields')
            ? $model->getTrackableFields()
            : [];

        // 입력에 trackable 필드가 하나도 없으면 user_overrides 갱신 불필요 — 기본 경로.
        if (empty($trackable) || empty(array_intersect(array_keys($values), $trackable))) {
            return parent::update($values);
        }

        $models = $this->get();
        if ($models->isEmpty()) {
            return 0;
        }

        $affected = 0;
        $keyName = $model->getKeyName();

        foreach ($models as $row) {
            $newOverrides = $row->calculateUserOverridesFor($values);
            $rowValues = $values;

            // user_overrides 에 변경이 있으면 UPDATE payload 에 포함 (json cast 는 Eloquent 가 처리)
            if ($newOverrides !== ($row->user_overrides ?? [])) {
                $rowValues['user_overrides'] = $newOverrides;
            }

            // 재귀 없이 기본 UPDATE — PK 단일 행 UPDATE
            $affected += $row->newQueryWithoutScopes()
                ->where($keyName, $row->getKey())
                ->toBase()
                ->update($this->prepareValuesForRow($rowValues, $row));
        }

        return $affected;
    }

    /**
     * array-cast 필드를 DB 저장 형식(JSON 문자열)으로 변환합니다.
     *
     * Eloquent Builder::update 는 toBase() 로 진입 시 array 값을 자동 JSON 인코딩하지
     * 않으므로, 본 메서드에서 모델의 cast 설정을 참조하여 사전 변환합니다.
     *
     * @param  array<string, mixed>  $values
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<string, mixed>
     */
    private function prepareValuesForRow(array $values, $model): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value) || is_object($value)) {
                // array/json cast 필드는 JSON 인코딩 후 저장
                $values[$key] = json_encode($value);
            }
        }

        return $values;
    }
}
