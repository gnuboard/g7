<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 상품 라벨 생성 요청
 */
class ProductLabelStoreRequest extends FormRequest
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
            'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 50)],
            'color' => 'required|string|max:20|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }

    /**
     * 검증 속성의 사용자 정의 이름
     *
     * @return array
     */
    public function attributes(): array
    {
        return [
            'name' => __('sirsoft-ecommerce::validation.attributes.label_name'),
            'name.*' => __('sirsoft-ecommerce::validation.attributes.label_name'),
            'color' => __('sirsoft-ecommerce::validation.attributes.label_color'),
            'is_active' => __('sirsoft-ecommerce::validation.attributes.is_active'),
            'sort_order' => __('sirsoft-ecommerce::validation.attributes.sort_order'),
        ];
    }

    /**
     * 검증 실패 메시지 커스텀
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => __('sirsoft-ecommerce::validation.label.name_required'),
            'color.required' => __('sirsoft-ecommerce::validation.label.color_required'),
            'color.regex' => __('sirsoft-ecommerce::validation.label.color_invalid'),
        ];
    }
}
