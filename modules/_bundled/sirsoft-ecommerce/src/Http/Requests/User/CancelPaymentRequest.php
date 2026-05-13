<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 결제 취소 기록 요청
 *
 * 유저가 PG 결제창을 닫았을 때 결제 취소 이력을 기록합니다.
 */
class CancelPaymentRequest extends FormRequest
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
            'cancel_code' => ['nullable', 'string', 'max:100'],
            'cancel_message' => ['nullable', 'string', 'max:500'],
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
            $orderNumber = $this->route('orderNumber');
            $this->order = Order::where('order_number', $orderNumber)->first();

            if (! $this->order) {
                abort(ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.order_not_found',
                    404
                ));
            }

            // 소유권 검증: 회원 주문은 본인만, 비회원 주문은 주문번호로 접근 허용
            $userId = Auth::id();
            if ($this->order->user_id !== null && $this->order->user_id !== $userId) {
                abort(ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.order_not_found',
                    404
                ));
            }

            if ($this->order->order_status !== OrderStatusEnum::PENDING_ORDER) {
                $validator->errors()->add(
                    'order_status',
                    __('sirsoft-ecommerce::exceptions.order_cancel_not_allowed')
                );
            }
        });
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
            422
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
}
