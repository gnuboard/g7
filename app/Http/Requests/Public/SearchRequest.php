<?php

namespace App\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 통합 검색 요청 클래스
 *
 * 검색어, 페이지네이션 등 기본 검색 파라미터를 검증합니다.
 * 모듈별 추가 파라미터(type, sort, board_slug 등)는 훅을 통해 확장 가능합니다.
 */
class SearchRequest extends FormRequest
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
            // 기본 검색 파라미터
            'q' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', 'string'],
            'sort' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        // 예: sort의 in 규칙, board_slug 등 모듈별 파라미터
        return HookManager::applyFilters('core.search.validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.max' => __('search.validation.q_max'),
            'page.integer' => __('search.validation.page_integer'),
            'page.min' => __('search.validation.page_min'),
            'per_page.integer' => __('search.validation.per_page_integer'),
            'per_page.min' => __('search.validation.per_page_min'),
            'per_page.max' => __('search.validation.per_page_max'),
        ];
    }
}
