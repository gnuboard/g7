<?php

namespace App\Http\Requests\Role;

use App\Extension\HookManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RoleListRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다. 권한은 라우트의 permission 미들웨어가 검사합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 역할 목록 조회 검증 규칙을 반환합니다.
     *
     * 확장은 `core.role.list_validation_rules` 필터로 조회 파라미터 규칙을 더할 수 있습니다.
     *
     * @return array<string, ValidationRule|array<mixed>|string> 필드별 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
            'search' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ];

        return HookManager::applyFilters('core.role.list_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지를 반환합니다.
     *
     * @return array<string, string> 규칙별 오류 메시지
     */
    public function messages(): array
    {
        return [
            'page.integer' => __('validation.integer', ['attribute' => 'page']),
            'page.min' => __('validation.min.numeric', ['attribute' => 'page', 'min' => 1]),
            'per_page.integer' => __('validation.integer', ['attribute' => 'per_page']),
            'per_page.min' => __('validation.min.numeric', ['attribute' => 'per_page', 'min' => 1]),
            'per_page.max' => __('validation.max.numeric', ['attribute' => 'per_page', 'max' => 100]),
            'search.max' => trans_choice('validation.max.string', 255, ['attribute' => 'search', 'max' => 255]),
        ];
    }

    /**
     * 검증 전 데이터 전처리
     *
     * 쿼리 파라미터 문자열 "true"/"false"를 boolean으로 변환합니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_active') && is_string($this->is_active)) {
            $this->merge([
                'is_active' => filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }
}
