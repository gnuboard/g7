<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Category;

/**
 * 카테고리 목록 조회 요청
 */
class CategoryListRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', Rule::exists(Category::class, 'id')],
            'is_active' => 'nullable|boolean',
            'search' => 'nullable|string|max:100',
            'hierarchical' => 'boolean', // 트리 구조 여부
            'flat' => 'boolean', // 평면 리스트 여부 (TagInput 등에 사용)
            'max_depth' => 'nullable|integer|min:1|max:10', // 최대 깊이 제한
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => __('sirsoft-ecommerce::validation.list.parent_id.exists'),
            'is_active.boolean' => __('sirsoft-ecommerce::validation.list.is_active.boolean'),
            'search.string' => __('sirsoft-ecommerce::validation.list.search.string'),
            'search.max' => __('sirsoft-ecommerce::validation.list.search.max'),
            'hierarchical.boolean' => __('sirsoft-ecommerce::validation.list.hierarchical.boolean'),
            'flat.boolean' => __('sirsoft-ecommerce::validation.list.flat.boolean'),
            'max_depth.integer' => __('sirsoft-ecommerce::validation.list.max_depth.integer'),
            'max_depth.min' => __('sirsoft-ecommerce::validation.list.max_depth.min'),
            'max_depth.max' => __('sirsoft-ecommerce::validation.list.max_depth.max'),
        ];
    }
}