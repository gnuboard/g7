<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ProviderMismatch;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayKginicis\Concerns\ResolvesEasyPaySelection;
use Plugins\Sirsoft\PayKginicis\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Concerns\VerifiesOrderOwnership;
use Plugins\Sirsoft\PayKginicis\Http\Requests\PaymentCloseReportRequest;

class PaymentCloseReportController
{
    use ResolvesEasyPaySelection;
    use SerializesPaymentCallbacks;
    use ValidatesCbtOrderContext;
    use VerifiesOrderOwnership;

    private const FAILURE_CODE = 'USER_CANCEL';

    private const FAILURE_MESSAGE = '사용자가 KG 이니시스 결제창을 닫았습니다.';

    /**
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 공개 계약 (주문 조회·소유 확인·실패 기록)
     */
    public function __construct(
        private readonly PaymentGatewayBridge $bridge,
    ) {}

    /**
     * PC 표준결제창 닫힘 보고를 검증하고 결제 실패/취소 이력을 기록합니다.
     *
     * @param  PaymentCloseReportRequest  $request  결제창 닫힘 보고 요청
     * @return JsonResponse 닫힘 보고 처리 결과
     */
    public function store(PaymentCloseReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $oid = $validated['oid'];
        $price = (int) $validated['price'];

        $rateLimitKey = $this->rateLimitKey($request, $oid);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            return ResponseHelper::error('common.failed', 429, [
                'message' => [__('sirsoft-pay_kginicis::messages.responses.too_many_requests')],
            ]);
        }
        RateLimiter::hit($rateLimitKey, 60);

        // 클레임 추가결제(CP 채번)는 기록할 주문 결제 이력이 없다 — 결제 건은 결제 대기로 남아
        // 신청서 상세에서 재시도하므로 보고만 받고 무시한다 (주문 조회로 흘리면 404 가 프론트
        // 흐름을 끊고 error 로그만 쌓인다).
        if ($this->bridge->isClaimPaymentNumber($oid)) {
            return ResponseHelper::success('common.success', [
                'recorded' => false,
                'reason' => 'claim_payment',
            ]);
        }

        $callbackLock = null;

