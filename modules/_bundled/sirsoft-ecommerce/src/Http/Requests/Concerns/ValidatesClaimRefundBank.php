<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\ClaimRefundAccountPolicy;
use Modules\Sirsoft\Ecommerce\Support\RefundBankMask;

/**
 * 클레임 신청·수정의 환불 계좌 검증 (취소·환불 / 반품 / 교환 / 수정 공용)
 *
 * 네 요청이 같은 세 가지를 본다 — 예전에는 요청마다 사본이 있었고, 계좌 필수 판정은 취소·환불
 * 요청에만 있어 **반품·교환은 계좌로 돈이 돌아가는 주문에서도 계좌 없이 접수됐다**. 그 신청서는
 * 승인·완료까지 지나 [환불하기]에서야 보낼 계좌가 없다는 것이 드러났다.
 *
 *  ⓐ 세 칸이 짝을 이루는가 — 하나만 오면 은행만 바뀌고 계좌번호가 옛 값으로 남는다.
 *  ⓑ 돈이 계좌로 돌아가는 주문인데 계좌가 비었는가 — 판정은 `ClaimRefundAccountPolicy` 한 곳이다
 *     (신청 화면이 계좌 칸을 세울 때 쓰는 `refund_account.required` 와 같은 술어).
 *  ⓒ 가린 등록 계좌가 그대로 돌아왔는가 — 신청 화면은 등록 계좌를 **가린 모양**으로 채워 두므로
 *     구매자가 손대지 않고 제출하면 가린 값이 온다. 소유권이 확인된 주문의 등록 계좌와 같은 모양일
 *     때만 원문으로 되돌린다(`RefundBankMask::restoreRegistered()`). 되돌릴 대상이 아닌데 `*` 가 섞여
 *     오면(한 칸만 고친 경우 등) 별표째 저장되지 않게 422 로 거절한다(`validateRefundBankNotMasked()`).
 */
trait ValidatesClaimRefundBank
{
    /**
     * 환불 계좌 세 칸이 짝을 이루는지 검사합니다. (ⓐ)
     *
     * @param  Validator  $validator  검증기
     * @return bool 짝을 이루면(또는 비었으면) true
     */
    protected function validateRefundBankComplete(Validator $validator): bool
    {
        $bank = (array) $this->input('refund_bank', []);
        $filled = array_filter(['bank_code', 'account_number', 'holder'], fn (string $f) => filled($bank[$f] ?? null));

        if ($filled !== [] && count($filled) !== 3) {
            $validator->errors()->add('refund_bank', __('sirsoft-ecommerce::validation.claims.refund_bank.incomplete'));

            return false;
        }

        return true;
    }

    /**
     * 돈이 계좌로 돌아가는 주문인데 계좌가 비었으면 막습니다. (ⓑ)
     *
     * 사유 문구가 수단마다 다르다 — 「사람이 송금해야 한다」와 「가상계좌라서 PG 가 요구한다」는
     * 구매자가 할 일은 같아도 왜 요구받는지가 달라, 한 문구로 뭉뚱그리면 이유를 납득하지 못한다.
     * 관리자 [주문취소]가 쓰는 문구 두 벌을 그대로 쓴다.
     *
     * @param  Validator  $validator  검증기
     * @param  Order  $order  소유권이 확인된 주문
     * @return void 반환값 없음
     */
    protected function validateRefundBankPresent(Validator $validator, Order $order): void
    {
        $bank = (array) $this->input('refund_bank', []);

        if (! app(ClaimRefundAccountPolicy::class)->missingForRequest($order, $bank)) {
            return;
        }

        $order->loadMissing('payment');

        // 가상계좌는 PG 가 계좌를 요구하는 축이고, 그 밖(무통장·PG 플러그인이 빠져 계좌 환불로 떨어진
        // 카드 등)은 사람이 송금하는 축이다. 결제수단 코드(`refundMethod()`)로 가르면 폴백된 카드 주문에
        // "가상계좌" 문구가 나간다 — 카드로 결제한 구매자는 이유를 납득하지 못한다.
        $messageKey = (bool) $order->payment?->isVirtualAccount()
            ? 'sirsoft-ecommerce::validation.order.refund_bank_required_for_vbank'
            : 'sirsoft-ecommerce::validation.order.refund_bank_required_for_manual_transfer';

        $validator->errors()->add('refund_bank', __($messageKey));
    }

