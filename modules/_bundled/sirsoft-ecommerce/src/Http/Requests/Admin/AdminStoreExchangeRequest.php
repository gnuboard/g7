<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesAdminClaimRequest;

/**
 * 관리자 교환 직권 접수 요청 (§3-11 · D5~D8)
 *
 * 고객 교환 신청(`User\StoreExchangeRequest`)과 같은 형태에 운영자 축(판매자 귀책 사유 · 배송비 직접
 * 입력 · 「무료」 · 「접수와 함께 승인」)을 더한다. 재발송지는 운영자가 고칠 수 있다 — 국가 변경 금지는
 * 서비스가 판정한다(교환 해외배송 미지원).
 */
class AdminStoreExchangeRequest extends FormRequest
{
    use ValidatesAdminClaimRequest;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인 (권한 체크는 라우트의 permission 미들웨어에서 수행)
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return $this->adminClaimRules(ClaimTypeEnum::EXCHANGE) + [
            'items.*.new_product_option_id' => ['nullable', 'integer'],
            'items.*.custom_options' => ['nullable', 'array'],
            'redelivery_address' => ['nullable', 'array'],
            'redelivery_address.recipient_name' => ['nullable', 'string', 'max:50'],
            'redelivery_address.phone' => ['nullable', 'string', 'max:20'],
            'redelivery_address.zipcode' => ['nullable', 'string', 'max:20'],
            'redelivery_address.address1' => ['nullable', 'string', 'max:255'],
            'redelivery_address.address2' => ['nullable', 'string', 'max:255'],
            'redelivery_address.city' => ['nullable', 'string', 'max:100'],
            'redelivery_address.state' => ['nullable', 'string', 'max:100'],
            'redelivery_address.country_code' => ['nullable', 'string', 'size:2'],
            'redelivery_address.changed_by_user' => ['nullable', 'boolean'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return $this->adminClaimMessages() + [
            'items.*.new_product_option_id.integer' => __('sirsoft-ecommerce::validation.claims.items.new_product_option_id_integer'),
            'items.*.custom_options.array' => __('sirsoft-ecommerce::validation.claims.items.custom_options_array'),
            'redelivery_address.array' => __('sirsoft-ecommerce::validation.claims.redelivery_address.array'),
            'redelivery_address.country_code.size' => __('sirsoft-ecommerce::validation.claims.redelivery_address.country_code_size'),
        ];
    }

    /**
     * 검증 오류 문구에 쓰는 필드 표시명
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    public function attributes(): array
    {
        return [
            'items.*.new_product_option_id' => __('sirsoft-ecommerce::validation.request_attributes.new_product_option'),
            'items.*.custom_options' => __('sirsoft-ecommerce::validation.request_attributes.custom_options'),
            'redelivery_address' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_address'),
            'redelivery_address.recipient_name' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_recipient_name'),
            'redelivery_address.phone' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_phone'),
            'redelivery_address.zipcode' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_zipcode'),
            'redelivery_address.address1' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_address1'),
            'redelivery_address.address2' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_address2'),
            'redelivery_address.city' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_city'),
            'redelivery_address.state' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_state'),
            'redelivery_address.country_code' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_country_code'),
            'redelivery_address.changed_by_user' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_changed_by_user'),
        ] + $this->adminClaimAttributes();
    }

    /**
     * 추가 검증 로직 — 접수 상품의 주문 소속 · 환불 계좌 완결성
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateAdminClaimItems($validator));
    }

    /**
     * 서비스에 넘길 교환 직권 접수 입력을 반환합니다. (공용 입력 + 재발송지)
     *
     * @return array<string, mixed> 접수 입력
     */
    public function toServiceInput(): array
    {
        return $this->toAdminInput() + [
            'redelivery_address' => $this->validated('redelivery_address'),
        ];
    }
}
