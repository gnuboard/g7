<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Models\ActivityLog;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimActivityLogRepositoryInterface;

/**
 * 클레임 활동 로그 Repository 구현체
 *
 * 조회 축은 주문 활동 로그(`OrderRepository::getActivityLogsForOrder()`)와 같은 형태다 —
 * 본체 loggable 하나에 딸린 자식들의 loggable 을 OR 로 묶는다. 신청서의 자식은 추가결제
 * (`ecommerce_claim_payments`)와 첨부(`ecommerce_claim_images`) 둘이다.
 *
 * 신청서의 `loggable_type` 은 morph map 이 정한 짧은 값(`exchange`/`return`/`refund`)이고,
 * 추가결제·첨부는 map 에 없어 FQCN 으로 저장된다 — `ClaimActivityLogListener` 가 기록할 때
 * 쓰는 값과 같은 규칙이라 여기서 형태를 바꾸면 그 축의 로그가 조용히 사라진다.
 */
class ClaimActivityLogRepository implements ClaimActivityLogRepositoryInterface
{
    use PaginatesWithDeferredJoin;

    /**
     * {@inheritDoc}
     */
    public function paginateForClaim(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 10);
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // 취소는 추가결제·첨부 축이 없다(관계 자체가 없다). 대신 취소 발 환불 행이 환불 실행
        // 이력을 들고 있으므로 그 행의 로그를 같은 신청서의 것으로 합친다.
        $paymentIds = $claim instanceof OrderRefund || $claim instanceof OrderCancel
            ? []
            : $claim->claimPayments()->pluck('id')->all();
        $imageIds = $claim instanceof OrderCancel
            ? []
            : $claim->images()->pluck('id')->all();
        $linkedRefund = $claim instanceof OrderCancel ? $claim->refund : null;

        $query = ActivityLog::where(function (Builder $q) use ($claim, $paymentIds, $imageIds, $linkedRefund) {
            // 신청서 자체 로그 (신청·상태 전이·상품 단위 전이·금액 수정·환불 실행)
            $q->where(function (Builder $sub) use ($claim) {
                $sub->where('loggable_type', $claim->getMorphClass())
                    ->where('loggable_id', $claim->getKey());
            });

            // 취소 발 환불 행의 로그 (환불 실행·완료)
            if ($linkedRefund !== null) {
                $q->orWhere(function (Builder $sub) use ($linkedRefund) {
                    $sub->where('loggable_type', $linkedRefund->getMorphClass())
                        ->where('loggable_id', $linkedRefund->getKey());
                });
            }

            // 이 신청서의 추가결제 로그 (결제 완료·환급·취소)
            if (! empty($paymentIds)) {
                $q->orWhere(function (Builder $sub) use ($paymentIds) {
                    $sub->where('loggable_type', ClaimPayment::class)
                        ->whereIn('loggable_id', $paymentIds);
                });
            }

            // 이 신청서의 첨부 로그 (업로드·삭제)
            if (! empty($imageIds)) {
                $q->orWhere(function (Builder $sub) use ($imageIds) {
                    $sub->where('loggable_type', ClaimImage::class)
                        ->whereIn('loggable_id', $imageIds);
                });
            }
        });

        // 활동 로그는 변경 내역(mediumText)을 리소스가 그대로 노출하므로 컬럼은 좁히지 않고
        // 지연 조인으로 넓은 컬럼을 읽는 행 수만 이번 페이지 분량으로 고정한다.
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: [['column' => 'created_at', 'direction' => $sortOrder]],
            perPage: $perPage,
            resultCap: PaginationLimits::resultCap('admin.activity_logs'),
        );
    }
}
