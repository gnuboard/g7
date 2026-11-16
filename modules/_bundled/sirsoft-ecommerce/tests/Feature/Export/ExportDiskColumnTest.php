<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\ExportException;
use Modules\Sirsoft\Ecommerce\Jobs\GenerateExportJob;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Services\ExportService;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 내보내기 행의 저장 디스크(`ecommerce_exports.disk`) 테스트 (#916 A8)
 *
 * 내보내기 파일은 관리자가 환경설정 > 드라이버 「비공개 첨부 저장소」에서 고른 디스크에 저장된다(「기본」이면 `modules`).
 * 저장소를 바꾼 뒤에도 그 전에 만든 파일을 받고·지우고·만료 정리할 수 있어야 한다 — 그래서 저장할 때의 디스크를 행에
 * 남기고 다운로드·삭제·정리는 행 disk 로 간다. 행 disk 가 사라진(고아) 디스크면 500 이 아니라 「파일 없음」(404)이다.
 *
 * 시험용 비공개 디스크 `export_private` 는 `filesystems.disks` 에 정의하고 `core.storage.private_attachment_disk` 로 고른다.
 */
class ExportDiskColumnTest extends ExportTestCase
{
    /**
     * 시험용 비공개 첨부 디스크
     */
    private const PRIVATE_DISK = 'export_private';

    private const PERMISSIONS = [
        'sirsoft-ecommerce.orders.read',
        'sirsoft-ecommerce.exports.create',
        'sirsoft-ecommerce.exports.read',
        'sirsoft-ecommerce.exports.download',
        'sirsoft-ecommerce.exports.delete',
    ];

    protected User $admin;

    /**
     * 큐를 가짜로 두고, 시험용 비공개 디스크를 정의하고, 전 권한 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([GenerateExportJob::class]);
        Config::set('filesystems.disks.'.self::PRIVATE_DISK, [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/'.self::PRIVATE_DISK),
            'throw' => false,
        ]);
        Storage::fake(self::PRIVATE_DISK);
        // 시작은 「기본」 — 각 테스트가 필요한 시점에 바꾼다
        Config::set('core.storage.private_attachment_disk', 'default');

        $this->admin = $this->createAdminUser(self::PERMISSIONS);
    }

    /**
     * 환경설정 > 드라이버 「비공개 첨부 저장소」를 고른 것과 같게 설정합니다.
     *
     * @param  string  $disk  디스크 (`default` 면 모듈 기본 디스크)
     * @return void 반환값 없음
     */
    private function choosePrivateDisk(string $disk): void
    {
        Config::set('core.storage.private_attachment_disk', $disk);
    }

    /**
     * 이 컬럼 전에 만든 것과 같은 완료 작업을 만듭니다 — `disk` 를 넣지 않아 기본값이 채워진다.
     *
     * @param  string|null  $expiresAt  만료 시각 (null 이면 3일 뒤)
     * @return Export 작업 (DB 에서 다시 읽은 값)
     */
    private function legacyExport(?string $expiresAt = null): Export
    {
        $path = 'legacy/'.uniqid().'.csv';
        Storage::disk('modules')->put($this->storedPath($path), "\u{FEFF}legacy,row\r\n");

        $export = Export::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => 'orders',
            'format' => 'csv',
            'status' => ExportStatusEnum::COMPLETED->value,
            'requested_by' => $this->admin->id,
            'options' => ['fields' => ['order.order_number']],
            'file_name' => 'orders.csv',
            'file_path' => $path,
            'requested_at' => now()->subDays(10),
            'completed_at' => now()->subDays(10),
            'expires_at' => $expiresAt ?? now()->addDays(3)->toDateTimeString(),
        ]);

        return $export->refresh();
    }

    /**
     * 화면에서 요청하고 큐 작업이 만든 것과 같은 완료 작업을 만듭니다.
     *
     * @return Export 작업
     */
    private function generatedExport(): Export
    {
        $order = OrderFactory::new()->paid()->create();
        OrderOptionFactory::new()->create(['order_id' => $order->id]);
        $this->travel(1)->seconds();

        $id = (int) $this->actingAs($this->admin)->postJson($this->exportsUrl(), [
            'type' => 'orders', 'format' => 'csv', 'column_mode' => 'custom', 'fields' => ['order.order_number'],
            'row_unit' => 'order', 'scope' => 'selected', 'selected_ids' => [$order->id], 'locale_mode' => 'locale',
        ], ['Idempotency-Key' => 'disk-'.uniqid()])->assertStatus(202)->json('data.id');
        app(ExportService::class)->generate($id);

        $export = Export::findOrFail($id);
        $this->assertSame(ExportStatusEnum::COMPLETED, $export->status, '파일 생성이 완료되지 않았습니다: '.$export->error_code);

        return $export;
    }

    /**
     * 마이그레이션이 컬럼을 더하고, 디스크를 적지 않은 행(기존 행)은 `modules` 로 채운다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=download
     *
     * @effects export_row_records_storage_disk
     */
    public function test_column_exists_and_existing_rows_default_to_modules(): void
    {
        $this->assertTrue(Schema::hasColumn('ecommerce_exports', 'disk'));

        $id = DB::table('ecommerce_exports')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'type' => 'orders',
            'format' => 'csv',
            'status' => ExportStatusEnum::PENDING->value,
            'options' => json_encode(['fields' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('modules', DB::table('ecommerce_exports')->where('id', $id)->value('disk'));
        $this->assertArrayNotHasKey('disk', Export::findOrFail($id)->toArray(), '저장 디스크는 화면·API 에 실리지 않는다(hidden)');
    }

    /**
     * 비공개 첨부 저장소를 바꾼 뒤에도 그 전에 만든 파일(`disk=modules`)은 `modules` 에서 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=download
     *
     * @effects old_export_served_from_its_row_disk
     */
    public function test_old_row_downloads_from_modules_after_private_disk_changes(): void
    {
        $export = $this->legacyExport();
        $this->assertSame('modules', $export->disk);

        $this->choosePrivateDisk(self::PRIVATE_DISK);

        $content = $this->actingAs($this->admin)->get($this->exportsUrl("/{$export->id}/download"))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('legacy,row', $content, '저장소를 바꾸기 전에 만든 파일을 받지 못했습니다.');
    }

    /**
     * 새 파일은 고른 비공개 디스크에 저장되고 그 디스크가 행에 남으며, 저장소를 다시 「기본」으로 돌려도 그 디스크에서 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=download
     *
     * @effects export_row_records_storage_disk, old_export_served_from_its_row_disk
     */
    public function test_new_row_is_stored_on_private_disk_and_records_it(): void
    {
        $this->choosePrivateDisk(self::PRIVATE_DISK);

        $export = $this->generatedExport();

        $this->assertSame(self::PRIVATE_DISK, $export->disk);
        Storage::disk(self::PRIVATE_DISK)->assertExists($this->storedPath((string) $export->file_path));
        Storage::disk('modules')->assertMissing($this->storedPath((string) $export->file_path));

        $this->choosePrivateDisk('default');
        $this->actingAs($this->admin)->get($this->exportsUrl("/{$export->id}/download"))->assertOk();
    }

    /**
     * 「기본」이면 지금처럼 `modules` 에 저장하고 `modules` 를 기록한다(대조군).
     *
     * @return void 반환값 없음
     *
     * @scenario action=download
     *
     * @effects export_row_records_storage_disk
     */
    public function test_default_choice_keeps_modules(): void
    {
        $export = $this->generatedExport();

        $this->assertSame('modules', $export->disk);
        Storage::disk('modules')->assertExists($this->storedPath((string) $export->file_path));
        $this->assertSame([], Storage::disk(self::PRIVATE_DISK)->allFiles());
    }

    /**
     * 삭제는 행 disk 에서 지운다 — 옛 행은 `modules`, 새 행은 비공개 디스크(그 사이 저장소를 바꿔도).
     *
     * @return void 반환값 없음
     *
     * @scenario action=bulk_delete
     *
     * @effects export_delete_and_prune_use_row_disk
     */
    public function test_delete_removes_file_from_row_disk(): void
    {
        $legacy = $this->legacyExport();
        $legacyStored = $this->storedPath((string) $legacy->file_path);

        $this->choosePrivateDisk(self::PRIVATE_DISK);
        $fresh = $this->generatedExport();
        $freshStored = $this->storedPath((string) $fresh->file_path);

        // 옛 행은 지금 저장소(비공개 디스크)가 아니라 modules 에서 지워야 한다
        $this->actingAs($this->admin)->deleteJson($this->exportsUrl("/{$legacy->id}"))->assertOk();
        Storage::disk('modules')->assertMissing($legacyStored);
        $this->assertNull(Export::withTrashed()->findOrFail($legacy->id)->file_path);

        // 새 행은 저장소를 「기본」으로 돌린 뒤에도 비공개 디스크에서 지워야 한다
        $this->choosePrivateDisk('default');
        $this->actingAs($this->admin)->deleteJson($this->exportsUrl("/{$fresh->id}"))->assertOk();
        Storage::disk(self::PRIVATE_DISK)->assertMissing($freshStored);
        $this->assertNull(Export::withTrashed()->findOrFail($fresh->id)->file_path);
    }

    /**
     * 만료 정리 명령(`sirsoft-ecommerce:prune-exports`)은 행 disk 에서 지우고 만료로 바꾼다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=prune
     *
     * @effects export_delete_and_prune_use_row_disk
     */
    public function test_prune_command_removes_expired_files_from_row_disk(): void
    {
        $legacy = $this->legacyExport(now()->subDay()->toDateTimeString());
        $legacyStored = $this->storedPath((string) $legacy->file_path);

        $this->choosePrivateDisk(self::PRIVATE_DISK);
        $fresh = $this->generatedExport();
        $fresh->forceFill(['expires_at' => now()->subDay()])->save();
        $freshStored = $this->storedPath((string) $fresh->file_path);

        // 정리 시점의 저장소는 「기본」 — 새 행 파일은 그래도 비공개 디스크에서 지워야 한다
        $this->choosePrivateDisk('default');
        $this->assertSame(0, Artisan::call('sirsoft-ecommerce:prune-exports'));

        Storage::disk('modules')->assertMissing($legacyStored);
        Storage::disk(self::PRIVATE_DISK)->assertMissing($freshStored);
        $this->assertSame(ExportStatusEnum::EXPIRED, $legacy->refresh()->status);
        $this->assertSame(ExportStatusEnum::EXPIRED, $fresh->refresh()->status);
        $this->assertNull($fresh->file_path);
    }

    /**
     * 행 disk 를 제공하던 디스크가 config 에서 사라지면(플러그인 비활성화 등) 500 이 아니라 「파일 없음」이다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=download
     *
     * @effects orphan_export_disk_falls_back_without_error
     */
    public function test_orphan_row_disk_falls_back_to_file_missing(): void
    {
        // 파일은 사라진 디스크에만 있었다 — 주입 디스크(modules)에는 없다
        $export = $this->legacyExport();
        Storage::disk('modules')->delete($this->storedPath((string) $export->file_path));
        $export->forceFill(['disk' => 'disk_from_a_removed_plugin'])->save();

        try {
            app(ExportService::class)->download($export->refresh(), $this->admin, '127.0.0.1', 'phpunit');
            $this->fail('고아 disk 행이 내려받아졌습니다.');
        } catch (ExportException $e) {
            $this->assertSame('file_missing', $e->errorCode());
        }

        $this->actingAs($this->admin)->get($this->exportsUrl("/{$export->id}/download"))->assertStatus(404);
    }

    /**
     * 모듈 제거는 기본 디스크뿐 아니라 행이 가리키는 비공개 디스크의 내보내기 파일도 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=prune
     *
     * @effects uninstall_purges_all_files, export_delete_and_prune_use_row_disk
     */
    public function test_uninstall_purge_removes_files_on_every_row_disk(): void
    {
        $legacy = $this->legacyExport();
        $legacyStored = $this->storedPath((string) $legacy->file_path);

        $this->choosePrivateDisk(self::PRIVATE_DISK);
        $fresh = $this->generatedExport();
        $freshStored = $this->storedPath((string) $fresh->file_path);
        $this->choosePrivateDisk('default');

        $this->assertTrue((new Module)->purgeExportFiles());

        Storage::disk('modules')->assertMissing($legacyStored);
        Storage::disk(self::PRIVATE_DISK)->assertMissing($freshStored);
        $this->assertSame(ExportStatusEnum::EXPIRED, $fresh->refresh()->status);
        $this->assertNull($fresh->file_path);
    }
}
