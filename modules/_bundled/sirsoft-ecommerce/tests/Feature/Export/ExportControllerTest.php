<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Exports\ExportDecimal;
use Modules\Sirsoft\Ecommerce\Exports\Formats\CsvExportWriter;
use Modules\Sirsoft\Ecommerce\Jobs\GenerateExportJob;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Models\ExportDownload;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\ExportService;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 내보내기 API Feature 테스트
 *
 * 검증 축:
 * - 요청: 202 접수·중복 방지 키·필수 헤더·기간 1년 상한·요청자당 동시 3건 상한
 * - 권한: 원본 조회 권한(orders.read)·개인정보 권한과 사유·본인 범위(self)
 * - 생성: 주문상품 단위의 주문 금액은 첫 행에만(합계가 두 번 더해지지 않음)·기준 시각 뒤 주문 제외·청크 순회
 * - 다운로드: 이력·횟수·활동 로그, 원본 조회 권한을 잃으면 403
 * - 관리: 선택 삭제는 진행중을 건너뜀·실패 건 다시 요청·보관 기간 만료 정리
 *
 * 큐는 가짜로 두고 파일 생성은 서비스의 `generate()` 를 직접 불러 확정적으로 검증한다.
 */
class ExportControllerTest extends ExportTestCase
{
    /**
     * 내보내기 전 권한 + 주문 조회 권한
     *
     * @var array<int, string>
     */
    private const FULL_PERMISSIONS = [
        'sirsoft-ecommerce.exports.create',
        'sirsoft-ecommerce.exports.read',
        'sirsoft-ecommerce.exports.download',
        'sirsoft-ecommerce.exports.delete',
        'sirsoft-ecommerce.orders.read',
    ];

    protected User $admin;

    /**
     * 저장소·큐를 가짜로 두고 전 권한 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([GenerateExportJob::class]);

        $this->admin = $this->createAdminUser(self::FULL_PERMISSIONS);
    }

    /**
     * 상품 두 개짜리 결제완료 주문을 만듭니다(기준 시각보다 앞서도록 1초 뒤로 시계를 민다).
     *
     * @return Order 주문
     */
    private function makeOrder(): Order
    {
        $order = OrderFactory::new()->paid()->create();
        OrderOptionFactory::new()->count(2)->create(['order_id' => $order->id]);
        $this->travel(1)->seconds();

        return $order->refresh();
    }

    /**
     * 주문상품 단위·선택 범위 요청 본문
     *
     * @param  array<int, int>  $ids  주문 ID
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 본문
     */
    private function body(array $ids, array $overrides = []): array
    {
        return array_merge([
            'type' => 'orders',
            'format' => 'csv',
            'column_mode' => 'custom',
            'fields' => ['order.order_number', 'option.sku', 'order.total_amount'],
            'row_unit' => 'order_option',
            'scope' => 'selected',
            'selected_ids' => $ids,
            'locale_mode' => 'locale',
            'include_totals' => true,
        ], $overrides);
    }

    /**
     * 내보내기를 요청합니다.
     *
     * @param  User  $user  요청자
     * @param  array<string, mixed>  $body  본문
     * @param  string|null  $key  중복 방지 키 (null 이면 헤더 없음)
     * @return TestResponse 응답
     */
    private function request(User $user, array $body, ?string $key = 'key-1'): TestResponse
    {
        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->actingAs($user)->postJson($this->exportsUrl(), $body, $headers);
    }

    /**
     * 요청 → 생성까지 마친 작업을 돌려줍니다.
     *
     * @param  array<string, mixed>  $body  본문
     * @return Export 작업
     */
    private function generated(array $body): Export
    {
        $id = (int) $this->request($this->admin, $body)->assertStatus(202)->json('data.id');
        app(ExportService::class)->generate($id);

        return Export::findOrFail($id);
    }

    /**
     * CSV 본문을 행 배열로 풉니다(BOM 제거).
     *
     * @param  string  $content  파일 내용
     * @return array<int, array<int, string>> 행
     */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith(CsvExportWriter::BOM, $content);
        $lines = explode("\r\n", substr($content, strlen(CsvExportWriter::BOM)));

