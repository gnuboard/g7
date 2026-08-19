<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesCancelItems;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 비회원 환불 예상금액 조회 요청
 *
 * 주문 소유권은 VerifyGuestOrderToken 미들웨어가 검증하며, 본 요청은
 * 환불 대상 항목 입력만 검증한다.
 */
class GuestEstimateRefundRequest extends FormRequest
{
    use ValidatesCancelItems;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 true (실제 인증은 VerifyGuestOrderToken 미들웨어가 수행)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, array<int, string>> 필드별 규칙 배열
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_option_id' => ['required', 'integer'],
            'items.*.cancel_quantity' => ['required', 'integer', 'min:1'],
            'refund_priority' => ['sometimes', 'string', 'in:'.implode(',', RefundPriorityEnum::values())],
        ];
    }

    /**
     * 추가 검증 로직 — 각 항목이 대상 주문에 속하고 취소 가능한지 확인합니다.
     *
     * 회원판 EstimateRefundRequest 와 동일 강도. 대상 주문은 미들웨어가 전달한
     * guest_order attribute 를 사용한다.
     *
     * @param  Validator  $validator  검증기
     * @return void
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->any()) {
                return;
            }

            $this->validateCancelItemsAgainstOrder($validator, $this->getOrder(), $this->input('items', []));
        });
    }

    /**
     * 미들웨어가 검증한 대상 주문을 반환합니다.
     *
     * @return Order 토큰 검증을 통과한 비회원 주문
     */
    public function getOrder(): Order
    {
        $order = $this->attributes->get('guest_order');

        if (! $order instanceof Order) {
            abort(ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.order_not_found', 404));
        }

        return $order;
    }

    /**
     * 환불 예상 대상 항목을 반환합니다.
     *
     * @return array<int, array{order_option_id: int, cancel_quantity: int}> 환불 대상 옵션 배열
     */
    public function getCancelItems(): array
    {
        return $this->validated('items') ?? [];
    }

    /**
     * 환불 우선순위를 반환합니다.
     *
     * @return RefundPriorityEnum 환불 우선순위 (입력 없으면 PG_FIRST)
     */
    public function getRefundPriority(): RefundPriorityEnum
    {
        $value = $this->validated('refund_priority');

        return $value ? RefundPriorityEnum::from($value) : RefundPriorityEnum::PG_FIRST;
    }
}
