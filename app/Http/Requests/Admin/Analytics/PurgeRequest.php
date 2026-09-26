<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 전체 삭제 요청
 *
 * 되돌릴 수 없으므로 확인 없이는 통과하지 않습니다 — 화면의 체크박스가 이 값입니다.
 */
class PurgeRequest extends FormRequest
{
    /**
     * 인가는 라우트 미들웨어가 판정합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'confirm' => ['required', 'accepted'],
        ];

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.purge_validation_rules', $rules, $this);
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

    /**
     * 검증 항목명을 반환합니다 — 오류 문구에 필드 키 대신 화면에서 쓰는 이름이 나오게 합니다.
     *
     * @return array<string, string> 필드 => 항목명
     */
    public function attributes(): array
    {
        return [
            'confirm' => __('analytics.attributes.confirm'),
        ];
    }
}
