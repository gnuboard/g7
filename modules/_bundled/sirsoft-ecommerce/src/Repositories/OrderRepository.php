<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Contracts\Extension\CacheInterface;
use App\Helpers\PermissionHelper;
use App\Models\ActivityLog;
use App\Models\User;
use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Repositories\Concerns\SortsByRelatedColumn;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
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
    public function findByIdForUpdate(int $id): ?Order
    {
        return $this->model->newQuery()->lockForUpdate()->find($id);
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
        $query = $this->model->newQuery();

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'sirsoft-ecommerce.orders.read');

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
            // 범위 조건으로 준다 (종료일은 그날 끝까지 포함).
            $this->applyDateRangeFilter(
                $query,
                $dateField,
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null
            );
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

        // 클레임 상태 필터 (환불/반품/교환)
        if (! empty($filters['claim_refund_status'])) {
            $this->applyClaimFilter($query, $filters['claim_refund_status'], 'refund');
        }
        if (! empty($filters['claim_return_status'])) {
            $this->applyClaimFilter($query, $filters['claim_return_status'], 'return');
        }
        if (! empty($filters['claim_exchange_status'])) {
            $this->applyClaimFilter($query, $filters['claim_exchange_status'], 'exchange');
        }

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
            relations: [
                'user',
                $withItems ? 'options' : 'firstOption',
                'shippingAddress',
                'payment',
                // 목록은 대표 배송 1건의 표시 정보만 그린다. 택배사 이름까지 함께 로드해야
                // Resource 가 행마다 carrier 를 다시 조회하지 않는다.
                'firstShipping.carrier',
            ],
            withCount: $withItems ? [] : [
                // "외 N건" 표기용 전체 옵션 수.
                'options',
                // 부분취소 뱃지 판정용 — 취소 옵션이 있으면서 남은 옵션도 있는 상태.
                // 컬렉션을 순회하지 않고 DB 집계 두 개로 같은 판정을 얻는다.
                'options as cancelled_options_count' => fn ($optionQuery) => $optionQuery->where(
                    'option_status',
                    OrderStatusEnum::CANCELLED->value,
                ),
            ],
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
        // 취소/환불·클레임 등 별도 라이프사이클 옵션은 동기화에서 제외한다.
        // (취소된 옵션이 결제완료/배송중 등으로 되살아나는 것을 차단 — OrderStatusEnum SSoT)
        return OrderOption::whereIn('order_id', $ids)
            ->whereNotIn('option_status', OrderStatusEnum::syncExcludedValues())
            ->update([
                'option_status' => $status,
                'updated_at' => now(),
            ]);
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

        // 오늘 주문 수
        $todayCountQuery = $this->model->newQuery();
        $this->applyDayFilter($todayCountQuery, 'ordered_at', today());
        $todayCount = $todayCountQuery->count();

        // 오늘 매출액
        $todayRevenueQuery = $this->model->newQuery()
            ->whereNotIn('order_status', [OrderStatusEnum::CANCELLED->value]);
        $this->applyDayFilter($todayRevenueQuery, 'ordered_at', today());
        $todayRevenue = $todayRevenueQuery->sum('total_paid_amount');

        // 이번 달 매출액
        $monthlyRevenueQuery = $this->model->newQuery()
            ->whereNotIn('order_status', [OrderStatusEnum::CANCELLED->value]);
        $this->applyMonthFilter($monthlyRevenueQuery, 'ordered_at', (int) now()->year, (int) now()->month);
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
            // 필터 적용 (getListWithFilters와 동일한 로직)
            $this->applyFiltersToQuery($query, $filters);
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
     * 필터 조건을 쿼리에 적용 (내부 헬퍼)
     *
     * @param  Builder  $query
     */
    protected function applyFiltersToQuery($query, array $filters): void
    {
        // 목록 기본 숨김 상태 제외 (PENDING_ORDER 등 — OrderStatusEnum::listHiddenValues SSoT)
        if (empty($filters['order_status']) && empty($filters['include_pending_order'])) {
            $query->whereNotIn('order_status', OrderStatusEnum::listHiddenValues());
        }

        // 날짜 필터
        if (! empty($filters['date_type']) && (! empty($filters['start_date']) || ! empty($filters['end_date']))) {
            $dateField = $filters['date_type'];

            // whereDate 는 인덱스를 무력화한다 — 범위 조건으로 준다.
            $this->applyDateRangeFilter(
                $query,
                $dateField,
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null
            );
        }

        // 주문상태 필터
        if (! empty($filters['order_status'])) {
            $statuses = is_array($filters['order_status'])
                ? $filters['order_status']
                : [$filters['order_status']];
            $query->whereIn('order_status', $statuses);
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
    public function getExpiredPendingPaymentOrders(int $limit = 100): Collection
    {
        return $this->model
            ->with(['payment', 'user'])
            ->where('order_status', OrderStatusEnum::PENDING_PAYMENT->value)
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
}
