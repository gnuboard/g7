<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 테이블명 리터럴을 모델 파생으로 정리한 내부 리팩토링

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFilters;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNicepayments\Concerns\GuardsOrderScope;
use Plugins\Sirsoft\PayNicepayments\Exceptions\NicePayRejectedException;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\EscrowDeliveryRegisterRequest;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

class AdminEscrowController extends AdminBaseController
{
    use GuardsOrderScope;

    /**
     * 의존성을 주입받습니다.
     *
     * @param  NicePaymentsApiService  $apiService  나이스페이먼츠 API 서비스
     * @param  PaymentGatewayBridge  $paymentGateway  이커머스 공개 결제 계약
     */
    public function __construct(
        private readonly NicePaymentsApiService $apiService,
        private readonly PaymentGatewayBridge $paymentGateway,
    ) {
        parent::__construct();
    }

    /**
     * 주문의 에스크로 결제 목록 조회
     *
     * GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/escrow-payments
     */
    /**
     * 주문의 에스크로 결제 목록 조회
     *
     * 에스크로(EscrowYN=Y) 로 결제된 가상계좌/카드 결제 건들의 TID/금액/상태를 반환.
     * 어드민 에스크로 배송 등록 화면에서 결제 선택 UI 데이터 소스로 사용.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 에스크로 결제 목록
     */
    public function getEscrowPayments(string $orderNumber): JsonResponse
    {
        if ($denied = $this->orderScopeDenial($orderNumber, 'sirsoft-ecommerce.orders.read')) {
            return $denied;
        }

        $payments = $this->paymentGateway->listEscrowPayments('nicepayments', $orderNumber);

        return ResponseHelper::success('common.success', [
            'escrow_payments' => array_map(fn (PaymentRecord $p) => [
                'id' => $p->id,
                'transaction_id' => $p->transactionId,
                'payment_method' => $p->paymentMethod,
                // 응답은 종전대로 상태 원문 문자열이다
                'payment_status' => $p->paymentStatus->value,
            ], $payments),
        ]);
    }

    /**
     * 에스크로 배송 등록
     *
     * POST /api/plugins/sirsoft-pay_nicepayments/admin/escrow/register-delivery
     */
    /**
     * 에스크로 배송 등록
     *
     * NicePay escrow_process.jsp 호출하여 배송 정보(택배사/송장번호/수령인 등)를
     * NicePay 측에 등록. 등록 완료 시 구매자에게 자동으로 구매확정 안내가 발송됨.
     *
     * @param  EscrowDeliveryRegisterRequest  $request  배송 정보 폼
     * @return JsonResponse 등록 결과 + ResultCode
     */
    public function registerDelivery(EscrowDeliveryRegisterRequest $request): JsonResponse
    {
        if ($denied = $this->transactionScopeDenial((string) $request->validated('tid'), 'sirsoft-ecommerce.orders.update')) {
            return $denied;
        }

        $validated = $request->validated();

        try {
            $payment = $this->findRegisterableEscrowPayment($validated['tid']);
            if (! $payment) {
                return ResponseHelper::error('common.failed', 422, [
                    'message' => [__('sirsoft-pay_nicepayments::messages.responses.escrow_payment_not_found')],
                ]);
            }

            $this->useStoredCredentials($payment);

            $result = $this->apiService->registerEscrowDelivery(
                tid: $validated['tid'],
                deliveryName: $validated['delivery_name'],
                trackingNumber: $validated['tracking_number'],
                buyerAddress: $validated['buyer_address'],
                registerName: $validated['register_name'],
            );

            Log::info('NicePayments: escrow delivery registered', [
                'tid' => $validated['tid'],
                'delivery_name' => $validated['delivery_name'],
                'tracking_number' => $validated['tracking_number'],
            ]);

            return ResponseHelper::success('common.success', $result);
        } catch (\Exception $e) {
            // 메시지 키 자리에는 원문 금지 — 키 해석에 실패해 원문이 그대로 나간다.
            // 원문은 서버 로그에 남기고, 응답 errors 에는 운영자가 봐도 되는 사유만 싣는다.
            Log::error('NicePayments: escrow delivery registration failed', [
                'tid' => $validated['tid'],
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error('common.failed', 502, [
                'message' => [$this->failureReason($e)],
            ]);
        }
    }

    /**
     * 배송 등록 실패 응답에 실을 사유를 고릅니다.
     *
     * 나이스페이먼츠가 돌려준 거절 사유(ResultMsg)는 운영자가 조치에 쓰는 정보라 그대로 싣는다.
     * 통신 예외(연결·시간 초과)의 원문에는 결제대행사 API 주소와 cURL 오류 문구가, DB 예외 원문에는
     * SQL·DB 접속 정보가 담기므로 운영 모드에서는 일반 문구로 바꾼다(원문은 호출 측이 서버 로그에 남긴다).
     * 디버그 모드는 코어 관례(SafeExceptionMessage)대로 원문을 둔다.
     *
     * @param  \Throwable  $e  처리 중 잡은 예외
     * @return string 응답에 실을 사유
     */
    private function failureReason(\Throwable $e): string
    {
        if ($e instanceof NicePayRejectedException || config('app.debug')) {
            return $e->getMessage();
        }

        return __('sirsoft-pay_nicepayments::messages.errors.escrow_delivery_failed');
    }

    /**
     * 배송 등록할 수 있는 에스크로 결제 레코드를 거래번호로 찾습니다.
     *
     * @param  string  $tid  거래번호
     * @return PaymentRecord|null 결제 레코드 (없으면 null)
     */
    private function findRegisterableEscrowPayment(string $tid): ?PaymentRecord
    {
        return $this->paymentGateway->findPaymentByTransactionId(
            'nicepayments',
            $tid,
            new PaymentFilters(escrow: true, status: PaymentStatusEnum::PAID),
        );
    }

    /**
     * 결제 당시의 상점 자격으로 API 서비스를 맞춥니다.
     *
     * @param  PaymentRecord  $payment  결제 레코드
     * @return void 반환값 없음
     */
    private function useStoredCredentials(PaymentRecord $payment): void
    {
        $meta = $payment->paymentMeta;
        $mid = trim((string) ($meta['mid'] ?? ''));
        if ($mid === '' || ! array_key_exists('is_test_mode', $meta)) {
            return;
        }

        $this->apiService->useStoredCredentials((bool) $meta['is_test_mode'], $mid);
    }
}
