<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * KG 이니시스 에스크로 구매거절확인 관리자 컨트롤러
 *
 * 구매자가 구매거절 선택 후 판매자(관리자)가 INIAPI v1 type=Dncf로 거절 확인 처리.
 * 메뉴얼: https://manual.inicis.com/pay/escrow_pc.html#dncf
 */
class AdminEscrowDenyConfirmController extends AdminBaseController
{
    use SanitizesPgResponse;

    /** 에스크로 구매거절확인 PG 응답 저장 허용 필드 */
    private const ESCROW_DENY_CONFIRM_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'tid',
        'TID',
        'originalTid',
        'mid',
        'MID',
        'type',
    ];

    public function __construct(
        private readonly KgInicisApiService $apiService,
    ) {
        parent::__construct();
    }

    /**
     * confirm
     *
     * @param  Request  $request
     * @param  string  $orderNumber
     * @return JsonResponse
     */
    public function confirm(Request $request, string $orderNumber): JsonResponse
    {
        $payment = $this->findEscrowPayment($orderNumber);

        if (! $payment) {
            return ResponseHelper::error('messages.failed', 404, null);
        }

        $meta = $payment->payment_meta ? json_decode($payment->payment_meta, true) : [];

        // 이미 구매거절확인 완료된 경우
        if (isset($meta['escrow_deny_confirm'])) {
            return ResponseHelper::error('messages.failed', 422, [
                'message' => ['이미 구매거절확인이 완료되었습니다.'],
            ]);
        }

        $dcnfName = trim((string) $request->input('dcnf_name', '관리자'));

        Log::info('KG Inicis: escrow deny confirm requested', [
            'order_number' => $orderNumber,
            'tid'          => $payment->transaction_id,
        ]);

        try {
            $this->apiService->useEscrowCredentials(true);

            $pgResponse = $this->apiService->denyConfirmEscrow([
                'originalTid' => $payment->transaction_id,
                'dcnfName'    => $dcnfName,
            ]);

            $resultCode = $pgResponse['resultCode'] ?? '';
            $sanitizedPgResponse = $this->sanitizePgResponse($pgResponse, self::ESCROW_DENY_CONFIRM_RESPONSE_KEYS);

            if ($resultCode !== '00') {
                Log::warning('KG Inicis: escrow deny confirm failed', [
                    'order_number' => $orderNumber,
                    'result_code'  => $resultCode,
                    'result_msg'   => $pgResponse['resultMsg'] ?? '',
                    'pg_response'  => $sanitizedPgResponse,
                ]);

                return ResponseHelper::error('messages.failed', 502, [
                    'message' => [$pgResponse['resultMsg'] ?? '구매거절확인에 실패했습니다.'],
                ]);
            }

            // payment_meta에 구매거절확인 이력 저장
            $meta['pg_response_sanitized'] = true;
            $meta['escrow_deny_confirm'] = [
                'confirmed_at' => now()->toDateTimeString(),
                'dcnf_name'    => $dcnfName,
                'pg_response'  => $sanitizedPgResponse,
            ];

            DB::table('ecommerce_order_payments')
                ->where('id', $payment->id)
                ->update([
                    'payment_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'updated_at'   => now(),
                ]);

            Log::info('KG Inicis: escrow deny confirm completed', [
                'order_number' => $orderNumber,
                'tid'          => $payment->transaction_id,
            ]);

            return ResponseHelper::success('messages.success', [
                'result_code' => $resultCode,
                'result_msg'  => $pgResponse['resultMsg'] ?? 'OK',
            ]);

        } catch (\Exception $e) {
            Log::error('KG Inicis: escrow deny confirm exception', [
                'order_number' => $orderNumber,
                'error'        => $e->getMessage(),
            ]);

            return ResponseHelper::error('messages.failed', 500, [
                'message' => [$e->getMessage()],
            ]);
        }
    }

    private function findEscrowPayment(string $orderNumber): ?object
    {
        return DB::table('ecommerce_order_payments as p')
            ->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('p.pg_provider', 'kginicis')
            ->where('p.is_escrow', true)
            ->whereNotNull('p.transaction_id')
            ->select([
                'p.id',
                'p.transaction_id',
                'p.payment_meta',
            ])
            ->first();
    }
}
