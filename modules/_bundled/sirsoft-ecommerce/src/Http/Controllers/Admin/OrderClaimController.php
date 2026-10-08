<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\MapsClaimErrors;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminClaimRequestPreviewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminStoreCancelRefundRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminStoreExchangeRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminStoreReturnRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimRequestService;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\ClaimScopeGuard;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 관리자 직권 접수 컨트롤러 (§3-11 — 주문 스코프)
 *
 * 주문 상세 상품 표의 선택 바에서 고객을 대신해 교환·반품·취소·환불을 접수한다. 라우트가 `{order}` 를
 * 모델로 묶으므로 권한 스코프는 미들웨어가 판정한다(`claims.create` — 주문의 스코프를 물려받는다).
 * 미들웨어는 라우트 권한 하나만 보므로, 운영자의 주문 조회 범위는 `ClaimScopeGuard` 가 보탠다(EC-667 —
 * 주문 조회를 「본인만」 으로 묶은 운영자가 남의 주문에 직권 접수하지 못한다).
 * 도메인 거절은 `MapsClaimErrors::claimDomainError()` 가 422 로 바꾼다.
 */
class OrderClaimController extends AdminBaseController
{
    use MapsClaimErrors;

    /** 직권 접수 권한 — 라우트 게이트와 같은 식별자 */
    private const PERMISSION_CREATE = 'sirsoft-ecommerce.claims.create';

    /**
     * @param  AdminClaimRequestService  $requestService  관리자 직권 접수 서비스
     * @param  AdminClaimService  $adminClaimService  관리자 클레임 서비스 (사유·청구 방식 선택지)
     * @param  ClaimScopeGuard  $claimScope  권한 스코프 가드 (주문 조회 범위 결합)
     */
    public function __construct(
        protected AdminClaimRequestService $requestService,
        protected AdminClaimService $adminClaimService,
        protected ClaimScopeGuard $claimScope,
    ) {}

    /**
     * 관리자 면의 500 응답에는 원인 요약을 싣습니다. (`MapsClaimErrors::claimServerError()`)
     *
     * @return bool 항상 true
     */
    protected function exposesServerErrorDetail(): bool
    {
        return true;
    }

