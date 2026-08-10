<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Helpers\ResponseHelper;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesCashReceiptIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 현금영수증 발급 요청 (유저 사후 발급 — 회원/비회원 공용)
 *
 * 주문 당시 미신청 건을 구매자가 주문상세에서 직접 발급할 때 사용된다.
 * 비회원 경로의 소유권은 VerifyGuestOrderToken 미들웨어가, 회원 경로는 컨트롤러가 검증한다.
 * 발급 취소는 유저에게 허용되지 않는다(관리자 전용).
 */
class IssueCashReceiptRequest extends FormRequest
{
    use ValidatesCashReceiptIssue;

    /**
     * 미들웨어가 검증한 비회원 주문을 반환합니다.
     *
     * @return Order 토큰 검증을 통과한 비회원 주문
     */
    public function getOrder(): Order
    {
        $order = $this->attributes->get('guest_order');

        if (! $order instanceof Order) {
            abort(ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.order_not_found', 404));
        }

        return $order;
    }
}
