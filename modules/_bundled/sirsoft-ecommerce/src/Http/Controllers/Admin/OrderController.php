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
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderAmountChangedException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderProcessingException;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
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
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 주문 관리 컨트롤러
 *
 * 관리자가 주문을 관리할 수 있는 기능을 제공합니다.
 */
class OrderController extends AdminBaseController
{
    public function __construct(
        private OrderService $orderService,
        private OrderOptionService $orderOptionService,
        private OrderCancellationService $cancellationService,
        private ActivityLogService $activityLogService,
        private OrderProcessingService $orderProcessingService,
        private UserClaimService $claimService,
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
                $result
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.bulk_update_failed',
                422
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
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
                $metadata
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.option_status_changed',
                $result
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
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.option_status_change_failed',
                500
            );
        }
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
     * @param  CancelOrderRequest  $request  주문 취소 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 취소 결과 JSON 응답
     */
    public function cancelOrder(CancelOrderRequest $request, Order $order): JsonResponse
    {
        try {
            // 운영자의 권한 축은 `order_settings.cancellable_statuses` 다. 환불신청서 접수
            // 경로는 그 설정을 모르므로 여기서 먼저 지킨다 — 없으면 배송중 주문처럼 설정이
            // 막아야 하는 상태가 통과한다(§12-B).
            $this->cancellationService->assertCancellable($order);

            $outcome = $this->claimService->requestRefund(
                $order,
                [
                    'items' => $this->toClaimItems($request, $order),
                    'reason_code' => $request->getReason(),
                    'reason_detail' => $request->getReasonDetail(),
                    'requested_by' => null,
                    'processed_by' => Auth::id(),
                    'refund_priority' => $request->getRefundPriority(),
                    'refund_bank' => $request->getRefundBankInfo(),
                    // 운영자 대리 접수 — 고객이 이 신청서를 철회·수정하지 못하게 하는 축이다(§4-3-1).
                    'refund_source_type' => RefundSourceTypeEnum::ADMIN,
                    'auto_advance' => $request->shouldAutoAdvanceRefund(),
                    // 분기는 운영자의 선택이 정한다 — 고객용 신청 창구로 재지 않는다(§12-B).
                    // 운영자의 취소 권한은 `order_settings.cancellable_statuses` + `orders.update`
                    // 에서 나오고 그 판정은 취소 서비스가 이미 한다. 여기서 다시 재면 두 목록에
                    // 없는 상태(배송완료 등)에서 운영자가 아무것도 할 수 없어진다.
                    'branch' => $request->shouldAutoAdvanceRefund()
                        ? OrderRefundRequestService::BRANCH_AUTO
                        : OrderRefundRequestService::BRANCH_REQUEST,
                    'gate_statuses' => $this->cancellationService->operatorCancellableStatusValues($order),
                    // 관리자 경로는 화면에 노출되지 않는 사유(판매자 귀책 전용)도 고를 수 있다.
                    'user_selectable_reason_only' => false,
                ],
            );

            // 주문 상세 정보를 새로 로드하여 반환
            $updatedOrder = $this->orderService->getDetail($order->id);

            // 접수 결과를 함께 싣는다 — 기본 운영(자동 전이 꺼짐)에서는 **아무것도 취소되지
            // 않고** 환불신청서만 생긴다. 화면이 그 사실과 신청서 번호를 모르면 "취소됐습니다"
            // 를 띄우고 운영자를 주문 화면에 남겨, 방금 만든 신청서는 아무도 보지 않는다.
            $refund = $outcome['refund'] ?? null;
            $cancel = $outcome['cancel'] ?? null;

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.cancelled',
                array_merge(
                    // `resolve()` 로 편다 — `toArray()` 를 직접 부르면 로드되지 않은 관계가
                    // `MissingValue` 로 남고, 그것을 감싼 중첩 리소스가 응답 직렬화 단계에서
                    // null 을 읽어 500 이 된다. 필터는 `resolve()` 안에 있다.
                    (new OrderResource($updatedOrder))->resolve($request),
                    [
                        'claim' => [
                            'branch' => $outcome['branch'] ?? null,
                            'claim_number' => $refund?->refund_number ?? $cancel?->cancel_number,
                            // 취소 확정 여부 — 취소신청서는 환불이 집행된 순간에만 생긴다(D3).
                            // 자동 전이가 완료까지 갔으면 환불신청서가 그 문서를 가리킨다(N12).
                            'cancelled' => $cancel !== null || $refund?->order_cancel_id !== null,
                        ],
                    ],
                )
            );
        } catch (OrderClaimException $e) {
            // 신청 자격·사유·수량 잠금 위반 — 취소 도메인 거절과 같은 422 계약.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.cancel_failed',
                422,
                ['detail' => __($e->getMessage())]
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
     * 취소 요청을 클레임 신청 아이템 형태로 바꿉니다. (전체취소 = 잔여 활성 상품 전부)
     *
     * 전체취소에도 상품 목록을 명시해 넘긴다 — 클레임 신청 경로는 "무엇을 얼마나" 를 반드시
     * 받아야 수량 잠금·금액 산정이 성립하고, 그 산정이 곧 환불신청서의 금액이 된다.
     *
     * @param  CancelOrderRequest  $request  취소 요청
     * @param  Order  $order  대상 주문
     * @return array<int, array{order_option_id: int, quantity: int}> 신청 아이템
     */
    private function toClaimItems(CancelOrderRequest $request, Order $order): array
    {
        if (! $request->isFullCancel()) {
            return array_map(
                fn (array $item): array => [
                    'order_option_id' => (int) $item['order_option_id'],
                    'quantity' => (int) $item['cancel_quantity'],
                ],
                $request->getCancelItems(),
            );
        }

        $order->loadMissing('options');
        $items = [];

        foreach ($order->options as $option) {
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                continue;
            }

            $items[] = [
                'order_option_id' => (int) $option->id,
                'quantity' => (int) $option->quantity,
            ];
        }

        return $items;
    }

    /**
     * 무통장 주문의 입금을 확인하여 결제완료로 전이합니다.
     *
     * 무통장(dbank) 미결제 주문에 한해 입금자명·입금액을 기록하고 결제완료 처리합니다.
     * 입금액이 결제예정금액과 정확히 일치하지 않으면 422 를 반환합니다.
     *
     * @param  ConfirmDepositRequest  $request  입금확인 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 입금확인 결과 JSON 응답
     */
    public function confirmDeposit(ConfirmDepositRequest $request, Order $order): JsonResponse
    {
        try {
            $this->orderProcessingService->confirmManualDeposit(
                $order,
                $request->getAmount(),
                $request->getDepositorName(),
                $request->shouldMarkOrderComplete(),
            );

            $updatedOrder = $this->orderService->getDetail($order->id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.deposit_confirmed',
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
