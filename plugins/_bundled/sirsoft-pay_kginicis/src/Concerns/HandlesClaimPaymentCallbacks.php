<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * PC/모바일 콜백 컨트롤러의 클레임 결제(CP 채번) 공용 분기입니다.
 *
 * 승인 위임·리다이렉트의 PG 무관 흐름은 모듈 `ClaimPaymentCallback` 이 맡고, 여기에는
 * 이 플러그인의 두 콜백 컨트롤러가 함께 쓰는 조각(결제 건 조회, 결제창 실패 복귀,
 * 승인 확정 뒤 거절에 대한 취소 보상)만 둔다.
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
            Log::error('KG Inicis: claim payment not found on window failure', [
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
     * 서명 발급 요청의 클레임 결제 맥락을 검증합니다. (주문 검증 경로의 CP 대응)
     *
     * 결제창을 열기 전 클라이언트가 보낸 금액을 결제 건의 기대 청구액과 대조한다 — 주문
     * 경로의 `validateOrderContext()` 와 같은 강도(대상 존재 / 결제 가능 상태 / KRW / 구매자
     * 대조 / 금액 일치)를 클레임 결제 건에 적용한다.
     *
     * @param  Request  $request  서명 요청
     * @param  string  $paymentNumber  클레임 결제번호 (CP 채번)
     * @param  int  $price  클라이언트가 보낸 결제 금액
     * @return JsonResponse|null 거절 응답 (통과 시 null)
     */
    protected function validateClaimPaymentContext($request, string $paymentNumber, int $price)
    {
        $payment = $this->findClaimPayment($paymentNumber);

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'Claim payment not found.',
            ], 404);
        }

        if (! $payment->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Claim payment is not payable.',
            ], 422);
        }

        $charge = app(ClaimPaymentService::class)->resolvePaymentCharge($payment);

        if ($charge === null) {
            return response()->json([
                'success' => false,
                'message' => 'Payment currency is not chargeable.',
            ], 422);
        }

        if (strtoupper($charge['currency']) !== 'KRW') {
            return response()->json([
                'success' => false,
                'message' => 'Standard KG Inicis signature is only available for KRW payments.',
            ], 422);
        }

        $payment->loadMissing('order');

        // 부모를 못 읽으면(soft-delete 등) 통과가 아니라 차단이다 — `$payment->order &&` 는
        // 주문이 사라진 결제 건에서 구매자 대조를 통째로 건너뛴다.
        if (! $payment->order || ! $this->requestMatchesOrderBuyer($request, $payment->order)) {
            return response()->json([
                'success' => false,
                'message' => 'Order buyer verification failed.',
            ], 403);
        }

        if ($price !== $charge['minor_unit_amount']) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount does not match the claim payment amount.',
            ], 422);
        }

        return null;
    }

    /**
     * 모듈 거절로 실패 처리된 클레임 승인 건을 취소 API 로 조용히 되돌립니다. (모바일 보상)
     *
     * 모바일 경로에는 망취소 URL 이 없어 승인 응답의 거래번호로 취소한다 — 승인 응답은
     * 모듈이 결제행 `raw_response` 에 저장한 뒤 거절하므로 거기서 되읽는다.
     *
     * @param  ClaimPayment  $payment  결제 건
     */
    protected function cancelClaimApprovalQuietly(ClaimPayment $payment): void
    {
        $raw = $payment->fresh()?->raw_response;
        $raw = is_array($raw) ? $raw : [];
        $tid = (string) ($raw['P_TID'] ?? $raw['tid'] ?? '');

        if ($tid === '') {
            Log::error('KG Inicis: claim compensation cancel skipped — no tid in raw response', [
                'payment_number' => $payment->payment_number,
            ]);

            return;
        }

        try {
            app(KgInicisApiService::class)->cancelPayment(
                $tid,
                'Card',
                null,
                __('sirsoft-pay_kginicis::messages.claim.compensation_cancel_reason'),
            );
        } catch (\Exception $e) {
            Log::error('KG Inicis: claim compensation cancel failed', [
                'payment_number' => $payment->payment_number,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
