<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

use App\Helpers\ResponseHelper;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;

/**
 * 클레임 FormRequest 의 주문 해석 (회원 · 비회원 공용)
 *
 * 같은 FormRequest 가 두 라우트를 받는다:
 *  - 회원 `user/orders/{id}/...` — 라우트의 주문 ID 로 조회하고 소유권을 본인 회원으로 판정한다.
 *  - 비회원 `guest/orders/{orderNumber}/...` — `VerifyGuestOrderToken` 미들웨어가 검증해 둔 `guest_order`
 *    attribute 를 쓴다(토큰이 곧 소유권). 라우트 값으로 주문을 다시 찾지 않는다 — 검증되지 않은 값으로
 *    주문을 되찾는 우회로가 된다.
 *
 * 소유권 위반은 존재 여부와 같은 404 로 답한다 — 다른 회원의 주문 번호를 짚어 "있지만 네 것이 아니다" 를
 * 알려 주지 않는다(취소 요청과 같은 계약). 배송지는 규칙(재발송지 국가)이 곧바로 읽으므로 Repository 가
 * 한 번에 싣는다(지연 로드 없음).
 */
trait ResolvesClaimOrder
{
    protected ?Order $order = null;

    /**
     * 라우트·토큰의 주문을 해석합니다. (1회 조회 후 보관, 없으면 null)
     *
     * @return Order|null 주문
     */
    protected function resolveOrder(): ?Order
    {
        if ($this->order !== null) {
            return $this->order;
        }

        $guestOrder = $this->attributes->get('guest_order');

        if ($guestOrder instanceof Order) {
            $guestOrder->loadMissing('shippingAddress');

            return $this->order = $guestOrder;
        }

        $orderId = (int) $this->route('id');

        if ($orderId <= 0) {
            return null;
        }

        return $this->order = app(OrderRepositoryInterface::class)->findWithShippingAddress($orderId);
    }

    /**
     * 비회원(토큰) 경로인지 반환합니다.
     *
     * @return bool 비회원 경로이면 true
     */
    protected function isGuestRoute(): bool
    {
        return $this->attributes->get('guest_order') instanceof Order;
    }

    /**
     * 주문 존재·소유권을 판정하고 위반이면 404 로 끝냅니다.
     *
     * @return Order 소유권이 확인된 주문
     */
    protected function assertOrderOwnership(): Order
    {
        $order = $this->resolveOrder();

        if (! $order || (! $this->isGuestRoute() && $order->user_id !== Auth::id())) {
            abort(ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.order_not_found', 404));
        }

        return $order;
    }

    /**
     * 소유권이 확인된 주문을 반환합니다. (부재·남의 주문이면 404 로 끝낸다)
     *
     * 주문 소유권 판정은 **이 한 곳**이다 — 회원 컨트롤러가 주문 ID 로 다시 찾고 `user_id` 를 비교하던
     * 사본(`claimableOptions`·`orderClaims`)을 걷어 내고 이 메서드로 모았다. 사본이 셋이면 한 곳만
     * 고쳐졌을 때 그 경로만 남의 주문을 연다.
     *
     * @return Order 주문
     */
    public function getOrder(): Order
    {
        return $this->assertOrderOwnership();
    }

    /**
     * 신청 주체 회원 ID 를 반환합니다. (비회원 경로면 null — 요청 본문이 신청자를 지정하게 두지 않는다)
     *
     * @return int|null 회원 ID
     */
    protected function requesterId(): ?int
    {
        return $this->isGuestRoute() ? null : Auth::id();
    }
}
