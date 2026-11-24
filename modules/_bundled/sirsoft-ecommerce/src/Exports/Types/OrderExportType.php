<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Types;

use App\Extension\HookManager;
use App\Models\User;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Locale;
use Modules\Sirsoft\Ecommerce\Enums\ExportTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderDateTypeEnum;
use Modules\Sirsoft\Ecommerce\Exports\ExportFieldType;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\OrderListRequest;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;

/**
 * 주문내역 내보내기
 *
 * 행 단위 둘:
 * - 주문별(order): 주문 1건 = 1행. 상품 열은 대표 상품 1개와 상품 종류 수
 * - 주문상품별(order_option): 주문 옵션 1건 = 1행. 주문 단위 합계 금액은 그 주문의 **첫 행에만** 쓴다(D13) —
 *   행마다 반복하면 엑셀에서 열을 더할 때 같은 금액이 여러 번 더해진다
 *
 * 원천 쿼리는 주문 목록과 같은 조건({@see OrderRepositoryInterface::newExportQuery()} — 목록과 같은 필터 경로·요청자 기준 권한 범위)다.
 */
class OrderExportType extends AbstractExportType
{
    public const UNIT_ORDER = 'order';

    public const UNIT_ORDER_OPTION = 'order_option';

    /**
     * 한 번에 읽는 주문 수
     */
    private const CHUNK = 500;

    /**
     * @param  OrderRepositoryInterface  $orders  주문 저장소 — 내보내기 원천 쿼리(목록과 같은 조건·요청자 범위)
     * @param  PaymentMethodResolver  $paymentMethods  결제수단 해석기 — 결제수단 필터를 목록과 같은 조건으로 푼다(레지스트리와 같은 scoped 수명)
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly PaymentMethodResolver $paymentMethods,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return ExportTypeEnum::ORDERS->value;
    }

    /**
     * {@inheritDoc}
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.export_type.orders';
    }

    /**
     * {@inheritDoc}
     */
    public function readPermission(): string
    {
        return 'sirsoft-ecommerce.orders.read';
    }

