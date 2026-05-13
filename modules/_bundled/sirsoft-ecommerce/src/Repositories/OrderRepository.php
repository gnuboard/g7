<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Helpers\PermissionHelper;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
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
                'options',
                'options.product',
                'options.shippings',
                'options.review',
                'shippingAddress',
                'billingAddress',
                'payment',
                'payments',
                'shippings',
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

        // pending_order 상태 기본 제외 (임시 주문 상태)
        // order_status 필터가 명시적으로 지정된 경우에만 pending_order 표시 가능
        if (empty($filters['order_status']) && empty($filters['include_pending_order'])) {
            $query->where('order_status', '!=', OrderStatusEnum::PENDING_ORDER->value);
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
        return OrderOption::whereIn('order_id', $ids)
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
        // pending_order 제외한 전체 통계
        $total = $this->model
            ->where('order_status', '!=', OrderStatusEnum::PENDING_ORDER->value)
            ->count();

        // 주문상태별 통계 (pending_order 제외)
        $statusCounts = $this->model
            ->where('order_status', '!=', OrderStatusEnum::PENDING_ORDER->value)
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
            ->where('order_status', '!=', OrderStatusEnum::PENDING_ORDER->value)
            ->selectRaw('order_status, COUNT(*) as count')
            ->groupBy('order_status')
            ->pluck('count', 'order_status')
            ->toArray();

        return [
            'pending_payment' => $statusCounts[OrderStatusEnum::PENDING_PAYMENT->value] ?? 0,
            'payment_complete' => $statusCounts[OrderStatusEnum::PAYMENT_COMPLETE->value] ?? 0,
            'preparing' => ($statusCounts[OrderStatusEnum::PREPARING->value] ?? 0)
                + ($statusCounts[OrderStatusEnum::SHIPPING_READY->value] ?? 0),
            'shipping' => $statusCounts[OrderStatusEnum::SHIPPING->value] ?? 0,
            'delivered' => $statusCounts[OrderStatusEnum::DELIVERED->value] ?? 0,
            'confirmed' => $statusCounts[OrderStatusEnum::CONFIRMED->value] ?? 0,
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
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array|string  $statuses
     * @param  string  $type  claim type (refund, return, exchange)
     * @return void
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
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array  $filters
     * @return void
     */
    protected function applyFiltersToQuery($query, array $filters): void
    {
        // pending_order 상태 기본 제외 (임시 주문 상태)
        if (empty($filters['order_status']) && empty($filters['include_pending_order'])) {
            $query->where('order_status', '!=', OrderStatusEnum::PENDING_ORDER->value);
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
                    $q->where('payment_method', 'vbank')
                        ->whereNotNull('vbank_due_at')
                        ->where('vbank_due_at', '<', now());
                })->orWhere(function ($q) {
                    // bank 수동 무통장 입금 기한 만료
                    $q->where('payment_method', 'bank')
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
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findByIdsKeyed(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($ids)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return Order::whereIn('id', $ids)->get()->keyBy('id');
    }
}
