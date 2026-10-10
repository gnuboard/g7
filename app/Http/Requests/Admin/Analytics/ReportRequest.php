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
        ]);

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.report_validation_rules', $rules, $this);
    }

    /**
     * 검증 항목명을 반환합니다 — 오류 문구에 필드 키 대신 화면에서 쓰는 이름이 나오게 합니다.
     *
     * @return array<string, string> 필드 => 항목명 (부모 요청의 항목명 포함)
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'sort' => __('analytics.attributes.sort'),
            'q' => __('analytics.attributes.q'),
        ]);
    }
}
