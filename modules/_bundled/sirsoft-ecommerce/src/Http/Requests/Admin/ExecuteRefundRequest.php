<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\RefundExecutionService;

/**
 * 관리자 환불 실행 요청 ('환불하기' — D18)
 *
 * 본문의 금액은 선택 사항이다 — 실려 있으면 화면에 입력된 값으로 실행하고(저장 없이 실행 가능),
 * 없으면 저장된 환불액으로 실행한다. 상한(D19)은 서비스가 실행 직전에 재계산해 검증한다.
 */
class ExecuteRefundRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인 (권한 체크는 라우트의 permission 미들웨어에서 수행)
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
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'refund_points_amount' => ['nullable', 'numeric', 'min:0'],
            'refund_method' => ['nullable', 'string', Rule::in(RefundMethodEnum::values())],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'refund_amount.numeric' => __('sirsoft-ecommerce::validation.claims.amounts.numeric'),
            'refund_amount.min' => __('sirsoft-ecommerce::validation.claims.amounts.min'),
            'refund_points_amount.numeric' => __('sirsoft-ecommerce::validation.claims.amounts.numeric'),
            'refund_points_amount.min' => __('sirsoft-ecommerce::validation.claims.amounts.min'),
            'refund_method.in' => __('sirsoft-ecommerce::validation.claims.refund_method.in'),
        ];
    }

    /**
     * 환불수단이 결제행과 맞는지 대조합니다. (P3 — 돈이 안 나가는 `COMPLETED` 차단)
     *
     * `refund_method` 는 "이 돈을 어떻게 돌려주는가" 이고 그 답은 **주문이 어떻게 결제됐는지**가
     * 정한다(`PaymentMethodResolver::refundMethod()`). 예전에는 본문 값을 그대로 받아 실행했고,
     * 두 방향 모두 돈이 어긋났다:
     *
     *  - 카드 결제에 `BANK` 를 실으면 PG 를 부르지 않고 `COMPLETED` 가 찍힌다 — 고객 카드는
     *    그대로인데 장부는 환불완료.
     *  - 무통장 결제에 `PG` 를 실으면 PG 리스너가 인식하지 못해 `success=false` → 트랜잭션
     *    롤백. 운영자는 원인 없는 500 을 본다.
     *
     * 어긋나면 422 로 막는다. "PG 없이 장부만" 이 필요하면 그 주문의 결제수단 자체를 바꿔야
     * 하며, 그것은 이 화면의 권한 밖이다.
     *
     * ## 대조 기준은 **실효 환불수단**이다 (P14 폴백 포함)
     *
     * 결제행의 원시 판정(`OrderPayment::refundMethod()`)을 기준으로 삼으면 P14 폴백 경로가
     * 통째로 막힌다 — PG 플러그인이 제거된 주문은 신청서에 `BANK` 로 저장되는데(
     * `RefundExecutionService::determineRefundMethod()` 가 리스너 부재를 보고 폴백한다)
     * 결제행은 여전히 `PG` 라고 말하므로, 화면이 저장값을 그대로 실어 보내면 항상
     * `payment_mismatch` 가 된다. 운영자는 "계좌로 송금하세요" 안내를 읽고 실제로 송금한 뒤
     * [환불하기]에서 원인 없는 422 를 받는다 — P14 가 세운 "손으로 송금하고 완료를 찍는
     * 경로는 살아 있다" 는 전제가 깨진다.
     *
     * 그래서 신청서가 저장할 때 쓴 것과 **같은 판정**으로 대조한다. 막으려던 것은
     * "실행 로직이 쓸 수단과 다른 값을 실어 보내는 것" 이고, 실행 로직이 쓰는 것이 실효 수단이다.
     *
     * @param  Validator  $validator  검증기
     * @return void
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $method = $this->input('refund_method');

            if (! filled($method)) {
                return;
            }

            $refund = OrderRefund::with('order.payment')->find((int) $this->route('id'));
            $order = $refund?->order;

            if (! $order?->payment) {
                return;
            }

            // 신청서가 저장할 때 쓴 것과 같은 판정 — P14 폴백(PG 리스너 부재 → BANK)을 포함한다.
            $expected = app(RefundExecutionService::class)->determineRefundMethod($order);

            if ($method !== $expected->value) {
                $validator->errors()->add(
                    'refund_method',
                    __('sirsoft-ecommerce::validation.claims.refund_method.payment_mismatch', [
                        'expected' => $expected->label(),
                    ])
                );
            }
        });
    }

    /**
     * 실행 시점 금액을 보낸 키만 담아 반환합니다.
     *
     * @return array<string, mixed> 실행 시점 금액 (없으면 빈 배열 — 저장값으로 실행)
     */
    public function overrides(): array
    {
        $amounts = [];

        foreach (['refund_amount', 'refund_points_amount', 'refund_method'] as $key) {
            if ($this->has($key)) {
                $amounts[$key] = $this->validated($key);
            }
        }

        return $amounts;
    }
}
