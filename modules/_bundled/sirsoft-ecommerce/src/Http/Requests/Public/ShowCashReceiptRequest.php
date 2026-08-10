<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Helpers\ResponseHelper;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 비회원 현금영수증 상태 조회 요청
 *
 * 주문 소유권은 VerifyGuestOrderToken 미들웨어가 검증하며, 본 요청은 입력을 받지 않는다.
 */
class ShowCashReceiptRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 true (실제 인증은 VerifyGuestOrderToken 미들웨어가 수행)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, array<int, string>> 검증 규칙 배열 (입력 없음)
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * 미들웨어가 검증한 대상 주문을 반환합니다.
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
