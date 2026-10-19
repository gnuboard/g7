<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Export\Sources;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\MileageTransactionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderRepository;
use Modules\Sirsoft\Ecommerce\Sources\EcommerceListQueries;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * 이커머스 관리 목록 ↔ 내보내기 원천 쿼리 파리티 테스트
 *
 * 내보내기는 목록과 같은 조건이어야 한다 — 어긋나면 화면은 걸러진 건수인데 파일은 전건이 되고, 오류도 경고도 없이
 * 파일 내용만 달라져 운영자가 알아챌 방법이 없다. 같은 조건을 다음 두 방식으로 보장한다.
 * - 주문·상품: 목록 저장소의 필터 메서드를 그대로 부른다 — 축마다 목록과 결과 ID 가 같은지 본다.
 * - 마일리지: 필터가 목록 메서드 안에 직접 쓰여 있어 이쪽에 옮겨 두었다 — 목록 메서드 **소스에서 필터 키를 파생**해
 *   새 축이 생기면 대응 케이스가 없다는 이유로 붉어지고, 축마다 결과 ID 가 같은지 본다.
 */
class EcommerceListQueriesParityTest extends ExportTestCase
{
    protected User $operator;

    protected EcommerceListQueries $sources;

    /**
     * 목록 조회 권한을 모두 가진 운영자와 원천 쿼리를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->createAdminUser([
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.mileage.read',
        ]);
        $this->actingAs($this->operator);
        $this->sources = app(EcommerceListQueries::class);
    }

    /**
     * 주문 축마다 "그 주문을 제외하는" 필터 payload (모듈 파리티 테스트와 같은 모집단)
     *
     * @return array<string, array<string, mixed>> 필터 키 => payload
     */
    private function orderExcludingPayloads(): array
    {
        return [
            'order_status' => ['order_status' => [OrderStatusEnum::CANCELLED->value]],
            'user_id' => ['user_id' => 999999],
            'orderer_uuid' => ['orderer_uuid' => 'no-such-uuid'],
            'member_type' => ['member_type' => 'guest'],
            'search_keyword' => ['search_keyword' => 'zzz-no-such-order-zzz', 'search_field' => 'all'],
            'date_type' => ['date_type' => 'ordered_at', 'start_date' => '2999-01-01'],
            'claim_refund_status' => ['claim_refund_status' => ['refund_complete']],
            'claim_return_status' => ['claim_return_status' => ['return_requested']],
            'claim_exchange_status' => ['claim_exchange_status' => ['exchange_requested']],
            'payment_method' => ['payment_method' => ['no_such_method']],
            'shipping_type' => ['shipping_type' => ['no_such_type']],
            'category_id' => ['category_id' => 999999],
            'min_amount' => ['min_amount' => 99999999],
            'max_amount' => ['max_amount' => 1],
            'country_codes' => ['country_codes' => ['ZZ']],
            'min_shipping_amount' => ['min_shipping_amount' => 99999999],
            'max_shipping_amount' => ['max_shipping_amount' => -1],
            'shipping_policy_id' => ['shipping_policy_id' => 999999],
            'order_device' => ['order_device' => ['no_such_device']],
            'claim_presence' => ['claim_presence' => 'any'],
            'claim_exchange_statuses' => ['claim_exchange_statuses' => ['requested']],
            'claim_return_statuses' => ['claim_return_statuses' => ['requested']],
            'claim_refund_statuses' => ['claim_refund_statuses' => ['requested']],
            'claim_cancel_types' => ['claim_cancel_types' => ['full']],
            'claim_fault_types' => ['claim_fault_types' => ['seller']],
            'claim_requested_from' => ['claim_requested_from' => '2999-01-01'],
            'claim_requested_to' => ['claim_requested_to' => '1999-01-01'],
        ];
    }

