<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 환불 예상금액 조회 요청 (사용자)
 *
 * 마이페이지에서 주문 취소 시 환불 예상금액을 미리 계산하기 위한 요청 검증입니다.
 */
class EstimateRefundRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_option_id' => ['required', 'integer'],
            'items.*.cancel_quantity' => ['required', 'integer', 'min:1'],
            'refund_priority' => ['sometimes', 'string', 'in:'.implode(',', RefundPriorityEnum::values())],
        ];
    }

    /**
     * 추가 검증 로직
     *
     * @param  Validator  $validator
     * @return void
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->any()) {
                return;
            }

            $orderId = $this->route('id');
            $this->order = Order::find($orderId);

            if (! $this->order) {
                abort(ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.order_not_found',
                    404
                ));
            }

            // 소유권 검증: 본인 주문만 조회 가능
            if ($this->order->user_id !== Auth::id()) {
                abort(ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.order_not_found',
                    404
                ));
            }

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
        });
    }

    /**
     * 검증 실패 시 응답 커스터마이징
     *
     * @param  Validator  $validator
     * @return void
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, ResponseHelper::error(
            $validator->errors()->first(),
            422
        ));
    }

    /**
     * 검증된 주문을 반환합니다.
     *
     * @return Order
     */
    public function getOrder(): Order
    {
        return $this->order;
    }

    /**
     * 검증된 취소 아이템 배열을 반환합니다.
     *
     * @return array [{order_option_id, cancel_quantity}]
     */
    public function getCancelItems(): array
    {
        return $this->validated('items');
    }

    /**
     * 환불 우선순위를 반환합니다.
     *
     * @return RefundPriorityEnum 환불 우선순위 (기본: PG_FIRST)
     */
    public function getRefundPriority(): RefundPriorityEnum
    {
        $value = $this->validated('refund_priority');

        return $value
            ? RefundPriorityEnum::from($value)
            : RefundPriorityEnum::PG_FIRST;
    }
}
