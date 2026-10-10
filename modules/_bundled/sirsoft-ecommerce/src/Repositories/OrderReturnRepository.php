<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Repositories\Concerns\FiltersAdminClaimList;
use Modules\Sirsoft\Ecommerce\Repositories\Concerns\PaginatesBuyerClaimList;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;

/**
 * 반품 신청서 리포지토리 구현체
 */
class OrderReturnRepository implements OrderReturnRepositoryInterface
{
    use FiltersAdminClaimList;
    use PaginatesBuyerClaimList;

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

        // 환불신청서는 완료 회차마다 상품 행에 연결된다(S7) — 살아 있는 환불이 연결되지 않은 상품 행이
        // 하나라도 남은 반품이 선점 대상이다. 연결된 몫은 그 환불신청서 쪽에서 센다(반려·철회된 신청서는 끊긴 연결).
        $query = $this->model->newQuery()
            ->with($relations)
            ->where('order_id', $orderId)
            ->whereHas('returnOptions', fn (Builder $options) => $options->withoutLiveRefund())
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
    public function sumActiveQuantitiesByOrderOptions(array $orderOptionIds): array
    {
        if ($orderOptionIds === []) {
            return [];
        }

        $finalValues = array_map(
            fn (ReturnStatusEnum $status) => $status->value,
            ReturnStatusEnum::finalStatuses(),
        );

        return OrderReturnOption::query()
            ->whereIn('order_option_id', array_values(array_unique(array_map('intval', $orderOptionIds))))
            ->whereNotIn('option_status', $finalValues)
            ->groupBy('order_option_id')
            ->selectRaw('order_option_id, SUM(return_quantity) as locked')
            ->pluck('locked', 'order_option_id')
            ->map(fn ($locked) => (int) $locked)
            ->all();
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
        // 송장은 상품 행에 있다(R12) — 그 택배사로 회수한 행이 하나라도 있는 반품 신청서를 센다.
        return $this->model->newQuery()
            ->whereHas('returnOptions', fn ($query) => $query->where('collection_carrier_id', $carrierId))
            ->count();
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
            // 목록이 직렬화하는 것만 싣는다 (형제 저장소와 같은 근거 — 첨부는 상세 소관,
            // 주문 배송지는 이 목록의 어느 칸도 읽지 않는다).
            // 송장은 상품 행에 있다 — 행의 택배사를 함께 싣는다(운송장 줄·조회 링크).
            ->with(['returnOptions.orderOption', 'returnOptions.collectionCarrier', 'order']);
    }

    /**
     * {@inheritDoc}
     */
    protected function buyerClaimStatusColumn(): string
    {
        return 'return_status';
    }

    /**
     * {@inheritDoc}
     */
    protected function buyerClaimListColumns(): array
    {
        return [(new OrderReturn)->getTable().'.*'];
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // 목록이 직렬화하는 것만 싣는다 — `order` 와 미수납 판정용 `claimPayments`.
        // 상품 요약은 헤더 `items_snapshot` 이 담당한다 (목록 응답의 하위 컬렉션 규율).
        // 상품 행은 운송장 요약·환불 미접수 판정이 읽는 칸만 싣는다(송장은 행에 있다).
        // 환불 미접수 뱃지(D9)는 행에 연결된 환불신청서의 상태까지 본다(반려·철회면 미연결로 센다).
        $query = $this->model->newQuery()->with(['order.shippingAddress', 'claimPayments', 'returnOptions:id,order_return_id,order_option_id,option_status,return_quantity,collection_carrier_id,collection_tracking_number,collected_at,refund_id,completed_at', 'returnOptions.refund:id,refund_status',
            // 환불 진행 배지(보완 5) — 출처 축, 페이지당 한 번. 외래키(refund_source_id)를 select 에 꼭 넣는다.
            'sourcedRefunds:id,order_id,refund_source_type,refund_source_id,refund_number,refund_status']);

        $this->applyAdminClaimFilters($query, $filters);

        // 컬럼도 목록(`ClaimAdminListResource`)이 실제로 읽는 것만 명시한다 — 주소·배송비
        // 스냅샷 JSON, 반려 사유, 관리자 메모 등은 상세(`ClaimResource`) 소관이다.
        return $query->orderByDesc('id')->paginate($perPage, [
            'id', 'order_id', 'return_number', 'return_status', 'requested_by', 'requested_by_admin',
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

    /**
     * 반품 고유 필터 — 접수 출처(D8) · 환불 미접수(D9)
     *
     * 환불 미접수는 완료된 상품 행 중 환불신청서가 연결되지 않았거나(`refund_id` 없음) 연결된 신청서가
     * 반려·철회된 행이 있는 신청서다(S7 — 회차별 접수). 금액 0 판정은 상세(`pendingRefundFor`)가 한다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  array  $filters  필터
     * @return void 쿼리를 제자리에서 수정한다
     */
    protected function applyAdminClaimTypeFilters(Builder $query, array $filters): void
    {
        if (($filters['request_source'] ?? null) === 'admin') {
            $query->whereNotNull('requested_by_admin');
        } elseif (($filters['request_source'] ?? null) === 'customer') {
            $query->whereNull('requested_by_admin');
        }

        if (! empty($filters['pending_refund'])) {
            // 판정은 모델 한 곳(`OrderReturn::pendingRefundItems` 와 그 쿼리판)이다.
            $query->pendingRefund();
        }
    }
}
