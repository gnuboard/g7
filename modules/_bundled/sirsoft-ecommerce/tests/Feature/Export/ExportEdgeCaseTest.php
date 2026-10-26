<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Extension\Cache\ModuleCacheDriver;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Exports\ExportDecimal;
use Modules\Sirsoft\Ecommerce\Exports\ExportTypeRegistry;
use Modules\Sirsoft\Ecommerce\Exports\Formats\CsvExportWriter;
use Modules\Sirsoft\Ecommerce\Jobs\GenerateExportJob;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Repositories\ExportRepository;
use Modules\Sirsoft\Ecommerce\Services\ExportService;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 경계 사례 — 외부 검수(2026-10-15)가 짚은 결함의 회귀 테스트
 *
 * 합계 대상 열만 고른 파일의 합계, 요청 뒤에 생긴 상품 옵션, 같은 중복 방지 키의 동시 요청, 일회용 다운로드 토큰의
 * 동시 소비. 동시성은 한 프로세스에서 경합 순간을 그대로 만들어 재현한다(먼저 읽은 쪽이 아직 모르는 상태).
 */
class ExportEdgeCaseTest extends ExportTestCase
{
    protected User $admin;

    /**
     * 큐를 가짜로 두고 주문·상품 조회 권한을 가진 관리자를 준비합니다.
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
            'sirsoft-ecommerce.exports.download',
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.products.read',
        ]);
    }

    /**
     * 요청 → 생성까지 마치고 파일 행을 돌려줍니다.
     *
     * @param  array<string, mixed>  $body  요청 본문
     * @param  string  $key  중복 방지 키
     * @return array<int, array<int, string>> CSV 행 (빈 줄은 빈 배열)
     */
    private function generateRows(array $body, string $key): array
    {
        $id = (int) $this->actingAs($this->admin)->postJson($this->exportsUrl(), $body, ['Idempotency-Key' => $key])->assertStatus(202)->json('data.id');
        app(ExportService::class)->generate($id);
        $content = $this->actingAs($this->admin)->get($this->exportsUrl("/{$id}/download"))->assertOk()->streamedContent();

        $this->assertStringStartsWith(CsvExportWriter::BOM, $content);
        $lines = explode("\r\n", substr($content, strlen(CsvExportWriter::BOM)));
        array_pop($lines);

        return array_map(fn (string $line) => $line === '' ? [] : str_getcsv($line, ',', '"', ''), $lines);
    }

    /**
     * 고른 열이 모두 합계 대상이면 표식은 합계 위 행에 따로 쓰이고, 합계 숫자는 가려지지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, totals=on
     *
     * @effects totals_label_never_hides_a_sum
     */
    public function test_totals_label_does_not_hide_sum_when_every_column_is_summable(): void
    {
        $order = OrderFactory::new()->paid()->create();
        $this->travel(1)->seconds();

        $rows = $this->generateRows([
            'type' => 'orders', 'format' => 'csv', 'column_mode' => 'custom', 'fields' => ['order.total_amount'],
            'row_unit' => 'order', 'scope' => 'selected', 'selected_ids' => [$order->id], 'locale_mode' => 'locale', 'include_totals' => true,
        ], 'edge-totals');

        // 헤더 + 데이터 + 빈 줄 + 표식 행 + 합계 행
        $this->assertCount(5, $rows);
        $this->assertSame([], $rows[2]);
        $this->assertStringStartsWith(__('sirsoft-ecommerce::export.headers.total'), $rows[3][0]);
        $this->assertSame(ExportDecimal::normalize($order->total_amount), $rows[4][0], '합계 숫자가 표식에 가려졌습니다.');
    }

    /**
     * 옵션 단위 상품 파일 — 요청 뒤(기준 시각 이후)에 추가된 옵션은 행 수에도 파일에도 들어가지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=products, scope=selected
     *
     * @effects rows_after_cutoff_excluded
     */
    public function test_options_added_after_request_are_excluded(): void
    {
        $product = ProductFactory::new()->create();
        $before = ProductOptionFactory::new()->create(['product_id' => $product->id]);
        $this->travel(1)->seconds();

        $id = (int) $this->actingAs($this->admin)->postJson($this->exportsUrl(), [
            'type' => 'products', 'format' => 'csv', 'column_mode' => 'custom', 'fields' => ['product.product_code', 'option.option_code'],
            'row_unit' => 'option', 'scope' => 'selected', 'selected_ids' => [$product->id], 'locale_mode' => 'locale', 'include_totals' => false,
        ], ['Idempotency-Key' => 'edge-options'])->assertStatus(202)->json('data.id');

        // 큐가 돌기 전에 옵션이 하나 더 생긴다
        $this->travel(1)->seconds();
        $after = ProductOptionFactory::new()->create(['product_id' => $product->id]);

        app(ExportService::class)->generate($id);
        $export = Export::findOrFail($id);
        $content = $this->actingAs($this->admin)->get($this->exportsUrl("/{$id}/download"))->assertOk()->streamedContent();

        $this->assertSame(1, (int) $export->total_rows);
        $this->assertSame(1, (int) $export->processed_rows);
        $this->assertStringContainsString((string) $before->option_code, $content);
        $this->assertStringNotContainsString((string) $after->option_code, $content);
    }