        try {
            // PC close-report 는 PC authCallback 과 경쟁할 수 있으므로 같은 주문 락을 공유한다.
            // authCallback 이 먼저 결제완료를 확정했다면 아래 최신 주문 조회에서 close-report 를 무시한다.
            $callbackLock = $this->acquireOrderCallbackLock('authCallback', $oid);

            $order = $this->bridge->findOrderByNumber($oid, 'kginicis');
            if (! $order) {
                return ResponseHelper::error('common.failed', 404, [
                    'message' => [__('sirsoft-pay_kginicis::messages.responses.order_not_found')],
                ]);
            }

            if (! $order->orderStatus->isBeforePayment()) {
                return ResponseHelper::success('common.success', [
                    'status' => 'ignored',
                    'reason' => 'order_not_payable',
                ]);
            }

            // 결제 성공 콜백(authorize)과 결제창 닫힘 보고가 경쟁할 때, 카드 주문은 승인 직전까지
            // order_status=PENDING_ORDER 라 위 가드를 통과한다. payment_status 가 이미 PAID 면 결제가
            // 성공한 것이므로 실패 처리하지 않는다(failPayment 가 옵션을 취소로 덮어 주문/옵션 상태가
            // 어긋나는 race 차단). failPayment 자체에도 동일 가드가 있으나, 여기서 차단해 불필요한
            // 마일리지 복원 시도·결제취소 이력 기록까지 미연에 방지한다.
            if ($order->payment?->isPaid) {
                return ResponseHelper::success('common.success', [
                    'status' => 'ignored',
                    'reason' => 'payment_already_paid',
                ]);
            }

            if (strtoupper((string) $order->currency) !== 'KRW') {
                return ResponseHelper::error('common.failed', 422, [
                    'message' => [__('sirsoft-pay_kginicis::messages.responses.krw_only')],
                ]);
            }

            if (! $this->requestOwnsOrder($request, $order)) {
                return ResponseHelper::error('common.failed', 403, [
                    'message' => [__('sirsoft-pay_kginicis::messages.responses.ownership_verification_failed')],
                ]);
            }

            $expectedPrice = $this->resolveExpectedPaymentPriceOrNull($order, 'close_report', [
                'received_amount' => $price,
            ]);
            if ($expectedPrice === null) {
                return ResponseHelper::error('common.failed', 422, [
                    'message' => [__('sirsoft-pay_kginicis::messages.responses.currency_not_chargeable')],
                ]);
            }

            if ($price !== $expectedPrice) {
                return ResponseHelper::error('common.failed', 422, [
                    'message' => [__('sirsoft-pay_kginicis::messages.responses.amount_mismatch')],
                ]);
            }

            $selectedEasyPayMethod = $this->normalizeEasyPayMethod(
                is_string($validated['payment_method'] ?? null) ? $validated['payment_method'] : null,
            );

            // 현행 세 단계 순서 그대로: 결제 실패 → 간편결제 문맥 기록 → 결제 취소 이력(부록 A3 KG #120·#123·#124).
            $failedOrder = $this->bridge->failPayment(
                $order->orderNumber,
                'kginicis',
                self::FAILURE_CODE,
                self::FAILURE_MESSAGE,
            );

            $closeReason = trim((string) ($validated['reason'] ?? ''));

            $this->persistEasyPayCloseContext($failedOrder, $selectedEasyPayMethod);

            $this->bridge->recordPaymentCancellation(
                $failedOrder->orderNumber,
                'kginicis',
                self::FAILURE_CODE,
                $closeReason !== '' ? $closeReason : self::FAILURE_MESSAGE,
            );

            return ResponseHelper::success('common.success', [
                'status' => 'recorded',
            ]);
        } catch (ProviderMismatch) {
            // 다른 결제사가 이미 결제(발급)한 행 — 결제 대기 행이 아니다. 거부는 첫 쓰기(결제 실패 처리)에서 나므로 아무것도 바뀌지 않았다.
            return ResponseHelper::success('common.success', [
                'status' => 'ignored',
                'reason' => 'payment_not_ready',
            ]);
        } catch (LockTimeoutException) {
            return ResponseHelper::success('common.success', [
                'status' => 'ignored',
                'reason' => 'callback_in_progress',
            ]);
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    /**
     * 레이트리밋 키를 생성합니다 (IP + 주문번호 조합).
     *
     * @param  PaymentCloseReportRequest  $request  요청
     * @param  string  $oid  주문번호
     * @return string 레이트리밋 키
     */
    private function rateLimitKey(PaymentCloseReportRequest $request, string $oid): string
    {
        return 'sirsoft-pay_kginicis:payment-close-report:'.sha1($request->ip().'|'.$oid);
    }

    /**
     * 결제창을 닫을 때 고른 간편결제 수단을 결제 메타에 남깁니다 (운영자 원인 파악용).
     *
     * 컬럼과 meta 를 결제 행 잠금 아래 한 번에 쓴다(meta 는 현재 값에 병합).
     *
     * @param  OrderRecord  $order  실패 처리된 주문
     * @param  string|null  $selectedEasyPayMethod  정규화된 간편결제 수단 (없으면 기록 안 함)
     * @return void 반환값 없음
     */
    private function persistEasyPayCloseContext(OrderRecord $order, ?string $selectedEasyPayMethod): void
    {
        // 결제 행이 없으면(또는 다른 결제사의 결제 뒤 행이면) 기록하지 않는다 — 현행 무동작과 같다.
        if ($selectedEasyPayMethod === null || $order->payment === null) {
            return;
        }

        $this->bridge->recordPgAttributes(
            $order->orderNumber,
            'kginicis',
            ['embedded_pg_provider' => $this->resolveEmbeddedPgProvider($selectedEasyPayMethod)],
            array_merge(
                $this->buildEasyPayPaymentMeta($selectedEasyPayMethod),
                ['close_report_payment_method' => $selectedEasyPayMethod],
            ),
        );
    }
}
