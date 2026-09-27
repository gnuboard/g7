<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Enums\Analytics\VisitorType;
use App\Extension\HookManager;
use Illuminate\Validation\Rule;

/**
 * 방문자 목록 조회 요청
 *
 * 방문자 화면의 첫 장입니다 — 방문 하나하나가 아니라 「사람」 단위 목록을 받습니다.
 * 기간 규약은 다른 통계 화면과 같고, 여기에 검색·유형 필터·쪽 나누기를 얹습니다.
 */
class VisitorIndexRequest extends PeriodRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = array_merge(parent::rules(), [
            // 운영자는 방문자 키·주소·회원 번호 중 무엇으로 찾을지 미리 고르지 않는다.
            'q' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', Rule::in(VisitorType::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.visitor_index_validation_rules', $rules, $this);
    }

    /**
     * 검증 항목명을 반환합니다 — 오류 문구에 필드 키 대신 화면에서 쓰는 이름이 나오게 합니다.
     *
     * @return array<string, string> 필드 => 항목명 (부모 요청의 항목명 포함)
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'q' => __('analytics.attributes.q'),
            'type' => __('analytics.attributes.type'),
        ]);
    }
}
