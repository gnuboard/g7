<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;

/**
 * 배송정책 클레임 설정 수정 요청 (회수지 + 국가별 클레임 배송비)
 *
 * 부분 수정 계약이다 — 보낸 축만 반영한다:
 * - `addresses.{exchange|return}`: 키가 있으면 upsert, 값이 null 이면 삭제, 키가 없으면 불변.
 * - `country_settings`: 나열된 국가만 갱신. 국가 코드는 이 정책의 국가별 설정에 실재해야
 *   한다 (배열 항목의 상위 스코프 — 남의 정책·미등록 국가는 422).
 */
class UpdateShippingPolicyClaimSettingsRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool 항상 true (권한 체크는 라우트의 permission 미들웨어에서 수행)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array 클레임 설정 수정 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'addresses' => ['nullable', 'array'],
            'country_settings' => ['nullable', 'array'],
            'country_settings.*.country_code' => [
                'required', 'string', 'max:10', 'distinct',
                Rule::exists(ShippingPolicyCountrySetting::class, 'country_code')
                    ->where('shipping_policy_id', (int) $this->route('id')),
            ],
            'country_settings.*.claim_fee_mode' => ['required', Rule::enum(ClaimShippingFeeModeEnum::class)],
            'country_settings.*.return_fixed_fee' => ['nullable', 'numeric', 'min:0'],
            'country_settings.*.exchange_fixed_fee' => ['nullable', 'numeric', 'min:0'],
        ];

        foreach (['exchange', 'return'] as $type) {
            $rules += [
                "addresses.{$type}" => ['nullable', 'array'],
                "addresses.{$type}.recipient_name" => ["required_with:addresses.{$type}", 'string', 'max:100'],
                "addresses.{$type}.phone" => ['nullable', 'string', 'max:20'],
                "addresses.{$type}.zipcode" => ['nullable', 'string', 'max:10'],
                "addresses.{$type}.address1" => ["required_with:addresses.{$type}", 'string', 'max:255'],
                "addresses.{$type}.address2" => ['nullable', 'string', 'max:255'],
                "addresses.{$type}.country_code" => ['nullable', 'string', 'max:10'],
            ];
        }

        return $rules;
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'country_settings.*.country_code.required' => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.country_code.required'),
            'country_settings.*.country_code.distinct' => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.country_code.distinct'),
            'country_settings.*.country_code.exists' => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.country_code.exists'),
            'country_settings.*.claim_fee_mode.required' => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.claim_fee_mode.required'),
            'country_settings.*.claim_fee_mode.Illuminate\Validation\Rules\Enum' => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.claim_fee_mode.enum'),
            'country_settings.*.return_fixed_fee.numeric' => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.fee.numeric'),
            'country_settings.*.return_fixed_fee.min' => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.fee.min'),
            'country_settings.*.exchange_fixed_fee.numeric' => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.fee.numeric'),
            'country_settings.*.exchange_fixed_fee.min' => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.fee.min'),
        ];

        foreach (['exchange', 'return'] as $type) {
            $messages += [
                "addresses.{$type}.recipient_name.required_with" => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.recipient_name.required'),
                "addresses.{$type}.recipient_name.max" => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.recipient_name.max'),
                "addresses.{$type}.address1.required_with" => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.address1.required'),
                "addresses.{$type}.address1.max" => __('sirsoft-ecommerce::validation.shipping_policy.claim_settings.address1.max'),
            ];
        }

        return $messages;
    }

    /**
     * 검증 오류 문구에 쓰는 필드 표시명
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    public function attributes(): array
    {
        return [
            'addresses' => __('sirsoft-ecommerce::validation.request_attributes.claim_addresses'),
            'country_settings' => __('sirsoft-ecommerce::validation.request_attributes.country_settings'),
            'country_settings.*.country_code' => __('sirsoft-ecommerce::validation.request_attributes.country_code'),
            'country_settings.*.claim_fee_mode' => __('sirsoft-ecommerce::validation.request_attributes.claim_fee_mode'),
            'country_settings.*.return_fixed_fee' => __('sirsoft-ecommerce::validation.request_attributes.return_fixed_fee'),
            'country_settings.*.exchange_fixed_fee' => __('sirsoft-ecommerce::validation.request_attributes.exchange_fixed_fee'),
        ];
    }
}
