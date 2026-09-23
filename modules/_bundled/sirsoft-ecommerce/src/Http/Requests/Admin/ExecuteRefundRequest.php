<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
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
            // 실행하며 적어 넣는 환불 계좌 — 계좌로 송금해야 끝나는 환불인데 신청서에 계좌가
            // 없을 때 운영자가 이 창에서 채운다. 상한은 접수·수정 경로와 같은 값이다.
            'refund_bank' => ['nullable', 'array'],
            'refund_bank.bank_code' => ['nullable', 'string', 'max:10'],
            'refund_bank.account_number' => ['nullable', 'string', 'max:50'],
            'refund_bank.holder' => ['nullable', 'string', 'max:50'],
            // 계좌 없이 완료로 기록하겠다는 **명시 동의**(T2-D6, PO 결정 2026-09-23).
            'force_without_refund_bank' => ['nullable', 'boolean'],
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
            'refund_bank.array' => __('sirsoft-ecommerce::validation.claims.refund_bank.array'),
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

        $validator->after(function (Validator $validator): void {
            $this->validateRefundBank($validator);
        });
    }

    /**
     * 계좌로 송금해야 끝나는 환불에 보낼 계좌가 있는지 확인합니다. (T2-D6)
     *
     * 세 가지를 본다.
     *
     *  ⓐ 본문에 실린 계좌가 **셋이 짝을 이루는가** — 은행만 바뀌고 계좌번호가 옛 값으로 남으면
     *     운영자가 없는 계좌로 이체한다(접수·수정 경로와 같은 등식이다).
     *  ⓑ 계좌가 **필요한 환불인가** — 둘을 함께 본다. 계좌로 나갈 현금이 있는가(마일리지로만
     *     돌려주는 환불은 회원 원장으로 들어가 보낼 계좌가 없다)와, 이 주문이 계좌로 환불되는
     *     주문인가다. 뒤쪽 판정은 구매자 접수·신청 화면과 같은 술어
     *     (`OrderRefundRequestService::refundsToBankAccount()`)다 — 다른 값으로 재현하면
     *     한쪽만 막히는 상태가 오류 없이 생긴다.
     *  ⓒ 계좌가 없는데 **동의도 없는가** — 그러면 막는다. 막지 않으면 돈은 나가지 않았는데
     *     장부만 `completed` 로 닫히고, 그 뒤에는 되돌릴 경로도 계좌를 적을 자리도 없다
     *     (2026-09-22 #599 3차 실측 L-8).
     *
     * 동의(`force_without_refund_bank`)가 있으면 통과시킨다 — 이미 다른 경로로 송금했거나
     * 채권을 포기하는 등 운영상 필요가 있기 때문이며(PO 결정 2026-09-23), 모르고 눌러 닫히는
     * 것과 **알고 누르는 것**을 가르는 것이 이 플래그의 일이다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function validateRefundBank(Validator $validator): void
    {
        $bank = (array) $this->input('refund_bank', []);
        $filled = array_filter(
            ['bank_code', 'account_number', 'holder'],
            fn (string $key) => filled($bank[$key] ?? null),
        );

        // ⓐ 일부만 채운 계좌 — 셋이 함께 와야 한다.
        if ($filled !== [] && count($filled) !== 3) {
            $validator->errors()->add(
                'refund_bank',
                __('sirsoft-ecommerce::validation.claims.refund_bank.incomplete')
            );

            return;
        }

        if ($filled !== []) {
            return;
        }

        if ($this->boolean('force_without_refund_bank')) {
            return;
        }

        $refund = OrderRefund::with('order.payment')->find((int) $this->route('id'));
        $order = $refund?->order;

        if (! $order) {
            return;
        }

        // 이미 신청서에 적혀 있으면 그것으로 보낸다.
        if (filled($refund->refund_bank_code)) {
            return;
        }

        // ⓑ-1 **계좌로 나갈 현금이 있는가.** 마일리지로만 돌려주는 환불(`refund_amount` 0,
        // `refund_points_amount` > 0)은 회원 원장으로 들어가므로 보낼 계좌가 없다 — 여기까지
        // 막으면 포인트 환급이 영영 실행되지 않는다. 기준은 실행에 실제로 쓰일 금액이라
        // 본문 override 가 있으면 그것을, 없으면 저장값을 본다(실행 로직과 같은 순서다).
        $amount = $this->has('refund_amount')
            ? (float) $this->input('refund_amount')
            : (float) $refund->refund_amount;

        if ($amount <= 0) {
            return;
        }

        // ⓑ-2 계좌가 필요한 환불인가 — 화면·구매자 접수와 같은 술어.
        if (! app(OrderRefundRequestService::class)->refundsToBankAccount($order)) {
            return;
        }

        // ⓒ 계좌도 동의도 없다.
        $validator->errors()->add(
            'refund_bank',
            __('sirsoft-ecommerce::validation.order.refund_bank_required_for_manual_transfer')
        );
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

        // 실행하며 적어 넣은 계좌도 같은 실행 본문으로 넘긴다 — 저장을 따로 왕복시키면
        // 「계좌는 저장됐는데 실행이 실패한」·그 반대의 반쪽 상태가 생긴다.
        foreach ($this->refundBankColumns() as $column => $value) {
            $amounts[$column] = $value;
        }

        return $amounts;
    }

    /**
     * 실행 본문에 실린 환불 계좌를 신청서 컬럼 이름으로 돌려줍니다.
     *
     * 세 칸이 모두 채워졌을 때만 낸다 — 검증이 일부만 실린 계좌를 이미 거절했으므로 여기까지
     * 온 값은 셋이 짝을 이루거나 아예 비어 있다.
     *
     * @return array<string, string> 신청서 컬럼 → 값 (비어 있으면 빈 배열)
     */
    public function refundBankColumns(): array
    {
        $bank = (array) $this->input('refund_bank', []);

        $code = trim((string) ($bank['bank_code'] ?? ''));
        $account = trim((string) ($bank['account_number'] ?? ''));
        $holder = trim((string) ($bank['holder'] ?? ''));

        if ($code === '' || $account === '' || $holder === '') {
            return [];
        }

        return [
            'refund_bank_code' => $code,
            'refund_bank_account' => $account,
            'refund_bank_holder' => $holder,
        ];
    }
}
