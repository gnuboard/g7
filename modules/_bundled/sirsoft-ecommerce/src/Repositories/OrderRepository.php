<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Helpers\PermissionHelper;
use App\Models\ActivityLog;
use App\Models\User;
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
            ])
            ->where('order_number', $orderNumber)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->with([
                'user',
                'options',
                'shippingAddress',
                'payment',
                'shippings',
            ]);

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
                // UUID에 해당하는 회원이 없으면 결과 없음
                $query->whereRaw('1 = 0');
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

            if (! empty($filters['start_date'])) {
                $query->whereDate($dateField, '>=', $filters['start_date']);
            }
            if (! empty($filters['end_date'])) {
                $query->whereDate($dateField, '<=', $filters['end_date']);
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
        if (! empty($filters['min_amount'])) {
            $query->where('total_amount', '>=', (float) $filters['min_amount']);
        }
        if (! empty($filters['max_amount'])) {
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
        if (! empty($filters['min_shipping_amount'])) {
            $query->where('total_shipping_amount', '>=', (float) $filters['min_shipping_amount']);
        }
        if (! empty($filters['max_shipping_amount'])) {
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

        // 정렬
        $sortBy = $filters['sort_by'] ?? 'ordered_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Order
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(Order $order, array $data): Order
    {
        $order->update($data);

        return $order->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(Order $order): bool
    {
        return $order->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(array $ids, string $status): int
    {
        return $this->model
            ->whereIn('id', $ids)
            ->update([
                'order_status' => $status,
                'updated_at' => now(),
            ]);
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
        $todayCount = $this->model
            ->whereDate('ordered_at', today())
            ->count();

        // 오늘 매출액
        $todayRevenue = $this->model
            ->whereDate('ordered_at', today())
            ->whereNotIn('order_status', [OrderStatusEnum::CANCELLED->value])
            ->sum('total_paid_amount');

        // 이번 달 매출액
        $monthlyRevenue = $this->model
            ->whereYear('ordered_at', now()->year)
            ->whereMonth('ordered_at', now()->month)
            ->whereNotIn('order_status', [OrderStatusEnum::CANCELLED->value])
            ->sum('total_paid_amount');

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

        // 정렬
        $sortBy = $filters['sort_by'] ?? 'ordered_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

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

            if (! empty($filters['start_date'])) {
                $query->whereDate($dateField, '>=', $filters['start_date']);
            }
            if (! empty($filters['end_date'])) {
                $query->whereDate($dateField, '<=', $filters['end_date']);
            }
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

        return ActivityLog::where(function (Builder $q) use ($order, $optionIds, $addressIds) {
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
        })->orderBy('created_at', $sortOrder)->paginate($perPage);
    }
}
