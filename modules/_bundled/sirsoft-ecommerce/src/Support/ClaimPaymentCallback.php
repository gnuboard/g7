<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Support;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;

/**
 * 결제 플러그인 콜백의 클레임 결제(CP 채번) 승인 공용 흐름입니다.
 *
 * 콜백 컨트롤러가 CP 건을 판별한 뒤의 나머지 — 모듈 승인 위임, 실패 코드 변환, 신청서
 * 상세로의 리다이렉트 — 는 PG 와 무관하게 동일하므로 여기에 모은다. 플러그인 4종이 각자
 * 이 흐름을 복제하면 실패 코드 계약이 한 곳만 고쳐질 때 나머지가 조용히 낡는다.
 *
 * PG 별로 다른 것(승인 확정 뒤 거절에 대한 보상 — 망취소 등)만 클로저로 받는다.
 */
final class ClaimPaymentCallback
{
    /**
     * 클레임 결제 건을 승인하고 신청서 상세로 리다이렉트합니다.
     *
     * 승인은 `ClaimPaymentService::approvePgPayment()` 에 위임한다 — 그 안에서
     * `claim_payment.approve` 필터가 발화해 플러그인의 승인 리스너가 실제 PG 승인을
     * 수행하므로, 서버 콜백 경로와 API 승인 경로가 같은 구현을 탄다.
     *
     * @param  ClaimPayment  $payment  승인 대상 결제 건
     * @param  array  $callback  PG 콜백 파라미터
     * @param  Closure|null  $onAmountMismatch  승인 금액 불일치 시 보상 훅 (PG 승인이 확정된 뒤의
     *                                          거절이므로 망취소 등으로 되돌리지 않으면 화면은
     *                                          실패인데 PG 는 청구된 채 남는다)
     * @return RedirectResponse 신청서 상세로 리다이렉트
     */
    public static function approveAndRedirect(ClaimPayment $payment, array $callback, ?Closure $onAmountMismatch = null): RedirectResponse
    {
        try {
            $paid = app(ClaimPaymentService::class)->approvePgPayment($payment, $callback);

            return redirect(ClaimPaymentRedirect::success($paid));
        } catch (OrderClaimException $e) {
            Log::warning('[claim.payment] 콜백 승인 거절', [
                'payment_number' => $payment->payment_number,
                'pg_provider' => $payment->pg_provider,
                'reason' => $e->getMessageKey(),
            ]);

            if ($onAmountMismatch !== null && str_ends_with($e->getMessageKey(), 'claim_payment_amount_mismatch')) {
                $onAmountMismatch($e);
            }

            return redirect(ClaimPaymentRedirect::fail($payment, self::errorCode($e)));
        } catch (\Exception $e) {
            Log::error('[claim.payment] 콜백 승인 처리 중 예외', [
                'payment_number' => $payment->payment_number,
                'pg_provider' => $payment->pg_provider,
                'error' => $e->getMessage(),
            ]);

            return redirect(ClaimPaymentRedirect::fail($payment, 'approve_failed'));
        }
    }

    /**
     * 클레임 결제 예외를 리다이렉트 쿼리용 짧은 코드로 바꿉니다.
     *
     * `claim_payment_amount_mismatch` → `amount_mismatch` 처럼 예외 키의 마지막 조각을 쓴다 —
     * PG 원문 메시지는 리다이렉트 주소에 싣지 않는다 (로그·결제행 컬럼에만 남는다).
     *
     * @param  OrderClaimException  $e  모듈 예외
     * @return string 오류 코드
     */
    public static function errorCode(OrderClaimException $e): string
    {
        $key = $e->getMessageKey();
        $marker = 'claim_payment_';
        $pos = strrpos($key, $marker);

        return $pos === false ? 'failed' : substr($key, $pos + strlen($marker));
    }
}
