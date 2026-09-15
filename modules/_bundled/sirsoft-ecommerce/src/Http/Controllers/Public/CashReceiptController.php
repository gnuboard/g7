<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\IssueCashReceiptRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\ShowCashReceiptRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\CashReceiptResource;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;

/**
 * 비회원 현금영수증 컨트롤러 (조회 토큰 보호)
 *
 * 주문 소유권은 VerifyGuestOrderToken 미들웨어가 검증한다 — 토큰이 지목한 주문 외에는 접근할 수 없다.
 * 회원 경로는 User\CashReceiptController 가 담당하며, 발급 취소는 양쪽 모두 제공하지 않는다(관리자 전용).
 */
class CashReceiptController extends PublicBaseController
{
    /**
     * @param  CashReceiptService  $cashReceiptService  현금영수증 서비스
     */
    public function __construct(
        private CashReceiptService $cashReceiptService,
    ) {}

    /**
     * 비회원 주문의 현금영수증 발급 상태를 조회합니다.
     *
     * @param  ShowCashReceiptRequest  $request  요청 (토큰 검증된 주문 보유)
     * @return JsonResponse 응답
     */
    public function show(ShowCashReceiptRequest $request): JsonResponse
    {
        $order = $request->getOrder();
        $order->loadMissing('payment');

        $receipt = $this->cashReceiptService->findActiveReceipt($order);

        return ResponseHelper::success('sirsoft-ecommerce::cash_receipt.messages.status_retrieved', [
            'issuable' => $this->cashReceiptService->resolveIssueBlocker($order) === null,
            'cash_receipt' => $receipt ? new CashReceiptResource($receipt->setRelation('order', $order)) : null,
        ]);
    }

    /**
     * 비회원 주문에 현금영수증을 사후 발급합니다.
     *
     * @param  IssueCashReceiptRequest  $request  요청 (토큰 검증된 주문 보유)
     * @return JsonResponse 응답
     */
    public function issue(IssueCashReceiptRequest $request): JsonResponse
    {
        $order = $request->getOrder();
        $order->loadMissing('payment');

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
            new CashReceiptResource($receipt->setRelation('order', $order))
        );
    }
}
