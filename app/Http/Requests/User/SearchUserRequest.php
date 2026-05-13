<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 사용자 검색 요청 검증
 */
class SearchUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'keyword' => 'required_without:uuid|string|max:255',
            'uuid' => ['required_without:keyword', 'uuid', Rule::exists(User::class, 'uuid')],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.search_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'keyword.required_without' => __('user.validation.search_keyword_required'),
            'keyword.string' => __('user.validation.name_string'),
            'keyword.max' => __('user.validation.name_max'),
        ];
    }
}
