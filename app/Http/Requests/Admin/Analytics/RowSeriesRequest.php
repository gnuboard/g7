<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Extension\HookManager;

/**
 * 한 항목의 날짜별 추이 요청
 *
 * 리포트 목록의 한 줄(페이지 하나·유입처 하나·브라우저 하나)이 기간 동안 어떻게 움직였는지를
 * 묻습니다. 기간 규약은 목록과 같고, 어느 항목인지를 저장 라벨로 받습니다.
 */
class RowSeriesRequest extends PeriodRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = array_merge(parent::rules(), [
            // 저장 라벨은 512자에서 잘린다(`DailyAccumulator::addDimension`).
            'label' => ['required', 'string', 'max:512'],
        ]);

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.row_series_validation_rules', $rules, $this);
    }

    /**
     * 검증 항목명을 반환합니다 — 오류 문구에 필드 키 대신 화면에서 쓰는 이름이 나오게 합니다.
     *
     * @return array<string, string> 필드 => 항목명 (부모 요청의 항목명 포함)
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'label' => __('analytics.attributes.label'),
        ]);
    }
}
