<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ProviderMismatch;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\Tosspayments\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\Tosspayments\Concerns\VerifiesOrderOwnership;
use Plugins\Sirsoft\Tosspayments\Http\Requests\PaymentCloseReportRequest;

/**
 * 토스페이먼츠 결제창 닫힘·결제 실패 보고 컨트롤러
 *
 * 브라우저 리턴 콜백(`/payment/fail`)은 인증도 서명도 없고 주문번호가 쿼리스트링으로 오므로
 * 주문 상태를 바꾸지 않는다. 주문을 실패로 전이시키는 것은 주문 소유자(회원 본인 로그인 /
 * 비회원 조회 토큰)를 확인한 이 경로뿐이며 — 주문자 연락처 대조는 제3자도 통과할 수 있어 쓰지 않는다 —
 * KCP·KG이니시스·나이스페이 플러그인의 close-report 와 같은 계약이다.
 */
class PaymentCloseReportController
{
    use RecordsPaymentWindowClosure;
    use VerifiesOrderOwnership;

    private const FAILURE_CODE = 'USER_CANCEL';

    private const FAILURE_MESSAGE = '사용자가 토스페이먼츠 결제창을 닫았습니다.';

    /**
     * @param  PaymentGatewayBridge  $bridge  이커머스 공개 결제 계약 (주문 조회, 비회원 토큰 확인, 결제창 닫힘 기록)
     */
    public function __construct(
        private readonly PaymentGatewayBridge $bridge,
    ) {}

    /**
     * 결제창 닫힘·결제 실패 보고를 검증하고 결제 실패/취소 이력을 기록합니다.
     *
     * @param  PaymentCloseReportRequest  $request  결제창 닫힘 보고 요청
     * @return JsonResponse 닫힘 보고 처리 결과
     */
    public function store(PaymentCloseReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $orderId = $validated['orderId'];
        $amount = (int) $validated['amount'];

        $rateLimitKey = $this->rateLimitKey($request->ip() ?? '', $orderId);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            return ResponseHelper::error('common.failed', 429, [
                'message' => [__('sirsoft-tosspayments::messages.responses.too_many_requests')],
            ]);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $order = $this->bridge->findOrderByNumber($orderId, self::TOSS_PROVIDER);
        if (! $order) {
            return ResponseHelper::error('common.failed', 404, [
                'message' => [__('sirsoft-tosspayments::messages.responses.order_not_found')],
            ]);
        }

        if (! $order->orderStatus->isBeforePayment()) {
            return ResponseHelper::success('common.success', [
                'status' => 'ignored',
                'reason' => 'order_not_payable',
            ]);
        }

        // 결제 성공 콜백(success → confirmPayment)과 이 보고가 경쟁할 수 있다. 카드 주문은 승인
        // 직전까지 order_status=PENDING_ORDER 라 위 가드를 통과하므로, 결제가 이미 성공했으면
        // 여기서 차단해 옵션이 취소로 덮이는 것을 막는다.
        if ($order->payment?->isPaid) {
            return ResponseHelper::success('common.success', [
                'status' => 'ignored',
                'reason' => 'payment_already_paid',
            ]);
        }

        // 가상계좌가 이미 발급된(입금대기) 주문은 주문 상태가 아직 결제 전이라 위 가드를 통과한다. 발급 저장이 커밋된 뒤
        // 후속 처리(장바구니 정리)가 실패하면 성공 콜백이 실패 화면으로 보내고, 그 화면이 이 보고를 보낸다 — 발급된 주문을
        // 실패와 취소로 덮으면 고객은 받은 계좌로 입금할 수 없다. 결제창이 승인, 발급 단계로 넘어가기 전(READY)만 실패로 기록한다.
        if ($order->payment && $order->payment->paymentStatus !== PaymentStatusEnum::READY) {
            return ResponseHelper::success('common.success', [
                'status' => 'ignored',
                'reason' => 'payment_not_ready',
            ]);
        }

        if (! $this->requestOwnsOrder($request, $order)) {
            return ResponseHelper::error('common.failed', 403, [
                'message' => [__('sirsoft-tosspayments::messages.responses.ownership_verification_failed')],
            ]);
        }

        $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'close_report', [
            'orderId' => $orderId,
            'received_amount' => $amount,
            'ip' => $request->ip(),
        ]);
        if ($expectedAmount === null) {
            return ResponseHelper::error('common.failed', 422, [
                'message' => [__('sirsoft-tosspayments::messages.responses.currency_not_chargeable')],
            ]);
        }

        if ($amount !== $expectedAmount) {
            return ResponseHelper::error('common.failed', 422, [
                'message' => [__('sirsoft-tosspayments::messages.responses.amount_mismatch')],
            ]);
        }

        // 결제창을 닫은 것인지 결제가 거절된 것인지는 실패 코드 유무로 갈린다. 두 경우 모두
        // 주문을 실패로 전이시키지만, 운영자가 원인을 구분할 수 있도록 단계를 나눠 기록한다.
        $failureCode = trim((string) ($validated['code'] ?? ''));
        $closeReason = trim((string) ($validated['reason'] ?? ''));

        try {
            $this->markPaymentWindowClosed(
                $order,
                $failureCode !== '' ? $failureCode : self::FAILURE_CODE,
                self::FAILURE_MESSAGE,
                $closeReason !== '' ? $closeReason : self::FAILURE_MESSAGE,
                $failureCode !== '' ? 'payment_failed' : 'window_closed',
            );
        } catch (ProviderMismatch) {
            // 다른 결제사가 이미 결제(발급)한 행 — 결제 대기 행이 아니다(계약이 쓰기를 거부했고 아무것도 바뀌지 않았다)
            return ResponseHelper::success('common.success', [
                'status' => 'ignored',
                'reason' => 'payment_not_ready',
            ]);
        }

        return ResponseHelper::success('common.success', [
            'status' => 'recorded',
        ]);
    }

    /**
     * 레이트리밋 키를 생성합니다 (IP + 주문번호 조합).
     *
     * @param  string  $ip  요청 IP
     * @param  string  $orderId  주문번호
     * @return string 레이트리밋 키
     */
    private function rateLimitKey(string $ip, string $orderId): string
    {
        return 'sirsoft-tosspayments:payment-close-report:'.sha1($ip.'|'.$orderId);
    }
}
