<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;

/**
 * 취소 가능 금액 조회 요청 (D19 — `GET admin/orders/{id}/cancellable-amount`)
 *
 * 두 문맥을 받는다 — `refund_id`(환불 상세: 그 신청서의 출처별 산식 + 자기 제외 선점) 또는
 * `items`(주문상세 환불 모달: 선택 상품 기준). 둘 다 없으면 미취소 옵션 전량 기준이다.
 * `items` 의 주문옵션은 상위 스코프(주문)를 부착해 검증한다 — 다른 주문의 옵션 ID 로
 * 이 주문의 한도를 묻는 교차 접근은 422 다.
 *
 * 두 문맥은 배타다 — 함께 오면 `prohibits` 로 거절한다. 서비스는 `refund_id` 를 먼저 보고
 * 즉시 반환하므로 게이트가 없으면 `items` 가 조용히 버려지고, 화면은 선택 상품 기준이라
 * 믿는 값 자리에 신청서 기준 상한을 표시한다(형제 `UpdateClaimAmountsRequest` 와 같은 결).
 */
class CancellableAmountRequest extends FormRequest
{
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
     * 검증 전 입력을 정규화합니다.
     *
     * 쿼리 스트링은 값을 문자열로 싣는다 — `?refund_id=` 처럼 빈 값으로 온 축은 제거해야
     * `prohibits` 가 "보내지 않은 것" 으로 본다. 남겨 두면 상품 기준 조회가 빈 `refund_id`
     * 하나 때문에 422 가 된다. 해석 불가한 값은 건드리지 않는다 — 오타 파라미터가
     * "지정 안 함" 으로 통과하면 안 되기 때문이다.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // GET 은 query 백이 입력 소스다 — request(POST) 백을 지우면 아무 효과가 없다.
        $source = $this->getInputSource();

        if ($source->get('refund_id') === '') {
            $source->remove('refund_id');
        }

        if ($source->get('items') === '') {
            $source->remove('items');
        }
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'refund_id' => ['nullable', 'integer', 'prohibits:items'],
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.order_option_id' => [
                'required_with:items',
                'integer',
                Rule::exists(OrderOption::class, 'id')->where('order_id', $this->orderId()),
            ],
            'items.*.cancel_quantity' => ['required_with:items', 'integer', 'min:1'],
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
            'refund_id.integer' => __('sirsoft-ecommerce::validation.claims.preview_claims.id_integer'),
            'refund_id.prohibits' => __('sirsoft-ecommerce::validation.claims.items.prohibited_with_refund'),
            'items.array' => __('sirsoft-ecommerce::validation.claims.items.array'),
            'items.max' => __('sirsoft-ecommerce::validation.claims.items.max'),
            'items.*.order_option_id.required_with' => __('sirsoft-ecommerce::validation.claims.items.order_option_id_required'),
            'items.*.order_option_id.exists' => __('sirsoft-ecommerce::validation.claims.items.not_in_order'),
            'items.*.cancel_quantity.required_with' => __('sirsoft-ecommerce::validation.claims.items.quantity_required'),
            'items.*.cancel_quantity.min' => __('sirsoft-ecommerce::validation.claims.items.quantity_min'),
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
            'refund_id' => __('sirsoft-ecommerce::validation.request_attributes.refund_id'),
            'items' => __('sirsoft-ecommerce::validation.request_attributes.items'),
            'items.*.order_option_id' => __('sirsoft-ecommerce::validation.request_attributes.order_option'),
            'items.*.cancel_quantity' => __('sirsoft-ecommerce::validation.request_attributes.cancel_quantity'),
        ];
    }

    /**
     * 라우트의 대상 주문 ID 를 반환합니다. (모델 바인딩·원시 값 모두 수용)
     *
     * @return int 주문 ID
     */
    protected function orderId(): int
    {
        $order = $this->route('order');

        return $order instanceof Order ? (int) $order->id : (int) $order;
    }

    /**
     * 환불신청서 문맥 ID 를 반환합니다.
     *
     * @return int|null 환불신청서 ID
     */
    public function refundId(): ?int
    {
        $id = $this->validated('refund_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * 대상 아이템을 계산기 공용 형태로 반환합니다.
     *
     * @return array<int, array{order_option_id: int, cancel_quantity: int}> 대상 아이템
     */
    public function items(): array
    {
        $items = [];

        foreach ((array) $this->validated('items') as $item) {
            $items[] = [
                'order_option_id' => (int) ($item['order_option_id'] ?? 0),
                'cancel_quantity' => (int) ($item['cancel_quantity'] ?? 0),
            ];
        }

        return $items;
    }
}
