<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 상품정보제공고시 템플릿 목록 조회 요청
 */
class ProductNoticeTemplateListRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
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
        if ($this->has('active_only')) {
            $value = $this->input('active_only');
            $this->merge([
                'active_only' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * per_page 는 정수 또는 전체 조회용 'all' 키워드를 허용합니다.
     *
     * @return array 검증 규칙
     */
    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:200',
            'active_only' => 'nullable|boolean',
            'per_page' => ['nullable', 'regex:/^(all|\d+)$/'],
            'page' => 'nullable|integer|min:1',
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 검증 오류 메시지
     */
    public function messages(): array
    {
        return [
            'search.string' => __('sirsoft-ecommerce::validation.list.search.string'),
            'search.max' => __('sirsoft-ecommerce::validation.list.search.max'),
            'active_only.boolean' => __('sirsoft-ecommerce::validation.list.active_only.boolean'),
            'per_page.regex' => __('sirsoft-ecommerce::validation.list.per_page.integer'),
            'page.integer' => __('sirsoft-ecommerce::validation.list.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.list.page.min'),
        ];
    }
}
