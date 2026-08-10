<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\IssueCashReceiptRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\CashReceiptResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;

/**
 * 회원 현금영수증 컨트롤러
 *
 * 주문 당시 미신청 건을 구매자가 직접 사후 발급한다.
 * 발급 취소는 제공하지 않는다 — 국세청 신고 정정을 동반하므로 관리자 전용이다(D12).
 */
class CashReceiptController extends AuthBaseController
{
    /**
     * @param  CashReceiptService  $cashReceiptService  현금영수증 서비스
     * @param  OrderService  $orderService  주문 서비스
     */
    public function __construct(
        private CashReceiptService $cashReceiptService,
        private OrderService $orderService,
    ) {}

    /**
     * 본인 주문의 현금영수증 발급 상태를 조회합니다.
     *
     * @param  int  $id  주문 ID
     * @return JsonResponse 응답
     */
    public function show(int $id): JsonResponse
    {
        $this->logApiUsage('user.cash-receipt.show');

        $order = $this->findOwnedOrder($id);

        if ($order === null) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.order_not_found', 404);
        }

        $receipt = $this->cashReceiptService->findActiveReceipt($order);

        return ResponseHelper::success('sirsoft-ecommerce::cash_receipt.messages.status_retrieved', [
            'issuable' => $this->cashReceiptService->resolveIssueBlocker($order) === null,
            'cash_receipt' => $receipt ? new CashReceiptResource($receipt) : null,
        ]);
    }

    /**
     * 본인 주문에 현금영수증을 사후 발급합니다.
     *
     * @param  IssueCashReceiptRequest  $request  요청
     * @param  int  $id  주문 ID
     * @return JsonResponse 응답
     */
    public function issue(IssueCashReceiptRequest $request, int $id): JsonResponse
    {
        $this->logApiUsage('user.cash-receipt.issue');

        $order = $this->findOwnedOrder($id);

        if ($order === null) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.order_not_found', 404);
        }

        $blocker = $this->cashReceiptService->resolveIssueBlocker($order);

        if ($blocker !== null) {
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
            new CashReceiptResource($receipt)
        );
    }

    /**
     * 로그인한 회원 본인 소유의 주문을 조회합니다.
     *
     * 클라이언트가 넘긴 사용자 식별자를 신뢰하지 않고 Auth::id() 로만 소유권을 판정한다.
     *
     * @param  int  $id  주문 ID
     * @return Order|null 본인 주문 (아니면 null)
     */
    private function findOwnedOrder(int $id)
    {
        $order = $this->orderService->getDetail($id);

        if (! $order || $order->user_id !== Auth::id()) {
            return null;
        }

        $order->loadMissing('payment');

        return $order;
    }
}