    /**
     * 가린 값(`*`)이 섞인 계좌가 원문으로 되돌릴 대상이 아니면 막습니다. (ⓒ 의 방어선)
     *
     * 원문 복원은 세 칸이 **가린 모양과 정확히 같을 때만** 일어난다. 그 밖에서 계좌번호·예금주에
     * `*` 가 섞여 오면 — 예금주만 고쳐 계좌번호는 `********7890` 그대로이거나, 가린 값 뒤에 한 글자를
     * 덧붙인 경우 — 복원도 되지 않고 별표째 저장되어, 운영자는 존재하지 않는 계좌로 송금하게 된다.
     * 화면이 그 조합을 막더라도 요청을 직접 만드는 경로가 있으므로 서버가 거절한다.
     *
     * 복원 기준은 주문의 등록 계좌이고, 수정 요청은 신청서에 이미 적힌 계좌를 함께 넘긴다
     * (`$alsoAccept` — 수정 화면이 그 계좌를 가린 채 그대로 되돌려 보내는 것은 "바꾸지 않음" 이다).
     *
     * @param  Validator  $validator  검증기
     * @param  Order  $order  소유권이 확인된 주문
     * @param  array{bank_code: string, account_number: string, holder: string}|null  $alsoAccept  함께 인정할 원문 계좌 (수정 대상 신청서의 계좌)
     * @return bool 통과하면 true
     */
    protected function validateRefundBankNotMasked(Validator $validator, Order $order, ?array $alsoAccept = null): bool
    {
        $bank = (array) $this->input('refund_bank', []);
        $submitted = [
            'bank_code' => (string) ($bank['bank_code'] ?? ''),
            'account_number' => (string) ($bank['account_number'] ?? ''),
            'holder' => (string) ($bank['holder'] ?? ''),
        ];

        if (! str_contains($submitted['account_number'], '*') && ! str_contains($submitted['holder'], '*')) {
            return true;
        }

        foreach ([app(ClaimRefundAccountPolicy::class)->registeredAccount($order), $alsoAccept] as $original) {
            if ($original !== null && RefundBankMask::restoreRegistered($submitted, $original) !== $submitted) {
                return true;
            }
        }

        $validator->errors()->add('refund_bank', __('sirsoft-ecommerce::validation.claims.refund_bank.masked'));

        return false;
    }

    /**
     * 검증된 계좌를 서비스 페이로드 형태로 돌려줍니다. (세 칸이 다 찬 경우만 — 가린 등록 계좌는 원문으로)
     *
     * @param  mixed  $bank  검증된 `refund_bank` 값
     * @param  Order  $order  소유권이 확인된 주문 (등록 계좌 대조용)
     * @return array{bank_code: string, account_number: string, holder: string}|null 계좌 (없으면 null)
     */
    protected function refundBankPayload(mixed $bank, Order $order): ?array
    {
        if (! is_array($bank)
            || ! filled($bank['bank_code'] ?? null)
            || ! filled($bank['account_number'] ?? null)
            || ! filled($bank['holder'] ?? null)
        ) {
            return null;
        }

        $restored = RefundBankMask::restoreRegistered(
            [
                'bank_code' => (string) $bank['bank_code'],
                'account_number' => (string) $bank['account_number'],
                'holder' => (string) $bank['holder'],
            ],
            app(ClaimRefundAccountPolicy::class)->registeredAccount($order),
        );

        return [
            'bank_code' => (string) $restored['bank_code'],
            'account_number' => (string) $restored['account_number'],
            'holder' => (string) $restored['holder'],
        ];
    }
}
