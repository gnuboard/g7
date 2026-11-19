<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Jobs\GenerateExportJob;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 요청 조건 요약의 기간 줄(펼침 줄·다운로드 이력 창) — 파일에 실제로 걸린 기간을 말하는지 잠근다.
 *
 * 검증 축:
 * - 「선택한 항목」: 기간과 관계없이 내보내므로 「적용 안 함」
 * - 「현재 검색 조건」인데 목록에 기간이 없음(상품): 「적용 안 함」
 * - 「목록의 조회 기간」이 실제로 걸림: 「목록의 현재 기간」
 * - 직접 지정: 날짜 범위
 */
class ExportSummaryPeriodTest extends ExportTestCase
{
    protected User $admin;

    /**
     * 큐를 가짜로 두고 주문·상품을 내보낼 수 있는 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([GenerateExportJob::class]);

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.exports.create',
            'sirsoft-ecommerce.exports.read',
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.products.read',
        ]);
    }

    /**
     * 내보내기를 요청하고 상세의 요청 조건 요약 기간 줄을 돌려줍니다.
     *
     * @param  array<string, mixed>  $body  요청 본문
     * @return string 기간 줄
     */
    private function summaryPeriod(array $body): string
    {
        $id = (int) $this->actingAs($this->admin)
            ->postJson($this->exportsUrl(), array_merge([
                'format' => 'csv', 'column_mode' => 'custom', 'locale_mode' => 'locale', 'include_totals' => false,
            ], $body), ['Idempotency-Key' => 'summary-'.uniqid()])
            ->assertStatus(202)
            ->json('data.id');

        return (string) $this->actingAs($this->admin)
            ->getJson($this->exportsUrl("/{$id}"))
            ->assertOk()
            ->json('data.summary.period');
    }

    /**
     * 「선택한 항목」은 기간을 보지 않으므로 기간 줄이 「적용 안 함」이다(「목록의 현재 기간」이 아니다).
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=selected
     *
     * @effects summary_period_reflects_applied_period
     */
    public function test_selected_scope_reports_no_period(): void
    {
        $order = OrderFactory::new()->paid()->create();
        $this->travel(1)->seconds();

        $period = $this->summaryPeriod([
            'type' => 'orders', 'fields' => ['order.order_number'], 'row_unit' => 'order',
            'scope' => 'selected', 'selected_ids' => [$order->id], 'period' => null,
        ]);

        $this->assertSame(__('sirsoft-ecommerce::export.summary.period_none'), $period);
        $this->assertNotSame(__('sirsoft-ecommerce::export.summary.period_current'), $period);
    }

    /**
     * 기간 칸이 비어 있는 상품 목록을 「현재 검색 조건」으로 내보내면 걸린 기간이 없으므로 「적용 안 함」이다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=products, scope=filter
     *
     * @effects summary_period_reflects_applied_period
     */
    public function test_filter_scope_without_list_period_reports_no_period(): void
    {
        ProductFactory::new()->create();
        $this->travel(1)->seconds();

        $period = $this->summaryPeriod([
            'type' => 'products', 'fields' => ['product.product_code'], 'row_unit' => 'product',
            'scope' => 'filter', 'filters' => [], 'period' => ['mode' => 'current'],
        ]);

        $this->assertSame(__('sirsoft-ecommerce::export.summary.period_none'), $period);
    }

    /**
     * 목록의 조회 기간이 실제로 걸린 요청은 「목록의 현재 기간」, 직접 지정한 기간은 날짜 범위를 그대로 보인다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=all
     *
     * @effects summary_period_reflects_applied_period
     */
    public function test_applied_period_is_reported(): void
    {
        OrderFactory::new()->paid()->create();
        $this->travel(1)->seconds();
        $start = now()->subDays(7)->toDateString();
        $end = now()->toDateString();

        $current = $this->summaryPeriod([
            'type' => 'orders', 'fields' => ['order.order_number'], 'row_unit' => 'order', 'scope' => 'all',
            'period' => ['mode' => 'current'],
            'filters' => ['date_type' => 'ordered_at', 'start_date' => $start, 'end_date' => $end],
        ]);
        $this->assertSame(__('sirsoft-ecommerce::export.summary.period_current'), $current);

        $custom = $this->summaryPeriod([
            'type' => 'orders', 'fields' => ['order.order_number'], 'row_unit' => 'order', 'scope' => 'all',
            'period' => ['mode' => 'custom', 'date_type' => 'ordered_at', 'start_date' => $start, 'end_date' => $end],
        ]);
        $this->assertSame("{$start} ~ {$end}", $custom);
    }
}
