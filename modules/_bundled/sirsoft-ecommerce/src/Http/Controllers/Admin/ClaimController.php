<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Resources\ActivityLogResource;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\MapsClaimErrors;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkClaimOptionStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkClaimStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CancellableAmountRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ClaimCompletionPreviewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ClaimListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ClaimLogsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ClaimTrackingRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExecuteRefundRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateClaimAmountsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateClaimStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UploadClaimImageRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimAdminCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimImageResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderClaimHistoryResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\ShippingCarrierService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Support\ClaimAdminAbilities;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 관리자 클레임 컨트롤러 (S6a — 목록·상세·전이·송장·첨부)
 *
 * 유형 스코프 라우트(`admin/{claimType}/{id}/…`)의 `claimType` 세그먼트는 라우트 `whereIn` 이
 * 이미 제약하므로 여기서는 `ClaimTypeEnum::fromAdminPathSegment()` 해석 결과를 신뢰한다.
 * 도메인 거절(`OrderClaimException`)은 `MapsClaimErrors::claimDomainError()` 가 422 로 바꾼다 —
 * 키+치환 파라미터 구성이며 매핑을 복제하지 않는다.
 */
class ClaimController extends AdminBaseController
{
    use MapsClaimErrors;

    /**
     * @param  AdminClaimService  $adminClaimService  관리자 클레임 오케스트레이션 서비스
     * @param  UserClaimService  $userClaimService  클레임 공용 서비스 (결제 블록 조립 재사용)
     * @param  ClaimAttachmentService  $attachmentService  클레임 첨부 서비스
     * @param  ShippingCarrierService  $carrierService  배송사 서비스 (상세 화면 송장 택배사 선택지)
     */
    public function __construct(
        protected AdminClaimService $adminClaimService,
        protected UserClaimService $userClaimService,
        protected ClaimAttachmentService $attachmentService,
        protected ShippingCarrierService $carrierService,
    ) {}

