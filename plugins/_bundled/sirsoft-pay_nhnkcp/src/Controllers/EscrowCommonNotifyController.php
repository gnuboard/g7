<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Extension\HookManager;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayNhnkcp\Concerns\SendsKcpNotifyResponse;
use Plugins\Sirsoft\PayNhnkcp\Http\Requests\EscrowCommonNotifyRequest;

/**
 * KCP 에스크로 공통통보 컨트롤러
 *
 * POST /plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify
 * KCP 가맹점 어드민에서 공통통보 URL로 등록해야 합니다.
 *
 * tx_cd 별 처리 (그누보드5 settle_kcp_common.php 참고):
 *   TX02 + cl_status=2 → 구매확인
 *   TX02 + cl_status=8 → 구매취소
 *   TX02 + cl_status=3 → 구매취소 확인
 *   TX03               → 배송시작 통보
 *
 * 응답: <form><input name="result" value="0000"> HTML (SendsKcpNotifyResponse trait)
 * IP 가드: RestrictKcpIp 미들웨어 (routes/web.php)
 */
class EscrowCommonNotifyController
{
    use SendsKcpNotifyResponse;

    /**
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 계약 (주문 조회, 통보 지문 기록)
     */
    public function __construct(
        private readonly PaymentGatewayBridge $bridge,
    ) {}

