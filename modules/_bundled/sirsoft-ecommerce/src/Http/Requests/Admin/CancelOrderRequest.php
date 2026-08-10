<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

/**
 * 주문 취소 요청 (관리자)
 *
 * 관리자가 주문을 전체취소 또는 부분취소할 때 사용됩니다.
 */
class CancelOrderRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 true (권한 검사는 permission 미들웨어가 담당)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['full', 'partial'])],
            'reason' => ['required', 'string', Rule::exists(ClaimReason::class, 'code')->where('type', 'refund')->where('is_active', true)],
            'reason_detail' => ['nullable', 'string', 'max:500'],
            'items' => ['required_if:type,partial', 'array', 'min:1'],
            'items.*.order_option_id' => ['required_with:items', 'integer'],
            'items.*.cancel_quantity' => ['required_with:items', 'integer', 'min:1'],
            'cancel_pg' => ['nullable', 'boolean'],
            'refund_priority' => ['sometimes', 'string', 'in:'.implode(',', RefundPriorityEnum::values())],

            // 환불 계좌 — 표시/필수 조건은 결제수단·입금상태에 따라 다르므로 withValidator 가 판정한다.
            'refund_bank.bank_code' => ['nullable', 'string', 'max:10'],
            'refund_bank.account_number' => ['nullable', 'string', 'max:50'],
            'refund_bank.holder' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * 환불 계좌 정보를 반환합니다. (미입력이면 null)
     *
     * 주문 시 입력된 계좌가 있으면 관리자가 수정할 수 있으며, 여기서 반환된 값이 결제행을 갱신합니다.
     *
     * @return array{bank_code: string, account_number: string, holder: string}|null 환불 계좌 (미입력 시 null)
     */
    public function getRefundBankInfo(): ?array
    {
        $bankCode = $this->input('refund_bank.bank_code');

        if (blank($bankCode)) {
            return null;
        }

        return [
            'bank_code' => (string) $bankCode,
            'account_number' => (string) $this->input('refund_bank.account_number'),
            'holder' => (string) $this->input('refund_bank.holder'),
        ];
    }

    /**
     * 검증 필드의 사용자 표시명
     *
     * @return array 필드명 → 표시명 매핑
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
     * @param  Validator  $validator  검증기
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->any()) {
                return;
            }

            /** @var Order $order */
            $order = $this->route('order');

            if (! $order) {
                $validator->errors()->add('order', __('sirsoft-ecommerce::exceptions.order_not_found'));

                return;
            }

            // 환경설정 기반 취소 가능 상태 확인
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

                return;
            }

            // 환불 계좌 조건부 검증 (결제수단·입금상태 기준)
            $this->validateRefundBank($validator, $order);

            // 부분취소 시 items 검증
            if ($this->input('type') === 'partial') {
                $this->validateCancelItems($validator, $order);
            }
        });
    }

    /**
     * 환불 계좌를 조건부로 검증합니다.
     *
     * 가상계좌 입금완료 건은 PG 환불 API 가 환불받을 계좌를 필수로 요구하므로 미입력을 거부합니다.
     * 입금 전 가상계좌는 PG 가 계좌를 요구하지 않고, 무통장은 관리자가 수동 이체하므로 선택 입력입니다.
     * 카드·간편결제는 원 결제수단으로 환불되므로 계좌 자체가 불필요합니다.
     *
     * 부분 입력(3필드 중 일부만)은 결제수단과 무관하게 거부합니다 — 그 상태로는 환불이 불가능합니다.
     *
     * @param  Validator  $validator  검증기
     * @param  Order  $order  취소 대상 주문
     */
    protected function validateRefundBank(Validator $validator, Order $order): void
    {
        $fields = ['bank_code', 'account_number', 'holder'];

        $filled = array_filter(
            $fields,
            fn (string $field): bool => filled($this->input("refund_bank.{$field}"))
        );

        // 부분 입력 거부 (전부 비었거나 전부 채워졌거나)
        if ($filled !== [] && count($filled) !== count($fields)) {
            foreach (array_diff($fields, $filled) as $missing) {
                $validator->errors()->add(
                    "refund_bank.{$missing}",
                    __('sirsoft-ecommerce::validation.order.refund_bank_required_with')
                );
            }

            return;
        }

        if ($filled !== []) {
            return;
        }

        // 미입력 — 가상계좌 입금완료 건만 필수. 주문 시 입력된 계좌가 있으면 그것을 쓰므로 통과.
        $order->loadMissing('payment');
        $payment = $order->payment;

        if (! $payment || ! $this->requiresRefundBank($payment)) {
            return;
        }

        if (filled($payment->refund_bank_code)) {
            return;
        }

        foreach ($fields as $field) {
            $validator->errors()->add(
                "refund_bank.{$field}",
                __('sirsoft-ecommerce::validation.order.refund_bank_required_for_vbank')
            );
        }
    }

    /**
     * 환불 계좌가 필수인 결제인지 판정합니다.
     *
     * @param  OrderPayment  $payment  결제 정보
     * @return bool 필수 여부
     */
    protected function requiresRefundBank(OrderPayment $payment): bool
    {
        $method = $payment->payment_method instanceof PaymentMethodEnum
            ? $payment->payment_method
            : PaymentMethodEnum::tryFrom((string) $payment->payment_method);

        if ($method !== PaymentMethodEnum::VBANK) {
            return false;
        }

        $status = $payment->payment_status instanceof PaymentStatusEnum
            ? $payment->payment_status
            : PaymentStatusEnum::tryFrom((string) $payment->payment_status);

        // 입금 전(waiting_deposit)이면 PG 가 환불받을 계좌를 요구하지 않는다.
        return $status !== null && ! $status->isAwaitingDeposit();
    }

    /**
     * 취소 아이템 목록을 검증합니다.
     *
     * @param  Validator  $validator  검증기
     * @param  Order  $order  취소 대상 주문
     */
    protected function validateCancelItems(Validator $validator, Order $order): void
    {
        $order->loadMissing('options');
        $items = $this->input('items', []);

        foreach ($items as $index => $item) {
            $option = $order->options->firstWhere('id', $item['order_option_id']);

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
     * 전체취소 여부를 반환합니다.
     *
     * @return bool 전체취소면 true
     */
    public function isFullCancel(): bool
    {
        return $this->validated('type') === 'full';
    }

    /**
     * 취소 사유 코드를 반환합니다.
     *
     * @return string|null 취소 사유 코드
     */
    public function getReason(): ?string
    {
        return $this->validated('reason');
    }

    /**
     * 상세 취소 사유를 반환합니다.
     *
     * @return string|null 상세 취소 사유 (미입력 시 null)
     */
    public function getReasonDetail(): ?string
    {
        return $this->validated('reason_detail');
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
     * PG 결제 취소 여부를 반환합니다.
     *
     * @return bool PG 결제도 함께 취소하면 true
     */
    public function shouldCancelPg(): bool
    {
        return (bool) ($this->validated('cancel_pg') ?? true);
    }

    /**
     * 환불 우선순위를 반환합니다.
     *
     * @return RefundPriorityEnum 환불 우선순위
     */
    public function getRefundPriority(): RefundPriorityEnum
    {
        $value = $this->validated('refund_priority');

        return $value ? RefundPriorityEnum::from($value) : RefundPriorityEnum::PG_FIRST;
    }
}
