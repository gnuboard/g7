<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\ModuleStorageDriver;
use App\Models\User;
use App\Services\Queue\QueueWorkerRegistry;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Exports\ExportColumn;
use Modules\Sirsoft\Ecommerce\Exports\ExportField;
use Modules\Sirsoft\Ecommerce\Exports\ExportFieldType;
use Modules\Sirsoft\Ecommerce\Exports\ExportRunContext;
use Modules\Sirsoft\Ecommerce\Exports\ExportValueFormatter;
use Modules\Sirsoft\Ecommerce\Jobs\GenerateExportJob;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Repositories\ExportRepository;
use Modules\Sirsoft\Ecommerce\Services\ExportService;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 운영 안정성 — 외부 검수 2차(2026-10-15)가 짚은 결함의 회귀 테스트
 *
 * 파일 삭제가 실패해도 기록을 지우지 않고 다음 정리가 다시 시도하는가, 만료 시각이 지난 파일은 받을 수 없고 정리는
 * 유예 뒤에 하는가, 날짜 전용 값이 시간대 때문에 하루 밀리지 않는가, 큐가 실행 중인 작업을 다시 집어도 그 작업을
 * 실패로 닫지 않는가, 긴 작업 큐가 있으면 거기로 가는가, 일회용 주소 발급 응답이 지금 횟수를 함께 주는가.
 */
class ExportRobustnessTest extends ExportTestCase
{
    protected User $admin;

