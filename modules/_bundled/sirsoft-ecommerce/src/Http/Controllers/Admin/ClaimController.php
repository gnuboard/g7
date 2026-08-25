<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\MapsClaimErrors;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkClaimOptionStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkClaimStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ClaimListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ClaimTrackingRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateClaimStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UploadClaimImageRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimAdminCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimImageResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
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
     */
    public function __construct(
        protected AdminClaimService $adminClaimService,
        protected UserClaimService $userClaimService,
        protected ClaimAttachmentService $attachmentService,
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

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.list_retrieved',
                (new ClaimAdminCollection($claims))->withCounts($counts)
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
                ['claim' => $this->detailResource($claim)]
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (Exception $e) {
            return $this->claimServerError('admin-show', $e);
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
     * 상세 응답 리소스를 조립합니다. (관리자 확장 필드 + 교환·반품 결제 블록)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|null  $claim  신청서
     * @return ClaimResource|null 상세 리소스
     */
    protected function detailResource(OrderExchange|OrderReturn|OrderRefund|null $claim): ?ClaimResource
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
