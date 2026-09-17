<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Extension\HookManager;

/**
 * 정보주체 삭제 요청
 *
 * 조회와 같은 기준을 쓰되 되돌릴 수 없으므로 확인을 함께 받습니다.
 */
class SubjectDeleteRequest extends SubjectRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = array_merge(parent::rules(), [
            'confirm' => ['required', 'accepted'],
        ]);

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.subject_delete_validation_rules', $rules, $this);
    }

    /**
     * 검증 실패 문구를 반환합니다.
     *
     * @return array<string, string> 문구 목록
     */
    public function messages(): array
    {
        return [
            'confirm.required' => __('validation.analytics.confirm_required'),
            'confirm.accepted' => __('validation.analytics.confirm_required'),
        ];
    }
}
