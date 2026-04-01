<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 브랜드 목록 조회 요청
 */
class BrandListRequest extends FormRequest
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
     * 검증 전 데이터 전처리
     *
     * URL 쿼리 파라미터는 문자열로 전달되므로 boolean 변환이 필요합니다.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $value = $this->input('is_active');
            // 문자열 "true", "1" → true, "false", "0" → false
            $this->merge([
                'is_active' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'is_active' => 'nullable|boolean',
            'search' => 'nullable|string|max:100',
            'sort' => 'nullable|string|in:name_asc,name_desc,created_asc,created_desc',
            'sort_by' => 'nullable|string|in:name,sort_order',
            'sort_order' => 'nullable|string|in:asc,desc',
            'locale' => 'nullable|string|in:' . implode(',', config('app.supported_locales', ['ko', 'en'])),
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
            'is_active.boolean' => __('sirsoft-ecommerce::validation.list.is_active.boolean'),
            'search.string' => __('sirsoft-ecommerce::validation.list.search.string'),
            'search.max' => __('sirsoft-ecommerce::validation.list.search.max'),
            'sort.string' => __('sirsoft-ecommerce::validation.list.sort.string'),
            'sort.in' => __('sirsoft-ecommerce::validation.list.sort.in'),
            'sort_by.string' => __('sirsoft-ecommerce::validation.list.sort_by.string'),
            'sort_by.in' => __('sirsoft-ecommerce::validation.list.sort_by.in'),
            'sort_order.string' => __('sirsoft-ecommerce::validation.list.sort_order.string'),
            'sort_order.in' => __('sirsoft-ecommerce::validation.list.sort_order.in'),
            'locale.string' => __('sirsoft-ecommerce::validation.list.locale.string'),
            'locale.in' => __('sirsoft-ecommerce::validation.list.locale.in'),
        ];
    }
}