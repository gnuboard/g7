<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;

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
     * @param  Validator  $validator
     * @return void
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $orderNumber = $this->route('orderNumber');
            $this->order = app(OrderRepositoryInterface::class)->findByOrderNumber((string) $orderNumber);

            if (! $this->order) {
                abort(ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.order_not_found',
                    404
                ));
            }

            // 소유권 검증
            // - 회원 주문: 본인만 통과.
            // - 비회원 주문: 보호된 게스트 경로(guest/orders/*)와 동일하게 X-Guest-Order-Token 을 요구한다.
            //   주문번호만으로 통과시키면 익명 요청이 결제를 cancelled 로 영속 변경할 수 있다.
            //   미들웨어(VerifyGuestOrderToken)를 라우트에 붙이지 않는 이유는 그 미들웨어에
            //   회원 pass-through 가 없어 공유 라우트에서 로그인 회원이 404 가 되기 때문이다.
            if ($this->order->user_id !== null) {
                if ($this->order->user_id !== Auth::id()) {
                    abort(ResponseHelper::moduleError(
                        'sirsoft-ecommerce',
                        'exceptions.order_not_found',
                        404
                    ));
                }
            } else {
                $verified = app(GuestOrderAuthService::class)->verifyToken(
                    $this->header('X-Guest-Order-Token'),
                    (string) $orderNumber
                );

                if (! $verified) {
                    abort(ResponseHelper::moduleError(
                        'sirsoft-ecommerce',
                        'exceptions.order_not_found',
                        404
                    ));
                }
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
     * 검증된 주문 반환
     *
     * @return Order
     */
    public function getOrder(): Order
    {
        return $this->order;
    }
}
