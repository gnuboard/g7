<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\HandlesClaimActions;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimPreviewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreExchangeRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreRefundRequestRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreReturnRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateClaimRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimCollection;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;

/**
 * 회원 클레임(교환/반품/취소·환불) 신청 컨트롤러 (마이페이지)
 *
 * 인증은 라우트 그룹(`user` 접두 + `auth:sanctum`)이 강제한다 — 이 클래스는 생성자를 재정의하므로
 * `AuthBaseController` 생성자의 미들웨어 등록은 실행되지 않는다. 액션 본문은 비회원 컨트롤러와 공유하는
 * `HandlesClaimActions` 에 있고, 이 클래스는 열람자(회원 ID)만 정한다.
 *
 * 주문 소유권은 FormRequest(`ResolvesClaimOrder::getOrder()`)가 한 곳에서 판정한다 — 이 컨트롤러는
 * 주문을 직접 찾지 않는다.
 */
class ClaimController extends AuthBaseController
{
    use HandlesClaimActions;

    /**
     * @param  UserClaimService  $claimService  클레임 서비스
     */
    public function __construct(
        private UserClaimService $claimService,
    ) {}

    /**
     * 주문의 클레임 가능 상품·유형·사유·설정 조회
     *
     * @param  ClaimOrderRequest  $request  소유권이 확인된 주문 요청
     * @param  int  $id  주문 ID (FormRequest 가 해석한다)
     * @return JsonResponse 응답
     */
    // route-param:unused $id 는 ClaimOrderRequest(ResolvesClaimOrder)가 라우트에서 읽어 소유권을 판정한다.
    public function claimableOptions(ClaimOrderRequest $request, int $id): JsonResponse
    {
        $this->logApiUsage('user.claims.claimable-options');

        return $this->respondClaimableOptions($request->getOrder());
    }

    /**
     * 이 주문의 신청 내역 (마이페이지 주문상세 '이 주문의 클레임' 섹션)
     *
     * 회원 신청내역 목록(`user.claims.index`)·비회원 주문상세 섹션(`guest.orders.claims.index`)과
     * 같은 계약(유형 서브탭 하나 · 최신순 · 페이지)이며 조회도 같은 `listForOrderPaginated()` 다.
     * 회원 목록에 주문 필터를 얹지 않고 주문 스코프 경로를 따로 두는 것은, 주문 상세가 이미
     * 주문 ID 로 형제 자원(`claimable-options`)을 부르고 있어 같은 모양이 화면에 자연스럽고,
     * 본인 주문이 아니면 존재를 드러내지 않고 404 로 닫는 규칙을 그 형제와 함께 두기 위해서다.
     *
     * @param  ClaimListRequest  $request  검증된 목록 요청 (소유권 판정 포함)
     * @param  int  $id  주문 ID (FormRequest 가 해석한다)
     * @return JsonResponse 응답
     */
    // route-param:unused $id 는 ClaimListRequest(ResolvesClaimOrder)가 라우트에서 읽어 소유권을 판정한다.
    public function orderClaims(ClaimListRequest $request, int $id): JsonResponse
    {
        $this->logApiUsage('user.orders.claims.index');

        // 소유권 판정은 요청의 한 곳이다 — 남의 주문이면 여기서 404 로 끝난다.
        $order = $request->getOrder();

        try {
            // 유형 미지정이면 접수 건이 있는 첫 유형을 서버가 고른다 — 화면은 응답의 claim_type 으로
            // 활성 서브탭을 세우고 counts 로 뱃지를 단다 (관리자 클레임 내역과 같은 규칙).
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
            return $this->claimServerError('order-claims', $e);
        }
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
            $userId = (int) Auth::id();
            $type = $request->claimType();

            // 서브탭 뱃지도 함께 낸다 (F12). 주문 스코프 목록·관리자 목록은 이미 같은 키를
            // 내는데 회원 목록만 빠져 있었다 — 뱃지가 영영 비어, 구매자는 다른 탭에 건이
            // 있는지 알 수 없어 네 탭을 차례로 눌러 봐야 했다.
            $counts = $this->claimService->countsForUser($userId, $request->filters());
            // 현재 탭 뱃지만 목록과 같은 필터로 맞춘다 (F15).
            $counts = $this->claimService->refreshCurrentUserTypeCount($userId, $counts, $type, $request->filters());

            $claims = $this->claimService->listForUser($userId, $type, $request->filters(), $request->perPage());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claims.list_retrieved',
                (new ClaimCollection($claims))->withTypeSummary($type, $counts),
            );
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