    /**
     * 클레임 통합 목록을 조회합니다. (유형 서브탭 단위 + 유형별 건수 뱃지)
     *
     * @param  ClaimListRequest  $request  목록 요청
     * @return JsonResponse 목록 응답
     */
    public function index(ClaimListRequest $request): JsonResponse
    {
        try {
            $claims = $this->adminClaimService->paginate($request->claimType(), $request->filters(), $request->perPage());
            $counts = $this->adminClaimService->counts($request->countFilters());
            // 현재 탭 뱃지만 목록과 같은 필터로 맞춘다 (F13) — 다른 탭은 "그 탭으로 가면 몇
            // 건인가" 라는 원 의미를 그대로 둔다.
            $counts = $this->adminClaimService->refreshCurrentTypeCount(
                $counts,
                $request->claimType(),
                $request->filters(),
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.list_retrieved',
                (new ClaimAdminCollection($claims))
                    ->withCounts($counts)
                    ->withCompleteDefaults($this->adminClaimService->completionDefaults())
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-list', $e);
        }
    }

    /**
     * 클레임 신청서 상세를 조회합니다.
     *
     * @param  string  $claimType  유형 세그먼트 (exchanges/returns/refunds)
     * @param  int  $id  신청서 ID
     * @return JsonResponse 상세 응답
     */
    public function show(string $claimType, int $id): JsonResponse
    {
        try {
            $claim = $this->adminClaimService->findForDetail($this->claimTypeOf($claimType), $id);

            if (! $claim) {
                return $this->claimNotFound();
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.retrieved',
                $this->detailPayload($claim)
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-show', $e);
        }
    }

    /**
     * 클레임 번호로 신청서 상세를 조회합니다. (관리자 상세 화면의 진입 경로)
     *
     * 상세 화면 라우트는 번호 기반(§5-3)이고 유형별 API 는 `(유형, id)` 기반이다. 화면이
     * 번호에서 유형을 추측하면 채번 접두 규칙의 두 번째 출처가 되므로(접두는 운영자가
     * 바꿀 수 있다) 해석은 서버가 한다.
     *
     * @param  string  $claimNumber  클레임 번호
     * @return JsonResponse 상세 응답
     */
    public function showByNumber(string $claimNumber): JsonResponse
    {
        try {
            $resolved = $this->adminClaimService->findForDetailByNumber($claimNumber);

            if ($resolved === null) {
                return $this->claimNotFound();
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.retrieved',
                $this->detailPayload($resolved['claim'])
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-show-by-number', $e);
        }
    }

    /**
     * 신청서 한 건의 활동 로그를 조회합니다. (상세 화면 활동 로그 섹션)
     *
     * 신청서 자신뿐 아니라 그 신청서의 추가결제·첨부에 남은 로그까지 한 목록으로 합친다 —
     * 운영자에게는 셋이 같은 신청서의 이력이다.
     *
     * @param  ClaimLogsRequest  $request  조회 요청 (페이지 크기·정렬)
     * @param  string  $claimNumber  클레임 번호
     * @return JsonResponse 활동 로그 응답
     */
    public function logs(ClaimLogsRequest $request, string $claimNumber): JsonResponse
    {
        try {
            $resolved = $this->adminClaimService->findForDetailByNumber($claimNumber);

            if ($resolved === null) {
                return $this->claimNotFound();
            }

            $logs = $this->adminClaimService->activityLogs($resolved['claim'], $request->getFilters());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.logs_retrieved',
                ActivityLogResource::collection($logs)->response()->getData(true)
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-claim-logs', $e);
        }
    }

    /**
     * 클레임 신청서 상태를 전이합니다. (완료 전이는 completion 페이로드 필수 — D26)
     *
     * 승인 시점 결제(`pay_on_approval`)의 미수납 건 승인은 결제 요청으로 우회된다 —
     * 응답의 `payment_requested` 가 참이면 승인이 아니라 결제대기 전환이 일어난 것이다.
     *
     * @param  UpdateClaimStatusRequest  $request  전이 요청
     * @param  string  $claimType  유형 세그먼트 (exchanges/returns/refunds)
     * @param  int  $id  신청서 ID
     * @return JsonResponse 전이 결과 응답
     */
    public function updateStatus(UpdateClaimStatusRequest $request, string $claimType, int $id): JsonResponse
    {
        $type = $this->claimTypeOf($claimType);
        $claim = $this->adminClaimService->findForTransition($type, $id);

        if (! $claim) {
            return $this->claimNotFound();
        }

        try {
            $processedBy = Auth::id();

            if ($request->statusValue() === 'completed') {
                $this->adminClaimService->complete($claim, [], $request->completionOptions(), $processedBy);
                $paymentRequested = false;
            } else {
                $result = $this->adminClaimService->transition($claim, $request->statusValue(), $request->context($processedBy));
                $paymentRequested = $result['payment_requested'];
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                $paymentRequested ? 'messages.claims.payment_requested' : 'messages.claims.status_updated',
                [
                    'claim' => $this->detailResource($this->adminClaimService->findForDetail($type, $id)),
                    'payment_requested' => $paymentRequested,
                ]
            );
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-update-status', $e);
        }
    }

    /**
     * 여러 신청서를 일괄 전이합니다. (부분 성공 계약 — 게이트 위반 skip 리포트)
     *
     * @param  BulkClaimStatusRequest  $request  일괄 전이 요청
     * @return JsonResponse 일괄 전이 결과 응답
     */
    public function bulkStatus(BulkClaimStatusRequest $request): JsonResponse
    {
        try {
            $result = $this->adminClaimService->bulkTransition(
                $request->claimType(),
                $request->ids(),
                $request->statusValue(),
                $request->context(Auth::id()),
                $request->completionOptions(),
            );

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.bulk_status_processed', $result);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-bulk-status', $e);
        }
    }

    /**
     * 신청 상품 단위로 상태를 일괄 전이합니다. (D25)
     *
     * @param  BulkClaimOptionStatusRequest  $request  상품 단위 전이 요청
     * @param  string  $claimType  유형 세그먼트 (exchanges/returns)
     * @param  int  $id  신청서 ID
     * @return JsonResponse 전이 결과 응답
     */
    public function bulkOptionStatus(BulkClaimOptionStatusRequest $request, string $claimType, int $id): JsonResponse
    {
        $type = $this->claimTypeOf($claimType);
        $claim = $this->adminClaimService->findForTransition($type, $id);

        if (! $claim instanceof OrderExchange && ! $claim instanceof OrderReturn) {
            return $this->claimNotFound();
        }

        try {
            $result = $this->adminClaimService->transitionOptions(
                $claim,
                $request->itemIds(),
                $request->statusValue(),
                $request->context(Auth::id()),
                $request->completionOptions(),
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.option_status_processed',
                $result + ['claim' => $this->detailResource($this->adminClaimService->findForDetail($type, $id))]
            );
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-option-status', $e);
        }
    }

    /**
     * 신청서 금액을 수정합니다. (D18 — 교환·반품: 배송비·청구방식·메모 / 환불: 환불액·환불수단)
     *
     * 환불액의 상한은 취소 가능 금액(D19)이며 초과는 저장·실행 모두 422 다.
     *
     * @param  UpdateClaimAmountsRequest  $request  금액 수정 요청
     * @param  string  $claimType  유형 세그먼트 (exchanges/returns/refunds)
     * @param  int  $id  신청서 ID
     * @return JsonResponse 수정 결과 응답
     */
    public function updateAmounts(UpdateClaimAmountsRequest $request, string $claimType, int $id): JsonResponse
    {
        $type = $this->claimTypeOf($claimType);
        $claim = $this->adminClaimService->findForTransition($type, $id);

        if (! $claim) {
            return $this->claimNotFound();
        }

        try {
            $this->adminClaimService->updateAmounts($claim, $request->amounts(), Auth::id());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.amounts_updated',
                ['claim' => $this->detailResource($this->adminClaimService->findForDetail($type, $id))]
            );
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-update-amounts', $e);
        }
    }

    /**
     * 주문의 취소 가능 금액(D19)을 조회합니다. (환불 상세·주문상세 환불 모달 공용)
     *
     * @param  CancellableAmountRequest  $request  조회 요청 (refund_id 또는 items 문맥)
     * @param  Order  $order  대상 주문
     * @return JsonResponse 산정 결과 응답
     */
    public function cancellableAmount(CancellableAmountRequest $request, Order $order): JsonResponse
    {
        try {
            $result = $this->adminClaimService->cancellableAmountForOrder(
                $order,
                $request->refundId(),
                $request->items(),
            );

            if ($result === null) {
                return $this->claimNotFound();
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.cancellable_retrieved',
                // 표기 조립은 서비스가 소유한다 — 상세 봉투(`detailPayload`)와 같은 지점을
                // 쓰지 않으면 같은 산정이 두 화면에서 다른 문자열로 나간다.
                ['cancellable' => $this->adminClaimService->cancellablePayload($result)]
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-cancellable-amount', $e);
        }
    }

    /**
     * 주문 스코프 클레임 내역을 그룹 구조로 조회합니다. (D20 — 주문상세 '클레임 내역' 섹션)
     *
     * 응답은 유형 서브탭 뱃지용 `counts` 와, 신청서가 상품 배열을 품는 그룹 구조의
     * `claims` (전 유형 평면 배열, 신청 역순)다 — 화면이 `claim_type` 으로 서브탭을 거르고
     * PC 는 1열 rowspan 병합, 모바일은 카드 묶음을 같은 응답으로 그린다(별도 데이터소스
     * 없음). 취소 발(`refund_source_type=cancel`) 환불신청서는 취소 서브탭이 대표하므로
     * 환불 축에서 제외한다 — 조회 자체가 관리자 목록·유저 주문상세 섹션과 같은
     * `UserClaimService::listForOrder()` 단일 출처다.
     *
     * @param  Order  $order  대상 주문
     * @return JsonResponse 그룹 응답 (`counts` + `claims`)
     */
    public function orderClaims(Order $order): JsonResponse
    {
        try {
            $byType = $this->userClaimService->listForOrder($order);
            $reasonLabels = $this->userClaimService->reasonLabelsFor($byType);

            $claims = collect($byType)
                ->flatMap(fn ($list) => $list)
                ->sortByDesc(fn ($claim) => [$claim->created_at?->getTimestamp() ?? 0, $claim->id])
                ->values()
                ->map(fn ($claim) => (new OrderClaimHistoryResource($claim))
                    ->withAdminFields()
                    ->withReasonLabels($reasonLabels)
                    ->toArray(request()));

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.list_retrieved',
                [
                    'counts' => collect($byType)->map(fn ($list) => $list->count())->toArray(),
                    'claims' => $claims->toArray(),
                ]
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-order-claims', $e);
        }
    }

    /**
     * 승인된 환불신청서의 환불을 실행합니다. ('환불하기' — D18·D19)
     *
     * 이미 실행됐거나 실행 중인 건(더블클릭)은 409, D19 상한 초과·상태 위반은 422,
     * PG 환불 거절은 422 + 조치 근거(`detail`)다.
     *
     * @param  ExecuteRefundRequest  $request  실행 요청 (본문 금액은 선택)
     * @param  int  $id  환불신청서 ID
     * @return JsonResponse 실행 결과 응답
     */
    public function executeRefund(ExecuteRefundRequest $request, int $id): JsonResponse
    {
        $claim = $this->adminClaimService->findForTransition(ClaimTypeEnum::REFUND, $id);

        if (! $claim instanceof OrderRefund) {
            return $this->claimNotFound();
        }

        try {
            $this->adminClaimService->executeRefund($claim, $request->overrides(), Auth::id());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.refund_executed',
                ['claim' => $this->detailResource($this->adminClaimService->findForDetail(ClaimTypeEnum::REFUND, $id))]
            );
        } catch (OrderClaimException $e) {
            // 재실행(더블클릭)만 409 — 충돌이지 입력 오류가 아니다. 화면은 새로고침을 안내한다.
            if ($e->getMessageKey() === 'sirsoft-ecommerce::exceptions.claim_refund_already_executed') {
                $messageKey = $e->getMessageKey();

                return ResponseHelper::error($messageKey, 409);
            }

            return $this->claimDomainError($e);
        } catch (OrderCancellationException $e) {
            // PG 환불 거절 — 관리자 전용 면의 errors 페이로드는 조치 근거로 허용된다 (취소 경로 선례).
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.claims.refund_execute_failed',
                422,
                ['detail' => $e->getMessage()]
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-execute-refund', $e);
        }
    }

    /**
     * 완료 처리 모달의 미리보기 요약을 조회합니다. (D26 — 3개 진입점 공용 모달의 SSoT)
     *
     * @param  ClaimCompletionPreviewRequest  $request  미리보기 요청
     * @return JsonResponse 미리보기 응답
     */
    public function completionPreview(ClaimCompletionPreviewRequest $request): JsonResponse
    {
        try {
            $preview = $this->adminClaimService->completionPreview(
                $request->claimType(),
                $request->claims(),
                $request->createRefundRequest(),
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.completion_preview_retrieved',
                $preview
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-completion-preview', $e);
        }
    }

    /**
     * 완료된 신청서에 환불신청서를 수동 접수합니다. (D26 — 완료 시 접수하지 않은 건)
     *
     * 전부 접수됐으면 `claim_refund_request_exists`, 환불 대상이 없으면
     * `claim_refund_not_applicable` — 화면은 두 사유를 구분해 안내한다.
     *
     * @param  string  $claimType  유형 세그먼트 (exchanges/returns)
     * @param  int  $id  신청서 ID
     * @return JsonResponse 접수 결과 응답
     */
    public function storeRefundRequest(string $claimType, int $id): JsonResponse
    {
        $type = $this->claimTypeOf($claimType);
        $claim = $this->adminClaimService->findForTransition($type, $id);

        if (! $claim instanceof OrderExchange && ! $claim instanceof OrderReturn) {
            return $this->claimNotFound();
        }

        try {
            $refund = $this->adminClaimService->createRefundRequestForCompleted($claim, Auth::id());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.refund_request_created',
                ['refund' => (new ClaimResource($refund))->withAdminFields()],
                201
            );
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-refund-request', $e);
        }
    }

    /**
     * 회수 운송장을 등록합니다. (회수준비중이면 회수중 전이 동반)
     *
     * @param  ClaimTrackingRequest  $request  송장 요청
     * @param  string  $claimType  유형 세그먼트 (exchanges/returns)
     * @param  int  $id  신청서 ID
     * @return JsonResponse 갱신 응답
     */
    public function updateCollectionTracking(ClaimTrackingRequest $request, string $claimType, int $id): JsonResponse
    {
        $type = $this->claimTypeOf($claimType);
        $claim = $this->adminClaimService->findForTransition($type, $id);

        if (! $claim instanceof OrderExchange && ! $claim instanceof OrderReturn) {
            return $this->claimNotFound();
        }

        try {
            $this->adminClaimService->registerCollectionTracking($claim, $request->carrierId(), $request->trackingNumber(), Auth::id());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.tracking_saved',
                ['claim' => $this->detailResource($this->adminClaimService->findForDetail($type, $id))]
            );
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-collection-tracking', $e);
        }
    }

