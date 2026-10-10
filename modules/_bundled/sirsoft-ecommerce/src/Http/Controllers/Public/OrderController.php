<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderModificationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderOptionNotConfirmableException;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\HandlesOrderCreation;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\CreateOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\GuestCancelOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\GuestEstimateRefundRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\GuestOrderTokenRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\GuestUpdateShippingAddressRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\VerifyGuestOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\CancelPaymentRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\GuestOrderResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Services\StockService;
use Modules\Sirsoft\Ecommerce\Services\TempOrderService;
use Modules\Sirsoft\Ecommerce\Services\UserAddressService;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

/**
 * 주문 공유 컨트롤러 (회원/비회원 공용 + 비회원 토큰 후속 액션)
 *
 * 책임:
 * - 회원/비회원 공유: 주문 생성(store), 주문번호 상세 조회(showByOrderNumber),
 *   PG 결제창 닫기(cancelPayment)
 * - 비회원 전용 토큰 후속: verify, cancel, estimateRefund, confirmOption
 *
 * 회원 전용 마이페이지 액션(index, show, cancel, estimateRefund, updateShippingAddress,
 * confirmOption, reorder)은 User\OrderController 가 담당한다.
 * (비회원 배송지 수정은 미지원 — 향후 필요 시 재도입)
 *
 * PG 플러그인 fetch 인터셉터가 /api/modules/sirsoft-ecommerce/user/orders 한 경로만
 * 매칭하므로 회원/비회원이 동일 엔드포인트(POST /user/orders)로 진입한다. 회원/비회원
 * 분기는 본 컨트롤러의 store() 내부에서 Auth::id() 로 동적 처리한다.
 */
class OrderController extends PublicBaseController
{
    use HandlesOrderCreation;

    /**
     * 주문 생성 경로가 함께 쓰는 협력자를 주입받습니다.
     *
     * @param  OrderProcessingService  $orderProcessingService  주문 생성·결제완료 처리 서비스
     * @param  TempOrderService  $tempOrderService  임시 주문(주문서) 서비스
     * @param  StockService  $stockService  재고 서비스
     * @param  GuestOrderAuthService  $guestOrderAuthService  비회원 주문 조회 인증 서비스
     * @param  OrderService  $orderService  주문 조회·수정 서비스
     * @param  OrderCancellationService  $cancellationService  주문 취소 서비스
     * @param  UserAddressService  $userAddressService  회원 주소록 서비스 (배송지 자동 저장·갱신)
     */
    public function __construct(
        protected OrderProcessingService $orderProcessingService,
        protected TempOrderService $tempOrderService,
        protected StockService $stockService,
        protected GuestOrderAuthService $guestOrderAuthService,
        protected OrderService $orderService,
        protected OrderCancellationService $cancellationService,
        protected UserAddressService $userAddressService
    ) {}

    /**
     * 주문 생성 (결제하기) — 회원/비회원 공유
     *
     * 회원이면 Auth::id() 를 임시주문 매칭/주문 user_id 로 사용하고 OrderResource 로 응답,
     * 비회원이면 user_id=null + GuestOrderResource 로 응답한다. 회원 전용 배송지 자동 저장
     * 후처리는 회원일 때만 수행한다.
     *
     * @param  CreateOrderRequest  $request  검증된 주문 생성 요청
     * @return JsonResponse 생성된 주문 정보를 포함한 JSON 응답
     */
    public function store(CreateOrderRequest $request): JsonResponse
    {
        $userId = Auth::id();

        return $this->processOrderCreation(
            request: $request,
            userId: $userId,
            buildResponseData: function (Order $order, bool $requiresPg, string $pgProvider) use ($userId): array {
                if ($userId !== null) {
                    return $this->buildOrderResponseData(
                        $order,
                        $requiresPg,
                        $pgProvider,
                        new OrderResource($order)
                    );
                }

                // 비회원: 민감 필드 노출을 피하기 위해 GuestOrderResource 로 응답
                $responseData = $this->buildOrderResponseData(
                    $order,
                    $requiresPg,
                    $pgProvider,
                    new GuestOrderResource($order)
                );

                return array_merge($responseData, $this->issueGuestOrderToken($order));
            },
            afterCreate: function (Order $order, bool $requiresPg) use ($request, $userId): void {
                // 회원만 배송지 자동 저장 후처리 수행 (비회원은 user_addresses 매칭 대상 아님)
                if ($userId !== null) {
                    $this->maybeSaveShippingAddress($userId, $request, $order, $requiresPg);
                }
            },
            logEndpoint: 'user.orders.store'
        );
    }

