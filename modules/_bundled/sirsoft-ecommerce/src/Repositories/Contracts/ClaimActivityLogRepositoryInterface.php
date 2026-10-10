<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;

/**
 * 클레임 활동 로그 Repository 인터페이스
 *
 * 클레임 한 건의 이력은 신청서 자신뿐 아니라 그 신청서에 딸린 추가결제·첨부에도 남는다
 * (`ClaimActivityLogListener`). 세 축을 한 목록으로 합쳐 돌려주는 것이 이 계약이다.
 */
interface ClaimActivityLogRepositoryInterface
{
    /**
     * 신청서 한 건의 활동 로그를 조회합니다. (신청서 + 추가결제 + 첨부)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  array{per_page?: int, sort_order?: string}  $filters  조회 필터
     * @return LengthAwarePaginator 활동 로그 페이지네이션
     */
    public function paginateForClaim(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim, array $filters = []): LengthAwarePaginator;
}
