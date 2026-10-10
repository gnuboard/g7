<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesClaimOrder;

/**
 * 클레임 신청 미리보기 요청 (회원 · 비회원 공용)
 *
 * 유형(exchange / return / refund)에 따라 서비스가 다른 산식을 돌린다. 사유·재발송지의 형태만 보고
 * 존재·자격은 서비스가 판정한다(미리보기 거절도 신청과 같은 사유 키로 422 에 실린다).
 */
class ClaimPreviewRequest extends FormRequest
{
    use ResolvesClaimOrder;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
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
        return [
            'type' => ['required', 'string', Rule::in([ClaimTypeEnum::EXCHANGE->value, ClaimTypeEnum::RETURN->value, ClaimTypeEnum::REFUND->value])],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.order_option_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.new_product_option_id' => ['nullable', 'integer'],
            'items.*.custom_options' => ['nullable', 'array'],
            // 교환·반품 미리보기는 사유의 귀책으로 배송비를 정하므로 사유 없이 계산할 수 없다.
            // `nullable` 로 두면 사유를 고르기 전 첫 진입에서 "선택한 사유를 사용할 수 없습니다"
            // 라는, 고른 적도 없는 값에 대한 도메인 오류가 나간다 — 필드 단위로 되돌린다.
            // 취소·환불(`refund`)은 회수 배송이 없어 사유가 금액에 개입하지 않는다.
            'reason_code' => [
                Rule::requiredIf(fn () => in_array($this->input('type'), [ClaimTypeEnum::EXCHANGE->value, ClaimTypeEnum::RETURN->value], true)),
                'nullable',
                'string',
                'max:50',
            ],
            'charge_method' => ['nullable', 'string', Rule::in(ClaimChargeMethodEnum::values())],
            'refund_priority' => ['nullable', 'string', Rule::in(RefundPriorityEnum::values())],
            'redelivery_address' => ['nullable', 'array'],
            'redelivery_address.zipcode' => ['nullable', 'string', 'max:20'],
            'redelivery_address.country_code' => ['nullable', 'string', 'size:2'],
            // 수정 화면의 미리보기 — 자기 신청서가 잡아 둔 수량을 잠금 집계에서 뺀다
            'claim_number' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'type.required' => __('sirsoft-ecommerce::validation.claims.type.required'),
            'type.in' => __('sirsoft-ecommerce::validation.claims.type.in'),
            'items.required' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.array' => __('sirsoft-ecommerce::validation.claims.items.array'),
            'items.min' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.max' => __('sirsoft-ecommerce::validation.claims.items.max'),
            'reason_code.required' => __('sirsoft-ecommerce::validation.claims.reason_code.required'),
            'items.*.order_option_id.required' => __('sirsoft-ecommerce::validation.claims.items.order_option_id_required'),
            'items.*.quantity.required' => __('sirsoft-ecommerce::validation.claims.items.quantity_required'),
            'items.*.quantity.min' => __('sirsoft-ecommerce::validation.claims.items.quantity_min'),
            'charge_method.in' => __('sirsoft-ecommerce::validation.claims.charge_method.in'),
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
            'type' => __('sirsoft-ecommerce::validation.request_attributes.claim_type'),
            'items' => __('sirsoft-ecommerce::validation.request_attributes.items'),
            'items.*.order_option_id' => __('sirsoft-ecommerce::validation.request_attributes.order_option'),
            'items.*.quantity' => __('sirsoft-ecommerce::validation.request_attributes.quantity'),
            'items.*.new_product_option_id' => __('sirsoft-ecommerce::validation.request_attributes.new_product_option'),
            'items.*.custom_options' => __('sirsoft-ecommerce::validation.request_attributes.custom_options'),
            'reason_code' => __('sirsoft-ecommerce::validation.request_attributes.reason_code'),
            'charge_method' => __('sirsoft-ecommerce::validation.request_attributes.charge_method'),
            'refund_priority' => __('sirsoft-ecommerce::validation.request_attributes.refund_priority'),
            'redelivery_address' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_address'),
            'redelivery_address.zipcode' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_zipcode'),
            'redelivery_address.country_code' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_country_code'),
            'claim_number' => __('sirsoft-ecommerce::validation.request_attributes.claim_number'),
        ];
    }

    /**
     * 추가 검증 로직 — 주문 존재·소유권
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->assertOrderOwnership();
        });
    }

    /**
     * 검증 실패 시 응답 커스터마이징
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, ResponseHelper::error(
            $validator->errors()->first(),
            422,
            $validator->errors()->toArray()
        ));
    }

    /**
     * 미리보기 유형을 반환합니다.
     *
     * @return ClaimTypeEnum 유형
     */
    public function claimType(): ClaimTypeEnum
    {
        return ClaimTypeEnum::from((string) $this->validated('type'));
    }
}
