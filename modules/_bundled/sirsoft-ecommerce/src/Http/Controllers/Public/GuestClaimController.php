<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\HandlesClaimActions;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\GuestOrderTokenRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimPaymentApproveRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimPaymentPayRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimPreviewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreExchangeRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreRefundRequestRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreReturnRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateClaimRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UploadClaimImageRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimImageResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;

/**
 * 비회원 클레임(교환/반품/취소·환불) 신청 컨트롤러
 *
 * 모든 액션은 `guest/orders/{orderNumber}/...` 아래에 있고 `VerifyGuestOrderToken` 미들웨어가 토큰으로
 * 검증한 주문(`guest_order` attribute)만 다룬다 — 라우트의 주문번호로 주문을 다시 찾지 않는다. 액션
 * 본문은 회원 컨트롤러와 공유하는 `HandlesClaimActions` 에 있고, 이 클래스는 열람자(비회원 주문)만 정한다.
 * 신청자(`requested_by`)는 항상 null 이며 마일리지 결제는 열리지 않는다.
 */
class GuestClaimController extends PublicBaseController
{
    use HandlesClaimActions;

    /**
     * 비회원 클레임 컨트롤러를 생성합니다.
     *
     * @param  UserClaimService  $claimService  클레임 서비스
     * @param  ClaimAttachmentService  $attachmentService  첨부 서비스
     */
    public function __construct(
        private UserClaimService $claimService,
        private ClaimAttachmentService $attachmentService,
    ) {}

    /**
     * 주문의 클레임 가능 상품·유형·사유·설정 조회
     *
     * @param  GuestOrderTokenRequest  $request  토큰 검증 주문
     * @return JsonResponse 응답
     */
    public function claimableOptions(GuestOrderTokenRequest $request): JsonResponse
    {
        $order = $request->getOrder();
        $order->loadMissing('shippingAddress');

        return $this->respondClaimableOptions($order);
    }

    /**
     * 신청 미리보기
     *
     * @param  ClaimPreviewRequest  $request  검증된 요청
     * @return JsonResponse 응답
     */
    public function preview(ClaimPreviewRequest $request): JsonResponse
    {
        return $this->respondPreview($request);
    }

    /**
     * 교환 신청
     *
     * @param  StoreExchangeRequest  $request  검증된 요청
     * @return JsonResponse 응답
     */
    public function storeExchange(StoreExchangeRequest $request): JsonResponse
    {
        return $this->respondStoreExchange($request);
    }

    /**
     * 반품 신청
     *
     * @param  StoreReturnRequest  $request  검증된 요청
     * @return JsonResponse 응답
     */
    public function storeReturn(StoreReturnRequest $request): JsonResponse
    {
        return $this->respondStoreReturn($request);
    }

    /**
     * 취소·환불 신청 (상태 자동 분기)
     *
     * @param  StoreRefundRequestRequest  $request  검증된 요청
     * @return JsonResponse 응답
     */
    public function storeRefund(StoreRefundRequestRequest $request): JsonResponse
    {
        return $this->respondStoreRefund($request);
    }

    /**
     * 이 주문의 신청 내역 (비회원 주문상세 섹션 — 횡단 목록 없음, §5-1-3)
     *
     * 비회원에게는 주문을 가로지르는 신청내역 화면이 없다(조회 토큰이 주문 하나에 묶인다).
     * 그래서 이 응답이 비회원의 목록이며 회원 목록(`user.claims.index`)과 같은 계약을 쓴다 —
     * 유형 서브탭 하나 · 최신순 · 페이지. 네 유형을 한 응답에 전량 싣던 종전 형태는 한 주문의
     * 건수가 그 주문의 상품 수에 묶인다는 전제에 기댔는데, 철회가 행을 남기므로(수량 잠금만
     * 풀린다) 신청↔철회를 반복하면 계속 쌓여 그 전제가 성립하지 않는다.
     *
     * @param  ClaimListRequest  $request  검증된 목록 요청 (토큰 검증 주문 포함)
     * @return JsonResponse 응답
     */
    public function index(ClaimListRequest $request): JsonResponse
    {
        try {
            // 유형 미지정이면 접수 건이 있는 첫 유형을 서버가 고른다 — 회원 주문상세 섹션과 같은 규칙.
            $order = $request->getOrder();
            $counts = $this->claimService->countsForOrder($order, $request->filters());
            $type = $this->claimService->resolveOrderListType($request->requestedClaimType(), $counts);
            // 현재 탭 뱃지만 목록과 같은 필터로 맞춘다 (F15).
            $counts = $this->claimService->refreshCurrentTypeCount($order, $counts, $type, $request->filters());
            $claims = $this->claimService->listForOrderPaginated(
                $order,
                $type,
                $request->filters(),
                $request->perPage(),
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.list_retrieved',
                (new ClaimCollection($claims))->withTypeSummary($type, $counts),
            );
        } catch (Exception $e) {
            return $this->claimServerError('guest-index', $e);
        }
    }

    /**
     * 신청서 상세
     *
     * @param  GuestOrderTokenRequest  $request  토큰 검증 주문
     * @param  string  $orderNumber  주문번호 (미들웨어 검증용)
     * @param  string  $claimNumber  클레임 번호
     * @return JsonResponse 응답
     */
    // route-param:unused $orderNumber 는 VerifyGuestOrderToken 미들웨어가 토큰↔주문 일치 검증에 쓰고, 본문은 미들웨어가 확정한 주문(guest_order)만 쓴다.
    public function show(GuestOrderTokenRequest $request, string $orderNumber, string $claimNumber): JsonResponse
    {
        return $this->respondShow($claimNumber);
    }

