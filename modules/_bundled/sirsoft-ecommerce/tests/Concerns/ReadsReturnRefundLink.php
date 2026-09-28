<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Concerns;

use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;

/**
 * 반품 → 환불신청서 연결을 상품 행에서 읽는 테스트 도우미 (S7)
 *
 * 반품의 환불 연결은 헤더 한 칸(`refund_id`)이 아니라 **완료 상품 행**의 `refund_id` 다 — 완료 회차마다
 * 그 회차 상품으로 환불신청서가 접수된다. 테스트가 헤더를 읽으면 언제나 null 이다.
 */
trait ReadsReturnRefundLink
{
    /**
     * 반품 상품 행이 가리키는 환불신청서 ID 를 반환합니다. (여럿이면 가장 최근 회차)
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return int|null 환불신청서 ID (연결된 행이 없으면 null)
     */
    protected function returnRefundId(OrderReturn $return): ?int
    {
        $id = OrderReturnOption::query()
            ->where('order_return_id', $return->id)
            ->whereNotNull('refund_id')
            ->max('refund_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * 반품 상품 행이 가리키는 환불신청서를 반환합니다. (여럿이면 가장 최근 회차)
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return OrderRefund|null 환불신청서 (연결된 행이 없으면 null)
     */
    protected function returnRefund(OrderReturn $return): ?OrderRefund
    {
        $id = $this->returnRefundId($return);

        return $id === null ? null : OrderRefund::find($id);
    }
}
