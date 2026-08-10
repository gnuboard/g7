<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 주문 취소 요청
 *
 * 마이페이지에서 사용자가 주문을 취소할 때 사용됩니다.
 */
class CancelOrderRequest extends FormRequest
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
     * @return array
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
     * 검증 필드의 사용자 표시명
     *
     * @return array
     */
    public function attributes(): array
    {
        return [
            'reason' => __('sirsoft-ecommerce::messages.admin.order.detail.modal.cancel.reason'),
            'reason_detail' => __('sirsoft-ecommerce::messages.admin.order.detail.modal.cancel.reason_detail'),
        ];
    }

    /**
     * 추가 검증 로직
     *
     * @param Validator $validator
     * @return void
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $orderId = $this->route('id');
            $this->order = Order::find($orderId);

            if (! $this->order) {
                abort(ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.order_not_found',
                    404
                ));
            }

            // 소유권 검증: 본인 주문만 취소 가능
            if ($this->order->user_id !== Auth::id()) {
                abort(ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.order_not_found',
                    404
                ));
            }

            // 환경설정 기반 취소 가능 상태 확인
            $cancellableStatuses = module_setting(
                'sirsoft-ecommerce',
                'order_settings.cancellable_statuses',
                ['payment_complete']
            );

            if (! $this->order->isCancellable($cancellableStatuses)) {
                $validator->errors()->add(
                    'order_status',
                    $this->order->getCancelDeniedReason($cancellableStatuses)
                );

                return;
            }

            // 부분취소 items 검증
            if ($this->has('items') && is_array($this->input('items'))) {
                $this->validateCancelItems($validator);
            }
        });
    }

    /**
     * 취소 아이템 목록을 검증합니다.
     *
     * @param  Validator  $validator
     * @return void
     */
    protected function validateCancelItems(Validator $validator): void
    {
        $this->order->loadMissing('options');
        $items = $this->input('items', []);

        foreach ($items as $index => $item) {
            $option = $this->order->options->firstWhere('id', $item['order_option_id']);

            if (! $option) {
                $validator->errors()->add(
                    "items.{$index}.order_option_id",
                    __('sirsoft-ecommerce::exceptions.order_option_not_found')
                );

                continue;
            }

            // 이미 취소된 옵션은 제외
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                $validator->errors()->add(
                    "items.{$index}.order_option_id",
                    __('sirsoft-ecommerce::exceptions.order_option_already_cancelled')
                );

                continue;
            }

            // 취소 수량이 현재 수량을 초과하는지 검증
            if ($item['cancel_quantity'] > $option->quantity) {
                $validator->errors()->add(
                    "items.{$index}.cancel_quantity",
                    __('sirsoft-ecommerce::exceptions.cancel_quantity_exceeds', [
                        'max' => $option->quantity,
                    ])
                );
            }
        }
    }

    /**
     * 검증 실패 시 응답 커스터마이징
     *
     * @param Validator $validator
     * @return void
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
     * 검증된 주문 반환
     *
     * @return Order
     */
    public function getOrder(): Order
    {
        return $this->order;
    }

    /**
     * 부분취소 여부를 반환합니다.
     *
     * @return bool
     */
    public function isPartialCancel(): bool
    {
        return $this->has('items') && is_array($this->input('items')) && count($this->input('items')) > 0;
    }

    /**
     * 취소 아이템 배열을 반환합니다.
     *
     * @return array [{order_option_id, cancel_quantity}]
     */
    public function getCancelItems(): array
    {
        return $this->validated('items') ?? [];
    }

    /**
     * 취소 사유 코드를 반환합니다.
     *
     * @return string|null
     */
    public function getReason(): ?string
    {
        return $this->validated('reason');
    }

    /**
     * 상세 취소 사유를 반환합니다.
     *
     * @return string|null
     */
    public function getReasonDetail(): ?string
    {
        return $this->validated('reason_detail');
    }

    /**
     * 환불 우선순위를 반환합니다.
     *
     * @return RefundPriorityEnum
     */
    public function getRefundPriority(): RefundPriorityEnum
    {
        $value = $this->validated('refund_priority');

        return $value ? RefundPriorityEnum::from($value) : RefundPriorityEnum::PG_FIRST;
    }
}
