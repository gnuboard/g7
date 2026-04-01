<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;

/**
 * 구매확정 요청
 *
 * 마이페이지에서 사용자가 주문 옵션을 구매확정할 때 사용됩니다.
 */
class ConfirmOrderOptionRequest extends FormRequest
{
    protected ?Order $order = null;

    protected ?OrderOption $option = null;

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
        return [];
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
            $optionId = $this->route('optionId');

            $this->order = Order::with('options')->find($orderId);

            if (! $this->order) {
                abort(ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.order_not_found',
                    404
                ));
            }

            // 소유권 검증: 본인 주문만 구매확정 가능
            if ($this->order->user_id !== Auth::id()) {
                abort(ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.order_not_found',
                    404
                ));
            }

            // 옵션 존재 확인
            $this->option = $this->order->options->firstWhere('id', (int) $optionId);

            if (! $this->option) {
                abort(ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.order_option_not_found',
                    404
                ));
            }

            // 이미 구매확정된 옵션인지 확인
            if ($this->option->option_status === OrderStatusEnum::CONFIRMED) {
                $validator->errors()->add(
                    'option_status',
                    __('sirsoft-ecommerce::exceptions.order_option_already_confirmed')
                );

                return;
            }

            // 환경설정 기반 구매확정 가능 상태 확인
            $confirmableStatuses = module_setting(
                'sirsoft-ecommerce',
                'order_settings.confirmable_statuses',
                ['shipping', 'delivered']
            );

            if (! in_array($this->option->option_status->value, $confirmableStatuses)) {
                $validator->errors()->add(
                    'option_status',
                    __('sirsoft-ecommerce::exceptions.order_option_cannot_confirm')
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
     * 검증된 주문 옵션 반환
     *
     * @return OrderOption
     */
    public function getOption(): OrderOption
    {
        return $this->option;
    }
}
