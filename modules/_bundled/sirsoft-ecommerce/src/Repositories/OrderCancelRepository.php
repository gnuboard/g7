<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Support\Query\PaginationLimits;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Repositories\Concerns\FiltersAdminClaimList;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;

/**
 * 주문 취소 리포지토리 구현체
 */
class OrderCancelRepository implements OrderCancelRepositoryInterface
{
    use FiltersAdminClaimList;

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
        $query = $this->model->newQuery()
            ->with(['cancelOptions.orderOption', 'refund', 'order'])
            ->whereHas('order', fn ($q) => $q->where('user_id', $userId));

        if (! empty($filters['status'])) {
            $query->where('cancel_status', $filters['status']);
        }

        // 기간 필터 — whereDate 는 컬럼에 DATE() 를 씌워 인덱스를 못 쓰게 만든다.
        // 같은 결과를 내는 범위 조건으로 바꿔 created_at 인덱스를 살린다
        // (종료일은 그날 23:59:59.999999 까지 포함해야 whereDate 와 동일한 경계를 갖는다).
        // 경계 해석은 저장소가 맡는다 — 요청이 문자열을 이어 붙이면 시각이 섞인 입력에서 규격 밖 값이 된다.
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->orderByDesc('id')->paginate($perPage, [(new OrderCancel)->getTable().'.*']);
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // 취소는 환불 정보 표기를 위해 `refund` 를 함께 싣는다. 상품 요약은 헤더
        // `items_snapshot` 이 담당한다 (목록 응답의 하위 컬렉션 규율).
        $query = $this->model->newQuery()->with(['order', 'refund']);

        $this->applyAdminClaimFilters($query, $filters);

        // 컬럼도 목록(`ClaimAdminListResource`)이 실제로 읽는 것만 명시한다 — 배송
        // 스냅샷 JSON 과 사유 상세 텍스트는 상세 화면 소관이다.
        return $query->orderByDesc('id')->paginate($perPage, [
            'id', 'order_id', 'cancel_number', 'cancel_type', 'cancel_status',
            'cancel_reason_type', 'cancelled_by', 'items_snapshot', 'created_at', 'cancelled_at',
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
        ];
    }
}
