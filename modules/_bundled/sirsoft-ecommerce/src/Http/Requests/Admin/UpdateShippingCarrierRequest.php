<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 배송사 수정 요청
 */
class UpdateShippingCarrierRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 요청 허용 여부(권한은 라우트 미들웨어가 검사)
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
        $carrierId = $this->route('id');

        $rules = [
            'code' => ['sometimes', 'string', 'max:50', Rule::unique(ShippingCarrier::class, 'code')->ignore($carrierId), 'regex:/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/'],
            'name' => ['sometimes', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'type' => ['sometimes', 'string', Rule::in(['domestic', 'international'])],
            'tracking_url' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.shipping_carrier.update_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'code.unique' => __('sirsoft-ecommerce::validation.shipping_carrier.code_unique'),
            'code.regex' => __('sirsoft-ecommerce::validation.shipping_carrier.code_format'),
            'name.required' => __('sirsoft-ecommerce::validation.shipping_carrier.name_required'),
            'type.in' => __('sirsoft-ecommerce::validation.shipping_carrier.type_invalid'),
        ];
    }

    /**
     * 검증 오류 문구에 쓰는 필드 표시 이름
     *
     * 다국어 칸의 현재 언어 값을 비우면 「배송사명의 한국어(ko) 값은 필수입니다.」처럼 칸 이름을 싣는다.
     *
     * @return array<string, string> 필드 키 → 표시 이름
     */
    public function attributes(): array
    {
        return [
            'name' => __('sirsoft-ecommerce::validation.attributes.shipping_carrier_name'),
        ];
    }
}
