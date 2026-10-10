<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Repositories\Concerns\FiltersAdminClaimList;
use Modules\Sirsoft\Ecommerce\Repositories\Concerns\PaginatesBuyerClaimList;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;

/**
 * 주문 환불 리포지토리 구현체
 */
class OrderRefundRepository implements OrderRefundRepositoryInterface
{
    use FiltersAdminClaimList;
    use PaginatesBuyerClaimList;

    /**
     * @param  OrderRefund  $model  주문 환불 모델
     */
    public function __construct(
        protected OrderRefund $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderRefund
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $relations = []): ?OrderRefund
    {
        return $this->model->newQuery()
            ->with($relations)
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function listBySource(string $sourceType, int $sourceId, array $relations = []): Collection
    {
        return $this->model->newQuery()
            ->with($relations)
            ->where('refund_source_type', $sourceType)
            ->where('refund_source_id', $sourceId)
            ->orderBy('id')
            ->get(); // audit:allow query-unbounded-get reason: 클레임 1건에서 파생된 환불신청서 목록 — 건수가 그 클레임의 처리 횟수에 묶인다
    }

    /**
     * {@inheritDoc}
     */
    public function listPendingByOrderId(int $orderId, array $excludeIds = []): Collection
    {
        $finalValues = array_map(
            fn (RefundStatusEnum $status) => $status->value,
            RefundStatusEnum::finalStatuses(),
        );

        $query = $this->model->newQuery()
            ->where('order_id', $orderId)
            ->whereNotIn('refund_status', $finalValues);

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByNumber(string $refundNumber, array $relations = []): ?OrderRefund
    {
        return $this->model->newQuery()
            ->with($relations)
            ->where('refund_number', $refundNumber)
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
    public function sumActiveDirectQuantityByOrderOption(int $orderOptionId, ?int $excludeRefundId = null): int
    {
        $finalValues = array_map(
            fn (RefundStatusEnum $status) => $status->value,
            RefundStatusEnum::finalStatuses(),
        );

        $liveDirectRefunds = $this->model->newQuery()
            ->select('id')
            ->whereIn('refund_source_type', RefundSourceTypeEnum::quantityOwningValues())
            ->whereNotIn('refund_status', $finalValues);

        if ($excludeRefundId !== null) {
            $liveDirectRefunds->where('id', '!=', $excludeRefundId);
        }

        return (int) OrderRefundOption::query()
            ->where('order_option_id', $orderOptionId)
            ->whereIn('order_refund_id', $liveDirectRefunds)
            // **행 단위 종결분도 뺀다.** 헤더가 아직 열려 있어도 그 안의 어떤 상품은 이미
            // 반려·철회로 끝났을 수 있다(상품 단위 판단). 그 수량을 계속 세면 고객은
            // "신청 가능 수량 부족" 만 보고, 왜 부족한지는 화면 어디에도 드러나지 않는다.
            ->whereNotIn('option_status', $finalValues)
            ->sum('quantity');
    }

    /**
     * {@inheritDoc}
     */
    public function sumActiveDirectQuantitiesByOrderOptions(array $orderOptionIds): array
    {
        if ($orderOptionIds === []) {
            return [];
        }

        $finalValues = array_map(
            fn (RefundStatusEnum $status) => $status->value,
            RefundStatusEnum::finalStatuses(),
        );

        $liveDirectRefunds = $this->model->newQuery()
            ->select('id')
            ->whereIn('refund_source_type', RefundSourceTypeEnum::quantityOwningValues())
            ->whereNotIn('refund_status', $finalValues);

        return OrderRefundOption::query()
            ->whereIn('order_option_id', array_values(array_unique(array_map('intval', $orderOptionIds))))
            ->whereIn('order_refund_id', $liveDirectRefunds)
            ->whereNotIn('option_status', $finalValues)
            ->groupBy('order_option_id')
            ->selectRaw('order_option_id, SUM(quantity) as locked')
            ->pluck('locked', 'order_option_id')
            ->map(fn ($locked) => (int) $locked)
            ->all();
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
        $query = $this->model->newQuery()
            // 목록이 직렬화하는 것만 싣는다 (형제 저장소와 같은 근거).
            ->with(['refundOptions.orderOption', 'order']);

        // 출처로 거르지 않는다(#599 후속 D6). 취소 발 환불도 환불 탭에 선다 — 취소신청서와
        // 환불신청서는 **다른 사실**을 기록하는 별개 문서이므로 한쪽이 다른 쪽을 대표하지
        // 않는다. 예전에는 취소 발을 여기서 뺐고, 그래서 결제완료 상품을 환불한 고객에게
        // 환불 이력이 아예 보이지 않았다(#599 제보의 그 화면).
        return $query;
    }

    /**
     * {@inheritDoc}
     */
    protected function buyerClaimStatusColumn(): string
    {
        return 'refund_status';
    }

    /**
     * {@inheritDoc}
     */
    protected function buyerClaimListColumns(): array
    {
        return [(new OrderRefund)->getTable().'.*'];
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // 환불 헤더에는 `items_snapshot` 이 없어 상품 요약을 상품 행 → 주문옵션에서 만든다.
        // 출처로 거르지 않는다(D6) — 목록의 출처 뱃지가 `고객 신청`/`운영자 접수`/`반품 발`
        // 을 구분해 보여 준다.
        $query = $this->model->newQuery()
            ->with(['order.shippingAddress', 'refundOptions.orderOption']);

        $this->applyAdminClaimFilters($query, $filters);

        // 컬럼도 목록(`ClaimAdminListResource`)이 실제로 읽는 것만 명시한다 — 계산 스냅샷
        // JSON 과 환불 계좌·PG 원문 같은 민감 컬럼은 목록 SELECT 에 싣지 않는다.
        return $query->orderByDesc('id')->paginate($perPage, [
            'id', 'order_id', 'refund_number', 'refund_status', 'requested_by',
            'reason_code', 'fault_type', 'refund_source_type',
            'refund_amount', 'refund_points_amount', 'created_at', 'refunded_at',
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function countForAdmin(array $filters = []): int
    {
        // 목록(`paginateForAdmin`)과 같은 술어를 쓴다 — 뱃지와 목록이 다른 수를 말하면
        // 운영자는 어느 쪽이 맞는지 알 수 없다.
        $query = $this->model->newQuery();

        $this->applyAdminClaimFilters($query, $filters);

        return $query->count();
    }

    /**
     * 관리자 목록 필터의 컬럼 대응표를 반환합니다.
     *
     * 환불 신청서에는 청구 방식 축이 없다 — 청구는 교환·반품의 추가결제 축이다.
     *
     * @return array{status: string, number: string, requester: string, reason: string, fault: ?string, charge: ?string, items_relation: string} 컬럼 대응표
     */
    protected function adminClaimColumns(): array
    {
        return [
            'status' => 'refund_status',
            'number' => 'refund_number',
            'requester' => 'requested_by',
            'reason' => 'reason_code',
            'fault' => 'fault_type',
            'charge' => null,
            'items_relation' => 'refundOptions',
            // 목록 '금액' 컬럼이 그리는 값과 같다 (환불액 + 환불 마일리지).
            'amount_columns' => ['refund_amount', 'refund_points_amount'],
            'amount_relation' => null,
            // 환불 테이블에는 통화 컬럼이 없다 — 주문의 결제 통화가 근거다.
            'currency' => null,
            'charge_payment_relation' => null,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function update(OrderRefund $refund, array $data): OrderRefund
    {
        $refund->update($data);

        return $refund->refresh();
    }

    /**
     * {@inheritDoc}
     */
    public function findForUpdate(int $id): ?OrderRefund
    {
        return $this->model->newQuery()
            ->lockForUpdate()
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function countByReasonCode(string $reasonCode): int
    {
        // 환불 카탈로그에서 고른 사유만 센다. 반품 발·교환 발 환불행은 자기 신청서의
        // `reason_code` 를 그대로 복사하므로 그 값의 출처는 반품/교환 카탈로그이고,
        // 코드 유일성이 `(type, code)` 쌍이라 같은 문자열이 유형 간 공존한다 — 스코프 없이
        // 세면 반품 한 건이 환불 카탈로그의 동명 사유를 잠근다(쓰인 적 없는 사유가 삭제·코드
        // 변경 영구 400). 취소 발은 이 컬럼을 채우지 않아 애초에 기여하지 않는다.
        return $this->model->newQuery()
            ->whereIn('refund_source_type', RefundSourceTypeEnum::quantityOwningValues())
            ->where('reason_code', $reasonCode)
            ->count();
    }

    /**
     * {@inheritdoc}
     */
    public function sumCompletedRefundAmountByMethod(int $orderId, RefundMethodEnum $method): float
    {
        return (float) OrderRefund::query()
            ->where('order_id', $orderId)
            ->where('refund_method', $method->value)
            ->where('refund_status', RefundStatusEnum::COMPLETED->value)
            ->sum('refund_amount');
    }

    /**
     * {@inheritdoc}
     */
    public function sumCompletedCashRefundForOrder(int $orderId, array $sourceValues): float
    {
        return (float) OrderRefund::query()
            ->where('order_id', $orderId)
            ->whereIn('refund_source_type', $sourceValues)
            ->where('refund_status', RefundStatusEnum::COMPLETED->value)
            ->sum('refund_amount');
    }

    /**
     * 환불 고유 필터 — 접수 출처 (운영자 대리 접수 = `refund_source_type = admin`)
     *
     * @param  Builder  $query  대상 쿼리
     * @param  array  $filters  필터
     * @return void 쿼리를 제자리에서 수정한다
     */
    protected function applyAdminClaimTypeFilters(Builder $query, array $filters): void
    {
        if (($filters['request_source'] ?? null) === 'admin') {
            $query->where('refund_source_type', RefundSourceTypeEnum::ADMIN->value);
        } elseif (($filters['request_source'] ?? null) === 'customer') {
            $query->where('refund_source_type', '!=', RefundSourceTypeEnum::ADMIN->value);
        }
    }
}