    /**
     * 상품 축마다 그 상품을 제외하는 필터 payload
     *
     * @param  string  $productCode  고정물 상품 코드
     * @return array<string, array<string, mixed>> 필터 키 => payload
     */
    private function productExcludingPayloads(string $productCode): array
    {
        return [
            'search_all' => ['search_field' => 'all', 'search_keyword' => 'zzz-no-such-product-zzz'],
            'search_product_code' => ['search_field' => 'product_code', 'search_keyword' => 'zzz-no-such-code'],
            'search_sku' => ['search_field' => 'sku', 'search_keyword' => 'zzz-no-such-sku'],
            'search_barcode' => ['search_field' => 'barcode', 'search_keyword' => 'zzz-no-such-barcode'],
            'brand_id' => ['brand_id' => 999999],
            'category_id' => ['category_id' => 999999],
            'date_type' => ['date_type' => 'created_at', 'start_date' => '2999-01-01'],
            'sales_status' => ['sales_status' => ['suspended']],
            'display_status' => ['display_status' => 'hidden'],
            'tax_status' => ['tax_status' => 'tax_free'],
            'shipping_policy_id' => ['shipping_policy_id' => 999999],
            'min_price' => ['price_type' => 'selling_price', 'min_price' => 999999999],
            'max_stock' => ['max_stock' => -1],
            // 통제 — 같은 코드로 찾으면 둘 다 그 상품을 싣는다
            'search_product_code_hit' => ['search_field' => 'product_code', 'search_keyword' => $productCode],
        ];
    }

    /**
     * 마일리지 축마다 그 거래를 제외하는 필터 payload
     *
     * @return array<string, array<string, mixed>> 필터 키 => payload
     */
    private function mileageExcludingPayloads(): array
    {
        return [
            'user_id' => ['user_id' => 999999],
            'type' => ['type' => 'use'],
            'currency' => ['currency' => 'ZZZ'],
            'start_date' => ['start_date' => '2999-01-01'],
            'end_date' => ['end_date' => '1999-01-01'],
            'search_keyword' => ['search_field' => 'member', 'search_keyword' => 'zzz-no-such-member-zzz'],
        ];
    }

    /**
     * 마일리지 목록 메서드에서 행을 거르지 않는 키 (정렬·검색 대상 지정)
     *
     * @return array<string, string> 키 => 사유
     */
    private function mileageNonFilterKeys(): array
    {
        return [
            'sort' => '정렬만 바꾼다',
            'search_field' => 'search_keyword 케이스가 덮는다',
        ];
    }

    /**
     * 주문 축마다 목록과 내보내기 결과가 같다(둘 다 그 주문을 걸러낸다).
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=filter
     *
     * @effects export_filter_matches_list_filter
     */
    #[Test]
    public function order_export_applies_every_list_filter_axis(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);

        $this->assertSame([$order->id], $this->orderListIds([]));
        $this->assertSame([$order->id], $this->ids($this->sources->orders([], null, $this->operator)));

        $leaked = [];
        foreach ($this->orderExcludingPayloads() as $axis => $filters) {
            $this->assertSame([], $this->orderListIds($filters), "주문 목록이 {$axis} 축으로 걸러내지 못했다 (케이스 payload 재검토 필요)");
            if ($this->ids($this->sources->orders($filters, null, $this->operator)) !== []) {
                $leaked[] = $axis;
            }
        }

