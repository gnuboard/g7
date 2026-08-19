<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 테이블명 리터럴을 모델 파생으로 정리한 내부 리팩토링 (#571)

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Http\Requests\EscrowDenyConfirmRequest;
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
     * 확인자명 형식 검증은 EscrowDenyConfirmRequest 가 담당한다.
     *
     * @param  EscrowDenyConfirmRequest  $request  구매거절확인 폼
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse
     */
    public function confirm(EscrowDenyConfirmRequest $request, string $orderNumber): JsonResponse
    {
        $payment = $this->findEscrowPayment($orderNumber);

        if (! $payment) {
            return ResponseHelper::error('common.failed', 404, null);
        }

        $meta = $payment->payment_meta ? json_decode($payment->payment_meta, true) : [];

        // 이미 구매거절확인 완료된 경우
        if (isset($meta['escrow_deny_confirm'])) {
            return ResponseHelper::error('common.failed', 422, [
                'message' => ['이미 구매거절확인이 완료되었습니다.'],
            ]);
        }

        $dcnfName = $request->confirmerName();

        Log::info('KG Inicis: escrow deny confirm requested', [
            'order_number' => $orderNumber,
            'tid' => $payment->transaction_id,
        ]);

        try {
            $this->apiService->useEscrowCredentials(true);

            $pgResponse = $this->apiService->denyConfirmEscrow([
                'originalTid' => $payment->transaction_id,
                'dcnfName' => $dcnfName,
            ]);

            $resultCode = $pgResponse['resultCode'] ?? '';
            $sanitizedPgResponse = $this->sanitizePgResponse($pgResponse, self::ESCROW_DENY_CONFIRM_RESPONSE_KEYS);

            if ($resultCode !== '00') {
                Log::warning('KG Inicis: escrow deny confirm failed', [
                    'order_number' => $orderNumber,
                    'result_code' => $resultCode,
                    'result_msg' => $pgResponse['resultMsg'] ?? '',
                    'pg_response' => $sanitizedPgResponse,
                ]);

                return ResponseHelper::error('common.failed', 502, [
                    'message' => [$pgResponse['resultMsg'] ?? '구매거절확인에 실패했습니다.'],
                ]);
            }

            // payment_meta에 구매거절확인 이력 저장
            $meta['pg_response_sanitized'] = true;
            $meta['escrow_deny_confirm'] = [
                'confirmed_at' => now()->toDateTimeString(),
                'dcnf_name' => $dcnfName,
                'pg_response' => $sanitizedPgResponse,
            ];

            // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
            DB::table((new OrderPayment)->getTable())
                ->where('id', $payment->id)
                ->update([
                    'payment_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);

            Log::info('KG Inicis: escrow deny confirm completed', [
                'order_number' => $orderNumber,
                'tid' => $payment->transaction_id,
            ]);

            return ResponseHelper::success('common.success', [
                'result_code' => $resultCode,
                'result_msg' => $pgResponse['resultMsg'] ?? 'OK',
            ]);

        } catch (\Exception $e) {
            Log::error('KG Inicis: escrow deny confirm exception', [
                'order_number' => $orderNumber,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error('common.failed', 500, [
                'message' => [$e->getMessage()],
            ]);
        }
    }

    private function findEscrowPayment(string $orderNumber): ?object
    {
        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        return DB::table((new OrderPayment)->getTable().' as p')
            ->join((new Order)->getTable().' as o', 'o.id', '=', 'p.order_id')
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
