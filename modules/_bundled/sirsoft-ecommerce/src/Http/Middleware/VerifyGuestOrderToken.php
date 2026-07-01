<?php

namespace Modules\Sirsoft\Ecommerce\Http\Middleware;

use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
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
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.order_not_found',
                404
            );
        }

        // 검증된 주문을 컨트롤러로 전달 (각 액션은 동일 주문에만 접근)
        $request->attributes->set('guest_order', $order);

        return $next($request);
    }
}