    /**
     * 같은 중복 방지 키의 요청 두 개가 동시에 들어와, 둘 다 「기존 작업 없음」을 본 뒤 하나가 먼저 저장해도 나중 쪽은
     * 500 이 아니라 먼저 저장된 작업을 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=selected
     *
     * @effects export_idempotency_key_dedupes
     */
    public function test_concurrent_same_idempotency_key_returns_existing_job(): void
    {
        $order = OrderFactory::new()->paid()->create();
        $this->travel(1)->seconds();
        $body = [
            'type' => 'orders', 'format' => 'csv', 'column_mode' => 'custom', 'fields' => ['order.order_number'],
            'row_unit' => 'order', 'scope' => 'selected', 'selected_ids' => [$order->id], 'locale_mode' => 'locale', 'include_totals' => false,
        ];
        $firstId = (int) $this->actingAs($this->admin)->postJson($this->exportsUrl(), $body, ['Idempotency-Key' => 'edge-race'])->assertStatus(202)->json('data.id');

        // 나중 요청은 먼저 요청이 저장되기 전에 조회했다 — 그 조회를 비게 만든 저장소로 서비스를 직접 부른다
        // (HTTP 로 다시 보내면 라우트가 캐시한 컨트롤러가 이전 서비스를 그대로 쓴다)
        $racing = new class(new Export) extends ExportRepository
        {
            private bool $missed = false;

            /**
             * 첫 조회만 비운다 — 먼저 요청이 저장되기 전에 조회한 순간
             *
             * @param  int  $userId  요청자 ID
             * @param  string  $idempotencyKey  중복 방지 키
             * @return Export|null 기존 작업
             */
            public function findByIdempotencyKey(int $userId, string $idempotencyKey): ?Export
            {
                if (! $this->missed) {
                    $this->missed = true;

                    return null;
                }

                return parent::findByIdempotencyKey($userId, $idempotencyKey);
            }
        };
        $type = app(ExportTypeRegistry::class)->find('orders');
        $export = $this->app->make(ExportService::class, ['exports' => $racing])->request($type, $body, $this->admin, 'edge-race');

        $this->assertSame($firstId, $export->id);
        $this->assertSame(1, Export::count());
    }

    /**
     * 「조건 없이 전체」 + 「목록의 조회 기간」 — 화면 조건 중 기간만 쓰고 검색어 등 나머지는 버린다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=all
     *
     * @effects export_filter_matches_list_filter
     */
    public function test_all_scope_keeps_only_list_period(): void
    {
        $start = now()->subDays(7)->toDateString();
        $end = now()->toDateString();

        $id = (int) $this->actingAs($this->admin)->postJson($this->exportsUrl(), [
            'type' => 'orders', 'format' => 'csv', 'column_mode' => 'custom', 'fields' => ['order.order_number'],
            'row_unit' => 'order', 'scope' => 'all', 'period' => ['mode' => 'current'], 'locale_mode' => 'locale', 'include_totals' => false,
            'filters' => ['date_type' => 'ordered_at', 'start_date' => $start, 'end_date' => $end, 'search_field' => 'all', 'search_keyword' => 'no-such-order'],
        ], ['Idempotency-Key' => 'edge-all-period'])->assertStatus(202)->json('data.id');

        $filters = (array) Export::findOrFail($id)->filters;
        $this->assertSame($start, $filters['start_date'] ?? null);
        $this->assertSame($end, $filters['end_date'] ?? null);
        $this->assertArrayNotHasKey('search_keyword', $filters, '「전체」인데 검색어가 조건에 남았습니다.');
    }

    /**
     * 같은 일회용 주소를 두 요청이 동시에 열어 둘 다 토큰을 읽었어도, 파일을 받는 것은 하나뿐이다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=download
     *
     * @effects download_link_rechecks_permission
     */
    public function test_one_time_token_is_consumed_once_under_race(): void
    {
        $order = OrderFactory::new()->paid()->create();
        $this->travel(1)->seconds();
        $id = (int) $this->actingAs($this->admin)->postJson($this->exportsUrl(), [
            'type' => 'orders', 'format' => 'csv', 'column_mode' => 'custom', 'fields' => ['order.order_number'],
            'row_unit' => 'order', 'scope' => 'selected', 'selected_ids' => [$order->id], 'locale_mode' => 'locale', 'include_totals' => false,
        ], ['Idempotency-Key' => 'edge-token'])->assertStatus(202)->json('data.id');
        app(ExportService::class)->generate($id);

        $service = app(ExportService::class);
        $token = $service->issueDownloadToken(Export::findOrFail($id), $this->admin);

        // 다른 요청이 같은 토큰을 이미 읽어 둔 상태 — 그 요청이 들고 있는 값은 캐시에서 지워진 뒤에도 남는다
        $stale = new class('sirsoft-ecommerce') extends ModuleCacheDriver
        {
            /** @var array<string, mixed> */
            public array $seen = [];

            /**
             * 한 번 읽은 값을 계속 돌려준다 — 먼저 읽어 둔 요청이 들고 있는 값
             *
             * @param  string  $key  캐시 키
             * @param  mixed  $default  기본값
             * @return mixed 처음 읽은 값
             */
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->seen[$key] ??= parent::get($key, $default);
            }
        };
        $racing = $this->app->make(ExportService::class, ['cache' => $stale]);
        $first = $racing->consumeDownloadToken($token);
        $second = $racing->consumeDownloadToken($token);

        $this->assertNotNull($first);
        $this->assertNull($second, '이미 쓴 토큰을 두 번째 요청도 썼습니다.');
    }
}
