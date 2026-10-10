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
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesClaimOrder;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesClaimPaymentMethod;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesClaimRefundBank;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Rules\ExchangeRedeliveryCountryUnchanged;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\ClaimRefundAccountPolicy;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;

/**
 * 교환 신청 요청 (회원 · 비회원 공용)
 *
 * 마이페이지 주문상세에서 사용자가 교환을 신청할 때 쓴다. 신청 자격(상태·수량·잠금)과
 * 신규 옵션의 유효성은 서비스(`OrderExchangeService::requestExchange`)가 주문을 잠근 뒤
 * 판정하므로 여기서는 **형태**, **신청 상품의 주문 소속**(배열 항목의 상위 스코프), **재발송지
 * 국가 불변**(교환 해외배송 미지원)만 본다 — 자격 판정을 여기서 복제하면 잠금 밖에서 읽은 값으로
 * 통과시킨 뒤 서비스가 거절하는 두 벌의 규칙이 된다.
 *
 * 비회원 경로(`guest/orders/{orderNumber}/exchanges`)는 소유권 판정만 다르므로 같은 클래스가 받는다 —
 * 주문 해석(회원 ID / HMAC 토큰)은 `ResolvesClaimOrder` 가 흡수한다.
 */
class StoreExchangeRequest extends FormRequest
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
            // null/미전송 = 동일상품 교환 (D29 기본값). 같은 상품 소속 여부는 서비스가 판정한다.
            'items.*.new_product_option_id' => ['nullable', 'integer'],
            'items.*.custom_options' => ['nullable', 'array'],
            'reason_code' => [
                'required',
                'string',
                Rule::exists(ClaimReason::class, 'code')
                    ->where('type', ClaimTypeEnum::EXCHANGE->value)
                    ->where('is_active', true)
                    ->where('is_user_selectable', true),
            ],
            'reason_detail' => ['nullable', 'string', 'max:1000'],
            'charge_method' => ['nullable', 'string', Rule::in(ClaimChargeMethodEnum::values())],
            'payment_method' => $this->paymentMethodRules(),
            'refund_priority' => ['nullable', 'string', Rule::in(RefundPriorityEnum::values())],
            // 계좌 환불 주문의 환불 계좌 — 저가 교환은 차액이 구매자에게 돌아가므로 반품·취소·환불과
            // 같은 강도로 받는다. 돌려줄 돈이 없는 교환에서는 화면이 입력칸을 세우지 않는다.
            'refund_bank' => ['nullable', 'array'],
            'refund_bank.bank_code' => ['nullable', 'string', 'max:10'],
            'refund_bank.account_number' => ['nullable', 'string', 'max:50'],
            'refund_bank.holder' => ['nullable', 'string', 'max:50'],
            'image_ids' => ['nullable', 'array', 'max:'.$this->maxImages()],
            'image_ids.*' => ['integer'],
            'redelivery_address' => ['nullable', 'array'],
            'redelivery_address.recipient_name' => ['nullable', 'string', 'max:50'],
            'redelivery_address.phone' => ['nullable', 'string', 'max:20'],
            'redelivery_address.zipcode' => ['nullable', 'string', 'max:20'],
            'redelivery_address.address1' => ['nullable', 'string', 'max:255'],
            'redelivery_address.address2' => ['nullable', 'string', 'max:255'],
            'redelivery_address.city' => ['nullable', 'string', 'max:100'],
            'redelivery_address.state' => ['nullable', 'string', 'max:100'],
            'redelivery_address.country_code' => [
                'nullable',
                'string',
                'size:2',
                // 주문은 규칙이 실행되는 시점에 해석한다 — 규칙 목록을 만드는 시점에 조회하지 않는다.
                new ExchangeRedeliveryCountryUnchanged(fn (): ?Order => $this->resolveOrder()),
            ],
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
        return [
            'items.required' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.array' => __('sirsoft-ecommerce::validation.claims.items.array'),
            'items.min' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.*.order_option_id.required' => __('sirsoft-ecommerce::validation.claims.items.order_option_id_required'),
            'items.*.quantity.required' => __('sirsoft-ecommerce::validation.claims.items.quantity_required'),
            'items.*.quantity.min' => __('sirsoft-ecommerce::validation.claims.items.quantity_min'),
            'items.*.new_product_option_id.integer' => __('sirsoft-ecommerce::validation.claims.items.new_product_option_id_integer'),
            'items.*.custom_options.array' => __('sirsoft-ecommerce::validation.claims.items.custom_options_array'),
            'reason_code.required' => __('sirsoft-ecommerce::validation.claims.reason_code.required'),
            'reason_code.exists' => __('sirsoft-ecommerce::validation.claims.reason_code.exists'),
            'reason_detail.max' => __('sirsoft-ecommerce::validation.claims.reason_detail.max'),
            'refund_bank.array' => __('sirsoft-ecommerce::validation.claims.refund_bank.array'),
            'charge_method.in' => __('sirsoft-ecommerce::validation.claims.charge_method.in'),
            'payment_method.in' => __('sirsoft-ecommerce::validation.claims.payment_method.in'),
            'image_ids.array' => __('sirsoft-ecommerce::validation.claims.image_ids.array'),
            'image_ids.max' => trans_choice('sirsoft-ecommerce::validation.claims.image_ids.max', $maxImages = $this->maxImages(), ['max' => $maxImages]),
            'image_ids.*.integer' => __('sirsoft-ecommerce::validation.claims.image_ids.integer'),
            'redelivery_address.array' => __('sirsoft-ecommerce::validation.claims.redelivery_address.array'),
            'redelivery_address.country_code.size' => __('sirsoft-ecommerce::validation.claims.redelivery_address.country_code_size'),
            'redelivery_address.changed_by_user.boolean' => __('sirsoft-ecommerce::validation.claims.redelivery_address.changed_by_user_boolean'),
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
            'items.*.new_product_option_id' => __('sirsoft-ecommerce::validation.request_attributes.new_product_option'),
            'items.*.custom_options' => __('sirsoft-ecommerce::validation.request_attributes.custom_options'),
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
            // 소유권 위반은 존재 여부와 같은 404 로 답한다 — 다른 회원의 주문 번호를 짚어
            // "있지만 네 것이 아니다" 를 알려 주지 않는다 (취소 요청과 같은 계약). 비회원 경로는 토큰이 소유권이다.
            $order = $this->assertOrderOwnership();

            $this->validateItemsBelongToOrder($validator, $order);

            // 환불 계좌는 세 필드가 함께 와야 한다 — 하나만 오면 계좌 없는 무통장 환불이 승인된다.
            if (! $this->validateRefundBankComplete($validator)) {
                return;
            }

            // 가린 값이 섞인 계좌는 등록 계좌를 가린 모양 그대로가 아니면 받지 않는다 — 별표째 저장된다.
            if (! $this->validateRefundBankNotMasked($validator, $order)) {
                return;
            }

            // 계좌로 돈이 돌아가는 주문은 교환 접수에서도 계좌를 받는다 — 단 **돌려줄 차액이 있는
            // 교환**만이다(저가 교환). 같은 가격·고가 교환은 구매자에게 돌아갈 돈이 없고, 신청 화면도
            // 그때는 계좌 칸을 세우지 않는다(`refund_product_amount > 0` 일 때만).
            if ($validator->errors()->isEmpty() && $this->exchangeReturnsMoney($order)) {
                $this->validateRefundBankPresent($validator, $order);
            }
        });
    }

    /**
     * 이 교환이 구매자에게 돌려줄 차액을 만드는지 반환합니다. (저가 교환)
     *
     * 판정은 신청 화면의 미리보기와 **같은 계산**(`UserClaimService::preview()`)이다 — 화면이 계좌 칸을
     * 세우는 조건(`refund_product_amount > 0`)과 같은 값을 읽는다. 계좌가 필요 없는 주문이면 미리보기를
     * 돌리지 않는다. 미리보기가 신청 자격으로 거절하면 여기서 판정하지 않는다 — 그 거절은 서비스가
     * 접수 단계에서 같은 사유로 다시 낸다.
     *
     * @param  Order  $order  소유권이 확인된 주문
     * @return bool 돌려줄 차액이 있으면 true
     */
    protected function exchangeReturnsMoney(Order $order): bool
    {
        if (! app(ClaimRefundAccountPolicy::class)->missingForRequest($order, (array) $this->input('refund_bank', []))) {
            return false;
        }

        try {
            $preview = app(UserClaimService::class)->preview($order, ClaimTypeEnum::EXCHANGE, [
                'items' => (array) $this->input('items', []),
                'reason_code' => (string) $this->input('reason_code', ''),
                'charge_method' => $this->input('charge_method'),
                'refund_priority' => $this->input('refund_priority'),
                'redelivery_address' => $this->input('redelivery_address'),
            ]);
        } catch (OrderClaimException) {
            return false;
        }

        return (float) ($preview['refund_product_amount'] ?? 0) > 0;
    }

    /**
     * 신청 상품이 모두 이 주문의 것인지 검증합니다. (배열 항목의 상위 스코프)
     *
     * 소유권 404 **뒤에** 판정한다 — 앞에 두면 남의 주문에 어떤 상품 ID 가 있는지가 422/404 의
     * 차이로 새어 나간다. 다른 주문의 상품 ID 는 이 요청 맥락에서 유효하지 않은 입력이므로 422 다
     * (취소 요청의 `ValidatesCancelItems` 와 같은 판정). 서비스도 잠금 안에서 같은 판정을 다시 한다.
     *
     * @param  Validator  $validator  검증기
     * @param  Order  $order  소유권이 확인된 주문
     * @return void 반환값 없음
     */
    protected function validateItemsBelongToOrder(Validator $validator, Order $order): void
    {
        $order->loadMissing('options');

        foreach ((array) $this->input('items', []) as $index => $item) {
            $optionId = (int) ($item['order_option_id'] ?? 0);

            if (! $order->options->contains('id', $optionId)) {
                $validator->errors()->add(
                    "items.{$index}.order_option_id",
                    __('sirsoft-ecommerce::exceptions.claim_option_not_found')
                );
            }
        }
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
     * `requested_by` 는 인증 주체에서 채운다(비회원 경로는 null) — 요청 본문이 신청자를 지정하게 두면 다른
     * 회원 명의의 신청이 된다.
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
            'redelivery_address' => $validated['redelivery_address'] ?? null,
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
     * 신청 시점 결제 수단을 반환합니다. (미지정이면 null — 서비스가 허용 목록의 첫 수단을 쓴다)
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