    /**
     * 신청 내용 수정 (승인 전)
     *
     * @param  UpdateClaimRequest  $request  검증된 요청
     * @param  string  $orderNumber  주문번호 (미들웨어 검증용)
     * @param  string  $claimNumber  클레임 번호
     * @return JsonResponse 응답
     */
    // route-param:unused $orderNumber 는 VerifyGuestOrderToken 미들웨어가 토큰↔주문 일치 검증에 쓰고, 본문은 미들웨어가 확정한 주문(guest_order)만 쓴다.
    public function update(UpdateClaimRequest $request, string $orderNumber, string $claimNumber): JsonResponse
    {
        return $this->respondUpdate($request, $claimNumber);
    }

    /**
     * 신청 철회
     *
     * @param  GuestOrderTokenRequest  $request  토큰 검증 주문
     * @param  string  $orderNumber  주문번호 (미들웨어 검증용)
     * @param  string  $claimNumber  클레임 번호
     * @return JsonResponse 응답
     */
    // route-param:unused $orderNumber 는 VerifyGuestOrderToken 미들웨어가 토큰↔주문 일치 검증에 쓰고, 본문은 미들웨어가 확정한 주문(guest_order)만 쓴다.
    public function withdraw(GuestOrderTokenRequest $request, string $orderNumber, string $claimNumber): JsonResponse
    {
        return $this->respondWithdraw($claimNumber);
    }

    /**
     * 신청서 작성 중 이미지 업로드 (주문 스코프 · 신청서 미귀속)
     *
     * @param  UploadClaimImageRequest  $request  검증된 요청
     * @return JsonResponse 응답
     */
    public function storeImage(UploadClaimImageRequest $request): JsonResponse
    {
        try {
            $image = $this->attachmentService->uploadPending($request->file('image'), $request->getOrder(), null);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.image_uploaded', new ClaimImageResource($image), 201);
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (Exception $e) {
            // 광역 catch 의 상태코드는 이 자리에서 명시한다. 정적 계약 검사가 catch 블록에서
            // 전방으로 스캔하므로, 헬퍼에만 맡기면 이 블록의 상태코드를 뒤쪽 지점의 것으로 읽는다.
            // (도메인 사유는 위 typed catch 가 이미 422 로 내린다 — 여기 도달분은 인프라 실패뿐이다.)
            Log::error('[claims] 비회원 첨부 업로드 실패', ['error' => $e->getMessage()]);

            return ResponseHelper::error('sirsoft-ecommerce::messages.claims.operation_failed', 500);
        }
    }

    /**
     * 첨부 이미지 삭제 (이 주문 소속만)
     *
     * @param  GuestOrderTokenRequest  $request  토큰 검증 주문
     * @param  string  $orderNumber  주문번호 (미들웨어 검증용)
     * @param  int  $image  첨부 ID
     * @return JsonResponse 응답
     */
    // route-param:unused $orderNumber 는 VerifyGuestOrderToken 미들웨어가 토큰↔주문 일치 검증에 쓰고, 본문은 미들웨어가 확정한 주문(guest_order)만 쓴다.
    public function destroyImage(GuestOrderTokenRequest $request, string $orderNumber, int $image): JsonResponse
    {
        $model = $this->attachmentService->find($image);

        if (! $model || ! $this->attachmentService->deletableBy($model, null, $request->getOrder())) {
            return $this->claimNotFound('exceptions.claim_image_not_found');
        }

        try {
            $this->attachmentService->delete($model);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.image_deleted', ['deleted' => true]);
        } catch (Exception $e) {
            // storeImage 와 같은 이유로 상태코드를 인라인으로 명시한다(정적 계약 검사의 전방 스캔).
            // 도메인 404 는 위 소유권 게이트가 `claimNotFound()` 로 이미 내렸다.
            Log::error('[claims] 비회원 첨부 삭제 실패', ['claim_image_id' => $image, 'error' => $e->getMessage()]);

            return ResponseHelper::error('sirsoft-ecommerce::messages.claims.operation_failed', 500);
        }
    }

    /**
     * 결제 시작
     *
     * @param  ClaimPaymentPayRequest  $request  검증된 요청
     * @param  string  $orderNumber  주문번호 (미들웨어 검증용)
     * @param  string  $paymentNumber  결제번호
     * @return JsonResponse 응답
     */
    // route-param:unused $orderNumber 는 VerifyGuestOrderToken 미들웨어가 토큰↔주문 일치 검증에 쓰고, 본문은 미들웨어가 확정한 주문(guest_order)만 쓴다.
    public function pay(ClaimPaymentPayRequest $request, string $orderNumber, string $paymentNumber): JsonResponse
    {
        return $this->respondPay($paymentNumber, $request->paymentMethod());
    }

    /**
     * PG 승인 콜백
     *
     * @param  ClaimPaymentApproveRequest  $request  검증된 요청
     * @param  string  $orderNumber  주문번호 (미들웨어 검증용)
     * @param  string  $paymentNumber  결제번호
     * @return JsonResponse 응답
     */
    // route-param:unused $orderNumber 는 VerifyGuestOrderToken 미들웨어가 토큰↔주문 일치 검증에 쓰고, 본문은 미들웨어가 확정한 주문(guest_order)만 쓴다.
    public function approve(ClaimPaymentApproveRequest $request, string $orderNumber, string $paymentNumber): JsonResponse
    {
        return $this->respondApprove($paymentNumber, $request->callback());
    }

    /**
     * {@inheritDoc}
     */
    protected function viewer(): array
    {
        $order = request()->attributes->get('guest_order');

        return ['user_id' => null, 'guest_order' => $order instanceof Order ? $order : null];
    }

    /**
     * {@inheritDoc}
     */
    protected function claims(): UserClaimService
    {
        return $this->claimService;
    }
}