    /**
     * {@inheritDoc}
     */
    public function rowUnits(): array
    {
        return [
            self::UNIT_ORDER => 'sirsoft-ecommerce::export.row_units.order',
            self::UNIT_ORDER_OPTION => 'sirsoft-ecommerce::export.row_units.order_option',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function dateTypes(): array
    {
        $types = [];
        foreach (OrderDateTypeEnum::cases() as $case) {
            $types[$case->value] = 'sirsoft-ecommerce::enums.order_date_type.'.$case->value;
        }

        return $types;
    }

    /**
     * {@inheritDoc}
     */
    public function periodRequired(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function listRequestClass(): string
    {
        return OrderListRequest::class;
    }

    /**
     * {@inheritDoc}
     */
    public function screenColumns(): array
    {
        $label = static fn (string $k) => 'sirsoft-ecommerce::export.screen.orders.'.$k;

        return [
            'ordered_at' => ['label_key' => $label('ordered_at'), 'fields' => ['order.ordered_at']],
            'order_number' => ['label_key' => $label('order_number'), 'fields' => ['order.order_number']],
            'product' => ['label_key' => $label('product'), 'fields' => [
                // 주문별
                'rep.product_code', 'rep.product_name', 'rep.option_name', 'rep.product_kind_count',
                // 주문상품별
                'option.product_code', 'option.sku', 'option.product_name', 'option.product_option_name',
                'option.quantity', 'option.unit_price', 'option.subtotal_price', 'option.subtotal_discount_amount',
                'option.subtotal_paid_amount', 'option.subtotal_tax_amount', 'option.subtotal_tax_free_amount', 'option.option_status',
            ]],
            'order_amount' => ['label_key' => $label('order_amount'), 'fields' => [
                'order.currency', 'order.subtotal_amount', 'order.total_discount_amount', 'order.total_shipping_amount',
                'order.total_points_used_amount', 'order.total_amount', 'order.total_paid_amount', 'order.total_due_amount',
                'order.total_earned_points_amount',
            ]],
            'order_status' => ['label_key' => $label('order_status'), 'fields' => ['order.order_status', 'order.order_status_code']],
            'orderer' => ['label_key' => $label('orderer'), 'fields' => [
                'order.member_type', 'address.orderer_name', 'address.orderer_phone', 'address.orderer_email', 'order.member_uuid',
            ]],
            'payment_method' => ['label_key' => $label('payment_method'), 'fields' => ['payment.method_label', 'payment.paid_at']],
            'delivery_method' => ['label_key' => $label('delivery_method'), 'fields' => [
                'shipping.type_label', 'shipping.carrier_name', 'shipping.tracking_number', 'shipping.shipping_status', 'shipping.shipped_at',
            ]],
            'country' => ['label_key' => $label('country'), 'fields' => ['address.recipient_country_code', 'address.country_name']],
            'device' => ['label_key' => $label('device'), 'fields' => ['order.order_device']],
            'claim' => ['label_key' => $label('claim'), 'fields' => [
                'claim.exchange_status', 'claim.return_status', 'claim.refund_status', 'order.total_refunded_amount',
            ]],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function excludedScreenColumns(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    protected function extendFields(array $fields, string $unit): mixed
    {
        return HookManager::applyFilters('sirsoft-ecommerce.export.orders.fields', $fields, $unit);
    }

    /**
     * {@inheritDoc}
     */
    protected function buildFields(string $unit): array
    {
        $isOption = $unit === self::UNIT_ORDER_OPTION;

        // 행 → 주문 (주문별은 행 자체, 주문상품별은 옵션의 주문)
        $orderOf = $isOption
            ? static fn (object $row) => $row->order
            : static fn (object $row) => $row;

        $groups = $this->groupsBySource();

        $fields = array_merge(
            $this->declaredFields(Order::class, 'order', $orderOf, $groups['order'], [], parentLevel: $isOption),
            $this->declaredFields(OrderAddress::class, 'address', static fn (object $row) => $orderOf($row)?->shippingAddress, $groups['address'], [], parentLevel: $isOption),
            $this->declaredFields(OrderPayment::class, 'payment', static fn (object $row) => $orderOf($row)?->payment, $groups['payment'], [], parentLevel: $isOption),
            $this->declaredFields(
                OrderShipping::class,
                'shipping',
                // 주문상품별은 그 옵션의 배송, 주문별은 대표 배송 1건
                $isOption ? static fn (object $row) => $row->shippings->first() : static fn (object $row) => $row->firstShipping,
                $groups['shipping'],
            ),
        );

        if ($isOption) {
            $fields = array_merge($fields, $this->declaredFields(OrderOption::class, 'option', static fn (object $row) => $row, $groups['option'], [self::UNIT_ORDER_OPTION]));
            $fields[] = $this->computed('option.product_code', ExportFieldType::STRING, false,
                static fn (OrderOption $o) => data_get($o->product_snapshot, 'product_code') ?? $o->product?->product_code, 'product', ['units' => [self::UNIT_ORDER_OPTION]]);
        } else {
            $first = static fn (Order $o) => $o->options->sortBy('id')->first();
            $fields[] = $this->computed('rep.product_code', ExportFieldType::STRING, false,
                static fn (Order $o) => ($opt = $first($o)) ? (data_get($opt->product_snapshot, 'product_code') ?? $opt->product?->product_code) : null, 'product', ['units' => [self::UNIT_ORDER]]);
            $fields[] = $this->computed('rep.product_name', ExportFieldType::TRANSLATABLE, false,
                static fn (Order $o) => $first($o)?->product_name, 'product', ['units' => [self::UNIT_ORDER]]);
            $fields[] = $this->computed('rep.option_name', ExportFieldType::TRANSLATABLE, false,
                static fn (Order $o) => $first($o)?->product_option_name, 'product', ['units' => [self::UNIT_ORDER]]);
            $fields[] = $this->computed('rep.product_kind_count', ExportFieldType::INTEGER, false,
                static fn (Order $o) => $o->options->count(), 'product', ['units' => [self::UNIT_ORDER]]);
        }

        // 주문 계산 필드
        $fields[] = $this->computed('order.order_status_code', ExportFieldType::STRING, false,
            static fn (object $row) => $orderOf($row)?->order_status?->value, 'order_status');
        $fields[] = $this->computed('order.member_type', ExportFieldType::STRING, false,
            static fn (object $row) => __($orderOf($row)?->user_id ? 'sirsoft-ecommerce::export.values.member' : 'sirsoft-ecommerce::export.values.guest'), 'orderer');
        $fields[] = $this->computed('order.member_uuid', ExportFieldType::STRING, false,
            static fn (object $row) => $orderOf($row)?->user?->uuid, 'orderer');

        // 결제·배송 계산 필드
        $fields[] = $this->computed('payment.method_label', ExportFieldType::STRING, false,
            static fn (object $row) => ($p = $orderOf($row)?->payment) && $p->payment_method ? $p->paymentMethodLabel() : null, 'payment_method');
        $shippingOf = $isOption ? static fn (object $row) => $row->shippings->first() : static fn (object $row) => $row->firstShipping;
        $fields[] = $this->computed('shipping.type_label', ExportFieldType::STRING, false,
            static fn (object $row) => ($s = $shippingOf($row)) && $s->shipping_type ? (ShippingType::getCachedByCode($s->shipping_type)?->getLocalizedName() ?? $s->shipping_type) : null, 'delivery_method');
        $fields[] = $this->computed('shipping.carrier_name', ExportFieldType::TRANSLATABLE, false,
            static fn (object $row) => $shippingOf($row)?->carrier?->name, 'delivery_method');
        $fields[] = $this->computed('address.country_name', ExportFieldType::STRING, false,
            static fn (object $row) => ($code = $orderOf($row)?->shippingAddress?->recipient_country_code) ? (Locale::getDisplayRegion('-'.$code, app()->getLocale()) ?: $code) : null, 'country');

        // 클레임 — 유형별 가장 최근 신청서의 상태
        $fields[] = $this->computed('claim.exchange_status', ExportFieldType::ENUM, false,
            static fn (object $row) => $orderOf($row)?->exchanges->sortByDesc('id')->first()?->exchange_status, 'claim');
        $fields[] = $this->computed('claim.return_status', ExportFieldType::ENUM, false,
            static fn (object $row) => $orderOf($row)?->returns->sortByDesc('id')->first()?->return_status, 'claim');
        $fields[] = $this->computed('claim.refund_status', ExportFieldType::ENUM, false,
            static fn (object $row) => $orderOf($row)?->refunds->sortByDesc('id')->first()?->refund_status, 'claim');

        return $fields;
    }

    /**
     * 출처별 컬럼 → 화면 컬럼 매핑 (선언 필드의 그룹)
     *
     * @return array<string, array<string, string>> 출처 → (컬럼 → 화면 컬럼 키)
     */
    private function groupsBySource(): array
    {
        $result = ['order' => [], 'address' => [], 'payment' => [], 'shipping' => [], 'option' => []];

        foreach ($this->screenColumns() as $screenKey => $def) {
            foreach ($def['fields'] as $fieldKey) {
                [$source, $column] = explode('.', $fieldKey, 2);
                if (isset($result[$source])) {
                    $result[$source][$column] = $screenKey;
                }
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function query(array $filters, ?array $ids, User $requester, ?CarbonInterface $cutoff): Builder
    {
        // 결제수단 선택지를 목록과 같은 결제 행 조건으로 푼다 — PG 별칭(toss_kakaopay 등)은 코어 값으로 저장된다 (#933).
        $filters = $this->paymentMethods->expandOrderListPaymentFilter($filters);

        $query = $this->orders->newExportQuery($filters, $ids, $requester);

        if ($cutoff !== null) {
            $query->where('ordered_at', '<', $cutoff);
        }

        return $query;
    }

    /**
     * {@inheritDoc}
     *
     * 주문상품은 주문과 함께 생기므로 기준 시각은 주문({@see query()})에만 건다 — 주문 뒤 바뀐 주문상품은 그 주문의
     * 현재 상태로 내보낸다.
     */
    public function countRows(Builder $query, string $unit, ?CarbonInterface $cutoff = null): int
    {
        if ($unit === self::UNIT_ORDER_OPTION) {
            return OrderOption::query()->whereIn('order_id', (clone $query)->select('id'))->count();
        }

        return (clone $query)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function rows(Builder $query, string $unit, ?CarbonInterface $cutoff = null): Generator
    {
        $relations = [
            'user:id,uuid',
            'options' => fn ($q) => $q->orderBy('id'),
            'options.product:id,product_code',
            'shippingAddress',
            'payment',
            'firstShipping.carrier',
            'exchanges:id,order_id,exchange_status',
            'returns:id,order_id,return_status',
            'refunds:id,order_id,refund_status',
        ];
        if ($unit === self::UNIT_ORDER_OPTION) {
            $relations[] = 'options.shippings.carrier';
        }

        // 최신 주문부터 — 목록 기본 정렬(주문일 내림차순)과 같은 방향. ID 기준 청크라 페이지가 밀리지 않는다.
        foreach ((clone $query)->with($relations)->lazyByIdDesc(self::CHUNK) as $order) {
            $currency = $order->currency;

            if ($unit === self::UNIT_ORDER_OPTION) {
                $first = true;
                foreach ($order->options as $option) {
                    $option->setRelation('order', $order);
                    yield [$option, $first, $currency];
                    $first = false;
                }

                continue;
            }

            yield [$order, true, $currency];
        }
    }
}
