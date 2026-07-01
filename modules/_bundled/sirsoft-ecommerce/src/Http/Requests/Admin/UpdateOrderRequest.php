<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;

/**
 * 주문 수정 요청
 */
class UpdateOrderRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool 권한 미들웨어에 위임하므로 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 필드별 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'order_status' => ['nullable', 'string', Rule::in(OrderStatusEnum::values())],
            'admin_memo' => ['nullable', 'string', 'max:2000'],
            'recipient_name' => ['required', 'string', 'max:50'],
            'recipient_phone' => ['required_without:recipient_tel', 'nullable', 'string', 'max:20'],
            'recipient_tel' => ['required_without:recipient_phone', 'nullable', 'string', 'max:20'],
            // 국내(KR) 주소 — 해외 주소(address_line_1)가 없을 때만 필수 (D7 해외 주문 지원)
            'recipient_zipcode' => ['required_without:address_line_1', 'nullable', 'string', 'max:10'],
            'recipient_address' => ['required_without:address_line_1', 'nullable', 'string', 'max:255'],
            'recipient_detail_address' => ['required_with:recipient_address', 'nullable', 'string', 'max:255'],
            // 해외 주소 — 국내 주소(recipient_address)가 없을 때만 필수 (D7)
            'address_line_1' => ['required_without:recipient_address', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'intl_city' => ['required_with:address_line_1', 'nullable', 'string', 'max:100'],
            'intl_state' => ['nullable', 'string', 'max:100'],
            'intl_postal_code' => ['required_with:address_line_1', 'nullable', 'string', 'max:20'],
            'delivery_memo' => ['nullable', 'string', 'max:500'],
            'recipient_country_code' => ['nullable', 'string', 'size:2'],
        ];
    }

    /**
     * 추가 검증 로직 — 주문 상태 전이 규칙(역방향/비연속 역행 차단)
     *
     * order_status 입력이 있고 현재 상태와 다를 때만, OrderStatusEnum 전이 게이트로 평가한다.
     * 위반 시 422 + 다국어 메시지. 취소(CANCELLED) 진입은 게이트가 통과 처리(OrderCancellationService 전담).
     *
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $input = $this->input('order_status');
            if ($input === null) {
                return; // 상태 미변경 요청
            }

            $order = $this->route('order');
            $current = $order?->order_status; // Enum (cast)
            if (! $current instanceof OrderStatusEnum) {
                return; // 비정상 — rules 의 in 검증에 위임
            }

            $target = OrderStatusEnum::tryFrom($input);
            if ($target === null) {
                return; // 잘못된 값 — rules 의 Rule::in 이 처리
            }

            if (! $current->canTransitionTo($target)) {
                $validator->errors()->add('order_status', __('sirsoft-ecommerce::validation.orders.status_transition.invalid', [
                    'from' => $current->label(),
                    'to' => $target->label(),
                ]));
            }
        });
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string> 규칙별 다국어 에러 메시지 배열
     */
    public function messages(): array
    {
        return [
            'order_status.in' => __('sirsoft-ecommerce::validation.orders.order_status.in'),
            'admin_memo.max' => __('sirsoft-ecommerce::validation.orders.admin_memo.max'),
            'recipient_name.required' => __('sirsoft-ecommerce::validation.orders.recipient_name.required'),
            'recipient_name.max' => __('sirsoft-ecommerce::validation.orders.recipient_name.max'),
            'recipient_phone.required_without' => __('sirsoft-ecommerce::validation.orders.recipient_phone.required_without'),
            'recipient_phone.max' => __('sirsoft-ecommerce::validation.orders.recipient_phone.max'),
            'recipient_tel.required_without' => __('sirsoft-ecommerce::validation.orders.recipient_tel.required_without'),
            'recipient_tel.max' => __('sirsoft-ecommerce::validation.orders.recipient_tel.max'),
            'recipient_zipcode.required_without' => __('sirsoft-ecommerce::validation.orders.recipient_zipcode.required'),
            'recipient_zipcode.max' => __('sirsoft-ecommerce::validation.orders.recipient_zipcode.max'),
            'recipient_address.required_without' => __('sirsoft-ecommerce::validation.orders.recipient_address.required'),
            'recipient_address.max' => __('sirsoft-ecommerce::validation.orders.recipient_address.max'),
            'recipient_detail_address.required_with' => __('sirsoft-ecommerce::validation.orders.recipient_detail_address.required'),
            'recipient_detail_address.max' => __('sirsoft-ecommerce::validation.orders.recipient_detail_address.max'),
            'address_line_1.required_without' => __('sirsoft-ecommerce::validation.order.address_line_1_required'),
            'intl_city.required_with' => __('sirsoft-ecommerce::validation.order.intl_city_required'),
            'intl_postal_code.required_with' => __('sirsoft-ecommerce::validation.order.intl_postal_code_required'),
            'delivery_memo.max' => __('sirsoft-ecommerce::validation.orders.delivery_memo.max'),
            'recipient_country_code.size' => __('sirsoft-ecommerce::validation.orders.recipient_country_code.size'),
        ];
    }
}
