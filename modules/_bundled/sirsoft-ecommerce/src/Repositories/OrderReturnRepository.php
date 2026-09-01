<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Support\Query\PaginationLimits;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Repositories\Concerns\FiltersAdminClaimList;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;

/**
 * 반품 신청서 리포지토리 구현체
 */
class OrderReturnRepository implements OrderReturnRepositoryInterface
{
    use FiltersAdminClaimList;

    /**
     * @param  OrderReturn  $model  반품 신청서 모델
     */
    public function __construct(
        protected OrderReturn $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderReturn
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(OrderReturn $return, array $data): OrderReturn
    {
        $return->update($data);

        return $return->refresh();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $relations = []): ?OrderReturn
    {
        return $this->model->newQuery()
            ->with($relations)
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByNumber(string $returnNumber, array $relations = []): ?OrderReturn
    {
        return $this->model->newQuery()
            ->with($relations)
            ->where('return_number', $returnNumber)
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
    public function listPendingWithoutRefundByOrderId(
        int $orderId,
        array $excludeIds = [],
        array $relations = [],
    ): Collection {
        $finalValues = array_map(
            fn (ReturnStatusEnum $status) => $status->value,
            ReturnStatusEnum::finalStatuses(),
        );

        $query = $this->model->newQuery()
            ->with($relations)
            ->where('order_id', $orderId)
            ->whereNull('refund_id')
            ->whereNotIn('return_status', $finalValues);

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findForUpdate(int $id): ?OrderReturn
    {
        return $this->model->newQuery()
            ->lockForUpdate()
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function sumActiveQuantityByOrderOption(int $orderOptionId, ?int $excludeReturnId = null): int
    {
        $finalValues = array_map(
            fn (ReturnStatusEnum $status) => $status->value,
            ReturnStatusEnum::finalStatuses(),
        );

        $query = OrderReturnOption::query()
            ->where('order_option_id', $orderOptionId)
            ->whereNotIn('option_status', $finalValues);

        if ($excludeReturnId !== null) {
            $query->where('order_return_id', '!=', $excludeReturnId);
        }

        return (int) $query->sum('return_quantity');
    }

    /**
     * {@inheritDoc}
     */
    public function countByReasonCode(string $reasonCode): int
    {
        return $this->model->newQuery()
            ->where('reason_code', $reasonCode)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function countByCarrierId(int $carrierId): int
    {
        return $this->model->newQuery()
            ->where('collection_carrier_id', $carrierId)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForUser(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            // 목록이 직렬화하는 것만 싣는다 (형제 저장소와 같은 근거 — 첨부는 상세 소관,
            // 주문 배송지는 이 목록의 어느 칸도 읽지 않는다).
            ->with(['returnOptions.orderOption', 'order', 'collectionCarrier'])
            ->whereHas('order', fn ($q) => $q->where('user_id', $userId));

        if (! empty($filters['status'])) {
            $query->where('return_status', $filters['status']);
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

        return $query->orderByDesc('id')->paginate($perPage, [(new OrderReturn)->getTable().'.*']);
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // 목록이 직렬화하는 것만 싣는다 — `order` 와 미수납 판정용 `claimPayments`.
        // 상품 요약은 헤더 `items_snapshot` 이 담당한다 (목록 응답의 하위 컬렉션 규율).
        $query = $this->model->newQuery()->with(['order.shippingAddress', 'claimPayments']);

        $this->applyAdminClaimFilters($query, $filters);

        // 컬럼도 목록(`ClaimAdminListResource`)이 실제로 읽는 것만 명시한다 — 주소·배송비
        // 스냅샷 JSON, 반려 사유, 관리자 메모 등은 상세(`ClaimResource`) 소관이다.
        return $query->orderByDesc('id')->paginate($perPage, [
            'id', 'order_id', 'return_number', 'return_status', 'requested_by',
            'reason_code', 'fault_type', 'charge_method', 'total_charge_amount',
            'currency_code', 'items_snapshot', 'created_at', 'completed_at',
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
     * @return array{status: string, number: string, requester: string, reason: string, fault: ?string, charge: ?string, items_relation: string} 컬럼 대응표
     */
    protected function adminClaimColumns(): array
    {
        return [
            'status' => 'return_status',
            'number' => 'return_number',
            'requester' => 'requested_by',
            'reason' => 'reason_code',
            'fault' => 'fault_type',
            'charge' => 'charge_method',
            'items_relation' => 'returnOptions',
            'amount_columns' => ['total_charge_amount'],
            'amount_relation' => null,
            'currency' => 'currency_code',
            'charge_payment_relation' => 'claimPayments',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function findOriginalOptionStatus(int $returnId, int $orderOptionId): ?string
    {
        $finalValues = array_map(
            fn (ReturnStatusEnum $status) => $status->value,
            ReturnStatusEnum::finalStatuses(),
        );

        $row = OrderReturnOption::query()
            ->where('order_return_id', $returnId)
            ->where('order_option_id', $orderOptionId)
            ->whereNotIn('option_status', $finalValues)
            ->first(['original_option_status']);

        if (! $row || $row->original_option_status === null) {
            return null;
        }

        return $row->original_option_status instanceof OrderStatusEnum
            ? $row->original_option_status->value
            : (string) $row->original_option_status;
    }

    /**
     * {@inheritDoc}
     */
    public function hasRecordedCurrency(string $code): bool
    {
        return $this->model->newQuery()->where('currency_code', $code)->exists();
    }
}
