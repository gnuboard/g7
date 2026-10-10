<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Support;

use App\Support\SiteUrl;
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
            // PG 별 보상 클로저를 받았으면 모듈의 망취소 훅은 부르지 않는다 — 두 경로가 모두
            // 취소를 시도하면 같은 승인을 두 번 취소한다. 클로저가 없으면 모듈 훅이 보상한다.
            $paid = app(ClaimPaymentService::class)->approvePgPayment(
                $payment,
                $callback,
                voidOnReject: $onAmountMismatch === null,
            );

            // 보안: 상대 경로를 redirect() 에 넘기면 요청 Host 로 절대화된다 — 사이트 주소로 만든다.
            return redirect(SiteUrl::absolute(ClaimPaymentRedirect::success($paid)));
        } catch (OrderClaimException $e) {
            Log::warning('[claim.payment] 콜백 승인 거절', [
                'payment_number' => $payment->payment_number,
                'pg_provider' => $payment->pg_provider,
                'reason' => $e->getMessageKey(),
            ]);

            // 승인이 성립한 뒤의 거절(금액 불일치 · 승인 중 철회 F5)은 PG 별 보상으로 되돌린다.
            if ($onAmountMismatch !== null
                && (str_ends_with($e->getMessageKey(), 'claim_payment_amount_mismatch')
                    || str_ends_with($e->getMessageKey(), 'claim_payment_withdrawn_during_approval'))) {
                $onAmountMismatch($e);
            }

            return redirect(SiteUrl::absolute(ClaimPaymentRedirect::fail($payment, self::errorCode($e))));
        } catch (\Exception $e) {
            Log::error('[claim.payment] 콜백 승인 처리 중 예외', [
                'payment_number' => $payment->payment_number,
                'pg_provider' => $payment->pg_provider,
                'error' => $e->getMessage(),
            ]);

            return redirect(SiteUrl::absolute(ClaimPaymentRedirect::fail($payment, 'approve_failed')));
        }
    }

    /**
     * 클레임 결제 예외를 리다이렉트 쿼리용 짧은 코드로 바꿉니다.
     *
     * 싣지 않기로 한 것은 PG **원문 메시지**뿐이고 **코드는 싣는다** — 이 클래스 헤더와
     * `ClaimPaymentService::approvePgPayment()` 가 같은 문장을 계약으로 적고 있다. 원문은
     * 상점 MID·PG 내부 상태를 담을 수 있어 로그·결제행 컬럼에만 남기지만, 코드는 구매자가
     * 받는 유일한 실패 식별자이자 고객센터가 건을 특정하는 값이다.
     *
     * 그래서 **파라미터가 코드를 들고 있으면 그쪽이 이긴다.** 키 조각만 보면
     * `OrderClaimException::paymentFailed('PG_API_ERROR')` 가 `failed` 로 뭉개진다 — 그 키의
     * 마지막 조각이 상수 `failed` 이고 진짜 코드는 치환 파라미터에 따로 실려 있기 때문이다.
     * 그러면 서로 다른 PG 거절이 전부 같은 토큰으로 수렴한다(2026-09-22 #599 3차 실측 F-5:
     * 결제행에는 `PG_API_ERROR` 와 한국어 사유가 남았는데 화면은 「실패 코드 failed」였다).
     *
     * 파라미터가 없는 예외는 기존대로 키의 마지막 조각을 쓴다 — `claim_payment_amount_mismatch`
     * → `amount_mismatch` 처럼 그 조각 자체가 사유인 경우다(모듈이 내린 판정이라 PG 코드가 없다).
     *
     * 코드 모양이 아닌 값은 받지 않는다. 이 값은 배너에 그대로 그려지므로, PG 가 코드 자리에
     * 문장·태그·줄바꿈을 담아 보내면 그것이 구매자가 읽는 문구가 된다 — 원문 메시지를 싣지
     * 않기로 한 이유가 그대로 적용된다.
     *
     * @param  OrderClaimException  $e  모듈 예외
     * @return string 오류 코드
     */
    public static function errorCode(OrderClaimException $e): string
    {
        $error = $e->getMessageParams()['error'] ?? null;

        if (is_string($error) && preg_match('/^[A-Za-z0-9_.-]{1,50}$/', $error) === 1) {
            return $error;
        }

        $key = $e->getMessageKey();
        $marker = 'claim_payment_';
        $pos = strrpos($key, $marker);

        return $pos === false ? 'failed' : substr($key, $pos + strlen($marker));
    }
}
