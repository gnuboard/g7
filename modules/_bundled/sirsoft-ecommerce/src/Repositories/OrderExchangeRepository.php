<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Repositories\Concerns\FiltersAdminClaimList;
use Modules\Sirsoft\Ecommerce\Repositories\Concerns\PaginatesBuyerClaimList;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;

/**
 * 교환 신청서 리포지토리 구현체
 */
class OrderExchangeRepository implements OrderExchangeRepositoryInterface
{
    use FiltersAdminClaimList;
    use PaginatesBuyerClaimList;

    /**
     * @param  OrderExchange  $model  교환 신청서 모델
     */
    public function __construct(
        protected OrderExchange $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderExchange
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(OrderExchange $exchange, array $data): OrderExchange
    {
        $exchange->update($data);

        return $exchange->refresh();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $relations = []): ?OrderExchange
    {
        return $this->model->newQuery()
            ->with($relations)
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByNumber(string $exchangeNumber, array $relations = []): ?OrderExchange
    {
        return $this->model->newQuery()
            ->with($relations)
            ->where('exchange_number', $exchangeNumber)
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
    public function listPendingByOrderId(int $orderId, array $excludeIds = []): Collection
    {
        $finalValues = array_map(
            fn (ExchangeStatusEnum $status) => $status->value,
            ExchangeStatusEnum::finalStatuses(),
        );

        $query = $this->model->newQuery()
            ->where('order_id', $orderId)
            ->whereNotIn('exchange_status', $finalValues);

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findForUpdate(int $id): ?OrderExchange
    {
        return $this->model->newQuery()
            ->lockForUpdate()
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function sumActiveQuantityByOrderOption(int $orderOptionId, ?int $excludeExchangeId = null): int
    {
        $finalValues = array_map(
            fn (ExchangeStatusEnum $status) => $status->value,
            ExchangeStatusEnum::finalStatuses(),
        );

        $query = OrderExchangeOption::query()
            ->where('order_option_id', $orderOptionId)
            ->whereNotIn('option_status', $finalValues);

        if ($excludeExchangeId !== null) {
            $query->where('order_exchange_id', '!=', $excludeExchangeId);
        }

        return (int) $query->sum('exchange_quantity');
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
            fn (ExchangeStatusEnum $status) => $status->value,
            ExchangeStatusEnum::finalStatuses(),
        );

        return OrderExchangeOption::query()
            ->whereIn('order_option_id', array_values(array_unique(array_map('intval', $orderOptionIds))))
            ->whereNotIn('option_status', $finalValues)
            ->groupBy('order_option_id')
            ->selectRaw('order_option_id, SUM(exchange_quantity) as locked')
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
        // 송장은 상품 행에 있다(R12) — 그 택배사를 회수·재발송 어느 방향으로든 쓴 행이 하나라도 있는
        // 교환 신청서를 센다.
        return $this->model->newQuery()
            ->whereHas('exchangeOptions', fn ($query) => $query
                ->where('collection_carrier_id', $carrierId)
                ->orWhere('redelivery_carrier_id', $carrierId))
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
            // 목록이 직렬화하는 것만 싣는다 (목록 응답의 하위 컬렉션 규율). 첨부(`images`)는
            // 신청내역 목록이 그리지 않는다 — 사진은 상세의 사유 카드에서만 보이므로 행마다
            // 전량 직렬화하면 한 페이지가 신청서 수 × 첨부 수만큼 부풀기만 한다.
            // 주문 배송지(`order.shippingAddress`)도 이 목록의 어느 칸도 읽지 않는다.
            // 송장은 상품 행에 있다 — 행의 택배사를 함께 싣는다(운송장 줄·조회 링크).
            ->with(['exchangeOptions.orderOption', 'exchangeOptions.collectionCarrier', 'exchangeOptions.redeliveryCarrier', 'order']);
    }

    /**
     * {@inheritDoc}
     */
    protected function buyerClaimStatusColumn(): string
    {
        return 'exchange_status';
    }

    /**
     * {@inheritDoc}
     */
    protected function buyerClaimListColumns(): array
    {
        return [(new OrderExchange)->getTable().'.*'];
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // 목록이 직렬화하는 것만 싣는다 — `order`(주문번호·주문자명·통화 스냅샷)와
        // 미수납 판정용 `claimPayments`. 상품 요약은 헤더 `items_snapshot` 이 담당하므로
        // 상품 행·이미지 관계는 싣지 않는다 (목록 응답의 하위 컬렉션 규율).
        // 상품 행은 운송장 요약·회수 지연·환불 미접수 판정이 읽는 칸만 싣는다(송장은 행에 있다).
        $query = $this->model->newQuery()->with(['order.shippingAddress', 'claimPayments', 'exchangeOptions:id,order_exchange_id,order_option_id,option_status,exchange_quantity,price_difference,collection_carrier_id,collection_tracking_number,collected_at,unreturned_at,redelivery_carrier_id,redelivery_tracking_number,redelivered_at,completed_at',
            // 환불 진행 배지(보완 5) — 출처 축, 페이지당 한 번.
            'refunds:id,order_id,refund_source_type,refund_source_id,refund_number,refund_status'])
            // 환불 미접수 뱃지(D9)의 근거 — 이 교환에서 파생된 살아 있는 차액 환불신청서 수.
            ->withLiveRefundCount();

        $this->applyAdminClaimFilters($query, $filters);

        // 컬럼도 목록(`ClaimAdminListResource`)이 실제로 읽는 것만 명시한다 — 주소·배송비
        // 스냅샷 JSON, 반려 사유, 관리자 메모 등은 상세(`ClaimResource`) 소관이다.
        return $query->orderByDesc('id')->paginate($perPage, [
            'id', 'order_id', 'exchange_number', 'exchange_status', 'requested_by', 'requested_by_admin',
            'reason_code', 'fault_type', 'charge_method', 'total_charge_amount', 'refund_product_amount',
            'currency_code', 'items_snapshot', 'created_at', 'completed_at',
            'exchange_mode', 'collection_due_at',
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
            'status' => 'exchange_status',
            'number' => 'exchange_number',
            'requester' => 'requested_by',
            'reason' => 'reason_code',
            'fault' => 'fault_type',
            'charge' => 'charge_method',
            'items_relation' => 'exchangeOptions',
            'amount_columns' => ['total_charge_amount'],
            'amount_relation' => null,
            'currency' => 'currency_code',
            'charge_payment_relation' => 'claimPayments',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function findOriginalOptionStatus(int $exchangeId, int $orderOptionId): ?string
    {
        $finalValues = array_map(
            fn (ExchangeStatusEnum $status) => $status->value,
            ExchangeStatusEnum::finalStatuses(),
        );

        $row = OrderExchangeOption::query()
            ->where('order_exchange_id', $exchangeId)
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
     * {@inheritDoc}
     */
    public function listOverdueCollectionCandidates(int $limit): Collection
    {
        $finalValues = array_map(
            fn (ExchangeStatusEnum $status) => $status->value,
            ExchangeStatusEnum::finalStatuses(),
        );

        return $this->model->newQuery()
            ->with(['exchangeOptions'])
            ->where('exchange_mode', ExchangeModeEnum::ADVANCE->value)
            ->whereNotNull('collection_due_at')
            ->where('collection_due_at', '<', now())
            ->whereNull('collection_overdue_notified_at')
            ->whereNotIn('exchange_status', $finalValues)
            ->orderBy('collection_due_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 교환 고유 필터 — 접수 출처(D8) · 회수 지연(선교환) · 환불 미접수(D9, 저가 차액)
     *
     * 회수 지연은 `ClaimTrackingGroups::overdueOf()` 와 같은 조건이다: 선교환 · 반송 기한 경과 · 끝나지 않은
     * 상품 중 회수 송장·도착·미회수 표시가 모두 없는 행이 있다. 환불 미접수는 완료된 저가 차액 행이 있는데
     * 이 교환에서 파생된 살아 있는 환불신청서가 없는 신청서다 — 금액 0 판정은 상세(`pendingRefundFor`)가 한다.
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

        $finalValues = array_map(fn (ExchangeStatusEnum $status) => $status->value, ExchangeStatusEnum::finalStatuses());

        if (! empty($filters['collection_overdue'])) {
            $query->where('exchange_mode', ExchangeModeEnum::ADVANCE->value)
                ->whereNotNull('collection_due_at')
                ->where('collection_due_at', '<', now())
                ->whereHas('exchangeOptions', fn ($q) => $q
                    ->whereNotIn('option_status', $finalValues)
                    ->whereNull('collection_tracking_number')
                    ->whereNull('collected_at')
                    ->whereNull('unreturned_at'));
        }

        if (! empty($filters['pending_refund'])) {
            // 판정은 모델 한 곳(`OrderExchange::pendingRefundItems` 와 그 쿼리판)이다.
            $query->pendingRefund();
        }
    }
}
