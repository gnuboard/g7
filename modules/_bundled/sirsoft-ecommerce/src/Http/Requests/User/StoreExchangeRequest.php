<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Rules\ExchangeRedeliveryCountryUnchanged;

/**
 * 교환 신청 요청 (회원)
 *
 * 마이페이지 주문상세에서 사용자가 교환을 신청할 때 쓴다. 신청 자격(상태·수량·잠금)과
 * 신규 옵션의 유효성은 서비스(`OrderExchangeService::requestExchange`)가 주문을 잠근 뒤
 * 판정하므로 여기서는 **형태**, **신청 상품의 주문 소속**(배열 항목의 상위 스코프), **재발송지
 * 국가 불변**(교환 해외배송 미지원)만 본다 — 자격 판정을 여기서 복제하면 잠금 밖에서 읽은 값으로
 * 통과시킨 뒤 서비스가 거절하는 두 벌의 규칙이 된다.
 *
 * 비회원 경로(`guest/orders/{orderNumber}/exchanges`)는 소유권 판정만 다르므로 같은 규칙
 * 집합(`rules()`)을 재사용하고, 주문 해석을 HMAC 토큰 기준으로 바꾼다.
 *
 * audit:allow api-doc-coverage 라우트·컨트롤러가 아직 없어(`POST user/orders/{id}/exchanges` 는 S5 소유)
 * 문서화할 엔드포인트가 생기지 않았다 — 엔드포인트를 배선하는 세션이 `api:docgen` 으로 함께 문서화한다.
 */
class StoreExchangeRequest extends FormRequest
{
    protected ?Order $order = null;

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
            'items' => ['required', 'array', 'min:1'],
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
            'refund_priority' => ['sometimes', 'string', Rule::in(RefundPriorityEnum::values())],
            'redelivery_address' => ['nullable', 'array'],
            'redelivery_address.recipient_name' => ['nullable', 'string', 'max:50'],
            'redelivery_address.phone' => ['nullable', 'string', 'max:20'],
            'redelivery_address.zipcode' => ['nullable', 'string', 'max:20'],
            'redelivery_address.address1' => ['nullable', 'string', 'max:255'],
            'redelivery_address.address2' => ['nullable', 'string', 'max:255'],
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
            'charge_method.in' => __('sirsoft-ecommerce::validation.claims.charge_method.in'),
            'redelivery_address.array' => __('sirsoft-ecommerce::validation.claims.redelivery_address.array'),
            'redelivery_address.country_code.size' => __('sirsoft-ecommerce::validation.claims.redelivery_address.country_code_size'),
            'redelivery_address.changed_by_user.boolean' => __('sirsoft-ecommerce::validation.claims.redelivery_address.changed_by_user_boolean'),
        ];
    }

    /**
     * 추가 검증 로직 — 주문 존재·소유권
     *
     * @param  Validator  $validator  검증기
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $order = $this->resolveOrder();

            // 소유권 위반은 존재 여부와 같은 404 로 답한다 — 다른 회원의 주문 번호를 짚어
            // "있지만 네 것이 아니다" 를 알려 주지 않는다 (취소 요청과 같은 계약).
            if (! $order || $order->user_id !== Auth::id()) {
                abort(ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.order_not_found',
                    404
                ));
            }

            $this->validateItemsBelongToOrder($validator, $order);
        });
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
     * 라우트의 주문을 해석합니다. (1회 조회 후 보관)
     *
     * 다른 주문 FormRequest 와 같이 검증 단계에서 처음 불린다 — 재발송지 국가 규칙은 클로저로
     * 이 메서드를 받아 규칙이 실제로 실행될 때 해석하고, `withValidator` 가 같은 결과를 재사용한다.
     * 배송지는 규칙이 곧바로 읽으므로 Repository 가 한 번에 싣는다(지연 로드 없음).
     * 주문이 없으면 null — 존재·소유권 판정은 `withValidator` 가 한다.
     *
     * @return Order|null 주문
     */
    protected function resolveOrder(): ?Order
    {
        if ($this->order !== null) {
            return $this->order;
        }

        $orderId = (int) $this->route('id');

        if ($orderId <= 0) {
            return null;
        }

        return $this->order = app(OrderRepositoryInterface::class)->findWithShippingAddress($orderId);
    }

    /**
     * 검증된 주문을 반환합니다.
     *
     * 검증을 통과한 요청에서만 부른다 — `withValidator` 가 주문 부재를 404 로 끝내므로 그 뒤에는
     * 항상 주문이 있다. 검증 전에 부르면 해석을 수행한다.
     *
     * @return Order 주문
     */
    public function getOrder(): Order
    {
        return $this->order ?? $this->resolveOrder()
            ?? abort(ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.order_not_found', 404));
    }

    /**
     * 서비스에 넘길 신청 페이로드를 반환합니다.
     *
     * `requested_by` 는 인증 주체에서 채운다 — 요청 본문이 신청자를 지정하게 두면 다른
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
            'refund_priority' => isset($validated['refund_priority'])
                ? RefundPriorityEnum::from($validated['refund_priority'])
                : RefundPriorityEnum::PG_FIRST,
            'redelivery_address' => $validated['redelivery_address'] ?? null,
            'requested_by' => Auth::id(),
        ];
    }
}
