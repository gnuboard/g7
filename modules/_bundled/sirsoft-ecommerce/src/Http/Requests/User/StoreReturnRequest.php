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
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesClaimPaymentMethod;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesClaimRefundBank;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;

/**
 * 반품 신청 요청 (회원 · 비회원 공용)
 *
 * 신청 자격(상태·수량·잠금)은 서비스(`OrderReturnService::requestReturn`)가 주문을 잠근 뒤 판정하므로
 * 여기서는 형태와 신청 상품의 주문 소속(소유권 404 뒤 422)만 본다 — 교환 요청과 같은 강도.
 */
class StoreReturnRequest extends FormRequest
{
    use ResolvesClaimOrder;
    use ValidatesClaimPaymentMethod;
    use ValidatesClaimRefundBank;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 요청 허용 여부
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
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.order_option_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'reason_code' => [
                'required',
                'string',
                Rule::exists(ClaimReason::class, 'code')
                    ->where('type', ClaimTypeEnum::RETURN->value)
                    ->where('is_active', true)
                    ->where('is_user_selectable', true),
            ],
            'reason_detail' => ['nullable', 'string', 'max:1000'],
            'charge_method' => ['nullable', 'string', Rule::in(ClaimChargeMethodEnum::values())],
            'payment_method' => $this->paymentMethodRules(),
            'refund_priority' => ['nullable', 'string', Rule::in(RefundPriorityEnum::values())],
            // 계좌 환불 주문의 환불 계좌 — 취소·환불(`StoreRefundRequestRequest`)과 같은 형태다.
            // 반품도 계좌로 환불될 수 있으므로(§6-3 "무통장 원결제면 환불계좌 입력") 같은 강도로 받는다.
            'refund_bank' => ['nullable', 'array'],
            'refund_bank.bank_code' => ['nullable', 'string', 'max:10'],
            'refund_bank.account_number' => ['nullable', 'string', 'max:50'],
            'refund_bank.holder' => ['nullable', 'string', 'max:50'],
            'image_ids' => ['nullable', 'array', 'max:'.$this->maxImages()],
            'image_ids.*' => ['integer'],
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
            'items.required' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.array' => __('sirsoft-ecommerce::validation.claims.items.array'),
            'items.min' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.*.order_option_id.required' => __('sirsoft-ecommerce::validation.claims.items.order_option_id_required'),
            'items.*.quantity.required' => __('sirsoft-ecommerce::validation.claims.items.quantity_required'),
            'items.*.quantity.min' => __('sirsoft-ecommerce::validation.claims.items.quantity_min'),
            'reason_code.required' => __('sirsoft-ecommerce::validation.claims.reason_code.required'),
            'reason_code.exists' => __('sirsoft-ecommerce::validation.claims.reason_code.exists'),
            'reason_detail.max' => __('sirsoft-ecommerce::validation.claims.reason_detail.max'),
            'refund_bank.array' => __('sirsoft-ecommerce::validation.claims.refund_bank.array'),
            'charge_method.in' => __('sirsoft-ecommerce::validation.claims.charge_method.in'),
            'payment_method.in' => __('sirsoft-ecommerce::validation.claims.payment_method.in'),
            'image_ids.array' => __('sirsoft-ecommerce::validation.claims.image_ids.array'),
            'image_ids.max' => trans_choice('sirsoft-ecommerce::validation.claims.image_ids.max', $maxImages = $this->maxImages(), ['max' => $maxImages]),
            'image_ids.*.integer' => __('sirsoft-ecommerce::validation.claims.image_ids.integer'),
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
            'items' => __('sirsoft-ecommerce::validation.request_attributes.items'),
            'items.*.order_option_id' => __('sirsoft-ecommerce::validation.request_attributes.order_option'),
            'items.*.quantity' => __('sirsoft-ecommerce::validation.request_attributes.quantity'),
            'reason_code' => __('sirsoft-ecommerce::validation.request_attributes.reason_code'),
            'reason_detail' => __('sirsoft-ecommerce::validation.request_attributes.reason_detail'),
            'charge_method' => __('sirsoft-ecommerce::validation.request_attributes.charge_method'),
            'payment_method' => __('sirsoft-ecommerce::validation.request_attributes.payment_method'),
            'refund_priority' => __('sirsoft-ecommerce::validation.request_attributes.refund_priority'),
            'refund_bank' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank'),
            'refund_bank.bank_code' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_code'),
            'refund_bank.account_number' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_account'),
            'refund_bank.holder' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_holder'),
            'image_ids' => __('sirsoft-ecommerce::validation.request_attributes.image_ids'),
            'image_ids.*' => __('sirsoft-ecommerce::validation.request_attributes.image_id'),
        ];
    }

    /**
     * 추가 검증 로직 — 주문 존재·소유권 확인 후 신청 상품의 주문 소속 검사
     *
     * 소유권 404 가 상품 소속 422 보다 먼저 나야 하므로 순서를 바꾸지 않는다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $order = $this->assertOrderOwnership();
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
            if (! $this->validateRefundBankComplete($validator)) {
                return;
            }

            // 가린 값이 섞인 계좌는 등록 계좌를 가린 모양 그대로가 아니면 받지 않는다 — 별표째 저장된다.
            if (! $this->validateRefundBankNotMasked($validator, $order)) {
                return;
            }

            // 계좌로 돈이 돌아가는 주문은 반품 접수에서도 계좌를 받는다 — 취소·환불 접수와 같은
            // 판정이다. 반품은 완료 뒤 환불신청서로 이어지므로, 여기서 비워 두면 그 환불이 승인·실행
            // 단계에서야 보낼 곳이 없다는 것이 드러난다.
            $this->validateRefundBankPresent($validator, $order);
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
     * 서비스에 넘길 신청 페이로드를 반환합니다.
     *
     * @return array 신청 페이로드
     */
    public function toServicePayload(): array
    {
        $validated = $this->validated();

        return [
            'items' => $validated['items'],
            'reason_code' => $validated['reason_code'],
            'reason_detail' => $validated['reason_detail'] ?? null,
            'charge_method' => $validated['charge_method'] ?? null,
            'refund_bank' => $this->refundBankPayload($validated['refund_bank'] ?? null, $this->getOrder()),
            'refund_priority' => isset($validated['refund_priority'])
                ? RefundPriorityEnum::from($validated['refund_priority'])
                : RefundPriorityEnum::PG_FIRST,
            'requested_by' => $this->requesterId(),
        ];
    }

    /**
     * 귀속할 미귀속 첨부 ID 목록을 반환합니다.
     *
     * @return array<int, int> 첨부 ID 목록
     */
    public function imageIds(): array
    {
        return array_map('intval', (array) ($this->validated('image_ids') ?? []));
    }

    /**
     * 신청 시점 결제 수단을 반환합니다.
     *
     * @return string|null 결제 수단
     */
    public function paymentMethod(): ?string
    {
        $method = $this->validated('payment_method');

        return $method !== null && $method !== '' ? (string) $method : null;
    }

    /**
     * 첨부 장수 상한을 반환합니다. (설정 단일 출처)
     *
     * 리터럴을 두면 운영자가 `claim.max_images` 를 바꿨을 때 화면 안내와 실제 거절선이 갈린다 —
     * 상한을 낮추면 요청 게이트가 놓치고 서비스가 뒤늦게 거절하고, 올리면 게이트가 먼저 막는다.
     *
     * @return int 첨부 장수 상한
     */
    protected function maxImages(): int
    {
        return app(ClaimAttachmentService::class)->maxImages();
    }
}