    /**
     * 내려받기 권한을 가진 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.exports.read',
            'sirsoft-ecommerce.exports.download',
            'sirsoft-ecommerce.exports.delete',
            'sirsoft-ecommerce.orders.read',
        ]);
    }

    /**
     * 파일이 있는 완료 작업을 만듭니다.
     *
     * @param  string|null  $expiresAt  만료 시각
     * @param  ExportStatusEnum  $status  상태
     * @return Export 작업
     */
    private function storedExport(?string $expiresAt, ExportStatusEnum $status = ExportStatusEnum::COMPLETED): Export
    {
        $path = 'fixture/'.uniqid().'.csv';
        Storage::disk('modules')->put($this->storedPath($path), "a,b\r\n");

        return Export::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => 'orders',
            'format' => 'csv',
            'status' => $status->value,
            'requested_by' => $this->admin->id,
            'options' => ['fields' => ['order.order_number']],
            'file_name' => 'orders.csv',
            'file_path' => $path,
            'requested_at' => now()->subDays(10),
            'completed_at' => now()->subDays(10),
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * 삭제를 실패시키는 저장소로 서비스를 만듭니다.
     *
     * @return array{0: ExportService, 1: object} 서비스와 저장소(`fail` 을 끄면 정상 삭제)
     */
    private function serviceWithFailingStorage(): array
    {
        $storage = new class('sirsoft-ecommerce', 'modules') extends ModuleStorageDriver
        {
            public bool $fail = true;

            /**
             * 실패 스위치가 켜져 있으면 지우지 않고 실패를 돌려준다.
             *
             * @param  string  $category  카테고리
             * @param  string  $path  경로
             * @return bool 삭제 여부
             */
            public function delete(string $category, string $path): bool
            {
                return $this->fail ? false : parent::delete($category, $path);
            }
        };

        return [$this->app->make(ExportService::class, ['storage' => $storage]), $storage];
    }

    /**
     * 정리 중 파일 삭제가 실패하면 기록을 만료로 바꾸지 않고 경로를 남긴다 — 다음 정리가 다시 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=prune
     *
     * @effects expired_files_pruned
     */
    public function test_prune_keeps_record_when_file_delete_fails_and_retries_later(): void
    {
        $export = $this->storedExport(now()->subDay()->toDateTimeString());
        [$service, $storage] = $this->serviceWithFailingStorage();

        $first = $service->prune();
        $export->refresh();
        $this->assertSame(0, $first['expired']);
        $this->assertSame(ExportStatusEnum::COMPLETED, $export->status);
        $this->assertNotNull($export->file_path, '파일을 못 지웠는데 경로를 지워 다음 정리가 찾지 못합니다.');

        $storage->fail = false;
        $second = $service->prune();
        $this->assertSame(1, $second['expired']);
        $this->assertSame(ExportStatusEnum::EXPIRED, $export->refresh()->status);
        $this->assertNull($export->file_path);
    }

    /**
     * 사용자 삭제에서 파일 삭제가 실패해도 작업은 지워지되 경로가 남고, 다음 정리가 그 파일을 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=bulk_delete
     *
     * @effects bulk_delete_skips_processing
     */
    public function test_deleted_job_file_is_retried_by_prune(): void
    {
        $export = $this->storedExport(now()->addDays(3)->toDateTimeString());
        $stored = $this->storedPath((string) $export->file_path);
        [$service, $storage] = $this->serviceWithFailingStorage();

        $service->delete($export, $this->admin);
        $trashed = Export::withTrashed()->findOrFail($export->id);
        $this->assertTrue($trashed->trashed());
        $this->assertNotNull($trashed->file_path);
        Storage::disk('modules')->assertExists($stored);

        $storage->fail = false;
        $service->prune();
        Storage::disk('modules')->assertMissing($stored);
        $this->assertNull(Export::withTrashed()->findOrFail($export->id)->file_path);
    }

    /**
     * 만료 시각이 지난 파일은 정리 전이라도 받을 수 없고, 정리는 만료 뒤 유예 시간이 지나야 파일을 지운다 —
     * 만료 직전에 시작한 다운로드가 전송 중에 파일을 잃지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=prune
     *
     * @effects expired_files_pruned
     */
    public function test_expired_file_is_not_downloadable_and_prune_waits_for_grace(): void
    {
        $justExpired = $this->storedExport(now()->subMinute()->toDateTimeString());
        $longExpired = $this->storedExport(now()->subMinutes(ExportService::PRUNE_GRACE_MINUTES + 1)->toDateTimeString());

        $this->assertFalse($justExpired->isDownloadable());
        $this->actingAs($this->admin)->postJson($this->exportsUrl("/{$justExpired->id}/download-link"))->assertStatus(410);

        $result = app(ExportService::class)->prune();

        $this->assertSame(1, $result['expired']);
        $this->assertSame(ExportStatusEnum::COMPLETED, $justExpired->refresh()->status);
        Storage::disk('modules')->assertExists($this->storedPath((string) $justExpired->file_path));
        $this->assertSame(ExportStatusEnum::EXPIRED, $longExpired->refresh()->status);
    }

    /**
     * 날짜 전용 값(방문 예정일)은 모델이 날짜 객체로 주어도 시간대를 옮기지 않는다 — 서쪽 시간대에서 하루 밀리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders
     *
     * @effects date_only_values_keep_calendar_day
     */
    public function test_date_only_value_keeps_calendar_day_in_any_timezone(): void
    {
        $field = new ExportField('shipping.visit_date', 'x', ExportFieldType::DATE, false, fn ($row) => $row);
        $datetime = new ExportField('order.ordered_at', 'x', ExportFieldType::DATETIME, false, fn ($row) => $row);
        $ctx = new ExportRunContext('order', ExportRunContext::LOCALE_MODE_ONE, 'ko', ['ko'], 'ko', 'America/Los_Angeles', false);
        $formatter = app(ExportValueFormatter::class);

        $this->assertSame('2026-10-15', $formatter->format(new ExportColumn($field, 'x'), Carbon::parse('2026-10-15', 'UTC'), $ctx));
        $this->assertSame('2026-10-15', $formatter->format(new ExportColumn($field, 'x'), '2026-10-15', $ctx));
        // 일시는 그대로 운영자 시간대로 옮긴다
        $this->assertSame('2026-10-14 17:00:00', $formatter->format(new ExportColumn($datetime, 'x'), Carbon::parse('2026-10-15 00:00:00', 'UTC'), $ctx));
    }

    /**
     * 큐가 실행 중인 작업을 다시 집어 시도 횟수 초과로 실패시켜도 작업 행은 실패로 닫지 않는다(첫 실행이 계속 돈다).
     * 진짜 시간 초과는 실패로 닫는다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=retry
     *
     * @effects reservation_retry_does_not_fail_running_job
     */
    public function test_re_reserved_job_does_not_fail_running_export(): void
    {
        $running = $this->storedExport(null, ExportStatusEnum::PROCESSING);

        (new GenerateExportJob($running->id))->failed(new MaxAttemptsExceededException('attempted too many times'));
        $this->assertSame(ExportStatusEnum::PROCESSING, $running->refresh()->status);

        (new GenerateExportJob($running->id))->failed(new TimeoutExceededException('timed out'));
        $this->assertSame(ExportStatusEnum::FAILED, $running->refresh()->status);
        $this->assertSame('timeout', $running->error_code);
    }

    /**
     * 긴 작업 연결·큐가 등록돼 있으면 생성 작업은 그쪽으로 간다(기본 연결의 짧은 재예약 시간에 걸리지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario action=retry
     *
     * @effects reservation_retry_does_not_fail_running_job
     */
    public function test_generate_job_uses_long_queue_when_registered(): void
    {
        $registry = $this->createMock(QueueWorkerRegistry::class);
        $registry->method('longPlacement')->willReturn(['connection' => 'database-long', 'queue' => 'long']);
        $this->app->instance(QueueWorkerRegistry::class, $registry);

        $job = new GenerateExportJob(1);
        $this->assertSame('database-long', $job->connection);
        $this->assertSame('long', $job->queue);

        $none = $this->createMock(QueueWorkerRegistry::class);
        $none->method('longPlacement')->willReturn(null);
        $this->app->instance(QueueWorkerRegistry::class, $none);

        $default = new GenerateExportJob(1);
        $this->assertNull($default->connection);
        $this->assertNull($default->queue);
    }

    /**
     * 일회용 주소 발급 응답은 지금 다운로드 횟수를 함께 준다 — 화면이 기록 반영을 확인하는 기준이다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=download
     *
     * @effects download_streams_via_one_time_link
     */
    public function test_download_link_returns_current_count(): void
    {
        $export = $this->storedExport(now()->addDays(3)->toDateTimeString());
        $export->forceFill(['download_count' => 2])->save();

        $this->actingAs($this->admin)->postJson($this->exportsUrl("/{$export->id}/download-link"))
            ->assertOk()
            ->assertJsonPath('data.download_count', 2);
    }

    /**
     * 데이터를 남기고 제거할 때 저장소 파일 삭제가 실패하면 기록을 그대로 두고 사유와 함께 제거를 멈춘다 — 경로를 지우면
     * 남은 파일을 다시 찾을 수 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=prune
     *
     * @effects uninstall_purges_all_files
     */
    public function test_uninstall_stops_when_file_purge_fails(): void
    {
        $export = $this->storedExport(now()->addDays(3)->toDateTimeString());
        $module = new class extends Module
        {
            /**
             * 모든 삭제가 실패하는 저장소를 돌려준다.
             *
             * @return StorageInterface 저장소
             */
            public function getStorage(): StorageInterface
            {
                return new class('sirsoft-ecommerce', 'modules') extends ModuleStorageDriver
                {
                    /**
                     * 카테고리 삭제를 실패시킨다.
                     *
                     * @param  string  $category  카테고리
                     * @return bool 언제나 false
                     */
                    public function deleteAll(string $category): bool
                    {
                        return false;
                    }
                };
            }
        };

        $this->assertFalse($module->uninstall());
        $this->assertNotNull($module->getLifecycleFailureReason());
        $export->refresh();
        $this->assertSame(ExportStatusEnum::COMPLETED, $export->status);
        $this->assertNotNull($export->file_path, '파일을 못 지웠는데 경로를 지워 다시 찾을 수 없습니다.');
    }

    /**
     * 파일을 저장한 뒤 작업 기록 갱신이 실패하면 저장한 파일을 지운다 — 기록 없는 파일이 남지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders
     *
     * @effects stored_file_removed_when_record_update_fails
     */
    public function test_stored_file_removed_when_completion_update_fails(): void
    {
        Bus::fake([GenerateExportJob::class]);
        $user = $this->createAdminUser([
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.exports.create',
        ]);
        $id = (int) $this->actingAs($user)->postJson($this->exportsUrl(), [
            'type' => 'orders', 'format' => 'csv', 'column_mode' => 'custom', 'fields' => ['order.order_number'], 'row_unit' => 'order',
            'scope' => 'all', 'period' => ['mode' => 'custom', 'date_type' => 'ordered_at', 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->toDateString()],
            'locale_mode' => 'locale', 'include_totals' => false,
        ], ['Idempotency-Key' => 'orphan-1'])->assertStatus(202)->json('data.id');

        $failing = new class(new Export) extends ExportRepository
        {
            /**
             * 완료 기록(파일 경로가 실린 갱신)만 실패시킨다.
             *
             * @param  Export  $export  작업
             * @param  array<string, mixed>  $data  갱신 값
             * @return Export 작업
             */
            public function update(Export $export, array $data): Export
            {
                if (array_key_exists('file_path', $data) && $data['file_path'] !== null) {
                    throw new \RuntimeException('DB 갱신 실패 흉내');
                }

                return parent::update($export, $data);
            }
        };
        $this->app->make(ExportService::class, ['exports' => $failing])->generate($id);

        $this->assertSame(ExportStatusEnum::FAILED, Export::findOrFail($id)->status);
        $this->assertSame([], Storage::disk('modules')->allFiles('sirsoft-ecommerce/exports'), '기록 없는 파일이 남았습니다.');
    }
}
