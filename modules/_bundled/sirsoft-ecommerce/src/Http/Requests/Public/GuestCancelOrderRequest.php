<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 비회원 주문 취소 요청
 *
 * 주문 소유권은 VerifyGuestOrderToken 미들웨어가 이미 검증했으므로,
 * 본 요청은 입력값 형식과 취소 가능 상태만 검증한다. 대상 주문은
 * 미들웨어가 request attribute(guest_order)로 전달한 것을 사용한다.
 */
class GuestCancelOrderRequest extends FormRequest
{
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
            'reason' => ['required', 'string', Rule::exists(ClaimReason::class, 'code')->where('type', 'refund')->where('is_active', true)->where('is_user_selectable', true)],
            'reason_detail' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.order_option_id' => ['required_with:items', 'integer'],
            'items.*.cancel_quantity' => ['required_with:items', 'integer', 'min:1'],
            'refund_priority' => ['sometimes', 'string', 'in:'.implode(',', RefundPriorityEnum::values())],
        ];
    }

    /**
     * 추가 검증 로직 (취소 가능 상태 확인)
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $order = $this->getOrder();

            $cancellableStatuses = module_setting(
                'sirsoft-ecommerce',
                'order_settings.cancellable_statuses',
                ['payment_complete']
            );

            if (! $order->isCancellable($cancellableStatuses)) {
                $validator->errors()->add(
                    'order_status',
                    $order->getCancelDeniedReason($cancellableStatuses)
                );
            }
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
     * 부분취소 여부를 반환합니다.
     *
     * @return bool items 배열에 항목이 1개 이상이면 부분취소
     */
    public function isPartialCancel(): bool
    {
        return $this->has('items') && is_array($this->input('items')) && count($this->input('items')) > 0;
    }

    /**
     * 부분취소 대상 항목을 반환합니다.
     *
     * @return array<int, array{order_option_id: int, cancel_quantity: int}> 취소 대상 옵션 배열
     */
    public function getCancelItems(): array
    {
        return $this->input('items', []);
    }

    /**
     * 취소 사유 코드를 반환합니다.
     *
     * @return string|null ClaimReason 코드 (refund 타입)
     */
    public function getReason(): ?string
    {
        return $this->input('reason');
    }

    /**
     * 취소 사유 상세를 반환합니다.
     *
     * @return string|null 사용자 입력 사유 상세 (최대 500자)
     */
    public function getReasonDetail(): ?string
    {
        return $this->input('reason_detail');
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
