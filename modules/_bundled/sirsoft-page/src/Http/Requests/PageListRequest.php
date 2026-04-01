<?php

namespace Modules\Sirsoft\Page\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 페이지 목록 조회 요청
 */
class PageListRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'published' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'search_field' => ['nullable', 'string', 'in:all,title,slug'],
            'filters' => ['nullable', 'array'],
            'filters.*.field' => ['nullable', 'string', 'in:all,title,slug'],
            'filters.*.value' => ['nullable', 'string', 'max:255'],
            'filters.*.operator' => ['nullable', 'string', 'in:like,eq,starts_with,ends_with'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort_by' => ['nullable', 'string', 'in:created_at,published_at'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }

    /**
     * 검증 오류 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'published.boolean' => __('sirsoft-page::validation.published.boolean'),
            'search.max' => __('sirsoft-page::validation.search.max'),
            'search_field.in' => __('sirsoft-page::validation.search_field.in'),
            'per_page.integer' => __('sirsoft-page::validation.per_page.integer'),
            'per_page.min' => __('sirsoft-page::validation.per_page.min'),
            'per_page.max' => __('sirsoft-page::validation.per_page.max'),
            'sort_by.in' => __('sirsoft-page::validation.sort_by.in'),
            'sort_order.in' => __('sirsoft-page::validation.sort_order.in'),
        ];
    }
}
