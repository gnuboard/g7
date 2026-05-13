<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 상품정보제공고시 템플릿 수정 요청
 */
class UpdateProductNoticeTemplateRequest extends FormRequest
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
        $rules = [
            'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'category' => 'nullable|string|max:100',
            'fields' => 'required|array|min:1',
            'fields.*' => 'required|array',
            'fields.*.name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 200, minLength: 1)],
            'fields.*.content' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 2000, minLength: 1)],
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ];

        // 훅을 통한 동적 규칙 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product-notice-template.update_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => __('sirsoft-ecommerce::validation.product_notice_template.name_required'),
            'fields.required' => __('sirsoft-ecommerce::validation.product_notice_template.fields_required'),
            'fields.min' => __('sirsoft-ecommerce::validation.product_notice_template.fields_min'),
            'fields.*.name.required' => __('sirsoft-ecommerce::validation.product_notice_template.field_name_required'),
            'fields.*.content.required' => __('sirsoft-ecommerce::validation.product_notice_template.field_content_required'),
        ];
    }
}
