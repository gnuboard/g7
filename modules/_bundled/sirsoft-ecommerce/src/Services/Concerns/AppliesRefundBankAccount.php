<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 환불 계좌를 다루는 공용 규칙
 *
 * 자리는 둘이고 뜻이 다르다.
 *
 * ① **신청서 컬럼**(`refund_bank_*` — 환불·반품·교환 신청서 각각) = 구매자가 그 신청을 낼 때
 *    적어 낸 계좌. 주문할 때 남긴 계좌를 채워서 열되 그 자리에서 고칠 수 있으므로, 신청서마다
 *    자기 값을 든다. 고친 값을 신청서가 들지 않으면 어느 계좌로 신청했는지가 남지 않는다.
 *
 * ② **주문 결제행**(`ecommerce_order_payments.refund_bank_*`) = **실제 송금 대상**. PG 환불 API 의
 *    `refundReceiveAccount` 가 이 컬럼을 읽고, 무통장은 운영자가 이 값을 보고 손으로 이체한다.
 *    그래서 이 자리는 신청 접수만으로는 바뀌지 않고, 그 환불을 **실행하는 시점**에 실행 대상
 *    환불의 계좌로 갱신된다. 접수 때 덮어쓰면 아직 나가지도 않은 신청이 주문 전체의 송금
 *    대상을 바꾸고, 반대로 실행 때 반영하지 않으면 구매자가 고친 계좌가 무시된 채 옛 계좌로
 *    돈이 나간다 — 둘 다 오류를 남기지 않는다.
 *
 * 즉시취소는 접수가 곧 실행이라 그 자리에서 ②를 갱신한다.
 *
 * `applyRefundBank()` 를 쓰는 서비스는 `$settingsService`(은행명 해석)와
 * `$orderPaymentRepository`(쓰기)를 보유해야 한다. `refundBankColumns()` 는 의존이 없다.
 */
trait AppliesRefundBankAccount
{
    /**
     * 신청서 행에 적을 환불 계좌 컬럼을 돌려줍니다.
     *
     * 미입력이면 빈 배열이라 컬럼이 아예 실리지 않는다 — 신청이 계좌를 지우지 않는다.
     *
     * 세 필드는 **짝**이다. 일부만 온 값을 받으면 은행과 계좌번호가 다른 사람의 것으로 섞인 채
     * 저장되고, 예전에는 빠진 키를 그대로 인덱싱해 500 이 났다 — FormRequest 를 거치지 않는
     * 서비스 호출도 같은 거절(422)을 받아야 한다.
     *
     * @param  array|null  $refundBankInfo  환불 계좌 (bank_code/account_number/holder, 미입력 시 null)
     * @return array{refund_bank_code?: string, refund_bank_account?: string, refund_bank_holder?: string} 신청서 컬럼
     *
     * @throws OrderClaimException 세 필드 중 일부만 채워진 경우
     */
    protected function refundBankColumns(?array $refundBankInfo): array
    {
        if ($refundBankInfo === null) {
            return [];
        }

        $code = $refundBankInfo['bank_code'] ?? null;
        $account = $refundBankInfo['account_number'] ?? null;
        $holder = $refundBankInfo['holder'] ?? null;
        $filled = array_filter([$code, $account, $holder], fn ($value) => filled($value));

        if ($filled === []) {
            return [];
        }

        if (count($filled) !== 3) {
            throw OrderClaimException::refundBankIncomplete();
        }

        return [
            'refund_bank_code' => (string) $code,
            'refund_bank_account' => (string) $account,
            'refund_bank_holder' => (string) $holder,
        ];
    }

    /**
     * 실제 송금 대상(주문 결제행)을 이 계좌로 갱신합니다.
     *
     * 미입력이면 기존 값(주문 시 입력분)을 그대로 둔다 — 값이 없는 신청이 송금 대상을 지우면
     * 운영자가 이체할 근거가 사라진다.
     *
     * @param  Order  $order  대상 주문
     * @param  array|null  $refundBankInfo  환불 계좌 (bank_code/account_number/holder, 미입력 시 null)
     * @return void 반환값 없음
     */
    protected function applyRefundBank(Order $order, ?array $refundBankInfo): void
    {
        if ($refundBankInfo === null) {
            return;
        }

        $order->loadMissing('payment');

        if (! $order->payment) {
            return;
        }

        $this->orderPaymentRepository->updateRefundBank(
            $order->payment,
            $refundBankInfo['bank_code'],
            $this->settingsService->resolveBankName($refundBankInfo['bank_code']),
            $refundBankInfo['account_number'],
            $refundBankInfo['holder'],
        );
    }
}
