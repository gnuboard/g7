<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Resources\ActivityLogResource;
use App\Services\ActivityLogService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\DTO\ManualPaidOptions;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderAmountChangedException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderEmailChannelUnavailableException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderMileageDeductionException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderProcessingException;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Exceptions\ReactivationCouponConflictException;
use Modules\Sirsoft\Ecommerce\Exceptions\ResourceScopeMismatchException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkChangeOrderOptionStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateOrdersRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CancelOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ConfirmDepositRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\EstimateRefundRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\OrderListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\OrderLogsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ResetGuestLookupPasswordRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\SendOrderEmailRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimRequestService;
use Modules\Sirsoft\Ecommerce\Services\ClaimScopeGuard;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 주문 관리 컨트롤러
 *
 * 관리자가 주문을 관리할 수 있는 기능을 제공합니다.
 */
class OrderController extends AdminBaseController
{
    /**
     * 주문 관리 서비스들을 주입받습니다.
     *
     * @param  OrderService  $orderService  주문 조회·수정·일괄변경 서비스
     * @param  OrderOptionService  $orderOptionService  주문상품(옵션) 상태·수량 서비스
     * @param  OrderCancellationService  $cancellationService  주문 취소 서비스
     * @param  ActivityLogService  $activityLogService  활동 로그 서비스
     * @param  OrderProcessingService  $orderProcessingService  결제·입금 처리 서비스
     * @param  AdminClaimRequestService  $adminClaimRequestService  관리자 직권 접수 서비스 ([취소·환불 접수] — §3-11)
     */
    public function __construct(
        private OrderService $orderService,
        private OrderOptionService $orderOptionService,
        private OrderCancellationService $cancellationService,
        private ActivityLogService $activityLogService,
        private OrderProcessingService $orderProcessingService,
        private AdminClaimRequestService $adminClaimRequestService,
    ) {}

