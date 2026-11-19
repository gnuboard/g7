<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Jobs\GenerateExportJob;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Services\ExportService;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 대용량 실측 — 주문 10만 건을 내보낼 때 시간과 메모리
 *
 * 행은 500행 청크로 읽으므로 메모리는 행 수가 아니라 청크 크기에 묶여야 한다. 주문 1건을 팩토리로 만들고 같은 행을
 * 주문번호만 바꿔 끼워 넣어 10만 건을 만든 뒤, 파일 생성의 소요 시간과 메모리 증가량을 재고 표준 오류로 남긴다
 * (이력 문서에 옮긴다). 트랜잭션으로 격리돼 테스트 DB 에 남지 않는다.
 */
class ExportScaleTest extends ExportTestCase
{
    /**
     * 만들 주문 수
     */
    private const ROWS = 100000;

    /**
     * 생성 중 허용하는 메모리 증가량(바이트) — 청크 크기에 묶인다는 판정선
     */
    private const MEMORY_BUDGET = 64 * 1024 * 1024;

    /**
     * 주문 10만 건을 모두 내보내고, 메모리 증가량이 판정선 안이다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=all
     *
     * @scale n=100000 asserts=chunked_scan_bounded_memory,all_rows_exported_across_chunks
     *
     * @effects rows_streamed_in_chunks
     */
    public function test_hundred_thousand_orders_export_with_bounded_memory(): void
    {
        Bus::fake([GenerateExportJob::class]);
        $admin = $this->createAdminUser([
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.exports.create',
            'sirsoft-ecommerce.exports.read',
        ]);

        $template = OrderFactory::new()->paid()->forGuest()->create(['ordered_at' => now()]);
        $row = (array) DB::table($template->getTable())->where('id', $template->id)->first();
        unset($row['id']);
        // 500행씩 — 주문 열이 70개 가까이 돼 1000행이면 자리표시자 상한(65535)을 넘는다
        for ($offset = 1; $offset < self::ROWS; $offset += 500) {
            $batch = [];
            for ($i = $offset; $i < min($offset + 500, self::ROWS); $i++) {
                $batch[] = ['order_number' => 'SCALE-'.$i] + $row;
            }
            DB::table($template->getTable())->insert($batch);
        }
        $this->travel(1)->seconds();

        $id = (int) $this->actingAs($admin)->postJson($this->exportsUrl(), [
            'type' => 'orders', 'format' => 'csv', 'column_mode' => 'custom',
            'fields' => ['order.order_number', 'order.total_amount'], 'row_unit' => 'order', 'scope' => 'all',
            'period' => ['mode' => 'custom', 'date_type' => 'ordered_at', 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString()],
            'locale_mode' => 'locale', 'include_totals' => true,
        ], ['Idempotency-Key' => 'scale-1'])->assertStatus(202)->json('data.id');

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $started = hrtime(true);

        app(ExportService::class)->generate($id);

        $seconds = (hrtime(true) - $started) / 1e9;
        $growth = memory_get_peak_usage() - $before;
        $export = Export::findOrFail($id);

        fwrite(STDERR, sprintf("\n[scale] rows=%d seconds=%.2f peak_growth_mb=%.1f file_bytes=%d\n", (int) $export->processed_rows, $seconds, $growth / 1048576, (int) $export->file_size));

        $this->assertSame(self::ROWS, (int) $export->processed_rows, '청크를 넘나들며 모든 행이 파일에 들어가지 않았습니다.');
        $this->assertLessThan(self::MEMORY_BUDGET, $growth, '행 수에 비례해 메모리가 늘었습니다 — 청크 순회가 아닙니다.');
    }
}
