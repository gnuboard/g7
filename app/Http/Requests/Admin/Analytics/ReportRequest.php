<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Extension\HookManager;

/**
 * 상위 목록 리포트 요청
 *
 * 기간 규약에 목록 규약(쪽·정렬·검색·내보내기)을 얹습니다.
 */
class ReportRequest extends PeriodRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = array_merge(parent::rules(), [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:visits,hits,entries,exits'],
            'q' => ['nullable', 'string', 'max:255'],
            'format' => ['nullable', 'string', 'in:json,csv'],
        ]);

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.report_validation_rules', $rules, $this);
    }

    /**
     * CSV 로 내려받는 요청인지 확인합니다.
     *
     * @return bool CSV 요청이면 true
     */
    public function wantsCsv(): bool
    {
        return $this->input('format') === 'csv';
    }
}
