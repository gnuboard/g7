<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Support\Query\PaginationLimits;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Repositories\Concerns\FiltersAdminClaimList;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;

/**
 * 주문 환불 리포지토리 구현체
 */
class OrderRefundRepository implements OrderRefundRepositoryInterface
{
    use FiltersAdminClaimList;

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
            ->where('refund_source_type', RefundSourceTypeEnum::DIRECT->value)
            ->whereNotIn('refund_status', $finalValues);

        if ($excludeRefundId !== null) {
            $liveDirectRefunds->where('id', '!=', $excludeRefundId);
        }

        return (int) OrderRefundOption::query()
            ->where('order_option_id', $orderOptionId)
            ->whereIn('order_refund_id', $liveDirectRefunds)
            ->sum('quantity');
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForUser(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->with(['refundOptions.orderOption', 'images', 'order.shippingAddress'])
            ->whereHas('order', fn ($q) => $q->where('user_id', $userId));

        // 취소 발 제외 — 출처 미상(NULL) 행은 직접 환불로 본다 (판정 단일 출처)
        OrderRefund::applyNonCancelOrigin($query);

        if (! empty($filters['status'])) {
            $query->where('refund_status', $filters['status']);
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

        return $query->orderByDesc('id')->paginate($perPage, [(new OrderRefund)->getTable().'.*']);
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // 환불 헤더에는 `items_snapshot` 이 없어 상품 요약을 상품 행 → 주문옵션에서 만든다.
        // 취소 발 환불은 유저 목록과 같은 이유로 뺀다 — 취소 서브탭과 이중 노출된다.
        $query = $this->model->newQuery()
            ->with(['order.shippingAddress', 'refundOptions.orderOption']);

        OrderRefund::applyNonCancelOrigin($query);

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
        $query = $this->model->newQuery();

        OrderRefund::applyNonCancelOrigin($query);

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
            ->where('refund_source_type', RefundSourceTypeEnum::DIRECT->value)
            ->where('reason_code', $reasonCode)
            ->count();
    }
}
