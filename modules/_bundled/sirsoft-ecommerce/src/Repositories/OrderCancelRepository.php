<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Support\Query\PaginationLimits;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Repositories\Concerns\FiltersAdminClaimList;
use Modules\Sirsoft\Ecommerce\Repositories\Concerns\PaginatesBuyerClaimList;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;

/**
 * 주문 취소 리포지토리 구현체
 */
class OrderCancelRepository implements OrderCancelRepositoryInterface
{
    use FiltersAdminClaimList;
    use PaginatesBuyerClaimList;

    /**
     * @param  OrderCancel  $model  주문 취소 모델
     */
    public function __construct(
        protected OrderCancel $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderCancel
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function markReactivated(int $cancelId, int $quantity, CarbonInterface $at): void
    {
        $this->model->newQuery()->whereKey($cancelId)->update([
            'reactivated_quantity' => DB::raw('reactivated_quantity + '.(int) $quantity),
            'reactivated_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function latestByOrderId(int $orderId): ?OrderCancel
    {
        return $this->model->newQuery()
            ->where('order_id', $orderId)
            ->latest('id')
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function countByCancelReasonType(string $reasonCode): int
    {
        return $this->model->newQuery()
            ->where('cancel_reason_type', $reasonCode)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function findByNumber(string $cancelNumber, array $relations = []): ?OrderCancel
    {
        return $this->model->newQuery()
            ->with($relations)
            ->where('cancel_number', $cancelNumber)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $relations = []): ?OrderCancel
    {
        return $this->model->newQuery()
            ->with($relations)
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function listByOrderId(int $orderId, array $relations = []): Collection
    {
        // 건수가 주문의 상품 수에 묶인다는 전제는 성립하지 않는다 — 이 조회에는 상태 필터가 없고
        // 철회는 행을 남기므로(수량 잠금만 풀린다) 같은 주문에서 신청↔철회를 반복하면 계속 쌓인다.
        // 그래서 코어 단일 해석의 결과 상한을 안전 상한으로 건다(실사용 건수는 그 훨씬 아래다).
        $cap = PaginationLimits::resultCap('ecommerce.claims');

        return $this->model->newQuery()
            ->with($relations)
            ->where('order_id', $orderId)
            ->orderByDesc('id')
            ->when($cap !== null, fn ($q) => $q->limit($cap))
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForUser(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->buyerClaimListQuery()
            ->whereHas('order', fn ($q) => $q->where('user_id', $userId));

        return $this->paginateBuyerClaimList($query, $filters, $perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForOrder(int $orderId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->buyerClaimListQuery()->where('order_id', $orderId);

        return $this->paginateBuyerClaimList($query, $filters, $perPage);
    }

    /**
     * {@inheritDoc}
     */
    protected function buyerClaimListQuery(): Builder
    {
        return $this->model->newQuery()
            // 목록이 직렬화하는 것만 싣는다. 취소 이력의 환불액은 연결된 환불신청서가 들고
            // 있고 목록이 그 값을 그리므로 `refund` 는 남긴다 — 주문 배송지는 읽지 않는다.
            ->with(['cancelOptions.orderOption', 'refund', 'order']);
    }

    /**
     * {@inheritDoc}
     */
    protected function buyerClaimStatusColumn(): string
    {
        return 'cancel_status';
    }

    /**
     * {@inheritDoc}
     */
    protected function buyerClaimListColumns(): array
    {
        return [(new OrderCancel)->getTable().'.*'];
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // 취소는 환불 정보 표기를 위해 `refund` 를 함께 싣는다. 상품 요약은 헤더
        // `items_snapshot` 이 담당한다 (목록 응답의 하위 컬렉션 규율).
        $query = $this->model->newQuery()->with(['order.shippingAddress', 'refund']);

        $this->applyAdminClaimFilters($query, $filters);

        // 컬럼도 목록(`ClaimAdminListResource`)이 실제로 읽는 것만 명시한다 — 배송
        // 스냅샷 JSON 과 사유 상세 텍스트는 상세 화면 소관이다.
        return $query->orderByDesc('id')->paginate($perPage, [
            'id', 'order_id', 'cancel_number', 'cancel_type', 'cancel_status',
            'cancel_reason_type', 'cancelled_by', 'items_snapshot', 'created_at', 'cancelled_at',
            // 재활성 배지(Q29) — 빠뜨리면 목록에서 언제나 null 이다
            'reactivated_at', 'reactivated_quantity',
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function countForAdmin(array $filters = []): int
    {
        $query = $this->model->newQuery();

        $this->applyAdminClaimFilters($query, $filters);

        return $query->count();
    }

    /**
     * 관리자 목록 필터의 컬럼 대응표를 반환합니다.
     *
     * 취소에는 귀책·청구 축이 없고, 신청자·사유 컬럼명이 다른 세 유형과 다르다
     * (`cancelled_by` / `cancel_reason_type`).
     *
     * @return array{status: string, number: string, requester: string, reason: string, fault: ?string, charge: ?string, items_relation: string} 컬럼 대응표
     */
    protected function adminClaimColumns(): array
    {
        return [
            'status' => 'cancel_status',
            'number' => 'cancel_number',
            'requester' => 'cancelled_by',
            'reason' => 'cancel_reason_type',
            'fault' => null,
            'charge' => null,
            'items_relation' => 'cancelOptions',
            // 취소는 자기 테이블에 금액 컬럼이 없다 — 목록도 환불 관계에서 가져온다.
            'amount_columns' => ['refund_amount', 'refund_points_amount'],
            'amount_relation' => 'refund',
            'currency' => null,
            'charge_payment_relation' => null,
        ];
    }
}
