<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Helpers\TimezoneHelper;
use App\Models\ActivityLog;
use App\Models\User;
use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Repositories\Concerns\SortsByRelatedColumn;
use App\Support\Query\PaginationLimits;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\TaxInvoiceStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\OrderTaxInvoice;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;

/**
 * 주문 Repository 구현체
 */
class OrderRepository implements OrderRepositoryInterface
{
    /**
     * 주문 통계 캐시 키
     */
    private const STATISTICS_CACHE_KEY = 'ecommerce.orders.statistics';

    /**
     * 주문 통계 캐시 태그
     */
    private const STATISTICS_CACHE_TAG = 'ecommerce.orders';

    use FiltersByDateRange;
    use PaginatesWithDeferredJoin;
    use ResolvesSortSpec;
    use SortsByRelatedColumn;

    /**
     * 주문 목록이 실제로 사용하는 컬럼
     *
     * `ecommerce_orders` 는 스냅샷 mediumText 5종 + 관리자 메모 + 통화별 금액 text 16종을 갖는다.
     * 목록에서 쓰지 않는 이 컬럼들까지 읽으면 뒤쪽 페이지에서 건너뛸 행의 넓은 컬럼까지 함께
     * 읽혀 비용이 선형으로 커진다. 관리자 목록(OrderListResource)과 회원 주문내역
     * (UserOrderListResource)이 참조하는 컬럼의 합집합만 남긴다.
     *
     * @var array<int, string>
     */
    public const LIST_COLUMNS = [
        'id',
        'user_id',
        'order_number',
        'order_status',
        'order_device',
        'is_first_order',
        'currency',
        'currency_snapshot',
        'total_amount',
        'total_shipping_amount',
        'total_paid_amount',
        'total_cancelled_amount',
        'total_refunded_amount',
        'total_points_used_amount',
        'total_earned_points_amount',
        'mc_total_amount',
        'mc_total_shipping_amount',
        'ordered_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * 주문 목록 정렬 허용 컬럼
     *
     * 요청 값을 그대로 orderBy 에 넘기면 없는 컬럼으로 SQL 오류가 나거나 인덱스 없는 넓은
     * 컬럼 정렬을 강제할 수 있다.
     *
     * 이 목록은 OrderListRequest 의 `sort_by` `in:` 규칙(ordered_at·paid_at·total_amount)
     * 보다 넓다. 그 규칙은 `HookManager::applyFilters` 로 확장에 열려 있어 확장이 정렬
     * 컬럼을 늘릴 수 있고, 그때 이 목록이 더 좁으면 게이트를 통과한 정렬이 조용히 기본
     * 정렬로 되돌아간다. 게이트보다 좁게 두지 않는다
     * (service-repository.md "정렬 컬럼 화이트리스트").
     *
     * 다만 created_at·total_shipping_amount·total_paid_amount 는 인덱스가 없어, 확장이
     * 게이트를 넓혀 이 컬럼으로 정렬시키면 지연 조인의 inner 도 전체 스캔이 된다.
     * 그 경우 인덱스를 함께 추가해야 한다.
     *
     * @var array<int, string>
     */
    private const SORTABLE_COLUMNS = [
        'id',
        'order_number',
        'order_status',
        'total_amount',
        'total_shipping_amount',
        'total_paid_amount',
        'ordered_at',
        'paid_at',
        'created_at',
    ];

    /**
     * 관계 테이블 컬럼 기준 허용 정렬 (`SortsByRelatedColumn`)
     *
     * 발송일은 주문이 아니라 배송 테이블에 있고 한 주문에 배송 행이 여러 건일 수 있다.
     * 상관 서브쿼리로 정렬하므로 원 행 수가 바뀌지 않아 총 건수·페이지 경계가 유지된다.
     * `ecommerce_order_shippings(order_id, shipped_at)` 복합 인덱스가 전제다.
     *
     * @var array<string, array{model: class-string, foreign_key: string, column: string}>
     */
    private const RELATED_SORTABLE_COLUMNS = [
        'shipped_at' => [
            'model' => OrderShipping::class,
            'foreign_key' => 'order_id',
            'column' => 'shipped_at',
        ],
    ];

    /**
     * @param  Order  $model  주문 모델
     */
    public function __construct(
        protected Order $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function existsAny(): bool
    {
        // 소프트삭제 포함 — 삭제된 주문도 과거 base 로 생성된 이력이라 base 잠금 유지가 안전(A2)
        return $this->model->newQuery()->withTrashed()->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function find(int $id): ?Order
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findWithShippingAddress(int $id): ?Order
    {
        return $this->model->with('shippingAddress')->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findForUpdate(int $id): ?Order
    {
        return $this->model->newQuery()
            ->lockForUpdate()
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findForUpdateOrFail(int $id): Order
    {
        return $this->model->newQuery()
            ->lockForUpdate()
            ->findOrFail($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdForUpdate(int $id): ?Order
    {
        return $this->findForUpdate($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findWithRelations(int $id): ?Order
    {
        return $this->model
            ->with([
                'user',
                // 옵션별 실제 구매 적립 발행 여부를 집계 컬럼(purchase_earn_transactions_exists)으로 함께 로드 (N+1 회피)
                'options' => fn ($q) => $q->withExists('purchaseEarnTransactions'),
                'options.product',
                'options.shippings',
                'options.shippings.carrier',
                'options.review',
                'shippingAddress',
                'billingAddress',
                'payment',
                'payments',
                'shippings',
                // 취소 이력 — 주문상세 화면의 취소 사유/일시 표시용 (최근 취소 먼저)
                'cancels' => fn ($q) => $q->latest('cancelled_at'),
                // 현금영수증 이력 — 주문상세의 발급 카드가 활성 영수증 1건과 전체 이력을 함께 표시한다.
                // 미로드 시 OrderResource 의 whenLoaded 가드로 응답에 키 자체가 나타나지 않는다.
                'cashReceipts',
                // 세금계산서 — 취소 뒤에도 발급 상태로 남은 계산서가 있는지 판정하는 근거(P15).
                // 상태 컬럼만 있으면 되므로 좁혀 싣는다. 목록 경로는 이 관계를 적재하지 않으므로
                // 그 응답에는 `tax_invoice_needs_review` 키가 아예 나오지 않는다(N+1 회피).
                'taxInvoices' => fn ($q) => $q->select(['id', 'order_id', 'invoice_status']),
            ])
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByOrderNumber(string $orderNumber): ?Order
    {
        return $this->model
            ->with([
                'user',
                'options',
                'shippingAddress',
                'payment',
                // 비회원 주문상세도 현금영수증 카드를 렌더하므로 함께 로드한다.
                'cashReceipts',
            ])
            ->where('order_number', $orderNumber)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        // 관계는 지연 조인의 outer 에서만 로드한다 (inner 에 붙으면 관계 쿼리가 두 번 실행되고,
        // inner 는 키 컬럼만 조회하므로 eager load 가 성립하지도 않는다).
        // 권한 스코프 필터링 — 건수만 세는 경로(countWithListFilters)와 같은 출발 쿼리
        $query = $this->newScopedListQuery();

        // 목록 필터는 단일 출처(applyListFilters)에서 적용한다 — 엑셀 내려받기
        // (`getForExport`)가 같은 메서드를 부르므로, 축이 늘어도 두 경로가 함께 따라온다.
        $this->applyListFilters($query, $filters);

        // 정렬 — 요청 값은 허용 목록으로만 해석한다.
        // 발송일(shipped_at)은 배송 테이블에 있어 상관 서브쿼리 정렬로 해석된다.
        $sort = $this->resolveSortSpecWithRelated(
            $filters,
            self::SORTABLE_COLUMNS,
            self::RELATED_SORTABLE_COLUMNS,
            $this->model,
            'ordered_at',
        );

        // 마이페이지 주문내역은 한 주문의 아이템을 전부 나열하므로 옵션 전량이 필요하다(실측:
        // `partials/mypage/orders/_list.json` 의 `order.items` 순회). 관리자 목록은 대표 상품
        // 1건과 "외 N건" 만 그리므로 대표 1건 + 집계로 충분하다.
        $withItems = ! empty($filters['with_items']);

        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: self::LIST_COLUMNS,
            sort: $sort,
            perPage: $perPage,
            relations: array_merge([
                'user',
                $withItems ? 'options' : 'firstOption',
                'shippingAddress',
                'payment',
                // 목록은 대표 배송 1건의 표시 정보만 그린다. 택배사 이름까지 함께 로드해야
                // Resource 가 행마다 carrier 를 다시 조회하지 않는다.
                'firstShipping.carrier',
                // 클레임 요약(D22)은 목록이 항상 그리는 값이라 무조건 싣는다. 관계는 컬럼을
                // 프루닝했고 건수는 DB 집계라, 실린 것은 행이 실제로 그리는 배지뿐이다
                // (공개 #76 이 금지한 것은 "화면이 그리지 않는" 하위 컬렉션의 전량 직렬화다).
                //
                // 종전에는 `with_claim_summary` 요청에만 실었는데, 컬럼 표시(브라우저 기억)와
                // 값 수신(주소)이 서로 다른 근거를 갖게 되어 어긋났다 — 컬럼을 한 번 켠
                // 운영자의 다음 진입에서 컬럼은 보이는데 전 행이 "-" 가 됐다. 근거를 하나로
                // 되돌려 그 어긋남 자체를 없앤다.
            ], $this->claimSummaryRelations()),
            withCount: array_merge(
                $withItems ? [] : [
                    // "외 N건" 표기용 전체 옵션 수.
                    'options',
                    // 부분취소 뱃지 판정용 — 취소 옵션이 있으면서 남은 옵션도 있는 상태.
                    // 컬렉션을 순회하지 않고 DB 집계 두 개로 같은 판정을 얻는다.
                    'options as cancelled_options_count' => fn ($optionQuery) => $optionQuery->where(
                        'option_status',
                        OrderStatusEnum::CANCELLED->value,
                    ),
                ],
                $this->claimSummaryCounts(),
            ),
            resultCap: PaginationLimits::resultCap('admin.orders'),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Order
    {
        $order = $this->model->create($data);
        $this->forgetStatisticsCache();

        return $order;
    }

    /**
     * {@inheritDoc}
     */
    public function update(Order $order, array $data): Order
    {
        $order->update($data);
        $this->forgetStatisticsCache();

        return $order->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(Order $order): bool
    {
        $deleted = $order->delete();
        $this->forgetStatisticsCache();

        return $deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(array $ids, string $status): int
    {
        $affected = $this->model
            ->whereIn('id', $ids)
            ->update([
                'order_status' => $status,
                'updated_at' => now(),
            ]);

        $this->forgetStatisticsCache();

        return $affected;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateShipping(array $ids, ?int $courierId, ?string $trackingNumber): int
    {
        $updatedCount = 0;

        DB::transaction(function () use ($ids, $courierId, $trackingNumber, &$updatedCount) {
            $orders = $this->model->with(['shippings', 'options'])->whereIn('id', $ids)->get();

            foreach ($orders as $order) {
                $updateData = [];

                if ($courierId !== null) {
                    $updateData['carrier_id'] = $courierId;
                }
                if ($trackingNumber !== null) {
                    $updateData['tracking_number'] = $trackingNumber;
                    $updateData['shipping_status'] = ShippingStatusEnum::SHIPPED->value;
                    $updateData['shipped_at'] = now();
                }

                if (empty($updateData)) {
                    continue;
                }

                if ($order->shippings->isNotEmpty()) {
                    // 기존 shipping 레코드 업데이트
                    foreach ($order->shippings as $shipping) {
                        $shipping->update($updateData);
                    }
                } else {
                    // shipping 레코드 미존재 시 옵션별로 생성
                    foreach ($order->options as $option) {
                        OrderShipping::create(array_merge([
                            'order_id' => $order->id,
                            'order_option_id' => $option->id,
                            'shipping_status' => ShippingStatusEnum::PENDING->value,
                            'shipping_type' => 'parcel',
                        ], $updateData));
                    }
                }

                $updatedCount++;
            }
        });

        return $updatedCount;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateOptionStatus(array $ids, string $status): int
    {
        return $this->optionStatusSyncQuery($ids)->update([
            'option_status' => $status,
            'updated_at' => now(),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function collectOptionStatusSyncChanges(array $ids, string $status): array
    {
        // 전/후를 적으려면 갱신 **전** 상태를 읽어야 한다. 이 경로는 관계 빌더의 raw update
        // 라 `OrderOptionService` 의 어떤 훅도 지나지 않아, 관리자 주문 수정·일괄 상태변경이
        // 끌고 간 상품 전이가 활동로그에 **한 줄도 남지 않았다**(A1·A2). 주문 헤더 줄만
        // 남으므로 운영자는 어느 상품이 함께 움직였는지 알 수 없다.
        return $this->optionStatusSyncQuery($ids)
            ->get(['id', 'order_id', 'option_status'])
            ->map(fn (OrderOption $option): array => [
                'order_option_id' => (int) $option->id,
                'order_id' => (int) $option->order_id,
                'previous' => $option->option_status instanceof OrderStatusEnum
                    ? $option->option_status->value
                    : (string) $option->option_status,
                'new' => $status,
            ])
            ->filter(fn (array $change): bool => $change['previous'] !== $change['new'])
            ->values()
            ->all();
    }

    /**
     * 주문 옵션 상태 동기화의 대상 조건을 만듭니다.
     *
     * 취소/환불·클레임 등 별도 라이프사이클 옵션은 동기화에서 제외한다
     * (취소된 옵션이 결제완료/배송중 등으로 되살아나는 것을 차단 — OrderStatusEnum SSoT).
     * 읽기(`collectOptionStatusSyncChanges`)와 쓰기(`bulkUpdateOptionStatus`)가 이 한 조건을
     * 공유해야 기록된 전/후와 실제로 옮겨진 행이 어긋나지 않는다.
     *
     * @param  array<int, int>  $ids  주문 ID 배열
     * @return Builder 대상 옵션 쿼리
     */
    private function optionStatusSyncQuery(array $ids): Builder
    {
        return OrderOption::whereIn('order_id', $ids)
            ->whereNotIn('option_status', OrderStatusEnum::syncExcludedValues());
    }

    /**
     * {@inheritDoc}
     */
    public function deleteClaimItemRows(int $orderId): int
    {
        $deleted = 0;

        $deleted += OrderExchangeOption::query()
            ->whereIn('order_exchange_id', OrderExchange::query()->where('order_id', $orderId)->select('id'))
            ->delete();
        $deleted += OrderReturnOption::query()
            ->whereIn('order_return_id', OrderReturn::query()->where('order_id', $orderId)->select('id'))
            ->delete();
        $deleted += OrderCancelOption::query()
            ->whereIn('order_cancel_id', OrderCancel::query()->where('order_id', $orderId)->select('id'))
            ->delete();
        $deleted += OrderRefundOption::query()
            ->whereIn('order_refund_id', OrderRefund::query()->where('order_id', $orderId)->select('id'))
            ->delete();

        return $deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function getStatistics(): array
    {
        // 이 통계는 주문 목록 화면과 함께 매 페이지 조회된다. 값이 초 단위로 달라질 필요는
        // 없으므로 짧게 캐시해 같은 집계가 페이지를 넘길 때마다 다시 돌지 않게 한다.
        // 주문이 바뀌면 즉시 무효화되므로 화면이 낡은 수치를 보여 주지 않는다.
        if (! g7_core_settings('cache.stats_enabled', true)) {
            return $this->computeStatistics();
        }

        $ttl = (int) g7_core_settings('cache.stats_ttl', 1800);

        if ($ttl <= 0) {
            return $this->computeStatistics();
        }

        return app(CacheInterface::class)->remember(
            self::STATISTICS_CACHE_KEY,
            fn () => $this->computeStatistics(),
            $ttl,
            [self::STATISTICS_CACHE_TAG]
        );
    }

    /**
     * 주문 통계 캐시를 무효화합니다.
     *
     * 주문이 생성·수정·삭제되면 통계도 곧바로 달라져야 합니다. 쓰기 경로가 이 메서드를
     * 부르지 않으면 화면이 TTL 이 끝날 때까지 이전 수치를 보여 줍니다.
     *
     * @return void 반환값 없음
     */
    public function forgetStatisticsCache(): void
    {
        app(CacheInterface::class)->forget(self::STATISTICS_CACHE_KEY);
    }

    /**
     * 주문 통계를 실제로 집계합니다.
     *
     * 날짜 조건은 전부 범위 조건으로 준다 — whereDate/whereYear/whereMonth 는 컬럼에
     * 함수를 씌워 ordered_at 인덱스를 쓸 수 없게 만든다.
     *
     * 「오늘」·「이번 달」은 사이트 표준시로 정한다 — 이 통계는 키 하나짜리 전역 캐시라 보는 사람마다
     * 경계를 달리할 수 없고, UTC 자정으로 자르면 한국 시간 새벽 0~9시 주문이 전날·지난달로 묶인다(7.1.0 QE-O1).
     *
     * @return array 주문 통계
     */
    private function computeStatistics(): array
    {
        // 숨김 상태(PENDING_ORDER 등) 제외한 전체 통계 (OrderStatusEnum::listHiddenValues SSoT)
        $total = $this->model
            ->whereNotIn('order_status', OrderStatusEnum::listHiddenValues())
            ->count();

        // 주문상태별 통계 (숨김 상태 제외)
        $statusCounts = $this->model
            ->whereNotIn('order_status', OrderStatusEnum::listHiddenValues())
            ->selectRaw('order_status, COUNT(*) as count')
            ->groupBy('order_status')
            ->pluck('count', 'order_status')
            ->toArray();

        $siteTimezone = TimezoneHelper::getSiteTimezone() ?: 'UTC';
        $siteNow = CarbonImmutable::now($siteTimezone);
        $siteToday = $siteNow->toDateString();

        // 오늘 주문 수
        $todayCountQuery = $this->model->newQuery();
        $this->applyDateRangeFilterInTimezone($todayCountQuery, 'ordered_at', $siteToday, $siteToday, $siteTimezone);
        $todayCount = $todayCountQuery->count();

        // 오늘 매출액
        $todayRevenueQuery = $this->model->newQuery()
            ->whereNotIn('order_status', [OrderStatusEnum::CANCELLED->value]);
        $this->applyDateRangeFilterInTimezone($todayRevenueQuery, 'ordered_at', $siteToday, $siteToday, $siteTimezone);
        $todayRevenue = $todayRevenueQuery->sum('total_paid_amount');

        // 이번 달 매출액
        $monthlyRevenueQuery = $this->model->newQuery()
            ->whereNotIn('order_status', [OrderStatusEnum::CANCELLED->value]);
        $this->applyDateRangeFilterInTimezone(
            $monthlyRevenueQuery,
            'ordered_at',
            $siteNow->startOfMonth()->toDateString(),
            $siteNow->endOfMonth()->toDateString(),
            $siteTimezone
        );
        $monthlyRevenue = $monthlyRevenueQuery->sum('total_paid_amount');

        return [
            'total' => $total,
            'status_counts' => $statusCounts,
            'today_count' => $todayCount,
            'today_revenue' => $todayRevenue,
            'monthly_revenue' => $monthlyRevenue,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getUserStatistics(int $userId): array
    {
        $statusCounts = $this->model
            ->where('user_id', $userId)
            ->whereNotIn('order_status', OrderStatusEnum::listHiddenValues())
            ->selectRaw('order_status, COUNT(*) as count')
            ->groupBy('order_status')
            ->pluck('count', 'order_status')
            ->toArray();

        // 상품준비중 카운터는 PREPARING + SHIPPING_READY 를 합산한다 — 클릭 필터도 동일 집합으로
        // 확장되도록 그룹 SSoT(OrderStatusEnum::statisticsFilterGroups)를 공유한다.
        $preparing = 0;
        foreach (OrderStatusEnum::expandStatisticsFilter(OrderStatusEnum::PREPARING->value) as $value) {
            $preparing += $statusCounts[$value] ?? 0;
        }

        return [
            'pending_payment' => $statusCounts[OrderStatusEnum::PENDING_PAYMENT->value] ?? 0,
            'payment_complete' => $statusCounts[OrderStatusEnum::PAYMENT_COMPLETE->value] ?? 0,
            'preparing' => $preparing,
            'shipping' => $statusCounts[OrderStatusEnum::SHIPPING->value] ?? 0,
            'delivered' => $statusCounts[OrderStatusEnum::DELIVERED->value] ?? 0,
            'confirmed' => $statusCounts[OrderStatusEnum::CONFIRMED->value] ?? 0,
            // 부분취소는 별도 주문 상태가 아니라 잔여 옵션 기준 진행 상태로 집계된다(partial_cancelled 제거).
            // 일부 취소된 주문은 자신의 진행 단계(결제완료/준비중/배송중 등) 카운터에 그대로 잡힌다.
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function countWithListFilters(array $filters): int
    {
        // 목록(`getListWithFilters`)과 같은 권한 범위·필터 단일 출처 — 축을 복제하면 배지 숫자와 목록 건수가 다시 갈린다.
        $query = $this->newScopedListQuery();
        $this->applyListFilters($query, $filters);

        return $query->count();
    }

    /**
     * 관리자 주문 목록의 출발 쿼리 — 주문 조회 권한 범위(`orders.read` 의 본인·역할 범위)를 건 새 쿼리를 돌려줍니다.
     *
     * 목록(`getListWithFilters`)과 그 건수만 세는 경로(`countWithListFilters`, 대시보드 「오늘」 배지)가 같은 범위로
     * 출발해야 범위가 좁혀진 관리자에게도 배지 숫자 = 배지로 연 목록 건수다. 판정식은 코어 단일 출처
     * `PermissionHelper::applyPermissionScope()`(현재 인증 사용자 기준, 전체 권한이면 조건 없음)다.
     *
     * @return Builder 권한 범위를 건 주문 쿼리
     */
    private function newScopedListQuery(): Builder
    {
        $query = $this->model->newQuery();
        PermissionHelper::applyPermissionScope($query, 'sirsoft-ecommerce.orders.read');

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function getForExport(array $filters, array $ids = []): Collection
    {
        $query = $this->model->newQuery()
            ->with([
                'user',
                'options',
                'shippingAddress',
                'payment',
                'shippings',
            ]);

        // 특정 ID가 지정된 경우
        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        } else {
            // 필터 적용 — 목록 조회(`getListWithFilters`)와 **같은 메서드**를 부른다.
            // 축을 여기에 복제하면 목록에만 필터가 늘어나는 순간 화면은 42건인데 내려받은
            // 파일은 전건이 된다. 오류도 경고도 없이 **파일 내용만** 달라지므로 운영자가
            // 알아챌 방법이 없다. 단일 출처를 부르면 축이 늘어도 이 경로가 함께 따라온다.
            $this->applyListFilters($query, $filters);
        }

        // 정렬 (목록 조회와 동일한 허용 컬럼 화이트리스트)
        foreach ($this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'ordered_at') as $sort) {
            $query->orderBy($sort['column'], $sort['direction']);
        }

        return $query->get();
    }

    /**
     * 클레임 필터 적용
     *
     * @param  Builder  $query
     * @param  array|string  $statuses
     * @param  string  $type  claim type (refund, return, exchange)
     * @return void 반환값 없음
     */
    protected function applyClaimFilter($query, $statuses, string $type): void
    {
        $statuses = is_array($statuses) ? $statuses : [$statuses];

        $statusMapping = match ($type) {
            'refund' => ['refund_complete'],
            'return' => ['return_requested', 'return_complete'],
            'exchange' => ['exchange_requested', 'exchange_complete'],
            default => [],
        };

        $query->whereHas('options', function ($q) use ($statuses, $statusMapping) {
            $filteredStatuses = array_intersect($statuses, $statusMapping);
            if (! empty($filteredStatuses)) {
                $q->whereIn('option_status', $filteredStatuses);
            }
        });
    }

    /**
     * 클레임 기준 필터를 적용합니다. (D22 — 클레임 신청서 테이블 기준)
     *
     * 유형별 whereExists 서브쿼리로 구성한다 — 1:N 조인은 원 행을 부풀려 총 건수·페이지
     * 경계를 깨뜨린다. 축 구성:
     *
     * - 상태 집합이 명시된 유형만 대상으로 하고(하나도 없으면 4유형 전부), 여러 유형은
     *   OR 로 잇는다 — "체크한 상태 중 하나라도 해당하는 클레임이 있는 주문".
     * - 귀책·신청일 축은 같은 서브쿼리에 함께 건다 — 그 조건을 전부 만족하는 클레임
     *   한 건이 존재해야 한다. 취소 테이블에는 귀책 컬럼이 없어 귀책 필터가 지정되면
     *   취소 유형은 매칭 대상에서 빠진다.
     * - `claim_presence`: any=존재 / none=부재(그룹 전체 NOT) / active=미종결 상태 한정.
     * - 환불 축은 취소 발(`refund_source_type=cancel`) 신청서를 제외한다 — 취소 축이
     *   대표한다 (관리자 클레임 목록·주문 스코프 내역과 동일 규칙).
     *
     * @param  Builder  $query  주문 목록 쿼리
     * @param  array  $filters  요청 필터
     * @return void 반환값 없음
     */
    protected function applyClaimCriteriaFilters(Builder $query, array $filters): void
    {
        $presence = $filters['claim_presence'] ?? null;

        $statusSets = array_filter([
            'exchange' => array_values((array) ($filters['claim_exchange_statuses'] ?? [])),
            'return' => array_values((array) ($filters['claim_return_statuses'] ?? [])),
            'refund' => array_values((array) ($filters['claim_refund_statuses'] ?? [])),
            'cancel' => array_values((array) ($filters['claim_cancel_types'] ?? [])),
        ]);
        $faults = array_values((array) ($filters['claim_fault_types'] ?? []));
        $from = $filters['claim_requested_from'] ?? null;
        $to = $filters['claim_requested_to'] ?? null;

        if ($presence === null && $statusSets === [] && $faults === [] && empty($from) && empty($to)) {
            return;
        }

        $types = $statusSets === [] ? ['exchange', 'return', 'refund', 'cancel'] : array_keys($statusSets);

        if ($faults !== []) {
            $types = array_values(array_diff($types, ['cancel']));
        }

        if ($types === []) {
            // 귀책 필터 + 취소 유형만 지정된 조합 — 매칭 가능한 유형이 없다.
            // `none` 은 "그런 클레임이 없는 주문" 이라 전건이고(조건을 걸지 않는다), 나머지는 0건이다.
            // 빈 whereIn 은 `0 = 1` 로 컴파일된다. (S6c 1차 이월 a — 이 분기가 presence 를 보지 않았다)
            if ($presence !== 'none') {
                $query->whereIn($query->getModel()->getKeyName(), []);
            }

            return;
        }

        $group = function ($q) use ($types, $statusSets, $faults, $from, $to, $presence) {
            foreach ($types as $index => $type) {
                $q->{$index === 0 ? 'whereExists' : 'orWhereExists'}(
                    fn ($sub) => $this->claimExistsSubquery($sub, $type, $statusSets[$type] ?? [], $faults, $from, $to, $presence === 'active')
                );
            }
        };

        $presence === 'none' ? $query->whereNot($group) : $query->where($group);
    }

    /**
     * 한 클레임 유형의 존재 서브쿼리를 구성합니다.
     *
     * 취소 축의 상태 어휘는 전이 상태가 아니라 취소 유형(전체/부분 — `cancel_type`)이다.
     * 취소는 즉시 파이프라인이라 전이 상태 2종(신청/완료)이 화면 구분 축이 되지 못하고,
     * 확정 시안(§6-4-2)의 칩도 부분취소/전체취소다. 진행 중(active) 판정만 `cancel_status`
     * 를 본다.
     *
     * @param  \Illuminate\Database\Query\Builder  $sub  EXISTS 서브쿼리 빌더
     * @param  string  $type  클레임 유형 (exchange|return|refund|cancel)
     * @param  array<int, string>  $statuses  상태(취소는 유형) 어휘 집합
     * @param  array<int, string>  $faults  귀책 집합
     * @param  string|null  $from  신청일 시작
     * @param  string|null  $to  신청일 종료
     * @param  bool  $activeOnly  미종결 상태만 매칭할지 여부 (claim_presence=active)
     * @return void 반환값 없음
     */
    protected function claimExistsSubquery($sub, string $type, array $statuses, array $faults, ?string $from, ?string $to, bool $activeOnly): void
    {
        $ordersTable = $this->model->getTable();

        [$table, $statusColumn, $finalValues] = match ($type) {
            'exchange' => [
                (new OrderExchange)->getTable(),
                'exchange_status',
                array_map(fn ($s) => $s->value, ExchangeStatusEnum::finalStatuses()),
            ],
            'return' => [
                (new OrderReturn)->getTable(),
                'return_status',
                array_map(fn ($s) => $s->value, ReturnStatusEnum::finalStatuses()),
            ],
            'refund' => [
                (new OrderRefund)->getTable(),
                'refund_status',
                array_map(fn ($s) => $s->value, RefundStatusEnum::finalStatuses()),
            ],
            'cancel' => [
                (new OrderCancel)->getTable(),
                'cancel_type',
                [CancelStatusEnum::COMPLETED->value],
            ],
        };

        $sub->from($table)
            ->selectRaw('1')
            ->whereColumn($table.'.order_id', $ordersTable.'.id');

        if ($statuses !== []) {
            $sub->whereIn($table.'.'.$statusColumn, $statuses);
        }

        if ($type !== 'cancel' && $faults !== []) {
            $sub->whereIn($table.'.fault_type', $faults);
        }

        $this->applyViewerDateRangeFilter($sub, $table.'.created_at', $from, $to);

        if ($activeOnly) {
            $activeColumn = $type === 'cancel' ? 'cancel_status' : $statusColumn;
            $sub->whereNotIn($table.'.'.$activeColumn, $finalValues);
        }
    }

    /**
     * 클레임 요약('클레임' 컬럼 — D22) 표시용 eager load 관계를 반환합니다.
     *
     * 목록 행은 유형별 최신 상태 배지와 건수만 그린다 — 배지가 실제로 읽는 컬럼만
     * 좁혀 싣는다. 환불 축은 출처로 거르지 않는다(D6) — 취소 발 환불도 환불 문서다.
     *
     * @return array<string, \Closure> 관계명 => 제약 클로저
     */
    protected function claimSummaryRelations(): array
    {
        return [
            'exchanges' => fn ($q) => $q->select(['id', 'order_id', 'exchange_number', 'exchange_status', 'created_at'])->orderByDesc('id'),
            'returns' => fn ($q) => $q->select(['id', 'order_id', 'return_number', 'return_status', 'created_at'])->orderByDesc('id'),
            'refunds' => fn ($q) => $q->select(['id', 'order_id', 'refund_number', 'refund_status', 'refund_source_type', 'created_at'])->orderByDesc('id'),
            'cancels' => fn ($q) => $q->select(['id', 'order_id', 'cancel_number', 'cancel_status', 'cancel_type', 'created_at'])->orderByDesc('id'),
        ];
    }

    /**
     * 클레임 요약(D22)의 유형별 건수 집계를 반환합니다.
     *
     * 건수는 DB 집계로 얻는다 — 적재된 컬렉션의 길이를 세면 목록 행마다 하위 컬렉션의
     * 크기에 결과가 묶인다. 환불 축은 관계 제약과 같은 규칙(출처 무관)을 쓴다.
     *
     * @return array<string, \Closure|string> withCount 별칭 => 제약 클로저
     */
    protected function claimSummaryCounts(): array
    {
        return [
            'exchanges',
            'returns',
            'refunds',
            'cancels',
        ];
    }

    /**
     * 목록 필터를 쿼리에 적용합니다. (목록·엑셀 내려받기 공용 단일 출처)
     *
     * 목록 조회(`getListWithFilters`)와 엑셀 내려받기(`getForExport`)가 같은 메서드를 부른다.
     * 축을 두 곳에 복제하면 한쪽에만 필터가 늘어나는 순간 화면은 42건인데 내려받은 파일은
     * 전건이 되고, 오류도 경고도 없이 **파일 내용만** 달라져 운영자가 알아챌 방법이 없다.
     *
     * 권한 스코프는 여기에 두지 않는다 — 필터가 아니라 호출자(화면)의 열람 범위이므로
     * 호출부가 각자 건다.
     *
     * @param  Builder  $query  주문 쿼리
     * @param  array  $filters  요청 필터
     * @return void 반환값 없음 (쿼리에 직접 조건을 건다)
     */
    protected function applyListFilters($query, array $filters): void
    {
        // 목록 기본 숨김 상태 제외 (PENDING_ORDER 등 임시 주문 상태 — OrderStatusEnum::listHiddenValues SSoT)
        // order_status 필터가 명시적으로 지정된 경우에만 숨김 상태 표시 가능
        if (empty($filters['order_status']) && empty($filters['include_pending_order'])) {
            $query->whereNotIn('order_status', OrderStatusEnum::listHiddenValues());
        }

        // 회원 ID 필터 (유저 주문내역 조회용)
        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        // 주문자 UUID 필터 (회원 검색 기반 주문자 필터)
        if (! empty($filters['orderer_uuid'])) {
            $ordererUser = User::where('uuid', $filters['orderer_uuid'])->first();
            if ($ordererUser) {
                $query->where('user_id', $ordererUser->id);
            } else {
                // UUID에 해당하는 회원이 없으면 결과 없음. 빈 whereIn 은 `0 = 1` 로 컴파일된다
                $query->whereIn($query->getModel()->getKeyName(), []);
            }
        }

        // 회원 구분 필터 (member: 회원 주문, guest: 비회원 주문)
        if (! empty($filters['member_type'])) {
            if ($filters['member_type'] === 'guest') {
                $query->whereNull('user_id');
            } elseif ($filters['member_type'] === 'member') {
                $query->whereNotNull('user_id');
            }
        }

        // 문자열 검색
        if (! empty($filters['search_keyword'])) {
            $keyword = $filters['search_keyword'];
            $field = $filters['search_field'] ?? 'all';

            $query->where(function ($q) use ($keyword, $field) {
                if ($field === 'all' || $field === 'order_number') {
                    $q->orWhere('order_number', 'like', "%{$keyword}%");
                }
                if ($field === 'all' || $field === 'orderer_name') {
                    $q->orWhereHas('shippingAddress', function ($subQ) use ($keyword) {
                        $subQ->where('orderer_name', 'like', "%{$keyword}%");
                    });
                }
                if ($field === 'all' || $field === 'recipient_name') {
                    $q->orWhereHas('shippingAddress', function ($subQ) use ($keyword) {
                        $subQ->where('recipient_name', 'like', "%{$keyword}%");
                    });
                }
                if ($field === 'all' || $field === 'orderer_phone') {
                    $q->orWhereHas('shippingAddress', function ($subQ) use ($keyword) {
                        $subQ->where('orderer_phone', 'like', "%{$keyword}%");
                    });
                }
                if ($field === 'all' || $field === 'recipient_phone') {
                    $q->orWhereHas('shippingAddress', function ($subQ) use ($keyword) {
                        $subQ->where('recipient_phone', 'like', "%{$keyword}%");
                    });
                }
                if ($field === 'all' || $field === 'product_name') {
                    $q->orWhereHas('options', function ($subQ) use ($keyword) {
                        $subQ->where('product_name', 'like', "%{$keyword}%");
                    });
                }
                if ($field === 'all' || $field === 'sku') {
                    $q->orWhereHas('options', function ($subQ) use ($keyword) {
                        $subQ->where('sku', 'like', "%{$keyword}%");
                    });
                }
            });
        }

        // 날짜 필터
        if (! empty($filters['date_type']) && (! empty($filters['start_date']) || ! empty($filters['end_date']))) {
            $dateField = $filters['date_type']; // ordered_at, paid_at, etc.

            // whereDate 는 컬럼에 DATE() 를 씌워 인덱스를 무력화한다 — 같은 결과를 내는
            // 범위 조건으로 준다 (종료일은 그날 끝까지 포함). 목록이 주문일 등을 보는 사람의
            // 시간대로 그리므로 그 하루로 자른다(UTC 하루로 자르면 한국 시간 새벽 주문이 빠진다).
            // 대시보드 「오늘」 배지 링크(date_basis=site)만 예외다 — 배지가 센 하루(사이트 표준시)와 같은 범위로 열어야
            // 계정 시간대가 사이트와 다른 관리자에게도 배지 숫자의 주문이 목록에 그대로 나온다.
            if (($filters['date_basis'] ?? null) === 'site') {
                $this->applyDateRangeFilterInTimezone(
                    $query,
                    $dateField,
                    $filters['start_date'] ?? null,
                    $filters['end_date'] ?? null,
                    TimezoneHelper::getSiteTimezone() ?: 'UTC'
                );
            } else {
                $this->applyViewerDateRangeFilter(
                    $query,
                    $dateField,
                    $filters['start_date'] ?? null,
                    $filters['end_date'] ?? null
                );
            }
        }

        // 주문상태 필터 (다중 선택 가능)
        if (! empty($filters['order_status'])) {
            $statuses = is_array($filters['order_status'])
                ? $filters['order_status']
                : [$filters['order_status']];

            // 합산 카운터 키(상품준비중 등)는 동일 상태 집합으로 확장해 카운터 수와 목록 수를 일치시킨다.
            // 일반 상태 값은 그대로 유지된다 (OrderStatusEnum::statisticsFilterGroups SSoT).
            $statuses = array_values(array_unique(array_merge(
                ...array_map(
                    fn ($s) => OrderStatusEnum::expandStatisticsFilter((string) $s),
                    $statuses
                )
            )));

            $query->whereIn('order_status', $statuses);
        }

        // 클레임 상태 필터 (환불/반품/교환) — 주문옵션 상태 기반의 단수 키.
        // 현재 이 이름을 실어 보내는 경로는 없다: OrderListRequest 는 복수 키
        // (claim_{exchange,return,refund}_statuses)만 검증 대상으로 선언하고, 관리자 화면의
        // 필터·URL·프리셋·엑셀도 D22 에서 전부 복수 키로 이관됐다. 호출자가 생기기 전까지
        // 도달하지 않는 분기이므로 "지원되는 계약" 으로 읽지 말 것 — 새 배선은 아래
        // applyClaimCriteriaFilters(D22, 클레임 신청서 테이블 기준)를 쓴다.
        if (! empty($filters['claim_refund_status'])) {
            $this->applyClaimFilter($query, $filters['claim_refund_status'], 'refund');
        }
        if (! empty($filters['claim_return_status'])) {
            $this->applyClaimFilter($query, $filters['claim_return_status'], 'return');
        }
        if (! empty($filters['claim_exchange_status'])) {
            $this->applyClaimFilter($query, $filters['claim_exchange_status'], 'exchange');
        }

        // 클레임 기준 필터 (D22) — 클레임 신청서 테이블 기준 whereExists 서브쿼리.
        // 화면·요청 검증이 쓰는 유일한 클레임 필터 경로다.
        $this->applyClaimCriteriaFilters($query, $filters);

        // 결제수단 필터
        if (! empty($filters['payment_method'])) {
            $methods = is_array($filters['payment_method'])
                ? $filters['payment_method']
                : [$filters['payment_method']];
            $query->whereHas('payment', function ($q) use ($methods) {
                $q->whereIn('payment_method', $methods);
            });
        }

        // 배송방법 필터
        if (! empty($filters['shipping_type'])) {
            $methods = is_array($filters['shipping_type'])
                ? $filters['shipping_type']
                : [$filters['shipping_type']];
            $query->whereHas('shippings', function ($q) use ($methods) {
                $q->whereIn('shipping_type', $methods);
            });
        }

        // 카테고리 필터
        if (! empty($filters['category_id'])) {
            $categoryId = $filters['category_id'];
            $query->whereHas('options.product.categories', function ($q) use ($categoryId) {
                $q->where('ecommerce_product_categories.category_id', $categoryId);
            });
        }

        // 금액 범위 필터
        // 0 은 유효한 경계값이다(예: 결제금액 0원 주문만). empty() 로 거르면 0 이 "미입력"으로
        // 취급돼 필터가 통째로 무시된다 — min_stock/max_stock 과 같은 판정식을 쓴다.
        if (isset($filters['min_amount']) && $filters['min_amount'] !== '') {
            $query->where('total_amount', '>=', (float) $filters['min_amount']);
        }
        if (isset($filters['max_amount']) && $filters['max_amount'] !== '') {
            $query->where('total_amount', '<=', (float) $filters['max_amount']);
        }

        // 국가 필터
        if (! empty($filters['country_codes'])) {
            $countries = is_array($filters['country_codes'])
                ? $filters['country_codes']
                : [$filters['country_codes']];
            $query->whereHas('shippingAddress', function ($q) use ($countries) {
                $q->whereIn('recipient_country_code', $countries);
            });
        }

        // 배송비 범위 필터
        // 0 은 유효한 경계값이다(무료배송 주문만 보기). empty() 로 거르면 무시된다.
        if (isset($filters['min_shipping_amount']) && $filters['min_shipping_amount'] !== '') {
            $query->where('total_shipping_amount', '>=', (float) $filters['min_shipping_amount']);
        }
        if (isset($filters['max_shipping_amount']) && $filters['max_shipping_amount'] !== '') {
            $query->where('total_shipping_amount', '<=', (float) $filters['max_shipping_amount']);
        }

        // 배송정책 필터 (OrderShipping 관계를 통해)
        if (! empty($filters['shipping_policy_id'])) {
            $query->whereHas('shippings', function ($q) use ($filters) {
                $q->where('shipping_policy_id', $filters['shipping_policy_id']);
            });
        }

        // 디바이스 필터
        if (! empty($filters['order_device'])) {
            $devices = is_array($filters['order_device'])
                ? $filters['order_device']
                : [$filters['order_device']];
            $query->whereIn('order_device', $devices);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function existsByOrderNumber(string $orderNumber): bool
    {
        return $this->model->where('order_number', $orderNumber)->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function hasOrderByUser(int $userId): bool
    {
        return $this->model->where('user_id', $userId)->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function getExpiredPendingPaymentOrders(int $limit = 100, ?int $pendingOrderExpireMinutes = null): Collection
    {
        return $this->model
            ->with(['payment', 'user'])
            ->where(function ($outer) use ($pendingOrderExpireMinutes) {
                // 입금 기한이 있는 결제수단 — 기한 도과분
                $outer->where(function ($scope) {
                    $scope->where('order_status', OrderStatusEnum::PENDING_PAYMENT->value)
                        ->whereHas('payment', function ($query) {
                            $query->where(function ($q) {
                                // vbank 가상계좌 입금 기한 만료
                                $q->where('payment_method', PaymentMethodEnum::VBANK->value)
                                    ->whereNotNull('vbank_due_at')
                                    ->where('vbank_due_at', '<', now());
                            })->orWhere(function ($q) {
                                // dbank 무통장입금(수동 입금확인) 입금 기한 만료
                                $q->where('payment_method', PaymentMethodEnum::DBANK->value)
                                    ->whereNotNull('deposit_due_at')
                                    ->where('deposit_due_at', '<', now());
                            });
                        })
                        // 진행 중인 환불신청서가 있는 주문은 이미 취소가 접수됐다 — 입금이 기록된 결제 전 주문의
                        // 취소는 환불신청서를 거치고(S3-D7) 운영자가 송금·실행할 때까지 결제대기로 남는다. 다시 집으면
                        // 수량 잠금에 걸려 실행마다 실패로 기록된다.
                        ->whereDoesntHave('refunds', function ($query) {
                            $query->whereNotIn('refund_status', array_map(
                                fn (RefundStatusEnum $status) => $status->value,
                                RefundStatusEnum::finalStatuses(),
                            ));
                        });
                });

                // 결제창까지 갔지만 결제가 성립하지 않은 주문(PG 카드 등) — 입금 기한이라는 개념이
                // 없어 종전에는 어떤 정리 주체도 없이 무한히 남았다. 브라우저 리턴 콜백이 주문
                // 상태를 바꾸지 않게 되면서(위조 콜백으로 남의 주문을 취소시킬 수 있었던 통로 차단)
                // 승인 거절분도 여기에 머무르므로, 주문 시각 기준 경과분을 정리 대상에 포함한다.
                // 선차감 마일리지 복원도 이 정리에서 함께 이루어진다.
                if ($pendingOrderExpireMinutes !== null && $pendingOrderExpireMinutes > 0) {
                    $outer->orWhere(function ($scope) use ($pendingOrderExpireMinutes) {
                        $scope->where('order_status', OrderStatusEnum::PENDING_ORDER->value)
                            ->where('ordered_at', '<', now()->subMinutes($pendingOrderExpireMinutes))
                            // 승인 콜백과 경쟁해 이미 결제가 성립한 주문은 건드리지 않는다.
                            ->whereHas('payment', function ($query) {
                                $query->whereNotIn('payment_status', [
                                    PaymentStatusEnum::PAID->value,
                                    PaymentStatusEnum::WAITING_DEPOSIT->value,
                                ]);
                            });
                    });
                }
            })
            ->orderBy('ordered_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * ID 목록으로 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  ID 목록
     * @return Collection ID 를 키로 하는 주문 컬렉션
     */
    public function findByIdsKeyed(array $ids): Collection
    {
        if (empty($ids)) {
            return new Collection;
        }

        return Order::whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdsWithRelationsKeyed(array $ids, array $with = []): Collection
    {
        if (empty($ids)) {
            return new Collection;
        }

        return Order::with($with)->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * {@inheritDoc}
     */
    public function getSnapshotsByIds(array $ids): array
    {
        return $this->model->whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();
    }

    /**
     * {@inheritDoc}
     */
    public function getActivityLogsForOrder(Order $order, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 10);
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $optionIds = $order->options()->pluck('id')->toArray();
        $addressIds = $order->addresses()->pluck('id')->toArray();

        $query = ActivityLog::where(function (Builder $q) use ($order, $optionIds, $addressIds) {
            // 주문 자체 로그
            $q->where(function (Builder $sub) use ($order) {
                $sub->where('loggable_type', $order->getMorphClass())
                    ->where('loggable_id', $order->getKey());
            });

            // 해당 주문의 옵션 로그
            if (! empty($optionIds)) {
                $q->orWhere(function (Builder $sub) use ($optionIds) {
                    $sub->where('loggable_type', (new OrderOption)->getMorphClass())
                        ->whereIn('loggable_id', $optionIds);
                });
            }

            // 해당 주문의 배송지 로그
            if (! empty($addressIds)) {
                $q->orWhere(function (Builder $sub) use ($addressIds) {
                    $sub->where('loggable_type', (new OrderAddress)->getMorphClass())
                        ->whereIn('loggable_id', $addressIds);
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

    /**
     * {@inheritDoc}
     */
    public function hasRecordedCurrency(string $code): bool
    {
        return $this->model->newQuery()->where('currency', $code)->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function listIssuedTaxInvoices(int $orderId): Collection
    {
        return OrderTaxInvoice::query()
            ->where('order_id', $orderId)
            ->where('invoice_status', TaxInvoiceStatusEnum::ISSUED->value)
            ->get(['id', 'invoice_number', 'total_amount']);
    }
}