    /**
     * 필터링된 주문 목록을 조회합니다.
     *
     * @param  OrderListRequest  $request  주문 목록 요청 데이터
     * @return JsonResponse 주문 목록과 통계 정보를 포함한 JSON 응답
     */
    public function index(OrderListRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $orders = $this->orderService->getList($filters);
            $statistics = $this->orderService->getStatistics();

            $collection = new OrderCollection($orders);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.fetch_success',
                $collection->withStatistics($statistics)
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.fetch_failed',
                500
            );
        }
    }

    /**
     * 특정 주문의 상세 정보를 조회합니다.
     *
     * @param  Order  $order  조회할 주문 모델
     * @return JsonResponse 주문 상세 정보를 포함한 JSON 응답
     */
    public function show(Order $order): JsonResponse
    {
        try {
            $order = $this->orderService->getDetail($order->id);

            if (! $order) {
                return ResponseHelper::notFound(
                    'messages.orders.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.fetch_success',
                new OrderResource($order)
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.fetch_failed',
                500
            );
        }
    }

    /**
     * 주문 정보를 수정합니다.
     *
     * @param  UpdateOrderRequest  $request  주문 수정 요청 데이터
     * @param  Order  $order  수정할 주문 모델
     * @return JsonResponse 수정된 주문 정보를 포함한 JSON 응답
     */
    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        try {
            $updatedOrder = $this->orderService->update($order, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.updated',
                new OrderResource($updatedOrder)
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.update_failed',
                422
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (ReactivationCouponConflictException|OrderMileageDeductionException|InsufficientStockException $e) {
            return $this->manualPaidFailure($e);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.update_failed',
                500
            );
        }
    }

    /**
     * 여러 주문을 일괄 변경합니다.
     *
     * @param  BulkUpdateOrdersRequest  $request  일괄 변경 요청 데이터
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function bulkUpdate(BulkUpdateOrdersRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->orderService->bulkUpdate($validated);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.bulk_updated',
                $result,
                200,
                ['count' => $result['updated_count'] ?? 0]
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.bulk_update_failed',
                422
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (ReactivationCouponConflictException|OrderMileageDeductionException|InsufficientStockException $e) {
            return $this->manualPaidFailure($e);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.bulk_update_failed',
                500
            );
        }
    }

    /**
     * 주문을 삭제합니다 (Soft Delete).
     *
     * @param  Order  $order  삭제할 주문 모델
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(Order $order): JsonResponse
    {
        try {
            $orderId = $order->id;
            $this->orderService->delete($order);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.deleted',
                ['deleted' => true]
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.delete_failed',
                500
            );
        }
    }

    /**
     * 주문 옵션 일괄 상태 변경 (수량 분할 지원)
     *
     * @param  BulkChangeOrderOptionStatusRequest  $request  일괄 변경 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function bulkChangeOptionStatus(BulkChangeOrderOptionStatusRequest $request, Order $order): JsonResponse
    {
        try {
            $validated = $request->validated();
            $newStatus = OrderStatusEnum::from($validated['status']);

            $metadata = [];
            if (! empty($validated['carrier_id'])) {
                $metadata['carrier_id'] = $validated['carrier_id'];
            }
            if (! empty($validated['tracking_number'])) {
                $metadata['tracking_number'] = $validated['tracking_number'];
            }

            $result = $this->orderOptionService->bulkChangeStatusWithQuantity(
                $validated['items'],
                $newStatus,
                $order->id,
                $metadata,
                ManualPaidOptions::fromInput($validated)
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.option_status_changed',
                $result,
                200,
                ['count' => $result['changed_count'] ?? 0]
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.option_status_change_failed',
                422
            );
        } catch (ResourceScopeMismatchException $e) {
            // 상위 주문 스코프 불일치는 잘못된 요청이다. FormRequest 가 1차로 422 를 내지만,
            // 훅·내부 호출로 Service 2차 방어가 트립한 경우에도 500 이 아닌 400 으로 응답해
            // 같은 모듈의 ProductController::setThumbnail 과 계약을 일치시킨다.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.option_status_change_failed',
                400
            );
        } catch (OrderClaimException $e) {
            // 교환·반품 진행 중인 상품의 상태 변경 차단 (D15 후단). FormRequest 가 1차로 422 를
            // 내지만, 훅·내부 호출로 Service 2차 방어가 트립한 경우에도 500 이 아닌 422 로 응답해
            // 화면 경로와 같은 운영자 안내를 전달한다 — 위 스코프 불일치 catch 와 같은 이유다.
            // 이미 번역된 원문($e->getMessage())이 아니라 키+치환 파라미터로 응답을 구성한다.
            // getMessageKey() 는 모듈 접두를 이미 포함한 완전한 키라 moduleError() 가 아닌
            // error() 에 그대로 넘긴다 — 접두를 두 번 붙이면 키가 해석되지 않는다.
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                422,
                null,
                $e->getMessageParams()
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (ReactivationCouponConflictException|OrderMileageDeductionException|InsufficientStockException $e) {
            return $this->manualPaidFailure($e);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.option_status_change_failed',
                500
            );
        }
    }

    /**
     * 목록 일괄변경 확인 창 — 수동 결제 처리 항목을 미리 계산합니다. (DB 변경 없음 — Q13·Q14·Q26)
     *
     * 쓰기 요청과 같은 FormRequest 를 써서 「미리보기 통과 ⇔ 실행 검증 통과」를 보장한다.
     *
     * @param  BulkUpdateOrdersRequest  $request  일괄 변경 요청 (실행과 같은 검증)
     * @return JsonResponse 주문별 세 항목·복원·경고와 집계
     */
    public function bulkManualPaidPreview(BulkUpdateOrdersRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $status = $validated['order_status'] ?? null;
            $status = $status instanceof OrderStatusEnum ? $status->value : $status;

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.manual_paid_preview_retrieved',
                $this->orderService->previewManualPaidEffectsForBulk($validated['ids'], $status),
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.orders.manual_paid_preview_failed', 500);
        }
    }

    /**
     * 주문상세 상품 일괄변경 확인 창 — 수동 결제 처리 항목을 미리 계산합니다. (DB 변경 없음 — Q13·Q14·Q26)
     *
     * @param  BulkChangeOrderOptionStatusRequest  $request  상품 일괄 변경 요청 (실행과 같은 검증)
     * @param  Order  $order  대상 주문
     * @return JsonResponse 세 항목·복원·경고와 집계
     */
    public function optionBulkManualPaidPreview(BulkChangeOrderOptionStatusRequest $request, Order $order): JsonResponse
    {
        try {
            $validated = $request->validated();

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.manual_paid_preview_retrieved',
                $this->orderOptionService->previewManualPaidEffects($order, $validated['items'], OrderStatusEnum::from($validated['status'])),
            );
        } catch (ResourceScopeMismatchException $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.orders.manual_paid_preview_failed', 400);
        } catch (OrderClaimException $e) {
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error($messageKey, 422, null, $e->getMessageParams());
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.orders.manual_paid_preview_failed', 500);
        }
    }

    /**
     * 수동 결제 처리(세 항목·재활성 복원)의 도메인 거절을 422 로 답합니다.
     *
     * 마일리지 부족·쿠폰 충돌은 막힌 주문번호를, 재고 부족은 모자란 상품을 함께 싣는다 — 운영자가 어느
     * 주문의 무엇을 끄거나 고쳐야 하는지 읽을 수 있어야 한다(종전에는 500 「수정 실패」만 보였다).
     *
     * @param  ReactivationCouponConflictException|OrderMileageDeductionException|InsufficientStockException  $e  거절 사유
     * @return JsonResponse 422 응답
     */
    private function manualPaidFailure(ReactivationCouponConflictException|OrderMileageDeductionException|InsufficientStockException $e): JsonResponse
    {
        if ($e instanceof ReactivationCouponConflictException) {
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error($messageKey, 422, [
                'order_numbers' => $e->getOrderNumbers(),
                'coupons' => $e->getCoupons(),
            ], $e->getMessageParams());
        }

        if ($e instanceof OrderMileageDeductionException) {
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error($messageKey, 422, ['order_numbers' => $e->getOrderNumbers()], $e->getMessageParams());
        }

        return ResponseHelper::error(
            'sirsoft-ecommerce::exceptions.manual_paid.stock_insufficient',
            422,
            ['items' => $e->getInsufficientItems()],
            ['reason' => $e->getMessage()],
        );
    }

    /**
     * 주문 관련 이메일을 발송합니다.
     *
     * @param  SendOrderEmailRequest  $request  이메일 발송 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 발송 결과 JSON 응답
     */
    public function sendEmail(SendOrderEmailRequest $request, Order $order): JsonResponse
    {
        try {
            $validated = $request->validated();
            $this->orderService->sendEmail($order, $validated['email'], $validated['message']);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.email_sent'
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (OrderEmailChannelUnavailableException $e) {
            // 메일 채널 꺼짐·미준비·템플릿 비활성 — 요청은 올바르나 사이트 설정 때문에 보낼 수 없다.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.email_channel_disabled',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.email_send_failed',
                500,
                ['detail' => $e->getMessage()]
            );
        }
    }

    /**
     * 환불 예상금액을 조회합니다.
     *
     * @param  EstimateRefundRequest  $request  환불 예상 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 환불 예상금액 JSON 응답
     */
    public function estimateRefund(EstimateRefundRequest $request, Order $order): JsonResponse
    {
        try {
            $result = $this->cancellationService->previewRefund(
                $order,
                $request->getCancelItems(),
                $request->getRefundPriority()
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.estimate_refund_success',
                $result->toPreviewArray()
            );
        } catch (OrderCancellationException $e) {
            // 클레임 수량 잠금 등 도메인 거절 — 실행(cancelOrder)과 같은 422 로 미리보기/실행 비대칭을 없앤다.
            // detail 은 조치 근거다 — 관리자 전용 면의 errors 페이로드는 진단 정보로 허용된다.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.estimate_refund_failed',
                422,
                ['detail' => $e->getMessage()]
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            Log::error('환불 예상금액 계산 실패', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.estimate_refund_failed',
                500
            );
        }
    }

    /**
     * 주문을 취소합니다. (전체취소/부분취소 — #599 후속 D7)
     *
     * **취소 즉시 환불되지 않는다.** 이 API 는 환불신청서를 만들 뿐이고, 금액확인 → 승인 →
     * 환불완료는 운영자가 클레임 상세에서 하나씩 밟는다. 취소신청서는 그 환불이 완료되는
     * 순간에 생긴다(D3) — 그래서 응답 시점의 주문은 아직 취소로 확정돼 있지 않다.
     *
     * 모달의 "환불까지 한 번에 처리"(`auto_advance_refund`, 기본 꺼짐)를 켜면 승인·완료까지
     * 자동으로 민다(§4-2). 계좌 환불 수단은 그래도 승인에서 멈춘다(D4) — 사람이 송금해야
     * 하기 때문이고, 그때 취소신청서도 아직 생기지 않는다(D5).
     *
     * 환불할 돈이 없는 주문(미결제·0원)은 환불신청서 없이 취소신청서가 즉시 생긴다(D1·D3).
     *
     * 「환불까지 한 번에 처리」 는 환불 실행 권한(`claims.execute-refund`)이 있어야 한다 — 직권 접수
     * 경로(`OrderClaimController::storeCancelRefund()`)와 같은 판정이다. 이 경로의 라우트 권한
     * (`orders.update`)만으로 PG 환불까지 가지 못하게 한다.
     *
     * @param  CancelOrderRequest  $request  주문 취소 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 취소 결과 JSON 응답
     */
    public function cancelOrder(CancelOrderRequest $request, Order $order): JsonResponse
    {
        if ($request->shouldAutoAdvanceRefund()
            && ! OrderClaimController::canAutoAdvanceRefund($order, app(ClaimScopeGuard::class))) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.claims.auto_refund_forbidden', 403);
        }

        try {
            $outcome = $this->adminClaimRequestService->requestCancelOrRefund(
                $order,
                [
                    'full_cancel' => $request->isFullCancel(),
                    'items' => $request->getCancelItems(),
                    'reason_code' => $request->getReason(),
                    'reason_detail' => $request->getReasonDetail(),
                    'refund_priority' => $request->getRefundPriority(),
                    'refund_bank' => $request->getRefundBankInfo(),
                    'auto_advance' => $request->shouldAutoAdvanceRefund(),
                ],
                Auth::id(),
            );

            // 주문 상세 정보를 새로 로드하여 반환
            $updatedOrder = $this->orderService->getDetail($order->id);

            // 접수 결과를 함께 싣는다 — 기본 운영(자동 전이 꺼짐)에서는 **아무것도 취소되지
            // 않고** 환불신청서만 생긴다. 응답 문구도 그 사실을 따라 간다: 실제로 취소가 확정됐을
            // 때만 "주문이 취소되었습니다" 이고, 신청서만 생겼으면 "환불신청서가 접수되었습니다" 다.
            // 같은 문구를 내면 운영자는 취소가 끝났다고 믿고 방금 만든 신청서를 보지 않는다.
            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                $outcome['cancelled'] ? 'messages.orders.cancelled' : 'messages.orders.cancel_refund_requested',
                array_merge(
                    // `resolve()` 로 편다 — `toArray()` 를 직접 부르면 로드되지 않은 관계가
                    // `MissingValue` 로 남고, 그것을 감싼 중첩 리소스가 응답 직렬화 단계에서
                    // null 을 읽어 500 이 된다. 필터는 `resolve()` 안에 있다.
                    (new OrderResource($updatedOrder))->resolve($request),
                    [
                        'claim' => [
                            'branch' => $outcome['branch'],
                            'claim_number' => $outcome['claim_number'],
                            'cancelled' => $outcome['cancelled'],
                        ],
                    ],
                )
            );
        } catch (OrderClaimException $e) {
            // 신청 자격·사유·수량 잠금 위반 — 취소 도메인 거절과 같은 422 계약. 사유는 키+치환
            // 파라미터로 다시 만든다 — 예외 메시지는 이미 번역된 문장이라 `__()` 에 넣으면 키로
            // 재번역을 시도해 파라미터 없이 원문이 그대로 남는다. 영어 사유는 `단수|복수` 꼴(수량 잠금 등)이라
            // 치환값의 `count` 로 한 문장을 고른다(`__()` 로 부르면 두 문장이 `|` 로 이어져 나온다).
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.cancel_failed',
                422,
                ['detail' => trans_count($e->getMessageKey(), $e->getMessageParams())]
            );
        } catch (OrderCancellationException $e) {
            // 취소 도메인 규칙 위반 — 운영자에게 안내 가능한 상황이므로 422.
            // detail 은 PG 환불 거절 사유 등 조치 근거다 — 관리자 전용 면의 errors
            // 페이로드는 진단 정보로 허용된다(감추면 서버 로그 없이는 조치 불가).
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.cancel_failed',
                422,
                ['detail' => $e->getMessage()]
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            Log::error('주문 취소 실패 (관리자)', [
                'order_id' => $order->id,
                'type' => $request->validated('type'),
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 무통장 주문의 입금을 확인합니다.
     *
     * 입금자명·입금액을 기록하고, 요청과 주문 상태에 따라 결제완료로 전이하거나(미결제 + 전이 요청),
     * 결제대기로 둔 채 입금만 기록하거나(전이 미요청), 이미 결제 이후 상태면 상태를 두고 결제금액만
     * 채웁니다. 응답 문장은 처리 전에 서비스가 판정한 갈래를 따릅니다.
     * 입금액이 결제예정금액과 정확히 일치하지 않으면 422 를 반환합니다.
     *
     * @param  ConfirmDepositRequest  $request  입금확인 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 입금확인 결과 JSON 응답
     */
    public function confirmDeposit(ConfirmDepositRequest $request, Order $order): JsonResponse
    {
        try {
            $markComplete = $request->shouldMarkOrderComplete();
            // 운영자가 상태를 먼저 올려 둔 주문(상품준비중 등)은 체크와 무관하게 상태를 두고
            // 입금과 결제금액만 기록한다 — 응답 문장도 그 결과를 따라야 하므로, 갈래는 처리 전에
            // 서비스가 처리에 쓰는 것과 같은 판정으로 받는다.
            $outcome = $this->orderProcessingService->resolveManualDepositOutcome($order, $markComplete);

            $this->orderProcessingService->confirmManualDeposit(
                $order,
                $request->getAmount(),
                $request->getDepositorName(),
                $markComplete,
            );

            $updatedOrder = $this->orderService->getDetail($order->id);

            // 「입금 내역만 기록」은 주문을 결제대기로 남겨 둔다(모달이 그렇게 안내한다).
            // 두 경우에 같은 문장을 돌려주면 응답만 읽는 소비자는 결제되지 않은 주문을
            // 결제완료로 읽는다 — 운영자가 후속 입금 확인을 건너뛸 수 있다.
            // 이미 결제 이후 상태인 주문은 어느 쪽도 아니다 — 상태는 그대로이고 결제금액만 채워진다.
            $messageKey = match ($outcome) {
                'status_kept' => 'messages.orders.deposit_recorded_status_kept',
                'complete' => 'messages.orders.deposit_confirmed',
                default => 'messages.orders.deposit_recorded',
            };

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                $messageKey,
                new OrderResource($updatedOrder)
            );
        } catch (PaymentAmountMismatchException $e) {
            // 입금액 불일치 — 사용자에게 금액 검증 실패를 알림
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.deposit_amount_mismatch',
                422,
                ['detail' => $e->getMessage()]
            );
        } catch (OrderProcessingException|OrderAmountChangedException $e) {
            // 입금확인 도메인 규칙 위반(전이 불가 상태/결제예정금액 변동) — 422.
            // detail 은 같은 메서드의 PaymentAmountMismatchException 과 동일한 관리자
            // 진단 통로다 — 한 메서드 안에서 노출 수준이 갈리면 안 된다.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.deposit_confirm_failed',
                422,
                ['detail' => $e->getMessage()]
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            Log::error('무통장 입금확인 실패 (관리자)', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 비회원 주문의 조회 비밀번호를 재설정합니다.
     *
     * 비회원 주문(user_id IS NULL)만 허용하며, 회원 주문은 거부합니다.
     * 평문 비밀번호는 응답/로그에 노출하지 않고 해시만 저장합니다.
     *
     * @param  ResetGuestLookupPasswordRequest  $request  재설정 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 재설정 결과 JSON 응답
     */
    public function resetGuestLookupPassword(ResetGuestLookupPasswordRequest $request, Order $order): JsonResponse
    {
        // 회원 주문은 조회 비밀번호 재설정 대상이 아님
        if ($order->user_id !== null) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.guest_password_reset_not_guest',
                422
            );
        }

        try {
            $this->orderService->resetGuestLookupPassword(
                $order,
                $request->validated('guest_lookup_password')
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.guest_password_reset_success'
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            Log::error('비회원 조회 비밀번호 재설정 실패', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.guest_password_reset_failed',
                500
            );
        }
    }

    /**
     * 주문의 활동 로그를 조회합니다.
     *
     * @param  OrderLogsRequest  $request  활동 로그 조회 요청 데이터
     * @param  Order  $order  주문 모델
     * @return JsonResponse 활동 로그 목록 JSON 응답
     */
    public function logs(OrderLogsRequest $request, Order $order): JsonResponse
    {
        try {
            // 주문 + 주문옵션 + 배송지 로그 합산 조회는 Repository 위임 (Service 경유)
            $logs = $this->orderService->getActivityLogs($order, $request->getFilters());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.logs_fetch_success',
                ActivityLogResource::collection($logs)->response()->getData(true)
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.logs_fetch_failed',
                500
            );
        }
    }
}