        $this->assertSame([], $leaked, '내보내기가 무시한 주문 필터 축: '.implode(', ', $leaked));
    }

    /**
     * 상품 축마다 목록과 내보내기 결과 ID 가 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=products, scope=filter
     *
     * @effects export_filter_matches_list_filter
     */
    #[Test]
    public function product_export_matches_list_for_every_axis(): void
    {
        $product = ProductFactory::new()->create();
        $repository = app(ProductRepositoryInterface::class);

        $mismatch = [];
        foreach ($this->productExcludingPayloads((string) $product->product_code) as $axis => $filters) {
            $list = collect($repository->getListWithFilters($filters, 100)->items())->pluck('id')->sort()->values()->all();
            $export = $this->ids($this->sources->products($filters, null, $this->operator));
            if ($list !== $export) {
                $mismatch[] = $axis;
            }
        }

        $this->assertSame([], $mismatch, '상품 목록과 결과가 다른 축: '.implode(', ', $mismatch));
        $this->assertSame([$product->id], $this->ids($this->sources->products(['search_field' => 'product_code', 'search_keyword' => (string) $product->product_code], null, $this->operator)));
    }

    /**
     * 마일리지 목록 필터(`applyListFilters`) 소스에서 파생한 필터 키마다 파리티 케이스가 있다(축이 늘면 케이스도 는다).
     *
     * @return void 반환값 없음
     *
     * @scenario type=mileage, scope=filter
     *
     * @effects export_filter_matches_list_filter
     */
    #[Test]
    public function derived_mileage_filter_axes_all_have_a_parity_case(): void
    {
        $method = (new ReflectionClass(MileageTransactionRepository::class))->getMethod('applyListFilters');
        $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        preg_match_all("/\\\$filters\['([a-z_]+)'\]/", $source, $m);
        $derived = array_values(array_unique($m[1]));

        $this->assertGreaterThanOrEqual(5, count($derived), '마일리지 목록 필터 키를 소스에서 파생하지 못했다 — 이 검사가 아무것도 재지 않고 있다');

        $covered = [...array_keys($this->mileageExcludingPayloads()), ...array_keys($this->mileageNonFilterKeys())];
        $missing = array_values(array_diff($derived, $covered));

        $this->assertSame([], $missing, '마일리지 목록에 새 필터 축이 생겼다 — 파리티 케이스를 더하세요: '.implode(', ', $missing));
    }

    /**
     * 마일리지 축마다 목록과 내보내기 결과 ID 가 같다(둘 다 그 거래를 걸러낸다).
     *
     * @return void 반환값 없음
     *
     * @scenario type=mileage, scope=filter
     *
     * @effects export_filter_matches_list_filter
     */
    #[Test]
    public function mileage_export_applies_every_list_filter_axis(): void
    {
        $transaction = MileageTransaction::create([
            'user_id' => $this->operator->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 100,
            'remaining_amount' => 100,
            'balance_after' => 100,
            'granted_by' => $this->operator->id,
            'description' => 'parity',
        ]);
        $repository = app(MileageTransactionRepositoryInterface::class);
        $listIds = fn (array $filters) => collect($repository->paginateWithFilters($filters, 100)->items())->pluck('id')->sort()->values()->all();

        $this->assertSame([$transaction->id], $listIds([]));
        $this->assertSame([$transaction->id], $this->ids($this->sources->mileageTransactions([], null, $this->operator)));

        $leaked = [];
        foreach ($this->mileageExcludingPayloads() as $axis => $filters) {
            $this->assertSame([], $listIds($filters), "마일리지 목록이 {$axis} 축으로 걸러내지 못했다 (케이스 payload 재검토 필요)");
            if ($this->ids($this->sources->mileageTransactions($filters, null, $this->operator)) !== []) {
                $leaked[] = $axis;
            }
        }

        $this->assertSame([], $leaked, '내보내기가 무시한 마일리지 필터 축: '.implode(', ', $leaked));
    }

    /**
     * 목록 저장소가 다른 구현으로 바뀌면 내보내기도 그 구현의 목록 필터를 그대로 쓴다 — 목록과 같은 경로를 지난다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=filter
     *
     * @effects export_filter_matches_list_filter
     */
    #[Test]
    public function bound_repository_list_filter_is_used(): void
    {
        $filters = ['order_status' => ['x']];
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects($this->once())->method('applyListFilters')->with($this->isInstanceOf(Builder::class), $filters);
        $this->app->instance(OrderRepositoryInterface::class, $repository);

        $this->app->make(EcommerceListQueries::class)->orders($filters, null, $this->operator);
    }

    /**
     * 주문 목록 경로로 걸러진 주문 ID
     *
     * @param  array  $filters  필터
     * @return array<int, int> 주문 ID
     */
    private function orderListIds(array $filters): array
    {
        $repository = app(OrderRepositoryInterface::class);
        $this->assertInstanceOf(OrderRepository::class, $repository);

        return collect($repository->getListWithFilters($filters, 100)->items())->pluck('id')->sort()->values()->all();
    }

    /**
     * 쿼리 결과 ID 를 정렬해 반환합니다.
     *
     * @param  Builder  $query  원천 쿼리
     * @return array<int, int> 정렬한 ID 목록
     */
    private function ids(Builder $query): array
    {
        return $query->pluck('id')->sort()->values()->all();
    }
}
