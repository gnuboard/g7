<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;

/**
 * 콜백·서명 컨트롤러의 클레임 결제(CP 채번) 공용 분기입니다.
 *
 * 승인 위임·리다이렉트의 PG 무관 흐름은 모듈 `ClaimPaymentCallback` 이 맡고, 여기에는
 * 이 플러그인의 컨트롤러들이 함께 쓰는 조각(결제 건 조회, SignData 발급 전 검증)만 둔다.
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
     * SignData 발급 요청의 클레임 결제 맥락을 검증합니다. (주문 검증 경로의 CP 대응)
     *
     * 주문 경로와 같은 강도 — 대상 존재 / 구매자 대조 / 결제 가능 상태 / 금액 일치 — 를
     * 클레임 결제 건에 적용한다. 응답 형태(`error` 키·상태코드)도 주문 경로를 따른다.
     *
     * @param  Request  $request  SignData 요청
     * @param  string  $paymentNumber  클레임 결제번호 (CP 채번)
     * @param  int  $amt  클라이언트가 보낸 결제 금액
     * @return JsonResponse|null 거절 응답 (통과 시 null)
     */
    protected function validateClaimSignDataContext(Request $request, string $paymentNumber, int $amt): ?JsonResponse
    {
        $payment = $this->findClaimPayment($paymentNumber);

        if (! $payment) {
            Log::warning('NicePayments: SignData - claim payment not found', [
                'payment_number' => $paymentNumber,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.order_not_found')], 422);
        }

        $payment->loadMissing('order');

        // 부모를 못 읽으면(soft-delete 등) 통과가 아니라 차단이다 — `$payment->order &&` 는
        // 주문이 사라진 결제 건에서 구매자 대조를 통째로 건너뛴다.
        if (! $payment->order || ! $this->requestMatchesOrderBuyer($request, $payment->order)) {
            Log::warning('NicePayments: SignData claim buyer verification failed', [
                'payment_number' => $paymentNumber,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_request')], 403);
        }

        if (! $payment->isPending() || $payment->pg_provider !== 'nicepayments') {
            Log::warning('NicePayments: SignData rejected for non-payable claim payment', [
                'payment_number' => $paymentNumber,
                'payment_status' => $payment->payment_status->value,
                'pg_provider' => $payment->pg_provider,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_request')], 422);
        }

        $charge = app(ClaimPaymentService::class)->resolvePaymentCharge($payment);

        if ($charge === null || $amt !== $charge['minor_unit_amount']) {
            Log::warning('NicePayments: SignData claim amount mismatch', [
                'payment_number' => $paymentNumber,
                'requested_amt' => $amt,
                'expected' => $charge['minor_unit_amount'] ?? null,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_request')], 422);
        }

        return null;
    }
}
