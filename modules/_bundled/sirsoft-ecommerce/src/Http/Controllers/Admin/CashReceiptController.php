<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\IssueCashReceiptRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\CashReceiptResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;

/**
 * 관리자 현금영수증 컨트롤러
 *
 * 발급 프로바이더에 종속되지 않는다 — 실제 발급/취소는 CashReceiptService 가
 * cash_receipt.issue / cash_receipt.cancel 필터 훅으로 프로바이더 리스너에 위임한다.
 */
class CashReceiptController extends AdminBaseController
{
    /**
     * @param  CashReceiptService  $cashReceiptService  현금영수증 서비스
     */
    public function __construct(
        private CashReceiptService $cashReceiptService,
    ) {}

    /**
     * 현금영수증을 발급합니다. (주문 시 미신청 건의 사후 발급 포함)
     *
     * @param  IssueCashReceiptRequest  $request  요청
     * @param  Order  $order  대상 주문
     * @return JsonResponse 응답
     */
    public function issue(IssueCashReceiptRequest $request, Order $order): JsonResponse
    {
        $order->loadMissing('payment');

        $blocker = $this->cashReceiptService->resolveIssueBlocker($order);

        if ($blocker !== null) {
            // 이미 발급된 주문에 대한 재발급 요청은 409 로 구분한다 (중복 발급 방지).
            $status = $blocker === 'ALREADY_ISSUED' ? 409 : 422;
            $messageKey = $this->cashReceiptService->describeIssueBlocker($blocker);

            return ResponseHelper::error($messageKey, $status, ['error_code' => $blocker]);
        }

        $receipt = $this->cashReceiptService->issue(
            $order,
            $request->getReceiptType(),
            $request->getIdentifier(),
            $request->getIdentifierType(),
        );

        if (! $receipt->isCompletedIssue()) {
            // 프로바이더가 준 상세 사유는 errors 로 내려보내고, 메시지는 다국어 키를 쓴다.
            return ResponseHelper::error(
                'sirsoft-ecommerce::cash_receipt.errors.issue_failed',
                422,
                ['error_code' => $receipt->error_code, 'error_message' => $receipt->error_message]
            );
        }

        return ResponseHelper::success(
            'sirsoft-ecommerce::cash_receipt.messages.issued',
            new CashReceiptResource($receipt->setRelation('order', $order))
        );
    }

    /**
     * 발급된 현금영수증을 전액 취소합니다.
     *
     * 부분취소 API 는 사용하지 않는다 — 금액이 바뀌는 경우는 reissue 가 담당한다.
     *
     * @param  Order  $order  대상 주문
     * @return JsonResponse 응답
     */
    public function cancel(Order $order): JsonResponse
    {
        $order->loadMissing('payment');

        if ($this->cashReceiptService->findActiveReceipt($order) === null) {
            return ResponseHelper::error(
                'sirsoft-ecommerce::cash_receipt.errors.no_active_receipt',
                422,
                ['error_code' => 'NO_ACTIVE_RECEIPT']
            );
        }

        if (! $this->cashReceiptService->cancelAll($order)) {
            return ResponseHelper::error(
                'sirsoft-ecommerce::cash_receipt.errors.cancel_failed',
                422,
                ['error_code' => 'CANCEL_FAILED']
            );
        }

        return ResponseHelper::success('sirsoft-ecommerce::cash_receipt.messages.cancelled');
    }

    /**
     * 현금영수증을 현재 주문 금액에 맞춰 재발급합니다. (재발급 실패 복구)
     *
     * 부분환불 직후 재발급이 실패해 "취소 성공 + 재발급 실패" 상태로 남은 주문을
     * 관리자가 수동으로 복구하는 경로다. 마지막 발급 이력의 용도와 저장된 암호문을 재사용하며,
     * 암호문이 폐기된 경우(구매확정 후)에는 관리자가 issue 로 식별번호를 재입력해야 한다.
     *
     * @param  Order  $order  대상 주문
     * @return JsonResponse 응답
     */
    public function reissue(Order $order): JsonResponse
    {
        $order->loadMissing('payment');

        $receipt = $this->cashReceiptService->recoverFailedIssue($order);

        // 발급 대상 금액이 0 이면(전액 환불 등) 활성 영수증이 없는 것이 정상 결과다.
        // 그 외에 활성 영수증이 없으면 복구가 실패한 것이다.
        if ($receipt === null && $this->cashReceiptService->calculateIssuableAmount($order)['amount'] > 0) {
            return ResponseHelper::error(
                'sirsoft-ecommerce::cash_receipt.errors.issue_failed',
                422,
                ['error_code' => 'REISSUE_FAILED']
            );
        }

        return ResponseHelper::success(
            'sirsoft-ecommerce::cash_receipt.messages.reissued',
            $receipt ? new CashReceiptResource($receipt->setRelation('order', $order)) : null
        );
    }
}
