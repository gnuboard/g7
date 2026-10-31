<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesAdminClaimRequest;

/**
 * 관리자 직권 접수 창의 청구 미리보기 요청 (D7 — DB 변경 없음)
 *
 * 접수 창이 상품·사유·배송비·청구 방식을 바꿀 때마다 부른다. 사유는 입력 중일 수 있어 선택이다 —
 * 교환·반품 미리보기는 사유 없이 부르면 서비스가 사유 부재로 거절하고, 화면은 그 동안 금액 칸을 비운다.
 */
class AdminClaimRequestPreviewRequest extends FormRequest
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
        $rules = $this->adminClaimRules($this->claimType());
        $rules['reason_code'] = ['nullable', ...array_values(array_filter($rules['reason_code'], fn ($rule) => $rule !== 'required'))];

        return [
            'type' => ['required', 'string', Rule::in([ClaimTypeEnum::EXCHANGE->value, ClaimTypeEnum::RETURN->value, ClaimTypeEnum::REFUND->value])],
            'items.*.new_product_option_id' => ['nullable', 'integer'],
            'items.*.custom_options' => ['nullable', 'array'],
            'redelivery_address' => ['nullable', 'array'],
            'full_cancel' => ['nullable', 'boolean'],
            'auto_advance' => ['nullable', 'boolean'],
        ] + $rules;
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return $this->adminClaimMessages();
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
            'items.*.new_product_option_id' => __('sirsoft-ecommerce::validation.request_attributes.new_product_option'),
            'items.*.custom_options' => __('sirsoft-ecommerce::validation.request_attributes.custom_options'),
            'redelivery_address' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_address'),
            'full_cancel' => __('sirsoft-ecommerce::validation.request_attributes.full_cancel'),
            'auto_advance' => __('sirsoft-ecommerce::validation.request_attributes.auto_advance'),
        ] + $this->adminClaimAttributes();
    }

    /**
     * 추가 검증 로직 — 접수 상품의 주문 소속
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateAdminClaimItems($validator));
    }

    /**
     * 미리보기 유형을 반환합니다. (exchange / return / refund — 그 밖은 교환)
     *
     * @return ClaimTypeEnum 클레임 유형
     */
    public function claimType(): ClaimTypeEnum
    {
        // 규칙 계산 중에 읽으므로 검증 전 값이다 — 문자열이 아니면 형 변환하지 않는다(형식 오류는 규칙이 422 로 돌려준다).
        $input = $this->input('type');
        $type = is_string($input) ? ClaimTypeEnum::tryFrom($input) : null;

        return in_array($type, [ClaimTypeEnum::EXCHANGE, ClaimTypeEnum::RETURN, ClaimTypeEnum::REFUND], true)
            ? $type
            : ClaimTypeEnum::EXCHANGE;
    }

    /**
     * 서비스에 넘길 미리보기 입력을 반환합니다.
     *
     * @return array<string, mixed> 미리보기 입력
     */
    public function toServiceInput(): array
    {
        $validated = $this->validated();

        return [
            'items' => $validated['items'],
            'reason_code' => $validated['reason_code'] ?? '',
            'charge_method' => $validated['charge_method'] ?? null,
            'claim_shipping_fee' => $validated['claim_shipping_fee'] ?? null,
            'refund_priority' => $validated['refund_priority'] ?? null,
            'redelivery_address' => $validated['redelivery_address'] ?? null,
            'full_cancel' => (bool) ($validated['full_cancel'] ?? false),
            'auto_advance' => (bool) ($validated['auto_advance'] ?? false),
        ];
    }
}