        return array_map(fn (string $line) => $line === '' ? [] : str_getcsv($line, ',', '"', ''), array_values(array_filter($lines, fn ($l, $i) => $i < count($lines) - 1, ARRAY_FILTER_USE_BOTH)));
    }

    /**
     * 요청은 202 로 접수되어 대기 작업이 되고 큐 작업이 발행된다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=selected
     *
     * @effects export_request_creates_pending_job
     */
    public function test_request_creates_pending_job(): void
    {
        $order = $this->makeOrder();

        $response = $this->request($this->admin, $this->body([$order->id]))->assertStatus(202);

        $export = Export::findOrFail($response->json('data.id'));
        $this->assertSame(ExportStatusEnum::PENDING, $export->status);
        $this->assertSame($this->admin->id, (int) $export->requested_by);
        $this->assertFalse((bool) $export->contains_personal_data);
        Bus::assertDispatchedAfterResponse(GenerateExportJob::class);
        $this->assertSame(1, ActivityLog::where('action', 'export.request')->count());
    }

    /**
     * 같은 요청자·같은 중복 방지 키면 새 작업을 만들지 않고 기존 작업을 돌려준다. 키가 없으면 422.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=selected
     *
     * @effects export_idempotency_key_dedupes
     */
    public function test_same_idempotency_key_returns_existing_job(): void
    {
        $order = $this->makeOrder();

        $first = $this->request($this->admin, $this->body([$order->id]), 'same-key')->assertStatus(202)->json('data.id');
        $second = $this->request($this->admin, $this->body([$order->id]), 'same-key')->assertStatus(202)->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Export::count());

        $this->request($this->admin, $this->body([$order->id]), null)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    /**
     * 기간은 시작일 포함 1년까지다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=all
     *
     * @effects export_period_capped_at_one_year
     */
    public function test_period_longer_than_one_year_is_rejected(): void
    {
        $body = $this->body([], [
            'scope' => 'all',
            'period' => ['mode' => 'custom', 'date_type' => 'ordered_at', 'start_date' => '2026-01-01', 'end_date' => '2027-01-01'],
        ]);

        $this->request($this->admin, $body)->assertStatus(422)->assertJsonValidationErrors(['period']);

        $body['period']['end_date'] = '2026-12-31';
        $this->request($this->admin, $body, 'key-2')->assertStatus(202);
    }

    /**
     * 요청자당 대기+진행중은 3건까지다 — 넷째는 422 이고 거부가 활동 로그에 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=selected
     *
     * @effects export_active_jobs_capped_per_requester
     */
    public function test_fourth_active_request_is_rejected(): void
    {
        $order = $this->makeOrder();

        foreach (['a', 'b', 'c'] as $key) {
            $this->request($this->admin, $this->body([$order->id]), $key)->assertStatus(202);
        }

        $this->request($this->admin, $this->body([$order->id]), 'd')->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->assertSame(3, Export::count());
        $this->assertSame(1, ActivityLog::where('action', 'export.request_denied')->count());
    }

    /**
     * 원본 목록 조회 권한(orders.read)이 없으면 카탈로그·요청 모두 403 이고 거부가 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, permission=no_domain_read
     *
     * @effects export_domain_read_required
     */
    public function test_domain_read_permission_is_required(): void
    {
        $order = $this->makeOrder();
        $operator = $this->createAdminUser(['sirsoft-ecommerce.exports.create', 'sirsoft-ecommerce.exports.read']);

        $this->actingAs($operator)->getJson($this->exportsUrl('/catalog?type=orders'))->assertStatus(403);
        $this->request($operator, $this->body([$order->id]))->assertStatus(403);

        $this->assertSame(0, Export::count());
        $this->assertSame(1, ActivityLog::where('action', 'export.request_denied')->count());
    }

    /**
     * 개인정보 필드는 개인정보 권한이 있어야 고를 수 있고(카탈로그에도 없다), 고르면 사유가 필수다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, permission=personal_data
     *
     * @effects export_personal_fields_need_permission_and_reason
     */
    public function test_personal_fields_need_permission_and_reason(): void
    {
        $order = $this->makeOrder();
        $body = $this->body([$order->id], ['row_unit' => 'order', 'fields' => ['order.order_number', 'address.orderer_name']]);

        $catalog = $this->actingAs($this->admin)->getJson($this->exportsUrl('/catalog?type=orders'))->assertOk()->json('data.fields.order');
        $this->assertNotContains('address.orderer_name', array_column($catalog, 'key'));
        // 사유를 적어도 권한이 없으면 막힌다(검증은 통과하고 컨트롤러가 403)
        $this->request($this->admin, [...$body, 'reason' => '월말 정산'])->assertStatus(403);

        $privileged = $this->createAdminUser([...self::FULL_PERMISSIONS, 'sirsoft-ecommerce.exports.personal_data']);
        $this->request($privileged, $body, 'p-1')->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $id = $this->request($privileged, [...$body, 'reason' => '월말 정산'], 'p-2')->assertStatus(202)->json('data.id');
        $this->assertTrue((bool) Export::findOrFail($id)->contains_personal_data);
    }

    /**
     * 주문상품 단위 파일 — 주문 금액은 그 주문의 첫 행에만 쓰이고, 합계 행은 주문 금액을 한 번만 더한다.
     * 다운로드는 이력·횟수·활동 로그를 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, row_unit=order_option, totals=on
     *
     * @effects parent_level_amount_written_on_first_row_only, totals_count_parent_amount_once, download_records_history
     */
    public function test_generate_and_download_order_option_file(): void
    {
        $order = $this->makeOrder();
        $export = $this->generated($this->body([$order->id]));

        $this->assertSame(ExportStatusEnum::COMPLETED, $export->status);
        $this->assertSame(2, (int) $export->processed_rows);

        $response = $this->actingAs($this->admin)->get($this->exportsUrl("/{$export->id}/download"))->assertOk();
        $rows = $this->csvRows($response->streamedContent());

        // 헤더 + 상품 두 줄 + 빈 줄 + 합계 한 줄
        $this->assertCount(5, $rows);
        $amount = ExportDecimal::normalize($order->total_amount);
        $this->assertSame([$order->order_number, $order->order_number], [$rows[1][0], $rows[2][0]]);
        $this->assertSame($amount, $rows[1][2]);
        $this->assertSame('', $rows[2][2], '주문 금액이 둘째 상품 행에도 적혔습니다 — 합계가 두 번 더해집니다.');
        $this->assertSame([], $rows[3]);
        $this->assertSame($amount, $rows[4][2]);

        $export->refresh();
        $this->assertSame(1, (int) $export->download_count);
        $this->assertSame(1, ExportDownload::where('export_id', $export->id)->count());
        $this->assertSame(1, ActivityLog::where('action', 'export.download')->count());
    }

    /**
     * 요청 뒤(기준 시각 이후)에 생긴 주문은 파일에 들어가지 않는다 — 큐가 늦게 돌아도 요청 시점의 결과다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=selected
     *
     * @effects rows_after_cutoff_excluded
     */
    public function test_rows_created_after_request_are_excluded(): void
    {
        $before = $this->makeOrder();
        $id = (int) $this->request($this->admin, $this->body([], ['scope' => 'all', 'row_unit' => 'order', 'fields' => ['order.order_number'], 'include_totals' => false, 'period' => ['mode' => 'custom', 'date_type' => 'ordered_at', 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString()]]))->assertStatus(202)->json('data.id');

        $this->travel(1)->minutes();
        $after = OrderFactory::new()->paid()->create(['ordered_at' => now()]);

        app(ExportService::class)->generate($id);
        $content = $this->actingAs($this->admin)->get($this->exportsUrl("/{$id}/download"))->assertOk()->streamedContent();

        $this->assertStringContainsString($before->order_number, $content);
        $this->assertStringNotContainsString($after->order_number, $content);
    }

    /**
     * 대상 행은 한 번에 읽지 않고 500행 청크로 순회한다 — 행 수와 무관하게 메모리가 청크 크기로 묶인다.
     *
     * 청크보다 많은 행을 넣고, 주문 조회가 전부 `limit 500` 청크이며 모든 행이 파일에 들어가는지 본다. 10만 건 규모의
     * 시간·메모리는 ExportScaleTest 가 잰다.
     *
     * @return void 반환값 없음
     *
     * @effects rows_streamed_in_chunks
     */
    public function test_rows_are_streamed_in_chunks(): void
    {
        OrderFactory::new()->paid()->forGuest()->count(1100)->create();
        $this->travel(1)->seconds();

        $id = (int) $this->request($this->admin, $this->body([], ['scope' => 'all', 'row_unit' => 'order', 'fields' => ['order.order_number'], 'include_totals' => false, 'period' => ['mode' => 'custom', 'date_type' => 'ordered_at', 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString()]]))->assertStatus(202)->json('data.id');

        DB::enableQueryLog();
        app(ExportService::class)->generate($id);
        $selects = array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^select .* from [`"]?\w*ecommerce_orders[`"]? /i', $q['query']) && ! preg_match('/count\(/i', $q['query']));
        DB::disableQueryLog();

        $this->assertGreaterThanOrEqual(3, count($selects), '1100행인데 청크가 3번 미만 — 청크 순회가 아니다');
        foreach ($selects as $q) {
            $this->assertMatchesRegularExpression('/limit 500\b/i', $q['query'], '청크 없이 전 행을 읽었다');
        }
        $this->assertSame(1100, (int) Export::findOrFail($id)->processed_rows);
    }

    /**
     * 원본 조회 권한을 잃은 운영자는 예전에 만든 파일도 내려받지 못한다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, permission=no_domain_read
     *
     * @effects download_requires_domain_read
     */
    public function test_download_requires_domain_read(): void
    {
        $order = $this->makeOrder();
        $export = $this->generated($this->body([$order->id]));

        $operator = $this->createAdminUser(['sirsoft-ecommerce.exports.read', 'sirsoft-ecommerce.exports.download']);
        $this->actingAs($operator)->get($this->exportsUrl("/{$export->id}/download"))->assertStatus(403);
        $this->assertSame(0, ExportDownload::count());
    }

    /**
     * 「다른 운영자의 내보내기 관리」 권한(all)이 없는 운영자는 남이 만든 작업을 목록에서 보지 못하고 상세·다운로드·삭제도
     * 할 수 없다. 그 권한이 있으면 남의 작업도 보인다.
     *
     * 범위는 별도 권한(`exports.all`)으로 나뉘어 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, permission=self_scope
     *
     * @effects self_scope_hides_others_jobs, all_permission_reveals_others_jobs
     */
    public function test_without_all_permission_others_jobs_are_hidden(): void
    {
        $order = $this->makeOrder();
        $export = $this->generated($this->body([$order->id]));

        $own = ['sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.exports.read', 'sirsoft-ecommerce.exports.download', 'sirsoft-ecommerce.exports.delete'];
        $operator = $this->createAdminUser($own);

        $this->actingAs($operator)->getJson($this->exportsUrl())->assertOk()->assertJsonCount(0, 'data.data');
        $this->actingAs($operator)->getJson($this->exportsUrl("/{$export->id}"))->assertStatus(403);
        $this->actingAs($operator)->get($this->exportsUrl("/{$export->id}/download"))->assertStatus(403);
        $this->actingAs($operator)->deleteJson($this->exportsUrl("/{$export->id}"))->assertStatus(403);
        $this->actingAs($operator)->deleteJson($this->exportsUrl('/bulk'), ['ids' => [$export->id]])
            ->assertOk()->assertJsonPath('data.deleted_count', 0)->assertJsonPath('data.skipped_count', 1);
        $this->assertSame(0, ExportDownload::count());
        $this->assertNotSoftDeleted('ecommerce_exports', ['id' => $export->id]);

        $manager = $this->createAdminUser([...$own, 'sirsoft-ecommerce.exports.all']);
        $this->actingAs($manager)->getJson($this->exportsUrl())->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.abilities.can_download', true);
        $this->actingAs($manager)->getJson($this->exportsUrl("/{$export->id}"))->assertOk();
    }

    /**
     * 선택 삭제는 진행중 작업을 건너뛰고 나머지를 지운 뒤 건수를 알린다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, action=bulk_delete
     *
     * @effects bulk_delete_skips_processing
     */
    public function test_bulk_delete_skips_processing_jobs(): void
    {
        $order = $this->makeOrder();
        $done = $this->generated($this->body([$order->id]));
        $processingId = (int) $this->request($this->admin, $this->body([$order->id]), 'key-p')->json('data.id');
        Export::whereKey($processingId)->update(['status' => ExportStatusEnum::PROCESSING->value]);

        $this->actingAs($this->admin)->deleteJson($this->exportsUrl('/bulk'), ['ids' => [$done->id, $processingId]])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1)
            ->assertJsonPath('data.skipped_count', 1);

        $this->assertSoftDeleted('ecommerce_exports', ['id' => $done->id]);
        $this->assertNotSoftDeleted('ecommerce_exports', ['id' => $processingId]);
        Storage::disk('modules')->assertMissing($this->storedPath($done->file_path));
    }

    /**
     * 실패한 작업은 같은 조건의 새 작업으로 다시 요청할 수 있고, 실패하지 않은 작업은 409 다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, action=retry
     *
     * @effects failed_job_can_be_retried
     */
    public function test_failed_job_can_be_retried(): void
    {
        $order = $this->makeOrder();
        $id = (int) $this->request($this->admin, $this->body([$order->id]))->json('data.id');
        app(ExportService::class)->markFailed($id, 'timeout');

        $newId = $this->actingAs($this->admin)->postJson($this->exportsUrl("/{$id}/retry"))->assertStatus(202)->json('data.id');

        $this->assertNotSame($id, $newId);
        $original = Export::findOrFail($id);
        $retried = Export::findOrFail($newId);
        $this->assertSame($original->options['fields'], $retried->options['fields']);
        $this->assertSame($original->options['row_unit'], $retried->options['row_unit']);
        $this->assertSame($original->selected_ids, $retried->selected_ids);
        $this->assertSame(1, ActivityLog::where('action', 'export.retry')->count());

        $this->actingAs($this->admin)->postJson($this->exportsUrl("/{$newId}/retry"))->assertStatus(409);
    }

    /**
     * 다시 요청할 조건에 지금 내보낼 행이 없으면 새 작업을 만들지 않고 422 로 알린다 — 일반 요청 창이 예상 0행이면
     * 막는 것과 같은 기준이다(빈 파일과 「0행 완료」 알림을 만들지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario action=retry
     *
     * @effects retry_with_no_rows_is_rejected
     */
    public function test_retry_without_rows_is_rejected(): void
    {
        $order = $this->makeOrder();
        $id = (int) $this->request($this->admin, $this->body([$order->id]))->json('data.id');
        app(ExportService::class)->markFailed($id, 'timeout');
        $order->delete();
        $before = Export::count();

        $this->actingAs($this->admin)->postJson($this->exportsUrl("/{$id}/retry"))
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.export.no_rows'));

        $this->assertSame($before, Export::count(), '0행 조건으로 새 작업이 만들어졌다');
        $this->assertSame(0, ActivityLog::where('action', 'export.retry')->count());
    }

    /**
     * 보관 기간이 지난 완료 파일은 정리 때 지워지고 「만료」가 된다(작업 기록은 남는다).
     *
     * @return void 반환값 없음
     *
     * @scenario action=prune
     *
     * @effects expired_files_pruned
     */
    public function test_prune_expires_old_files(): void
    {
        $order = $this->makeOrder();
        $export = $this->generated($this->body([$order->id]));
        Storage::disk('modules')->assertExists($this->storedPath($export->file_path));

        $result = app(ExportService::class)->prune(now()->addDays(ExportService::DEFAULT_RETENTION_DAYS + 1)->toImmutable());

        $this->assertSame(1, $result['expired']);
        $export->refresh();
        $this->assertSame(ExportStatusEnum::EXPIRED, $export->status);
        $this->assertNull($export->file_path);
        $this->assertSame(1, ActivityLog::where('action', 'export.expire')->count());
        $this->actingAs($this->admin)->get($this->exportsUrl("/{$export->id}/download"))->assertStatus(410);
    }
}
