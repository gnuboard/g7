<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 무통장 입금확인 요청 (관리자)
 *
 * 관리자가 무통장(dbank) 미결제 주문의 입금을 수동 확인할 때 사용됩니다.
 * 권한은 라우트 미들웨어(permission:admin,sirsoft-ecommerce.orders.update)에서 처리하며,
 * 여기서는 주문이 무통장·미결제 상태인지와 입금 금액/입금자명 형식만 검증합니다.
 */
class ConfirmDepositRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인 (권한은 미들웨어에서 처리)
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, array<int, string>> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0'],
            'depositor_name' => ['nullable', 'string', 'max:100'],
            // true 면 입금 기록과 함께 주문을 결제완료로 전이(마일리지/재고/알림 포함).
            // false 면 결제 레코드(payment)만 입금완료로 기록(주문 상태 전이 없음).
            'mark_order_complete' => ['nullable', 'boolean'],
        ];
    }

    /**
     * 검증 필드의 사용자 표시명
     *
     * @return array<string, string> 필드명 → 표시명 매핑
     */
    public function attributes(): array
    {
        return [
            'amount' => __('sirsoft-ecommerce::messages.orders.deposit.amount'),
            'depositor_name' => __('sirsoft-ecommerce::messages.orders.deposit.depositor_name'),
        ];
    }

    /**
     * 추가 검증 로직 — 주문이 무통장·미결제 상태인지 확인
     *
     * @param  Validator  $validator  검증기
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->any()) {
                return;
            }

            /** @var Order|null $order */
            $order = $this->route('order');

            if (! $order) {
                $validator->errors()->add('order', __('sirsoft-ecommerce::exceptions.order_not_found'));

                return;
            }

            $order->loadMissing('payment');

            // 무통장(dbank) 결제수단만 입금확인 대상
            if ($order->payment?->payment_method !== PaymentMethodEnum::DBANK) {
                $validator->errors()->add(
                    'order',
                    __('sirsoft-ecommerce::messages.orders.deposit_not_dbank')
                );

                return;
            }

            // 결제 레코드(payment) 자체가 미입금(ready/waiting_deposit) 상태일 때만 입금확인 가능.
            // 주문 상태(order_status)가 아닌 결제 레코드 기준으로 판정해, order_status 가 다른 경로로
            // 먼저 결제완료로 전이돼도 실제 미입금 결제는 입금확인할 수 있게 한다(버튼 노출 조건과 일관).
            if ($order->payment?->payment_status?->isAwaitingDeposit() !== true) {
                $validator->errors()->add(
                    'payment_status',
                    __('sirsoft-ecommerce::messages.orders.deposit_not_pending')
                );
            }
        });
    }

    /**
     * 검증 실패 시 응답 커스터마이징
     *
     * @param  Validator  $validator  검증기
     *
     * @throws ValidationException 검증 실패 시
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
     * 입금 금액을 반환합니다.
     *
     * @return float 입금 금액
     */
    public function getAmount(): float
    {
        return (float) $this->validated('amount');
    }

    /**
     * 입금자명을 반환합니다.
     *
     * @return string|null 입금자명 (미입력 시 null)
     */
    public function getDepositorName(): ?string
    {
        return $this->validated('depositor_name');
    }

    /**
     * 입금 확인 시 주문을 결제완료로 전이할지 여부를 반환합니다.
     *
     * @return bool 체크박스 미전달 시 기본값 false (입금 기록만)
     */
    public function shouldMarkOrderComplete(): bool
    {
        return (bool) $this->validated('mark_order_complete', false);
    }
}