    /**
     * 재발송 운송장을 등록합니다. (교환 전용 — 재발송준비중이면 재발송중 전이 동반)
     *
     * @param  ClaimTrackingRequest  $request  송장 요청
     * @param  int  $id  교환 신청서 ID
     * @return JsonResponse 갱신 응답
     */
    public function updateRedeliveryTracking(ClaimTrackingRequest $request, int $id): JsonResponse
    {
        $claim = $this->adminClaimService->findForTransition(ClaimTypeEnum::EXCHANGE, $id);

        if (! $claim instanceof OrderExchange) {
            return $this->claimNotFound();
        }

        try {
            $this->adminClaimService->registerRedeliveryTracking($claim, $request->carrierId(), $request->trackingNumber(), Auth::id());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.tracking_saved',
                ['claim' => $this->detailResource($this->adminClaimService->findForDetail(ClaimTypeEnum::EXCHANGE, $id))]
            );
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-redelivery-tracking', $e);
        }
    }

    /**
     * 운영자 대리 첨부를 업로드합니다. (이미 귀속된 신청서에 추가)
     *
     * @param  UploadClaimImageRequest  $request  업로드 요청
     * @param  string  $claimType  유형 세그먼트 (exchanges/returns/refunds)
     * @param  int  $id  신청서 ID
     * @return JsonResponse 업로드 응답
     */
    public function storeImage(UploadClaimImageRequest $request, string $claimType, int $id): JsonResponse
    {
        $type = $this->claimTypeOf($claimType);

        if (! $this->adminClaimService->findForTransition($type, $id)) {
            return $this->claimNotFound();
        }

        try {
            $image = $this->attachmentService->uploadFor($request->file('image'), $type, $id, Auth::id());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.image_uploaded',
                ['image' => new ClaimImageResource($image)],
                201
            );
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-store-image', $e);
        }
    }

    /**
     * 신청서의 첨부를 일괄 정리합니다. (반려·종결 건 정리 — 파일 + 행)
     *
     * @param  string  $claimType  유형 세그먼트 (exchanges/returns/refunds)
     * @param  int  $id  신청서 ID
     * @return JsonResponse 삭제 결과 응답
     */
    public function destroyImages(string $claimType, int $id): JsonResponse
    {
        $type = $this->claimTypeOf($claimType);

        if (! $this->adminClaimService->findForTransition($type, $id)) {
            return $this->claimNotFound();
        }

        try {
            $deleted = $this->attachmentService->deleteFor($type, $id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.images_cleared',
                ['deleted' => $deleted]
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-destroy-images', $e);
        }
    }

    /**
     * 유형 세그먼트를 Enum 으로 해석합니다. (라우트 `whereIn` 이 어휘를 제약한다)
     *
     * @param  string  $claimType  유형 세그먼트
     * @return ClaimTypeEnum 클레임 유형
     */
    protected function claimTypeOf(string $claimType): ClaimTypeEnum
    {
        return ClaimTypeEnum::fromAdminPathSegment($claimType) ?? ClaimTypeEnum::EXCHANGE;
    }

    /**
     * 상세 응답 페이로드를 조립합니다. (신청서 + 능력 + 완료 기본값 + 진행단계)
     *
     * `abilities` 와 `complete_defaults` 는 목록 응답이 이미 나르는 축이고, 상세 화면도
     * 같은 버튼(상태 전이·금액 수정·환불 실행)과 같은 모달(D26)을 그린다. 상세가 그 값을
     * 설정 엔드포인트에서 읽으면 `settings.read` 게이트에 막혀 클레임 권한만 가진 운영자
     * (기본 역할 `manager`)에게만 403 이 되고, 상점이 꺼 둔 기본값이 조용히 계약 기본값으로
     * 되돌아간다 — 목록이 이 통로를 택한 것과 같은 근거다.
     *
     * 취소(`OrderCancel`)도 같은 봉투로 나간다 — 취소에는 전이·송장·청구·환불 실행 축이 없어
     * 그 자리(세그먼트·택배사·청구 방식·환불 수단·취소 가능 금액)가 비고, 화면은 취소를 조회
     * 전용으로 그린다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return array<string, mixed> 상세 응답 페이로드
     */
    protected function detailPayload(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): array
    {
        return [
            'claim' => $this->detailResource($claim),
            // 유형 스코프 API 의 경로 세그먼트. 화면이 `claim_type + 's'` 로 조립하면 그 조립이
            // 세그먼트 규칙의 두 번째 출처가 된다 — 규칙이 바뀌어도 오류 없이 404 만 남는다.
            'claim_path_segment' => $claim->claimType()->adminPathSegment(),
            'abilities' => ClaimAdminAbilities::forUser(Auth::user()),
            'complete_defaults' => $this->adminClaimService->completionDefaults(),
            'progress_steps' => $this->adminClaimService->progressSteps($claim),
            // '배송지 다름' 뱃지의 근거 (D14). 저장된 두 주소의 비교로 도출하므로 화면이
            // 주소 필드를 다시 대조하지 않는다 — 대조 규칙이 두 곳에 생기면 한쪽만 바뀐다.
            'redelivery_address_differs' => $this->adminClaimService->redeliveryAddressDiffers($claim),
            // 회수·재발송 송장의 택배사 선택지. 배송사 엔드포인트는 `settings.read` 게이트라
            // 이 화면(`claims.read`)이 직접 부르면 클레임 권한만 가진 운영자에게만 403 이 되고
            // 드롭다운이 빈 채로 열린다 — 송장을 저장할 수 없게 되므로 이 응답이 통로다.
            // 환불에는 회수·재발송 축이 없어 빈 배열이다 (그리지 않는 값을 싣지 않는다).
            'carriers' => $claim instanceof OrderRefund || $claim instanceof OrderCancel
                ? []
                : $this->carrierService->getActiveCarrierOptions(),
            // 결제 정보 섹션(D17-③)이 그리는 원 주문의 결제 카드·금액 행 세트. 주문 상세
            // 엔드포인트는 `orders.read` 게이트라 이 화면(`claims.read`)이 직접 부르면
            // 클레임 권한만 가진 운영자에게만 403 이 되어 결제 정보 탭이 통째로 빈다.
            'order' => $claim->order ? $this->orderPaymentContext($claim->order) : null,
            // '이 클레임의 정산' 블록의 환불 예정액 — 헤더 컬럼이 아니라 완료 시점 재계산
            // 결과라 완료 미리보기와 **같은 경로**에서 나온다. 환불신청서는 자기 금액이
            // 헤더 컬럼이라 null 이다.
            'settlement' => $this->adminClaimService->settlementFor($claim),
            // 상세의 신청서 단위 전이 바 선택지 (F1). 목록 일괄 바에서만 전이할 수 있던
            // 시절에는 운영자가 상세를 열어 내용을 확인한 뒤 목록으로 되돌아가 그 줄을 다시
            // 찾아야 했고, 환불 유형은 상세에 어떤 전이 컨트롤도 없었다.
            'transition_options' => $this->adminClaimService->transitionOptionsFor($claim),
            // 청구 방식 어휘는 운영자 설정(`claim.allowed_charge_methods`)이 정하고 그 조회는
            // `settings.read` 게이트다 — 완료 기본값·택배사와 같은 이유로 봉투가 나른다.
            'charge_methods' => $claim instanceof OrderRefund || $claim instanceof OrderCancel
                ? []
                : $this->adminClaimService->chargeMethodOptions(),
            // 환불 수단 어휘 — 환불 실행 섹션의 라디오. 다른 유형에는 그 축이 없다.
            'refund_methods' => $claim instanceof OrderRefund
                ? $this->adminClaimService->refundMethodOptions()
                : [],
            // 취소 가능 금액 산정 근거 (D19). 별도 엔드포인트(`orders/{order}/cancellable-amount`)
            // 도 같은 값을 주지만 그 조회는 상세 응답의 도착을 기다려야 주문 id 를 알 수 있어,
            // 기다리는 동안 환불 실행 섹션이 빈 채로 열린다 — 한 왕복으로 함께 싣는다.
            'cancellable' => $claim instanceof OrderRefund && $claim->order
                ? $this->cancellablePayloadForRefund($claim)
                : null,
        ];
    }

    /**
     * 결제 정보 섹션이 실제로 그리는 주문 축만 골라 담습니다. (D17-③)
     *
     * 값은 `OrderResource` 가 만든다 — 금액 표기·통화 판정을 여기서 다시 적으면 같은 주문이
     * 주문 상세와 클레임 상세에서 다른 문자열로 보인다. 다만 **그 결과를 통째로 싣지는
     * 않는다**: 이 화면은 주문 옵션·배송·주소를 그리지 않으므로, 실으면 그 화면이 쓰지도
     * 않는 하위 컬렉션이 응답에 실리고 미로드 관계가 직렬화 시점에 터진다.
     *
     * 목록은 결제 카드와 금액 행이 읽는 축 + 주문 식별 축(`id`·`order_number`)과 통화 축
     * (`base_currency`·`payment_currency`·`is_cross_currency`)이다. 뒤의 다섯은 지금 화면이
     * 읽지 않는다 — 통화 병기는 결제행 단위(`payments[].is_cross_currency`)가 판정하고, 식별
     * 축은 이 봉투를 소비할 다음 화면(주문 링크·원장 대조)을 위해 남긴다. 스칼라라서 '봉투는
     * 창고가 아니다' 가 겨냥하는 하위 컬렉션·미로드 관계와는 성질이 다르다.
     * `_partial_claim_payment.json` 을 고칠 때 함께 본다.
     *
     * @param  Order  $order  원 주문
     * @return array 결제 정보 섹션이 읽는 주문 축
     */
    protected function orderPaymentContext(Order $order): array
    {
        $order->loadMissing('payments');

        return array_intersect_key(
            (new OrderResource($order))->toArray(request()),
            array_flip([
                'id',
                'order_number',
                'order_status',
                'is_partially_cancelled',
                'base_currency',
                'payment_currency',
                'is_cross_currency',
                'payments',
                'subtotal_amount_formatted',
                'total_shipping_amount_formatted',
                'total_discount_amount',
                'total_discount_amount_formatted',
                'total_product_coupon_discount_amount',
                'total_product_coupon_discount_amount_formatted',
                'total_code_discount_amount',
                'total_code_discount_amount_formatted',
                'total_points_used_amount',
                'total_points_used_amount_formatted',
                'total_deposit_used_amount',
                'total_deposit_used_amount_formatted',
                'total_paid_amount_formatted',
                'total_cancelled_amount',
                'total_cancelled_amount_formatted',
                'total_refunded_amount',
                'total_refunded_amount_formatted',
                'total_refunded_points_amount',
                'total_refunded_points_amount_formatted',
                'total_tax_amount_formatted',
                'total_vat_amount_formatted',
                'total_earned_points_amount',
            ])
        );
    }

    /**
     * 환불신청서 문맥의 취소 가능 금액 페이로드를 만듭니다. (D19 — 상세 봉투)
     *
     * 산정도 표기도 서비스가 소유한다 — 컨트롤러가 표기를 조립하면 형제 엔드포인트
     * (`cancellableAmount()`)와 같은 숫자가 다르게 보인다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @return array|null 산정 결과 (해석 불가 시 null)
     */
    protected function cancellablePayloadForRefund(OrderRefund $refund): ?array
    {
        $result = $this->adminClaimService->cancellableAmountForOrder($refund->order, (int) $refund->id);

        return $result === null ? null : $this->adminClaimService->cancellablePayload($result);
    }

    /**
     * 상세 응답 리소스를 조립합니다. (관리자 확장 필드 + 교환·반품 결제 블록)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|null  $claim  신청서
     * @return ClaimResource|null 상세 리소스
     */
    protected function detailResource(OrderExchange|OrderReturn|OrderRefund|OrderCancel|null $claim): ?ClaimResource
    {
        if ($claim === null) {
            return null;
        }

        $resource = (new ClaimResource($claim))->withAdminFields();

        if ($claim instanceof OrderExchange || $claim instanceof OrderReturn) {
            $resource->withPaymentBlock($this->userClaimService->paymentBlock($claim));
        }

        return $resource;
    }
}
