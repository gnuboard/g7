<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\ClaimRefundAccountPolicy;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;

/**
 * 관리자 환불 실행 요청 ('환불하기' — D18)
 *
 * 본문의 금액은 선택 사항이다 — 실려 있으면 화면에 입력된 값으로 실행하고(저장 없이 실행 가능),
 * 없으면 저장된 환불액으로 실행한다. 상한(D19)은 서비스가 실행 직전에 재계산해 검증한다.
 */
class ExecuteRefundRequest extends FormRequest
{
    /**
     * 실행 대상 환불신청서 (1회 조회 후 보관)
     */
    protected ?OrderRefund $targetRefund = null;

    /**
     * 실행 대상 조회를 마쳤는지 (부재도 결과이므로 따로 기록한다)
     */
    protected bool $targetRefundResolved = false;

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
            // 계좌 없이 완료로 기록하겠다는 **명시 동의**(T2-D6, 운영 결정 2026-09-23).
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
     * 검증 오류 문구에 쓰는 필드 표시명
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    public function attributes(): array
    {
        return [
            'refund_amount' => __('sirsoft-ecommerce::validation.request_attributes.refund_amount'),
            'refund_points_amount' => __('sirsoft-ecommerce::validation.request_attributes.refund_points_amount'),
            'refund_method' => __('sirsoft-ecommerce::validation.request_attributes.refund_method'),
            'refund_bank' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank'),
            'refund_bank.bank_code' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_code'),
            'refund_bank.account_number' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_account'),
            'refund_bank.holder' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_holder'),
            'force_without_refund_bank' => __('sirsoft-ecommerce::validation.request_attributes.force_without_refund_bank'),
        ];
    }

    /**
     * 환불수단이 그 주문에서 쓸 수 있는 수단인지 대조합니다. (금액 수정과 같은 강도)
     *
     * 게이트는 **[금액 수정](`UpdateClaimAmountsRequest` → `refundMethodOptions()`)과 같은 선택지**다 —
     * PG 결제창을 쓰지 않은 주문에 `PG` 를 실으면 막고, 계좌 수동 환불(`BANK`)은 결제수단과 무관하게
     * 받는다. 마일리지(`POINTS`)는 회원 주문에서만, 현금을 돌려줄 때는 마일리지 기능이 켜진 상점에서만
     * 받는다(D1·Q21). 두 경로의 강도가 달랐던 동안은 카드 주문에 계좌 수동 환불을
     * [금액 수정]으로 저장할 수는 있는데 [환불하기]가 `payment_mismatch` 로 막아, 저장한 수단으로
     * 실행할 길이 없었다.
     *
     * 카드 주문의 계좌 수동 환불은 **허용한다**(2026-09-23 결정) — PG 취소가 불가능한 상황(부분취소
     * 미지원·승인 후 기한 경과·가맹점 해지)에서 운영자가 계좌로 돌려주고 장부를 닫는 것이 실제
     * 운영 경로다. 대신 그 돈이 나갈 계좌가 있어야 한다 — 아래 계좌 검증이 실행 수단 축까지 본다
     * (`ClaimRefundAccountPolicy::requiredForRefund()`). 무통장 주문의 `PG` 는 여전히 막는다 —
     * PG 리스너가 인식하지 못해 원인 없는 500 이 된다.
     *
     * 선택지 판정은 상세 화면의 환불 수단 라디오와 **같은 곳**이다(`AdminClaimService::refundMethodOptions()`).
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $method = $this->input('refund_method');

            if (! filled($method)) {
                return;
            }

            $refund = $this->targetRefund();
            $order = $refund?->order;

            if ($order === null) {
                return;
            }

            // 선택지는 [금액 수정] 저장 가드와 **같은 판정**이다(`OrderRefundRequestService::pgRefundAvailable()`·
            // `pointsRefundAllowed()`). PG 플러그인이 빠져 계좌 환불로 떨어진 주문(P14)은 선택지에서 PG 가
            // 이미 빠진다 — 여기서 따로 거르면 저장과 실행이 다시 다른 기준을 읽게 된다. 「마일리지」는 이번
            // 실행의 현금 환불액(본문에 있으면 그 값)으로 판정한다(Q21).
            $cashAmount = (float) ($this->input('refund_amount') ?? $refund->refund_amount);
            $allowed = array_column(app(AdminClaimService::class)->refundMethodOptions($order, $cashAmount), 'value');

            if (! in_array($method, $allowed, true)) {
                // 금액 수정 경로가 같은 위반에 내는 문구와 같다(`OrderClaimException::refundMethodUnavailable()`).
                // PG 결제인데 막혔다면 그 주문의 결제 플러그인이 꺼진 것이다 — 사유와 해결책을 따로 말한다(S3-D25).
                $pluginOff = $method === RefundMethodEnum::PG->value
                    && app(OrderRefundRequestService::class)->pgRefundBlockedByInactivePlugin($order);
                $validator->errors()->add(
                    'refund_method',
                    $pluginOff
                        ? __('sirsoft-ecommerce::exceptions.pg_provider_inactive', ['provider' => (string) $order->payment->pg_provider])
                        : __('sirsoft-ecommerce::exceptions.claim_refund_method_unavailable')
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
     * 채권을 포기하는 등 운영상 필요가 있기 때문이며(운영 결정 2026-09-23), 모르고 눌러 닫히는
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

        $refund = $this->targetRefund();

        if ($refund === null || $refund->order === null) {
            return;
        }

        // ⓑ 계좌가 필요한 환불인가 — 상세 화면의 `refund_account_required` 와 같은 한 곳이다.
        // 기준은 실행에 실제로 쓰일 값이라 본문 override(금액·수단)가 있으면 그것을, 없으면 저장값을
        // 본다(실행 로직과 같은 순서다). 마일리지로만 돌려주는 환불은 계좌가 필요 없고, 카드 주문이라도
        // 계좌 수동 환불(`BANK`)을 고르면 계좌가 필요하다.
        $required = app(ClaimRefundAccountPolicy::class)->requiredForRefund(
            $refund,
            $this->has('refund_amount') ? (float) $this->input('refund_amount') : null,
            filled($this->input('refund_method')) ? RefundMethodEnum::tryFrom((string) $this->input('refund_method')) : null,
        );

        if (! $required) {
            return;
        }

        // ⓒ 계좌도 동의도 없다.
        $validator->errors()->add(
            'refund_bank',
            __('sirsoft-ecommerce::validation.order.refund_bank_required_for_manual_transfer')
        );
    }

    /**
     * 실행 대상 환불신청서를 반환합니다. (Repository 경유 · 요청당 1회 조회)
     *
     * 수단 대조와 계좌 검증이 같은 신청서를 읽는다 — 각자 모델을 직접 조회하면 한 요청에서 같은
     * 행을 두 번 읽고, 저장소 규약(Repository 인터페이스 경유)도 우회한다.
     *
     * @return OrderRefund|null 환불신청서 (없으면 null — 부재는 컨트롤러가 404 로 낸다)
     */
    protected function targetRefund(): ?OrderRefund
    {
        if (! $this->targetRefundResolved) {
            $this->targetRefund = app(OrderRefundRepositoryInterface::class)
                ->findById((int) $this->route('id'), ['order.payment']);
            $this->targetRefundResolved = true;
        }

        return $this->targetRefund;
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