    /**
     * 방금 생성한 비회원 주문의 조회 토큰을 응답 필드로 발급합니다.
     *
     * 결제창 닫힘 보고(PG 플러그인 close-report)는 이 토큰으로 주문 소유자를 확인한다.
     * 주문자 연락처는 제3자도 알 수 있어 소유 증명이 되지 못하므로, 주문을 실제로 만든
     * 브라우저만 받는 이 응답에서 발급한다. 발급 실패가 주문 생성 실패로 번지지 않도록
     * 예외는 기록만 하고 빈 배열을 돌려준다 — 그 경우 닫힘 보고는 거부되고 만료 정리 잡이 주문을 정리한다.
     *
     * @param  Order  $order  방금 생성된 비회원 주문
     * @return array{guest_order_token?: string, guest_order_token_expires_at?: string} 응답에 병합할 토큰 필드
     */
    protected function issueGuestOrderToken(Order $order): array
    {
        try {
            $issued = $this->guestOrderAuthService->issueForOrder($order);

            return [
                'guest_order_token' => $issued['token'],
                'guest_order_token_expires_at' => $issued['expires_at'],
            ];
        } catch (Exception $e) {
            Log::warning('Guest order token issue failed on order creation', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 회원 주문 생성 시 배송지 자동 저장 (회원 전용)
     *
     * non-PG 결제는 즉시 저장하고, PG 결제는 order_meta 에 플래그를 남겨
     * completePayment() 시점에 저장하도록 위임합니다.
     *
     * 갈래가 둘이다 — 주문서에서 저장된 배송지를 고쳐 보냈으면(`save_shipping_address_id`)
     * 그 행을 «갱신» 하고, 직접 입력한 주소면 새로 «추가» 한다. 갱신을 두지 않으면 주문서에서
     * 고친 주소가 주소록에 남지 않아 다음 주문서도 옛 주소로 열리고, 같은 손질을 매번 반복하게
     * 된다(2026-09-21 요구사항). 소유자가 아닌 행 ID 가 오면 서비스가 null 을 돌려 아무 일도
     * 일어나지 않는다 — 남의 주소록은 이 경로로 닿지 않는다.
     *
     * @param  int  $userId  회원 ID (비회원은 호출 측에서 가드)
     * @param  CreateOrderRequest  $request  검증된 요청 데이터
     * @param  Order  $order  생성된 주문
     * @param  bool  $requiresPg  PG 결제 필요 여부
     * @return void 반환값 없음
     */
    protected function maybeSaveShippingAddress(int $userId, CreateOrderRequest $request, Order $order, bool $requiresPg): void
    {
        $updateAddressId = (int) ($request->input('save_shipping_address_id') ?? 0);

        if (! $request->boolean('save_shipping_address') && $updateAddressId <= 0) {
            return;
        }

        if ($requiresPg) {
            // PG 결제: order_meta에 플래그 저장 (completePayment 시점에 처리)
            $order->update([
                'order_meta' => array_merge($order->order_meta ?? [], [
                    'save_shipping_address' => true,
                    'save_shipping_address_id' => $updateAddressId > 0 ? $updateAddressId : null,
                    'shipping_info_for_save' => $request->getShippingInfo(),
                ]),
            ]);

            return;
        }

        // 비PG 결제: 즉시 배송지 저장
        try {
            $this->userAddressService->storeShippingInfoForUser(
                $userId,
                $request->getShippingInfo(),
                $updateAddressId > 0 ? $updateAddressId : null
            );
        } catch (Exception $e) {
            Log::warning('Auto save shipping address failed on order creation', [
                'user_id' => $userId,
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 주문번호로 주문 상세 조회 (회원/비회원 공유)
     *
     * 분기 규칙:
     * - 로그인되어 있으면 본인 회원 주문만 OrderResource 로 반환, 아니면 404
     * - 비로그인이면 X-Guest-Order-Token 으로 비회원 주문 매칭 → GuestOrderResource, 실패 시 404
     *
     * 회원이 비회원 토큰을 들고 진입해도 회원 분기가 우선 — 로그인 시 토큰을 정리하는
     * 프론트 규약과 일관. 실패 사유는 모두 동일한 404 로 처리해 정보 노출을 차단.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 주문 상세 정보 (회원: OrderResource, 비회원: GuestOrderResource)
     */
    public function showByOrderNumber(string $orderNumber): JsonResponse
    {
        $this->logApiUsage('user.orders.show-by-number');

        if (Auth::check()) {
            $order = $this->orderService->getByOrderNumber($orderNumber);

            if (! $order || $order->user_id !== Auth::id()) {
                // 회원 본인 주문 아님 → 마이페이지 주문 목록으로 안내
                return ResponseHelper::error(
                    'sirsoft-ecommerce::exceptions.order_not_found',
                    404,
                    ['redirect_to' => '/mypage/orders']
                );
            }

            // 권한 검증 후 관계를 풀로드해 OrderResource 가 shippings/shipping_address 등 모든 필드를 응답에 포함하도록 한다.
            $detail = $this->orderService->getDetail($order->id);

            return ResponseHelper::success('sirsoft-ecommerce::messages.orders.retrieved', new OrderResource($detail));
        }

        $token = request()->header('X-Guest-Order-Token');
        $order = $this->guestOrderAuthService->verifyToken($token, $orderNumber);

        if (! $order) {
            // 비회원 토큰 부재/만료/위조 → 비회원 조회 폼으로 안내
            return ResponseHelper::error(
                'sirsoft-ecommerce::exceptions.order_not_found',
                404,
                // 상점 주소 설정(route_path/no_route)을 반영해야 주소를 바꾼 상점에서도
                // 실제 존재하는 비회원 조회 화면으로 안내된다 (공개 #85).
                ['redirect_to' => ShopPathResolver::path('guest/orders')]
            );
        }

        $detail = $this->orderService->getDetail($order->id);

        return ResponseHelper::success(
            'sirsoft-ecommerce::messages.orders.retrieved',
            new GuestOrderResource($detail)
        );
    }

    /**
     * 결제 취소 기록 (결제창 닫기) — 회원/비회원 공유
     *
     * 유저가 PG 결제창을 닫았을 때 호출됩니다.
     * 주문 상태는 변경하지 않고, order_payments에 취소 이력만 기록합니다.
     *
     * @param  CancelPaymentRequest  $request  검증된 요청 데이터
     * @return JsonResponse 결제 취소 기록 결과
     */
    public function cancelPayment(CancelPaymentRequest $request): JsonResponse
    {
        $this->logApiUsage('orders.cancel-payment');

        $this->orderProcessingService->recordPaymentCancellation(
            $request->getOrder(),
            $request->validated('cancel_code'),
            $request->validated('cancel_message')
        );

        return ResponseHelper::success('sirsoft-ecommerce::messages.order.payment_cancelled');
    }

    /**
     * 비회원 주문 조회 인증
     *
     * 주문번호 + 전화번호 + 조회 비밀번호로 본인 확인을 수행하고, 성공 시
     * 30분 유효한 조회 토큰을 발급합니다. 모든 실패(주문 없음/회원 주문/
     * 전화번호 불일치/비밀번호 오류/잠금)는 동일한 "주문을 찾을 수 없습니다"
     * 응답으로 처리해 정보 노출을 차단합니다.
     *
     * @param  VerifyGuestOrderRequest  $request  검증된 조회 인증 요청
     * @return JsonResponse 조회 토큰과 최소 주문 요약, 실패 시 404
     */
    public function verify(VerifyGuestOrderRequest $request): JsonResponse
    {
        $result = $this->guestOrderAuthService->authenticate(
            orderNumber: $request->input('order_number'),
            ordererPhone: $request->input('orderer_phone'),
            guestLookupPassword: $request->input('guest_lookup_password'),
            clientIp: $request->ip()
        );

        if (! $result) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.order_not_found',
                404
            );
        }

        /** @var Order $order */
        $order = $result['order'];

        return ResponseHelper::success('sirsoft-ecommerce::messages.orders.retrieved', [
            'guest_order_token' => $result['token'],
            'expires_at' => $result['expires_at'],
            // 최소 주문 요약 (상세는 토큰으로 보호되는 별도 엔드포인트에서 제공)
            'order' => [
                'order_number' => $order->order_number,
                'order_status' => $order->order_status,
            ],
        ]);
    }

    /**
     * 비회원 주문 취소
     *
     * 회원 취소와 동일한 서비스를 재사용하되, 취소자(cancelledBy)는 null 로 둔다.
     *
     * @param  GuestCancelOrderRequest  $request  검증된 취소 요청
     * @return JsonResponse 취소 후 주문 상세
     */
    public function cancel(GuestCancelOrderRequest $request): JsonResponse
    {
        try {
            $order = $request->getOrder();

            if ($request->isPartialCancel()) {
                $this->cancellationService->cancelOrderOptions(
                    order: $order,
                    cancelItems: $request->getCancelItems(),
                    reason: $request->getReason(),
                    reasonDetail: $request->getReasonDetail(),
                    cancelledBy: null,
                    refundPriority: $request->getRefundPriority(),
                    // 구매자(비회원) 본인 요청 — 고객 축으로 접수한다. 운영자 축으로 접수되면
                    // 화면에 노출되지 않는 판매자 귀책 사유가 이 공개 창구로 통과해 배송비
                    // 부담이 달라진다.
                    buyerRequest: true,
                );
            } else {
                $this->cancellationService->cancelOrder(
                    order: $order,
                    reason: $request->getReason(),
                    reasonDetail: $request->getReasonDetail(),
                    cancelledBy: null,
                    refundPriority: $request->getRefundPriority(),
                    buyerRequest: true,
                );
            }

            $updatedOrder = $this->orderService->getDetail($order->id);

            return ResponseHelper::success(
                'sirsoft-ecommerce::messages.order.cancelled',
                new GuestOrderResource($updatedOrder)
            );
        } catch (OrderCancellationException $e) {
            // 취소 도메인 규칙 위반 — 사용자에게 안내 가능한 상황이므로 422.
            // 사유 키가 실려 오면(클레임 수량 잠금·상태 자격 등) 그 사유를 안내한다 — 환불 예상
            // (`estimateRefund`)과 같은 규칙이다. 키가 없으면(PG 거절 등) 원문을 싣지 않고 일반 실패
            // 문구만 낸다 — 이 면은 비회원 화면이라 PG 원문(가맹점 설정·내부 코드)을 내보내지 않는다.
            // PG 원문을 품는 키(`pg_refund_failed`)는 공개 면에 내보내지 않는다(`publicMessageKey()`).
            $messageKey = $e->publicMessageKey();

            return $messageKey === null
                ? ResponseHelper::error('sirsoft-ecommerce::exceptions.order_cancel_failed', 422)
                : ResponseHelper::error($messageKey, 422, null, $e->getMessageParams());
        } catch (Exception $e) {
            Log::error('비회원 주문 취소 실패', [
                'order_number' => $request->getOrder()->order_number,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error(
                'sirsoft-ecommerce::exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 비회원 환불 예상금액 조회
     *
     * @param  GuestEstimateRefundRequest  $request  검증된 환불 예상 요청
     * @return JsonResponse 환불 예상 결과
     */
    public function estimateRefund(GuestEstimateRefundRequest $request): JsonResponse
    {
        try {
            $result = $this->cancellationService->previewRefund(
                $request->getOrder(),
                $request->getCancelItems(),
                $request->getRefundPriority()
            );

            return ResponseHelper::success(
                'sirsoft-ecommerce::messages.order.estimate_refund_success',
                $result->toPreviewArray()
            );
        } catch (OrderCancellationException $e) {
            // 클레임 수량 잠금 등 도메인 거절 — 취소 실행과 같은 422 로 미리보기/실행 비대칭을 없앤다.
            // 사유 키가 실려 오면 그대로 안내한다(관리자 면과 같은 근거) — 없으면 일반 실패 키다.
            // PG 원문을 품는 키(`pg_refund_failed`)는 공개 면에 내보내지 않는다(`publicMessageKey()`).
            $messageKey = $e->publicMessageKey();

            return $messageKey === null
                ? ResponseHelper::error('sirsoft-ecommerce::exceptions.order_cancel_failed', 422)
                : ResponseHelper::error($messageKey, 422, null, $e->getMessageParams());
        } catch (Exception $e) {
            Log::error('비회원 환불 예상금액 계산 실패', [
                'order_number' => $request->getOrder()->order_number,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error(
                'sirsoft-ecommerce::exceptions.order_estimate_refund_failed',
                500
            );
        }
    }

    /**
     * 비회원 주문 배송지 수정 (배송 전 상태)
     *
     * 주문 소유권은 VerifyGuestOrderToken 미들웨어가 검증한다. 비회원은 저장된
     * 회원 주소(address_id)를 쓸 수 없으므로 직접 입력한 배송지 필드를 받아
     * 회원과 동일한 OrderService::updateShippingAddress 로 처리한다.
     *
     * @param  GuestUpdateShippingAddressRequest  $request  검증된 배송지 데이터
     * @return JsonResponse 수정 후 주문 상세 (GuestOrderResource)
     */
    public function updateShippingAddress(GuestUpdateShippingAddressRequest $request): JsonResponse
    {
        try {
            $order = $request->getOrder();
            $this->orderService->updateShippingAddress($order, $request->validated());
            $updatedOrder = $this->orderService->getDetail($order->id);

            return ResponseHelper::success(
                'sirsoft-ecommerce::messages.orders.shipping_address_updated',
                new GuestOrderResource($updatedOrder)
            );
        } catch (OrderModificationException $e) {
            // 도메인 규칙 위반(배송 전 상태 아님 등) — 사용자가 고칠 수 있는 상황이므로 422
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.cannot_modify_address',
                422
            );
        } catch (Exception $e) {
            // 그 외 예외는 서버 결함/인프라 장애다. 422 로 뭉개면 장애가 입력 오류로 위장된다.
            Log::error('비회원 배송지 수정 실패', [
                'order_number' => $request->getOrder()->order_number,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.order_shipping_address_update_failed',
                500
            );
        }
    }

    /**
     * 비회원 주문 옵션 구매확정 (배송 완료 후)
     *
     * @param  GuestOrderTokenRequest  $request  토큰 검증된 요청
     * @param  string  $orderNumber  주문번호 (라우트 파라미터 — 미들웨어 토큰 검증에 사용)
     * @param  int  $optionId  주문 옵션 ID (라우트 파라미터)
     * @return JsonResponse 구매확정 후 주문 상세
     */
    // route-param:unused $orderNumber 는 VerifyGuestOrderToken 미들웨어가 토큰↔주문 일치 검증에
    // 사용하고, 본문은 미들웨어가 확정한 주문($request->getOrder())만 쓴다. 본문에서 재사용하면
    // 검증되지 않은 라우트 값으로 주문을 다시 찾는 우회로가 생긴다.
    public function confirmOption(GuestOrderTokenRequest $request, string $orderNumber, int $optionId): JsonResponse
    {
        $order = $request->getOrder();

        // 토큰으로 검증된 주문에 속한 옵션만 확정 가능
        $option = $order->options()->whereKey($optionId)->first();

        if (! $option) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.order_not_found', 404);
        }

        try {
            $this->orderService->confirmOption($order, $option);
            $updatedOrder = $this->orderService->getDetail($order->id);

            return ResponseHelper::success(
                'sirsoft-ecommerce::messages.order.confirmed',
                new GuestOrderResource($updatedOrder)
            );
        } catch (OrderOptionNotConfirmableException $e) {
            // 상태 게이트 위반(이미 확정 / 확정 불가 상태) — 도메인 사유이므로 422.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.'.$e->getReason(),
                422
            );
        } catch (Exception $e) {
            // 위 typed 예외를 제외한 나머지는 전부 서버 결함/인프라 장애다.
            Log::error('비회원 구매확정 실패', [
                'order_number' => $order->order_number,
                'option_id' => $optionId,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }
}
