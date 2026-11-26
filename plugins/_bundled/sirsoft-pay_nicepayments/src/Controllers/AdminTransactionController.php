<?php

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 결제 행 조회를 이커머스 공개 결제 계약으로 옮긴 내부 리팩토링 

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFilters;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayNicepayments\Concerns\GuardsOrderScope;
use Plugins\Sirsoft\PayNicepayments\Concerns\ResolvesEasyPayDisplay;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\TransactionQueryRequest;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

class AdminTransactionController extends AdminBaseController
{
    use GuardsOrderScope;
    use ResolvesEasyPayDisplay;

    /** 이 플러그인 결제 행의 결제사 id(옛 식별자 포함) */
    private const PG_SCOPE = ['nicepayments', 'nicepay'];

    /**
     * 의존성을 주입받습니다.
     *
     * @param  NicePaymentsApiService  $apiService  나이스페이먼츠 API 서비스
     * @param  PaymentGatewayBridge  $paymentGateway  이커머스 공개 결제 계약
     */
    public function __construct(
        private NicePaymentsApiService $apiService,
        private readonly PaymentGatewayBridge $paymentGateway,
    ) {
        parent::__construct();
    }

    /**
     * TID 단건 거래 조회
     *
     * NicePay 단건 거래 조회 API 를 호출하고 로컬 DB 의 보조 정보(에스크로 여부,
     * 테스트 모드 플래그)를 합쳐 반환한다.
     *
     * @param  TransactionQueryRequest  $request  tid 입력 폼
     * @return JsonResponse 거래 정보 + _local_is_escrow / EscrowYN / _is_test_mode 보강
     */
    public function query(TransactionQueryRequest $request): JsonResponse
    {
        if ($denied = $this->transactionScopeDenial(trim((string) $request->validated('tid')), 'sirsoft-ecommerce.orders.read')) {
            return $denied;
        }

        return $this->queryByTid(trim((string) $request->validated('tid')));
    }

    /**
     * 주문번호로 거래 조회 (TID 자동 매핑)
     *
     * 어드민 주문 상세에서 거래 조회 버튼 클릭 시 사용. 이 플러그인 결제 행의
     * 거래번호를 찾아 queryByTid 로 위임.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 거래 정보 또는 매핑 없을 시 null
     */
    public function queryByOrder(string $orderNumber): JsonResponse
    {
        if ($denied = $this->orderScopeDenial($orderNumber, 'sirsoft-ecommerce.orders.read')) {
            return $denied;
        }

        $payment = $this->paymentGateway->findPaymentByOrderNumber(
            self::PG_SCOPE,
            $orderNumber,
            new PaymentFilters(hasTransactionId: true, nonEmptyTransactionId: true),
        );

        if (! $payment || $payment->transactionId === null) {
            return ResponseHelper::success('common.success', null);
        }

        return $this->queryByTid($payment->transactionId);
    }

    /**
     * 거래번호로 거래를 조회해 화면 표시용 응답을 만듭니다.
     *
     * @param  string  $tid  거래번호
     * @return JsonResponse 거래 조회 응답
     */
    private function queryByTid(string $tid): JsonResponse
    {
        try {
            $result = $this->apiService->queryTransaction($tid);

            $localPayment = $this->paymentGateway->findPaymentByTransactionId(self::PG_SCOPE, $tid);

            $result['_local_is_escrow'] = $localPayment?->isEscrow ?? false;
            $display = $localPayment ? $this->resolvePaymentDisplay($localPayment) : [
                'payment_method_label' => null,
                'payment_method_display_label' => null,
                'embedded_pg_provider' => null,
                'embedded_pg_provider_label' => null,
            ];
            $result['_base_pay_method_label'] = $display['payment_method_label'];
            $result['_embedded_pg_provider'] = $display['embedded_pg_provider'];
            $result['_embedded_pg_provider_label'] = $display['embedded_pg_provider_label'];
            $result['_pay_method_label'] = $display['payment_method_display_label'];
            $result['payment_method_display_label'] = $display['payment_method_display_label'];

            if ($localPayment !== null && $localPayment->paymentMeta !== []) {
                $meta = $localPayment->paymentMeta;
                $result['EscrowYN'] = $meta['pg_raw_response']['EscrowYN']
                    ?? ($result['EscrowYN'] ?? 'N');
                $result['_is_test_mode'] = (bool) ($meta['is_test_mode'] ?? false);
            }

            return ResponseHelper::success('common.success', $result);
        } catch (\Exception $e) {
            Log::error('NicePayments queryTransaction failed', [
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error('common.failed', 502, null);
        }
    }
}
