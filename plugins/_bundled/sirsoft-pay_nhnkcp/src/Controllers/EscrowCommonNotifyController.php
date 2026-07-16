<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Extension\HookManager;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
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

    public function __construct(
        private readonly OrderProcessingService $orderService,
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

        $txCd     = (string) $validated['tx_cd'];
        $tno      = (string) ($validated['tno'] ?? '');
        $orderNo  = (string) $validated['order_no'];
        $clStatus = (string) ($validated['cl_status'] ?? '');

        Log::info('KCP: escrow common notify received', [
            'tx_cd'    => $txCd,
            'tno'      => $tno,
            'order_no' => $orderNo,
            'cl_status' => $clStatus,
        ]);

        try {
            $order = $this->orderService->findByOrderNumber($orderNo);

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

            match ($txCd) {
                'TX02' => match ($clStatus) {
                    '2'  => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.purchase_confirmed', $order, $validated),
                    '8'  => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.purchase_cancelled', $order, $validated),
                    '3'  => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.denial_confirmed', $order, $validated),
                    default => Log::info('KCP: escrow TX02 unknown cl_status', ['cl_status' => $clStatus, 'order_no' => $orderNo]),
                },
                'TX03' => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.delivery_started', $order, $validated),
                default => Log::info('KCP: escrow common notify - unhandled tx_cd', ['tx_cd' => $txCd, 'order_no' => $orderNo]),
            };

            $this->recordEscrowNotifyProcessed($order, $fingerprint);

            Log::info('KCP: escrow common notify processed', [
                'tx_cd'    => $txCd,
                'order_no' => $orderNo,
                'cl_status' => $clStatus,
            ]);

        } catch (\Exception $e) {
            Log::error('KCP: escrow common notify failed', [
                'tx_cd'    => $txCd,
                'order_no' => $orderNo,
                'error'    => $e->getMessage(),
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
     */
    private function validateEscrowNotifyContext(Order $order, array $validated, string $tno): ?string
    {
        $payment = $order->payment;
        if (! $payment) {
            return 'payment_not_found';
        }

        if ($payment->pg_provider !== 'nhnkcp') {
            return 'pg_provider_mismatch';
        }

        if (! (bool) $payment->is_escrow) {
            return 'not_escrow_payment';
        }

        $storedTno = trim((string) ($payment->transaction_id ?? ''));
        if ($storedTno === '' || $tno === '' || ! hash_equals($storedTno, $tno)) {
            return 'tno_mismatch';
        }

        $meta = $payment->payment_meta ?? [];
        $storedSiteCd = trim((string) ($meta['site_cd'] ?? ''));
        $receivedSiteCd = trim((string) ($validated['site_cd'] ?? ''));
        if ($storedSiteCd !== '' && $receivedSiteCd !== '' && ! hash_equals($storedSiteCd, $receivedSiteCd)) {
            return 'site_cd_mismatch';
        }

        return null;
    }

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

    private function wasEscrowNotifyAlreadyProcessed(Order $order, string $fingerprint): bool
    {
        $payment = $order->payment;
        $meta = $payment?->payment_meta ?? [];
        $processed = $meta['escrow_common_notify_fingerprints'] ?? [];
        $processed = is_array($processed) ? $processed : [];

        return in_array($fingerprint, $processed, true);
    }

    private function recordEscrowNotifyProcessed(Order $order, string $fingerprint): void
    {
        $payment = $order->payment;
        if (! $payment) {
            return;
        }

        $meta = $payment->payment_meta ?? [];
        $processed = $meta['escrow_common_notify_fingerprints'] ?? [];
        $processed = is_array($processed) ? $processed : [];
        $processed[] = $fingerprint;
        $meta['escrow_common_notify_fingerprints'] = array_values(array_unique(array_slice($processed, -20)));

        $payment->update(['payment_meta' => $meta]);
    }
}
