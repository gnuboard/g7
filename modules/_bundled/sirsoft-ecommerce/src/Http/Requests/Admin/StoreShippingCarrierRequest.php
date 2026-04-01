<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 배송사 생성 요청
 */
class StoreShippingCarrierRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', Rule::unique(ShippingCarrier::class, 'code'), 'regex:/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/'],
            'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'type' => ['required', 'string', Rule::in(['domestic', 'international'])],
            'tracking_url' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.shipping_carrier.create_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'code.required' => __('sirsoft-ecommerce::validation.shipping_carrier.code_required'),
            'code.unique' => __('sirsoft-ecommerce::validation.shipping_carrier.code_unique'),
            'code.regex' => __('sirsoft-ecommerce::validation.shipping_carrier.code_format'),
            'name.required' => __('sirsoft-ecommerce::validation.shipping_carrier.name_required'),
            'type.required' => __('sirsoft-ecommerce::validation.shipping_carrier.type_required'),
            'type.in' => __('sirsoft-ecommerce::validation.shipping_carrier.type_invalid'),
        ];
    }
}