    /**
     * 직권 접수 창이 쓰는 문맥을 돌려줍니다. (물리 게이트 상태 · 사유 선택지 · 청구 방식)
     *
     * 선택 바의 [교환 접수]·[반품 접수]·[취소·환불 접수] 가 켜지는 조건(D5)과 접수 창의 선택지를 한
     * 응답이 나른다 — 사유 카탈로그·청구 방식 설정 조회는 `settings.read` 게이트라 직권 접수 권한만
     * 가진 운영자에게는 403 이 되어 선택지가 조용히 빈다. 게이트 상태 목록은 서버 판정과 같은 출처다.
     *
     * @param  Order  $order  대상 주문
     * @return JsonResponse 문맥 응답 (`gates` · `reasons` · `charge_methods`)
     */
    public function context(Order $order): JsonResponse
    {
        try {
            $this->claimScope->assertOrderAccessible($order, self::PERMISSION_CREATE);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.retrieved', [
                'order_id' => $order->id,
                'gates' => $this->requestService->gateStatuses(),
                'reasons' => [
                    ClaimTypeEnum::EXCHANGE->value => $this->adminClaimService->reasonOptionsForType(ClaimTypeEnum::EXCHANGE),
                    ClaimTypeEnum::RETURN->value => $this->adminClaimService->reasonOptionsForType(ClaimTypeEnum::RETURN),
                ],
                'charge_methods' => array_merge(
                    $this->adminClaimService->chargeMethodOptions(),
                    [['value' => AdminClaimRequestService::CHARGE_FREE, 'label' => __('sirsoft-ecommerce::messages.claims.charge_free')]],
                ),
            ]);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-request-context', $e);
        }
    }

    /**
     * 직권 접수 창의 청구 미리보기를 계산합니다. (DB 변경 없음 — D7)
     *
     * @param  AdminClaimRequestPreviewRequest  $request  미리보기 요청
     * @param  Order  $order  대상 주문
     * @return JsonResponse 미리보기 응답
     */
    public function preview(AdminClaimRequestPreviewRequest $request, Order $order): JsonResponse
    {
        try {
            $this->claimScope->assertOrderAccessible($order, self::PERMISSION_CREATE);

            $preview = $this->requestService->preview($order, $request->claimType(), $request->toServiceInput());

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.preview_success', $this->normalizePreview($preview));
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-request-preview', $e);
        }
    }

    /**
     * 교환을 직권 접수합니다.
     *
     * @param  AdminStoreExchangeRequest  $request  접수 요청
     * @param  Order  $order  대상 주문
     * @return JsonResponse 접수 응답 (201)
     */
    public function storeExchange(AdminStoreExchangeRequest $request, Order $order): JsonResponse
    {
        try {
            $this->claimScope->assertOrderAccessible($order, self::PERMISSION_CREATE);

            $result = $this->requestService->requestExchange($order, $request->toServiceInput(), Auth::id());

            return $this->requestedResponse($result);
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-exchange-request', $e);
        }
    }

    /**
     * 반품을 직권 접수합니다.
     *
     * @param  AdminStoreReturnRequest  $request  접수 요청
     * @param  Order  $order  대상 주문
     * @return JsonResponse 접수 응답 (201)
     */
    public function storeReturn(AdminStoreReturnRequest $request, Order $order): JsonResponse
    {
        try {
            $this->claimScope->assertOrderAccessible($order, self::PERMISSION_CREATE);

            $result = $this->requestService->requestReturn($order, $request->toServiceInput(), Auth::id());

            return $this->requestedResponse($result);
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-return-request', $e);
        }
    }

    /**
     * 취소·환불을 직권 접수합니다. (종전 [주문취소] 의 흡수 — 환불신청서 접수)
     *
     * @param  AdminStoreCancelRefundRequest  $request  접수 요청 (종전 주문취소와 같은 본문)
     * @param  Order  $order  대상 주문
     * @return JsonResponse 접수 응답 (`branch` · `claim_number` · `cancelled`)
     */
    public function storeCancelRefund(AdminStoreCancelRefundRequest $request, Order $order): JsonResponse
    {
        try {
            $this->claimScope->assertOrderAccessible($order, self::PERMISSION_CREATE);

            $outcome = $this->requestService->requestCancelOrRefund($order, [
                'full_cancel' => $request->isFullCancel(),
                'items' => $request->getCancelItems(),
                'reason_code' => $request->getReason(),
                'reason_detail' => $request->getReasonDetail(),
                'refund_priority' => $request->getRefundPriority(),
                'refund_bank' => $request->getRefundBankInfo(),
                'auto_advance' => $request->shouldAutoAdvanceRefund(),
            ], Auth::id());

            // 실제로 취소가 확정됐을 때만 「주문이 취소되었습니다」 다 — 신청서만 생겼으면 접수 문구를 낸다.
            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                $outcome['cancelled'] ? 'messages.orders.cancelled' : 'messages.orders.cancel_refund_requested',
                [
                    'branch' => $outcome['branch'],
                    'claim_number' => $outcome['claim_number'],
                    'cancelled' => $outcome['cancelled'],
                    // 종전 [주문취소] 응답과 같은 블록 — 같은 취소 모달이 이 값을 읽는다.
                    'claim' => [
                        'branch' => $outcome['branch'],
                        'claim_number' => $outcome['claim_number'],
                        'cancelled' => $outcome['cancelled'],
                    ],
                ],
                201,
            );
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (OrderCancellationException $e) {
            // 즉시 확정 분기의 취소 파이프라인 거절 — 관리자 면이라 PG 거절 사유를 조치 근거로 싣는다.
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.orders.cancel_failed', 422, ['detail' => $e->getMessage()]);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-cancel-refund-request', $e);
        }
    }

    /**
     * 교환·반품 접수 응답을 만듭니다. (새 신청서 + 결제 요청 여부 + 승인 건너뜀 사유)
     *
     * @param  array{claim: OrderExchange|OrderReturn, payment_requested: bool, approval_skipped: array{reason: string}|null}  $result  접수 결과
     * @return JsonResponse 201 응답
     */
    protected function requestedResponse(array $result): JsonResponse
    {
        $claim = $result['claim'];
        $claim->loadMissing('order');
        $order = $claim->order;

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            $result['approval_skipped'] !== null ? 'messages.claims.admin_requested_approval_skipped' : 'messages.claims.admin_requested',
            [
                'claim' => (new ClaimResource($claim))
                    ->withOrderCurrency($order?->currency_snapshot['base_currency'] ?? $order?->currency)
                    ->withCurrencySnapshot($order?->currency_snapshot),
                'claim_number' => $claim instanceof OrderExchange ? $claim->exchange_number : $claim->return_number,
                'payment_requested' => $result['payment_requested'],
                'approval_skipped' => $result['approval_skipped'],
            ],
            201,
        );
    }

    /**
     * 미리보기 결과의 DTO 를 배열로 풉니다. (회원 미리보기와 같은 형태)
     *
     * @param  array  $preview  서비스 미리보기 결과
     * @return array 응답용 배열
     */
    protected function normalizePreview(array $preview): array
    {
        foreach ($preview as $key => $value) {
            if ($value instanceof ClaimShippingFee) {
                $preview[$key] = $value->toArray();
            } elseif ($value instanceof AdjustmentResult) {
                $preview[$key] = $value->toPreviewArray();
            } elseif ($value instanceof \BackedEnum) {
                $preview[$key] = $value->value;
            }
        }

        return $preview;
    }
}
