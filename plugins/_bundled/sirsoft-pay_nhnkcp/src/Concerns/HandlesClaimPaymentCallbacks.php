<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;

/**
 * 콜백·승인키 컨트롤러의 클레임 결제(CP 채번) 공용 분기입니다.
 *
 * 승인 위임·리다이렉트의 PG 무관 흐름은 모듈 `ClaimPaymentCallback` 이 맡고, 여기에는
 * 이 플러그인의 컨트롤러들이 함께 쓰는 조각(결제 건 조회, 결제창 실패 복귀, 승인키 발급
 * 전 검증, 승인 확정 뒤 거절에 대한 취소 보상)만 둔다.
 */
trait HandlesClaimPaymentCallbacks
{
    /**
     * 클레임 결제 건을 결제번호로 되찾습니다.
     *
     * @param  string  $paymentNumber  클레임 결제번호 (CP 채번)
     * @return ClaimPayment|null 결제 건
     */
    protected function findClaimPayment(string $paymentNumber): ?ClaimPayment
    {
        return app(ClaimPaymentRepositoryInterface::class)->findByPaymentNumber($paymentNumber);
    }

    /**
     * 결제창 단계 실패(승인 이전)를 신청서 상세로 돌려보냅니다.
     *
     * @param  string  $paymentNumber  클레임 결제번호
     * @param  string|null  $errorCode  실패 코드 (null 이면 사용자 취소 — 오류 안내 없이 복귀)
     * @return RedirectResponse|null 리다이렉트 (결제 건을 못 찾으면 null — 호출부 실패 URL 로 폴백)
     */
    protected function redirectClaimWindowFailure(string $paymentNumber, ?string $errorCode): ?RedirectResponse
    {
        $payment = $this->findClaimPayment($paymentNumber);

        if (! $payment) {
            Log::error('KCP: claim payment not found on window failure', [
                'payment_number' => $paymentNumber,
                'error_code' => $errorCode,
            ]);

            return null;
        }

        return $errorCode === null
            ? redirect(ClaimPaymentRedirect::cancelled($payment))
            : redirect(ClaimPaymentRedirect::fail($payment, $errorCode));
    }

    /**
     * 모바일 승인키 발급 요청의 클레임 결제 맥락을 검증합니다. (주문 검증 경로의 CP 대응)
     *
     * 주문 경로와 같은 강도 — 대상 존재 / 결제 가능 상태 / 소유자 대조 / KRW / 금액 일치 —
     * 를 클레임 결제 건에 적용한다. 소유자 대조는 주문 경로처럼 회원 주문만 잠근다
     * (비회원 주문은 user_id 가 없어 통과하고, 콜백 승인 단계의 결제 건 잠금이 방어한다).
     *
     * @param  Request  $request  승인키 요청
     * @param  string  $paymentNumber  클레임 결제번호 (CP 채번)
     * @param  int  $amount  클라이언트가 보낸 결제 금액
     * @param  string|null  $currency  클라이언트가 보낸 통화 (nullable)
     * @return JsonResponse|null 거절 응답 (통과 시 null)
     */
    protected function validateClaimApprovalKeyContext(Request $request, string $paymentNumber, int $amount, ?string $currency): ?JsonResponse
    {
        $payment = $this->findClaimPayment($paymentNumber);

        if (! $payment) {
            return response()->json([
                'success' => false,
                'error' => 'Claim payment not found.',
            ], 404);
        }

        if (! $payment->isPending()) {
            return response()->json([
                'success' => false,
                'error' => 'Claim payment is not payable.',
            ], 422);
        }

        $payment->loadMissing('order');

        if ((int) ($payment->order?->user_id ?? 0) !== (int) ($request->user()?->id ?? 0)) {
            return response()->json([
                'success' => false,
                'error' => 'You cannot request a NHN KCP approval key for this claim payment.',
            ], 403);
        }

        $charge = app(ClaimPaymentService::class)->resolvePaymentCharge($payment);

        if ($charge === null) {
            return response()->json([
                'success' => false,
                'error' => 'Payment currency is not chargeable.',
            ], 422);
        }

        if (strtoupper($charge['currency']) !== 'KRW' || ($currency !== null && strtoupper($currency) !== 'KRW')) {
            return response()->json([
                'success' => false,
                'error' => 'NHN KCP supports KRW payments only.',
            ], 422);
        }

        if ($amount !== $charge['minor_unit_amount']) {
            return response()->json([
                'success' => false,
                'error' => 'Payment amount does not match the claim payment amount.',
            ], 422);
        }

        return null;
    }

    /**
     * 모듈 거절로 실패 처리된 클레임 승인 건을 취소 API 로 조용히 되돌립니다. (보상)
     *
     * 승인 응답은 모듈이 결제행 `raw_response` 에 저장한 뒤 거절하므로 거래번호를 거기서
     * 되읽는다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  int  $cancelAmt  승인됐던 금액 (모름이면 0)
     */
    protected function cancelClaimApprovalQuietly(ClaimPayment $payment, int $cancelAmt = 0): void
    {
        $raw = $payment->fresh()?->raw_response;
        $raw = is_array($raw) ? $raw : [];
        $tno = (string) ($raw['tno'] ?? '');

        if ($tno === '') {
            Log::error('KCP: claim compensation cancel skipped — no tno in raw response', [
                'payment_number' => $payment->payment_number,
            ]);

            return;
        }

        try {
            app(NhnKcpApiService::class)->cancelPayment(
                $tno,
                $payment->payment_number,
                $cancelAmt,
                __('sirsoft-pay_nhnkcp::messages.claim.compensation_cancel_reason'),
            );
        } catch (\Exception $e) {
            Log::error('KCP: claim compensation cancel failed', [
                'payment_number' => $payment->payment_number,
                'tno' => $tno,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
