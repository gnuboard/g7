<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;

/**
 * CBT(일본 엔화) 경로의 클레임 추가결제(CP 채번) 공용 분기입니다.
 *
 * 결제 통화가 JPY 인 상점에서는 프론트가 `pg_payment_data.currency` 로 CBT 경로를 고르므로
 * (`plugins/_bundled/sirsoft-pay_kginicis/resources/js/handlers/requestPayment.ts`), 클레임 추가결제도 토큰·해시·콜백 3단계를 그대로
 * 탄다. 그 세 지점이 CP 번호를 주문번호로 조회하면 `Order not found` 404 로 끝나므로
 * — 결제창이 열리기도 전에, 원인을 알 수 없는 형태로 — 국내 원화 경로(`HandlesClaimPaymentCallbacks`)
 * 와 같은 강도의 검증을 이 자리에 둔다.
 *
 * 검증 축은 주문 경로(`ValidatesCbtOrderContext`)와 1:1 로 대응한다:
 * 대상 존재 / 결제 가능 상태 / 통화(JPY) / 구매자 대조 / 금액 일치.
 *
 * 결제 건 조회·청구액은 이커머스 공개 계약으로 한다(#916) — 호스트는 `HandlesClaimPaymentCallbacks` 를 함께 쓰고
 * 생성자에서 `PaymentGatewayBridge` 를 `$bridge` 로 주입받는다.
 */
trait HandlesCbtClaimPayments
{
    /**
     * CBT 요청의 클레임 결제 맥락을 검증합니다. (주문 검증 경로의 CP 대응)
     *
     * @param  Request  $request  CBT 토큰·해시 요청
     * @param  string  $paymentNumber  클레임 결제번호 (CP 채번)
     * @param  int  $price  클라이언트가 보낸 결제 금액 (최소 화폐단위)
     * @return JsonResponse|null 거절 응답 (통과 시 null)
     */
    protected function validateCbtClaimPaymentContext(Request $request, string $paymentNumber, int $price): ?JsonResponse
    {
        $payment = $this->findClaimPayment($paymentNumber);

        if (! $payment) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.claim_payment_not_found', 404);
        }

        if (! $payment->isPending) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.claim_payment_not_payable', 422);
        }

        $charge = $this->bridge->resolveClaimCharge($paymentNumber);

        if ($charge === null) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.currency_not_chargeable', 422);
        }

        // CBT 는 엔화 전용 경로다 — 원화 결제 건이 이리로 오면 국내 표준결제로 가야 한다.
        if (strtoupper($charge->currency) !== 'JPY') {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.cbt_jpy_only', 422);
        }

        // 부모를 못 읽으면(soft-delete 등) 통과가 아니라 차단이다 — 국내 경로와 같은 판정.
        $order = $this->claimPaymentOrder($payment);
        if (! $order || ! $this->cbtRequestMatchesOrderBuyer($request, $order)) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.buyer_verification_failed', 403);
        }

        if ($price !== $charge->minorUnitAmount) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.claim_amount_mismatch', 422);
        }

        return null;
    }

    /**
     * CBT 콜백에서 승인 대상 클레임 결제 건을 되찾습니다. (엔화·결제대기만)
     *
     * 콜백은 브라우저 리다이렉트라 JSON 거절을 돌려줄 수 없다 — 자격 미달이면 null 을
     * 돌려주고 호출부가 신청서 상세로 되돌린다. 돌려주는 Model 은 공개 Support 승인 인자로만 쓴다.
     *
     * @param  string  $paymentNumber  클레임 결제번호
     * @return ClaimPayment|null 승인 가능한 결제 건 (읽기 전용)
     */
    protected function resolveApprovableCbtClaimPayment(string $paymentNumber): ?ClaimPayment
    {
        $payment = $this->findClaimPaymentModel($paymentNumber);

        if (! $payment || ! $this->bridge->describeClaimPayment($payment)->isPending) {
            return null;
        }

        $charge = $this->bridge->resolveClaimCharge($payment);

        if ($charge === null || strtoupper($charge->currency) !== 'JPY') {
            return null;
        }

        return $payment;
    }
}
