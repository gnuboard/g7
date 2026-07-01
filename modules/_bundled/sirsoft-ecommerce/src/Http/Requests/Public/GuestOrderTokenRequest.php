<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Helpers\ResponseHelper;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 비회원 주문 토큰 보호 요청 (입력 본문 없는 액션용)
 *
 * 상세 조회·구매확정처럼 별도 입력 본문이 없는 비회원 액션에서 사용한다.
 * 주문 소유권은 VerifyGuestOrderToken 미들웨어가 검증하며, 본 요청은
 * 미들웨어가 전달한 주문(guest_order attribute)에 대한 접근자만 제공한다.
 */
class GuestOrderTokenRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * 미들웨어가 검증한 대상 주문을 반환합니다.
     *
     * @return Order
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
