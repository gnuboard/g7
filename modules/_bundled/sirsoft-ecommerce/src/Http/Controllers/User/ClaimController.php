<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\HandlesClaimActions;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimPreviewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreExchangeRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreRefundRequestRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreReturnRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateClaimRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimCollection;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;

/**
 * 회원 클레임(교환/반품/취소·환불) 신청 컨트롤러 (마이페이지)
 *
 * AuthBaseController 상속으로 모든 메서드에 auth:sanctum 이 강제된다. 액션 본문은
 * 비회원 컨트롤러와 공유하는 `HandlesClaimActions` 에 있고, 이 클래스는 열람자(회원 ID)만 정한다.
 */
class ClaimController extends AuthBaseController
{
    use HandlesClaimActions;

    public function __construct(
        private UserClaimService $claimService,
        private OrderRepositoryInterface $orderRepository,
    ) {}

    /**
     * 주문의 클레임 가능 상품·유형·사유·설정 조회
     *
     * @param  int  $id  주문 ID
     * @return JsonResponse 응답
     */
    public function claimableOptions(int $id): JsonResponse
    {
        $this->logApiUsage('user.claims.claimable-options');

        $order = $this->orderRepository->findWithShippingAddress($id);

        if (! $order || $order->user_id !== Auth::id()) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.order_not_found', 404);
        }

        return $this->respondClaimableOptions($order);
    }

    /**
     * 신청 미리보기 (배송비·차액·환불 예정액)
     *
     * @param  ClaimPreviewRequest  $request  검증된 요청
     * @return JsonResponse 응답
     */
    public function preview(ClaimPreviewRequest $request): JsonResponse
    {
        $this->logApiUsage('user.claims.preview');

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
        $this->logApiUsage('user.claims.exchange.store');

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
        $this->logApiUsage('user.claims.return.store');

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
        $this->logApiUsage('user.claims.refund.store');

        return $this->respondStoreRefund($request);
    }

    /**
     * 신청 내역 목록 (유형 서브탭 단위 페이지네이션)
     *
     * @param  ClaimListRequest  $request  검증된 요청
     * @return JsonResponse 응답
     */
    public function index(ClaimListRequest $request): JsonResponse
    {
        $this->logApiUsage('user.claims.index');

        try {
            $claims = $this->claimService->listForUser((int) Auth::id(), $request->claimType(), $request->filters(), $request->perPage());

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.list_retrieved', new ClaimCollection($claims));
        } catch (Exception $e) {
            return $this->claimServerError('index', $e);
        }
    }

    /**
     * 신청서 상세
     *
     * @param  string  $claimNumber  클레임 번호
     * @return JsonResponse 응답
     */
    public function show(string $claimNumber): JsonResponse
    {
        $this->logApiUsage('user.claims.show');

        return $this->respondShow($claimNumber);
    }

    /**
     * 신청 내용 수정 (승인 전 — 사유·상세 사유·첨부·교환 재발송지)
     *
     * @param  UpdateClaimRequest  $request  검증된 요청
     * @param  string  $claimNumber  클레임 번호
     * @return JsonResponse 응답
     */
    public function update(UpdateClaimRequest $request, string $claimNumber): JsonResponse
    {
        $this->logApiUsage('user.claims.update');

        return $this->respondUpdate($request, $claimNumber);
    }

    /**
     * 신청 철회
     *
     * @param  string  $claimNumber  클레임 번호
     * @return JsonResponse 응답
     */
    public function withdraw(string $claimNumber): JsonResponse
    {
        $this->logApiUsage('user.claims.withdraw');

        return $this->respondWithdraw($claimNumber);
    }

    /**
     * {@inheritDoc}
     */
    protected function viewer(): array
    {
        return ['user_id' => Auth::id(), 'guest_order' => null];
    }

    /**
     * {@inheritDoc}
     */
    protected function claims(): UserClaimService
    {
        return $this->claimService;
    }
}