    /**
     * KCP 에스크로 통보 처리 (공통 webhook)
     *
     * @param  EscrowCommonNotifyRequest  $request  검증된 통보 페이로드 (order_no, tx_cd, cl_status 등)
     * @return Response 200 + KCP 표준 result HTML
     */
    public function handle(EscrowCommonNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $txCd = (string) $validated['tx_cd'];
        $tno = (string) ($validated['tno'] ?? '');
        $orderNo = (string) $validated['order_no'];
        $clStatus = (string) ($validated['cl_status'] ?? '');

        Log::info('KCP: escrow common notify received', [
            'tx_cd' => $txCd,
            'tno' => $tno,
            'order_no' => $orderNo,
            'cl_status' => $clStatus,
        ]);

        try {
            $order = $this->bridge->findOrderByNumber($orderNo, 'nhnkcp');

            if (! $order) {
                Log::error('KCP: escrow common notify - order not found', ['order_no' => $orderNo]);

                // 영구 실패 — result=0000 으로 재통보 차단
                return $this->kcpNotifyResponse();
            }

            $contextError = $this->validateEscrowNotifyContext($order, $validated, $tno);
            if ($contextError !== null) {
                Log::warning('KCP: escrow common notify context mismatch — retry requested', [
                    'reason' => $contextError,
                    'tx_cd' => $txCd,
                    'tno' => $tno,
                    'order_no' => $orderNo,
                ]);

                return $this->kcpNotifyRetry();
            }

            $fingerprint = $this->escrowNotifyFingerprint($validated);
            if ($this->wasEscrowNotifyAlreadyProcessed($order, $fingerprint)) {
                Log::info('KCP: escrow common notify replay ignored', [
                    'tx_cd' => $txCd,
                    'tno' => $tno,
                    'order_no' => $orderNo,
                    'cl_status' => $clStatus,
                ]);

                return $this->kcpNotifyResponse();
            }

            // 공개 훅은 종전처럼 주문 Model 을 넘긴다(구독자 호환) — 계약의 읽기 전용 Model 조회로 얻는다.
            $hookOrder = $this->bridge->findOrderModel($orderNo, 'nhnkcp');

            match ($txCd) {
                'TX02' => match ($clStatus) {
                    '2' => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.purchase_confirmed', $hookOrder, $validated),
                    '8' => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.purchase_cancelled', $hookOrder, $validated),
                    '3' => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.denial_confirmed', $hookOrder, $validated),
                    default => Log::info('KCP: escrow TX02 unknown cl_status', ['cl_status' => $clStatus, 'order_no' => $orderNo]),
                },
                'TX03' => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.delivery_started', $hookOrder, $validated),
                default => Log::info('KCP: escrow common notify - unhandled tx_cd', ['tx_cd' => $txCd, 'order_no' => $orderNo]),
            };

            $this->recordEscrowNotifyProcessed($order, $fingerprint);

            Log::info('KCP: escrow common notify processed', [
                'tx_cd' => $txCd,
                'order_no' => $orderNo,
                'cl_status' => $clStatus,
            ]);

        } catch (\Exception $e) {
            Log::error('KCP: escrow common notify failed', [
                'tx_cd' => $txCd,
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);

            // 일시적 실패 (DB 등) — result != 0000 으로 KCP 재통보 유도
            return $this->kcpNotifyRetry();
        }

        return $this->kcpNotifyResponse();
    }

    /**
     * 에스크로 공통통보가 실제 결제 건과 같은 거래인지 확인한다.
     *
     * 현재 이 컨트롤러는 hook dispatch 만 하지만, 향후 listener 가 주문 상태를 바꿀 수 있으므로
     * 주문번호 단독 신뢰를 피하고 저장된 tno/site_cd/escrow 플래그를 먼저 확인한다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  array  $validated  검증된 통보 파라미터
     * @param  string  $tno  통보 거래번호
     * @return string|null 거절 사유 코드 (통과 시 null)
     */
    private function validateEscrowNotifyContext(OrderRecord $order, array $validated, string $tno): ?string
    {
        $payment = $order->payment;
        if (! $payment) {
            return 'payment_not_found';
        }

        if ($payment->pgProvider !== 'nhnkcp') {
            return 'pg_provider_mismatch';
        }

        if (! $payment->isEscrow) {
            return 'not_escrow_payment';
        }

        $storedTno = trim((string) ($payment->transactionId ?? ''));
        if ($storedTno === '' || $tno === '' || ! hash_equals($storedTno, $tno)) {
            return 'tno_mismatch';
        }

        $meta = $payment->paymentMeta;
        $storedSiteCd = trim((string) ($meta['site_cd'] ?? ''));
        $receivedSiteCd = trim((string) ($validated['site_cd'] ?? ''));
        if ($storedSiteCd !== '' && $receivedSiteCd !== '' && ! hash_equals($storedSiteCd, $receivedSiteCd)) {
            return 'site_cd_mismatch';
        }

        return null;
    }

    /**
     * 통보 재전송을 가려낼 지문(통보 핵심 필드의 SHA-256)을 만든다.
     *
     * @param  array  $validated  검증된 통보 파라미터
     * @return string 통보 지문
     */
    private function escrowNotifyFingerprint(array $validated): string
    {
        $keys = [
            'site_cd',
            'tno',
            'order_no',
            'tx_cd',
            'tx_tm',
            'cl_status',
            'st_cd',
            'can_msg',
            'waybill_no',
            'waybill_corp',
        ];

        $payload = [];
        foreach ($keys as $key) {
            $payload[$key] = (string) ($validated[$key] ?? '');
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 같은 통보 지문을 이미 처리했는지 확인한다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  string  $fingerprint  통보 지문
     * @return bool 처리했으면 true
     */
    private function wasEscrowNotifyAlreadyProcessed(OrderRecord $order, string $fingerprint): bool
    {
        $meta = $order->payment?->paymentMeta ?? [];
        $processed = $meta['escrow_common_notify_fingerprints'] ?? [];
        $processed = is_array($processed) ? $processed : [];

        return in_array($fingerprint, $processed, true);
    }

    /**
     * 처리한 통보 지문을 결제 meta 에 남긴다(최근 20건).
     *
     * 지문 목록은 누적이라 계약의 잠금 아래에서 현재 meta 에 덧붙인다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  string  $fingerprint  통보 지문
     * @return void 반환값 없음
     */
    private function recordEscrowNotifyProcessed(OrderRecord $order, string $fingerprint): void
    {
        if ($order->payment === null) {
            return;
        }

        $this->bridge->mutatePaymentMetaWithLock($order->orderNumber, 'nhnkcp', function (array $meta) use ($fingerprint): array {
            $processed = $meta['escrow_common_notify_fingerprints'] ?? [];
            $processed = is_array($processed) ? $processed : [];
            $processed[] = $fingerprint;
            $meta['escrow_common_notify_fingerprints'] = array_values(array_unique(array_slice($processed, -20)));

            return $meta;
        });
    }
}
