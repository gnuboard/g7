<?php

namespace Modules\Sirsoft\Ecommerce\Http\Middleware;

use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * 비회원 주문 조회 토큰 검증 미들웨어
 *
 * X-Guest-Order-Token 헤더의 HMAC 토큰을 검증하여, 라우트의 주문번호와
 * 일치하는 비회원 주문만 통과시킨다. 검증된 주문은 request attribute
 * (guest_order)로 컨트롤러에 전달된다.
 *
 * 토큰 부재/만료/위조/다른 주문 재사용은 모두 동일하게 "주문을 찾을 수 없습니다"
 * 응답으로 처리한다 (정보 노출 차단).
 */
class VerifyGuestOrderToken
{
    public function __construct(
        private GuestOrderAuthService $guestOrderAuthService
    ) {}

    /**
     * 요청을 처리합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Guest-Order-Token');
        $orderNumber = (string) $request->route('orderNumber');

        $order = $this->guestOrderAuthService->verifyToken($token, $orderNumber);

        if (! $order) {
            // 돌아갈 곳을 함께 실어 보낸다 — 이 404 를 받는 화면(비회원 주문 상세·클레임 상세·
            // 신청서 작성)은 남은 토큰을 비우고 조회 폼으로 안내해야 하는데, 그 주소는 상점 주소
            // 설정(route_path/no_route)에 따라 달라져 화면이 스스로 조립할 수 없다. 형제 경로
            // (`OrderController::showByOrderNumber`)가 이미 같은 값을 같은 키로 준다 — 주소 규칙의
            // 단일 출처는 `ShopPathResolver` 다.
            //
            // 화면이 `_global.shopBase` 로 조립하는 폴백에 기대면 그 값이 서기 **전에** 해소되는
            // 응답(blocking 데이터소스)에서 `undefined/guest/orders` 라는 상대 경로가 만들어져
            // 404 가 열린다(2026-09-01 브라우저 실측).
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.order_not_found',
                404,
                ['redirect_to' => ShopPathResolver::path('guest/orders')]
            );
        }

        // 검증된 주문을 컨트롤러로 전달 (각 액션은 동일 주문에만 접근)
        $request->attributes->set('guest_order', $order);

        return $next($request);
    }
}
