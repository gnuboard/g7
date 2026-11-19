<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

use App\Helpers\ResponseHelper;
use App\Support\ScreenUrl;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentCallback;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;

/**
 * 콜백·승인키 컨트롤러의 클레임 결제(CP 채번) 공용 분기입니다.
 *
 * 승인 위임·리다이렉트의 PG 무관 흐름은 모듈 `ClaimPaymentCallback` 이 맡고, 여기에는
 * 이 플러그인의 컨트롤러들이 함께 쓰는 조각(결제 건 조회, 결제창 실패 복귀, 승인키 발급
 * 전 검증, 콜백 락 안의 승인 위임)만 둔다. 승인 확정 뒤 거절의 보상은 승인 리스너의
 * `voidApproval()` 이 맡는다.
 *
 * 호스트 요건: 생성자에서 이커머스 결제 계약 `PaymentGatewayBridge` 를 `$bridge` 로 주입받는다
 * (서비스 로케이터 `app()` 로 꺼내지 않는다). 결제 건 조회·청구액·주문 조회는 모두 그 계약으로 한다.
 * 공개 Support(`ClaimPaymentCallback`·`ClaimPaymentRedirect`)가 결제 건 Model 을 받으므로 조회는 계약의
 * 읽기 전용 Model 조회(`findClaimPaymentModel`)를 쓰고, 그 Model 은 속성 읽기만 한다. 승인 직렬화 헬퍼
 * `approveClaimPaymentSerialized()` 를 부르는 호스트는 `SerializesPaymentCallbacks` 도 함께 쓴다.
 * 승인키 검증의 소유자 판정은 `VerifiesOrderOwnership` 을 이 트레이트가 함께 들여 쓴다(주문 경로와 한 판정).
 */
trait HandlesClaimPaymentCallbacks
{
    use VerifiesOrderOwnership;

    /**
     * 클레임 결제 건을 결제번호로 되찾습니다.
     *
     * 이 PG(`nhnkcp`) 결제 건이거나 결제수단을 고르기 전(PG 미지정) 건만 보인다(계약의 조회 범위).
     * 반환 Model 은 공개 Support 인자 호환용 읽기 전용이다 — 속성 읽기만 한다.
     *
     * @param  string  $paymentNumber  클레임 결제번호 (CP 채번)
     * @return ClaimPayment|null 결제 건
     */
    protected function findClaimPayment(string $paymentNumber): ?ClaimPayment
    {
        return $this->bridge->findClaimPaymentModel($paymentNumber, 'nhnkcp');
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
            return redirect(ScreenUrl::toAbsolute($this->busyClaimCallbackRedirectUrl($payment)));
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
        $latestRecord = $this->bridge->describeClaimPayment($latest);

        Log::warning('KCP: claim payment callback skipped because the callback lock is busy', [
            'payment_number' => $payment->payment_number,
            'payment_status' => $latestRecord->paymentStatus?->value,
        ]);

        return $latestRecord->isPaid
            ? ClaimPaymentRedirect::success($latest)
            : ClaimPaymentRedirect::fail($latest, 'callback_locked');
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
            ? redirect(ScreenUrl::toAbsolute(ClaimPaymentRedirect::cancelled($payment)))
            : redirect(ScreenUrl::toAbsolute(ClaimPaymentRedirect::fail($payment, $errorCode)));
    }

    /**
     * 모바일 승인키 발급 요청의 클레임 결제 맥락을 검증합니다. (주문 검증 경로의 CP 대응)
     *
     * 주문 경로와 같은 강도 — 대상 존재 / 결제 가능 상태 / 소유자 대조 / KRW / 금액 일치 —
     * 를 클레임 결제 건에 적용한다. 소유자 대조는 결제 건이 딸린 주문을 기준으로 주문 경로와 같은 판정
     * ({@see VerifiesOrderOwnership::orderOwnershipRejection()})을 쓴다 — 회원 주문은 로그인한 본인,
     * 비회원 주문은 그 주문의 조회 토큰(`X-Guest-Order-Token`)이어야 통과한다(#855). 주문이 사라졌으면
     * 열람자와 무관하게 차단한다(아래 본문 주석).
     *
     * @param  Request  $request  승인키 요청
     * @param  string  $paymentNumber  클레임 결제번호 (CP 채번)
     * @param  int  $amount  클라이언트가 보낸 결제 금액
     * @param  string|null  $currency  클라이언트가 보낸 통화 (nullable)
     * @return JsonResponse|null 거절 응답 (통과 시 null)
     */
    protected function validateClaimApprovalKeyContext(
        Request $request,
        string $paymentNumber,
        int $amount,
        ?string $currency,
    ): ?JsonResponse {
        $payment = $this->bridge->findClaimPayment($paymentNumber, 'nhnkcp');

        if (! $payment) {
            return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.claim_payment_not_found', 404);
        }

        if (! $payment->isPending) {
            return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.claim_payment_not_payable', 422);
        }

        $order = $payment->orderNumber === null ? null : $this->bridge->findOrderByNumber($payment->orderNumber, 'nhnkcp');

        // 부모를 못 읽으면(soft-delete 등) 통과가 아니라 차단이다 — `?->user_id ?? 0` 처럼 접으면
        // 주문이 사라진 결제 건에서 열람자 판정이 0 == 0 으로 통과한다. 라우트가 optional.sanctum 이라
        // (#883) 비로그인 요청도 여기까지 오므로, 그 방어를 이 함수 밖의 조건에 기대지 않고 이 자리에서 닫는다.
        if ($order === null) {
            return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.claim_approval_forbidden', 403);
        }

        // 소유자 판정은 주문 경로와 한 규칙이다(#855) — 회원 주문은 로그인 본인만(아니면 403), 비회원 주문은
        // 그 주문의 조회 토큰만(없거나 다른 주문 것이면 401). 결제번호만 아는 제3자는 어느 쪽으로도 통과하지 못한다.
        $ownershipError = $this->orderOwnershipRejection(
            $request,
            $order,
            'messages.responses.claim_approval_forbidden',
        );

        if ($ownershipError !== null) {
            return $ownershipError;
        }

        $charge = $this->bridge->resolveClaimCharge($paymentNumber);

        if ($charge === null) {
            return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.currency_not_chargeable', 422);
        }

        if (strtoupper($charge->currency) !== 'KRW' || ($currency !== null && strtoupper($currency) !== 'KRW')) {
            return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.krw_only', 422);
        }

        if ($amount !== $charge->minorUnitAmount) {
            return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.claim_amount_mismatch', 422);
        }

        return null;
    }
}
