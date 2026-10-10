<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

use App\Support\SiteUrl;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentCallback;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;

/**
 * 콜백·서명 컨트롤러의 클레임 결제(CP 채번) 공용 분기입니다.
 *
 * 승인 위임·리다이렉트의 PG 무관 흐름은 모듈 `ClaimPaymentCallback` 이 맡고, 여기에는
 * 이 플러그인의 컨트롤러들이 함께 쓰는 조각(결제 건 조회, SignData 발급 전 검증)만 둔다.
 *
 * 호스트 요건: 생성자에서 `ClaimPaymentRepositoryInterface` 를 `$claimPaymentRepository` 로 주입받는다
 * (서비스 로케이터 `app()` 로 Repository 를 꺼내지 않는다). 승인 직렬화 헬퍼
 * `approveClaimPaymentSerialized()` 를 부르는 호스트는 `SerializesPaymentCallbacks` 도 함께 쓴다.
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
        return $this->claimPaymentRepository->findByPaymentNumber($paymentNumber);
    }

    /**
     * 클레임 결제 승인을 결제번호 단위 콜백 락 안에서 수행하고 신청서 상세로 돌려보냅니다.
     *
     * 락 획득은 try **안**이다 — 대기 시간을 넘긴 `LockTimeoutException` 이 밖으로 새면 콜백이
     * 500 으로 끝나 구매자는 결제 결과를 볼 수 없다. 락을 못 얻으면 결제 건 상태만 읽어
     * 복귀 주소를 정한다({@see busyClaimCallbackRedirectUrl()}).
     *
     * @param  string  $context  콜백 문맥 (락 키 구분자 — 주문 경로와 같은 값을 쓴다)
     * @param  ClaimPayment  $payment  승인 대상 결제 건
     * @param  array  $callback  모듈 승인에 넘길 콜백 파라미터
     * @return RedirectResponse 신청서 상세로 리다이렉트
     */
    protected function approveClaimPaymentSerialized(
        string $context,
        ClaimPayment $payment,
        array $callback,
    ): RedirectResponse {
        $lock = null;

        try {
            $lock = $this->acquireOrderCallbackLock($context, (string) $payment->payment_number);

            // 보상 클로저를 넘기지 않는다 — 승인 뒤 거절의 보상은 모듈의 `claim_payment.void_approval`
            // 필터(이 플러그인 승인 리스너의 `voidApproval()`)가 맡아, API 승인 경로와 같은 한 구현이 된다.
            return ClaimPaymentCallback::approveAndRedirect($payment, $callback);
        } catch (LockTimeoutException) {
            return redirect(SiteUrl::absolute($this->busyClaimCallbackRedirectUrl($payment)));
        } finally {
            $this->releaseOrderCallbackLock($lock);
        }
    }

    /**
     * 콜백 락을 얻지 못한 클레임 결제의 복귀 주소를 정합니다.
     *
     * 락을 쥔 앞 요청이 이미 결제를 확정했으면 성공으로, 아니면 재시도 안내(`callback_locked`)로
     * 보낸다. 결제 건 상태만 읽고 아무것도 바꾸지 않는다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return string 리다이렉트 URL
     */
    protected function busyClaimCallbackRedirectUrl(ClaimPayment $payment): string
    {
        $latest = $this->findClaimPayment((string) $payment->payment_number) ?? $payment;

        Log::warning('NicePayments: claim payment callback skipped because the callback lock is busy', [
            'payment_number' => $payment->payment_number,
            'payment_status' => $latest->payment_status?->value,
        ]);

        return $latest->isPaid()
            ? ClaimPaymentRedirect::success($latest)
            : ClaimPaymentRedirect::fail($latest, 'callback_locked');
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
