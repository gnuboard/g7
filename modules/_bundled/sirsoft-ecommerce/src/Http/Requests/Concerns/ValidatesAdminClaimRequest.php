<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimRequestService;

/**
 * 관리자 직권 접수 요청 공용 규칙 (교환·반품 접수 · 미리보기 — §3-11)
 *
 * 고객 신청 요청과 다른 점은 운영자 축뿐이다: 판매자 귀책 사유(`is_user_selectable = false`)도 받고,
 * 배송비 직접 입력(`claim_shipping_fee`)과 「무료」 청구 방식을 받으며, 「접수와 함께 승인」(`approve`)을
 * 받는다. 신청 자격(물리 게이트·수량 잠금)은 서비스가 주문을 잠근 뒤 판정한다 — 여기서 복제하지 않는다.
 * 대상 주문은 라우트 모델 바인딩(`{order}`)이 정하고, 요청은 **상품이 그 주문의 것인지**만 본다.
 */
trait ValidatesAdminClaimRequest
{
    /**
     * 직권 접수 공용 검증 규칙을 반환합니다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형 (사유 카탈로그 축)
     * @return array<string, mixed> 검증 규칙
     */
    protected function adminClaimRules(ClaimTypeEnum $type): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.order_option_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'reason_code' => [
                'required',
                'string',
                // 운영자는 판매자 귀책 사유(고객 화면에 노출하지 않는 사유)도 고른다 — D7.
                Rule::exists(ClaimReason::class, 'code')
                    ->where('type', $type->value)
                    ->where('is_active', true),
            ],
            'reason_detail' => ['nullable', 'string', 'max:1000'],
            'charge_method' => ['nullable', 'string', Rule::in([...ClaimChargeMethodEnum::values(), AdminClaimRequestService::CHARGE_FREE])],
            'claim_shipping_fee' => ['nullable', 'numeric', 'min:0'],
            'refund_priority' => ['nullable', 'string', Rule::in(RefundPriorityEnum::values())],
            'refund_bank' => ['nullable', 'array'],
            'refund_bank.bank_code' => ['nullable', 'string', 'max:10'],
            'refund_bank.account_number' => ['nullable', 'string', 'max:50'],
            'refund_bank.holder' => ['nullable', 'string', 'max:50'],
            'approve' => ['nullable', 'boolean'],
        ];
    }

    /**
     * 직권 접수 공용 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지 배열
     */
    protected function adminClaimMessages(): array
    {
        return [
            'items.required' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.array' => __('sirsoft-ecommerce::validation.claims.items.array'),
            'items.min' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.*.order_option_id.required' => __('sirsoft-ecommerce::validation.claims.items.order_option_id_required'),
            'items.*.quantity.required' => __('sirsoft-ecommerce::validation.claims.items.quantity_required'),
            'items.*.quantity.min' => __('sirsoft-ecommerce::validation.claims.items.quantity_min'),
            'reason_code.required' => __('sirsoft-ecommerce::validation.claims.reason_code.required'),
            'reason_code.exists' => __('sirsoft-ecommerce::validation.claims.reason_code.exists'),
            'reason_detail.max' => __('sirsoft-ecommerce::validation.claims.reason_detail.max'),
            'charge_method.in' => __('sirsoft-ecommerce::validation.claims.charge_method.in'),
            'claim_shipping_fee.numeric' => __('sirsoft-ecommerce::validation.claims.amounts.numeric'),
            'claim_shipping_fee.min' => __('sirsoft-ecommerce::validation.claims.amounts.min'),
            'refund_bank.array' => __('sirsoft-ecommerce::validation.claims.refund_bank.array'),
        ];
    }

    /**
     * 직권 접수 공용 규칙의 필드 표시명을 반환합니다.
     *
     * 이 규칙 키들은 요청 파일에 글자로 적히지 않아 라벨 검사(audit)가 보지 못한다 — 트레이트를 쓰는
     * 요청은 `attributes()` 에서 이 목록을 합친다.
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    protected function adminClaimAttributes(): array
    {
        return [
            'items' => __('sirsoft-ecommerce::validation.request_attributes.items'),
            'items.*.order_option_id' => __('sirsoft-ecommerce::validation.request_attributes.order_option'),
            'items.*.quantity' => __('sirsoft-ecommerce::validation.request_attributes.quantity'),
            'reason_code' => __('sirsoft-ecommerce::validation.request_attributes.reason_code'),
            'reason_detail' => __('sirsoft-ecommerce::validation.request_attributes.reason_detail'),
            'charge_method' => __('sirsoft-ecommerce::validation.request_attributes.charge_method'),
            'claim_shipping_fee' => __('sirsoft-ecommerce::validation.request_attributes.claim_shipping_fee'),
            'refund_priority' => __('sirsoft-ecommerce::validation.request_attributes.refund_priority'),
            'refund_bank' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank'),
            'refund_bank.bank_code' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_code'),
            'refund_bank.account_number' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_account'),
            'refund_bank.holder' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_holder'),
            'approve' => __('sirsoft-ecommerce::validation.request_attributes.approve_on_receipt'),
        ];
    }

    /**
     * 접수 상품이 모두 라우트의 주문에 속하는지 검증합니다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function validateAdminClaimItems(Validator $validator): void
    {
        $order = $this->route('order');

        if (! $order instanceof Order) {
            return;
        }

        $order->loadMissing('options');

        foreach ((array) $this->input('items', []) as $index => $item) {
            if (! $order->options->contains('id', (int) ($item['order_option_id'] ?? 0))) {
                $validator->errors()->add(
                    "items.{$index}.order_option_id",
                    __('sirsoft-ecommerce::exceptions.claim_option_not_found')
                );
            }
        }

        // 환불 계좌는 세 필드가 함께 와야 한다 — 하나만 오면 계좌 없는 무통장 환불이 승인된다.
        $bank = (array) $this->input('refund_bank', []);
        $filled = array_filter(['bank_code', 'account_number', 'holder'], fn (string $field): bool => filled($bank[$field] ?? null));

        if ($filled !== [] && count($filled) !== 3) {
            $validator->errors()->add('refund_bank', __('sirsoft-ecommerce::validation.order.refund_bank_required_with'));
        }
    }

    /**
     * 서비스에 넘길 직권 접수 입력을 반환합니다.
     *
     * @return array<string, mixed> 접수 입력
     */
    public function toAdminInput(): array
    {
        $validated = $this->validated();
        $bank = $validated['refund_bank'] ?? null;

        return [
            'items' => $validated['items'],
            'reason_code' => $validated['reason_code'],
            'reason_detail' => $validated['reason_detail'] ?? null,
            'charge_method' => $validated['charge_method'] ?? null,
            'claim_shipping_fee' => $validated['claim_shipping_fee'] ?? null,
            'refund_priority' => $validated['refund_priority'] ?? null,
            'refund_bank' => is_array($bank) && filled($bank['bank_code'] ?? null) ? [
                'bank_code' => (string) $bank['bank_code'],
                'account_number' => (string) ($bank['account_number'] ?? ''),
                'holder' => (string) ($bank['holder'] ?? ''),
            ] : null,
            // 「접수와 함께 승인」 은 기본 켜짐이다(D6).
            'approve' => (bool) ($validated['approve'] ?? true),
        ];
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
}
