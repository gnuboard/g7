<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 테이블명 리터럴을 모델 파생으로 정리한 내부 리팩토링 (#571)

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Concerns\GuardsOrderScope;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Concerns\SerializesPaymentCallbacks;
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
    use GuardsOrderScope;
    use SanitizesPgResponse;
    use SerializesPaymentCallbacks;

    /** 구매거절확인 주문 잠금 문맥 (SerializesPaymentCallbacks 잠금 키 구분자) */
    public const LOCK_CONTEXT = 'escrow-deny-confirm';

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

    /**
     * 의존성을 주입받습니다.
     *
     * @param  KgInicisApiService  $apiService  KG이니시스 API 서비스
     */
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
     * 같은 주문의 거절확인은 주문 단위 잠금(`escrow-deny-confirm`)으로 한 번에 하나만 처리한다. 두 요청이
     * 겹치면 둘 다 "아직 확인 전"을 보고 PG 에 거절확인(Dncf)을 두 번 보내기 때문이다. 결제 메타는 잠금을 잡은
     * 뒤 다시 읽어, 앞선 요청이 이미 확인했으면 409 로 거절한다. 잠금을 기다리다 시간이 다 되면 다른 요청이
     * 처리 중이므로 409(`messages.errors.escrow_in_progress`)를 돌려준다.
     *
     * @param  EscrowDenyConfirmRequest  $request  구매거절확인 폼
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse
     */
    public function confirm(EscrowDenyConfirmRequest $request, string $orderNumber): JsonResponse
    {
        if ($denied = $this->orderScopeDenial($orderNumber, 'sirsoft-ecommerce.orders.update')) {
            return $denied;
        }

        try {
            $lock = $this->acquireOrderCallbackLock(self::LOCK_CONTEXT, $orderNumber);
        } catch (LockTimeoutException) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.errors.escrow_in_progress', 409, [
                'message' => [__('sirsoft-pay_kginicis::messages.errors.escrow_in_progress')],
            ]);
        }

        try {
            return $this->confirmUnderLock($request, $orderNumber);
        } finally {
            $this->releaseOrderCallbackLock($lock);
        }
    }

    /**
     * 주문 잠금을 잡은 상태에서 결제 메타를 다시 읽고 구매거절확인을 PG 에 전송·기록합니다.
     *
     * @param  EscrowDenyConfirmRequest  $request  구매거절확인 폼
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 거절확인 결과 응답
     */
    private function confirmUnderLock(EscrowDenyConfirmRequest $request, string $orderNumber): JsonResponse
    {
        $payment = $this->findEscrowPayment($orderNumber);

        if (! $payment) {
            return ResponseHelper::error('common.failed', 404, null);
        }

        $meta = $payment->payment_meta ? json_decode($payment->payment_meta, true) : [];

        // 잠금 안에서 다시 읽은 메타 기준 — 이미 구매거절확인을 마친 건은 PG 에 다시 보내지 않는다
        if (isset($meta['escrow_deny_confirm'])) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.errors.escrow_deny_already_confirmed', 409, [
                'message' => [__('sirsoft-pay_kginicis::messages.errors.escrow_deny_already_confirmed')],
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
                // audit:allow datetime-display-user-timezone reason: UTC 저장 — 표시 시 변환
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

    /**
     * 주문번호의 KG이니시스 에스크로 결제 레코드를 찾습니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return object|null 결제 레코드 (없으면 null)
     */
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
