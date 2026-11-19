<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Enums\ScopeType;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\AssetUrl;
use Illuminate\Support\Facades\Bus;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Exports\Formats\CsvExportWriter;
use Modules\Sirsoft\Ecommerce\Jobs\GenerateExportJob;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Models\ExportDownload;
use Modules\Sirsoft\Ecommerce\Services\ExportService;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 일회용 다운로드 주소 테스트
 *
 * 관리 화면은 파일을 브라우저 메모리에 올리지 않도록, 인증된 API 로 일회용 주소를 발급받고 브라우저가 그 주소를 직접 연다.
 * 주소는 발급받은 사람·작업에 묶이고 60초 안에 한 번만 쓸 수 있으며, 쓰는 시점에 그 사람의 권한을 다시 본다.
 */
class ExportDownloadLinkTest extends ExportTestCase
{
    private const PERMISSIONS = [
        'sirsoft-ecommerce.orders.read',
        'sirsoft-ecommerce.exports.create',
        'sirsoft-ecommerce.exports.read',
        'sirsoft-ecommerce.exports.download',
    ];

    protected User $admin;

    /**
     * 큐를 가짜로 두고 전 권한 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([GenerateExportJob::class]);
        $this->admin = $this->createAdminUser(self::PERMISSIONS);
    }

    /**
     * 완료된 주문 내보내기 작업을 만듭니다.
     *
     * @return Export 작업
     */
    private function completedExport(): Export
    {
        $order = OrderFactory::new()->paid()->create();
        OrderOptionFactory::new()->create(['order_id' => $order->id]);
        $this->travel(1)->seconds();

        $id = (int) $this->actingAs($this->admin)->postJson($this->exportsUrl(), [
            'type' => 'orders', 'format' => 'csv', 'column_mode' => 'custom', 'fields' => ['order.order_number'],
            'row_unit' => 'order', 'scope' => 'selected', 'selected_ids' => [$order->id], 'locale_mode' => 'locale',
        ], ['Idempotency-Key' => 'link-'.uniqid()])->assertStatus(202)->json('data.id');
        app(ExportService::class)->generate($id);

        return Export::findOrFail($id);
    }

    /**
     * 발급받은 주소로 인증 없이 받을 수 있고, 이력·횟수·활동 로그가 발급받은 사람으로 남으며, 두 번째는 410 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=download
     *
     * @effects download_streams_via_one_time_link, download_records_history
     */
    public function test_one_time_link_streams_once_and_records_history(): void
    {
        $export = $this->completedExport();

        $url = $this->actingAs($this->admin)->postJson($this->exportsUrl("/{$export->id}/download-link"))
            ->assertOk()
            ->assertJsonPath('data.expires_in', ExportService::DOWNLOAD_TOKEN_TTL_SECONDS)
            ->json('data.url');
        $this->assertMatchesRegularExpression('#^/api/modules/sirsoft-ecommerce/exports/file/[A-Za-z0-9]{64}$#', $url);

        $this->app['auth']->forgetGuards();
        $content = $this->get($url)->assertOk()->streamedContent();
        $this->assertStringStartsWith(CsvExportWriter::BOM, $content, '일회용 주소가 CSV 파일을 내려주지 않았습니다.');
        $this->assertGreaterThan(strlen(CsvExportWriter::BOM) + 2, strlen($content));

        $this->assertSame(1, (int) $export->refresh()->download_count);
        $this->assertSame($this->admin->id, (int) ExportDownload::where('export_id', $export->id)->value('user_id'));
        $this->assertSame(1, ActivityLog::where('action', 'export.download')->count());

        $this->get($url)->assertStatus(410);
    }

    /**
     * 하위 폴더 설치(`/g7`)에서는 일회용 주소가 마운트 접두사를 품는다 — 화면 핸들러가 이 값을 `<a href>` 에 그대로 넣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=download
     *
     * @effects download_link_carries_the_mount_prefix
     */
    public function test_one_time_link_carries_the_mount_prefix_in_a_subfolder_install(): void
    {
        $export = $this->completedExport();
        AssetUrl::forceBasePrefix('/g7');

        try {
            $url = $this->actingAs($this->admin)->postJson($this->exportsUrl("/{$export->id}/download-link"))
                ->assertOk()
                ->json('data.url');

            $this->assertMatchesRegularExpression('#^/g7/api/modules/sirsoft-ecommerce/exports/file/[A-Za-z0-9]{64}$#', $url);
        } finally {
            AssetUrl::forceBasePrefix(null);
        }
    }

    /**
     * 발급 뒤 다운로드 권한을 잃으면 주소를 써도 막힌다(쓰는 시점에 다시 본다). 형식이 틀린 토큰은 라우트가 받지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=download
     *
     * @effects download_link_rechecks_permission
     */
    public function test_link_rechecks_permission_when_used(): void
    {
        $export = $this->completedExport();
        $url = $this->actingAs($this->admin)->postJson($this->exportsUrl("/{$export->id}/download-link"))->assertOk()->json('data.url');

        $this->admin->roles()->detach();
        $this->clearPermissionCache();
        $this->app['auth']->forgetGuards();

        $this->get($url)->assertStatus(403);
        $this->assertSame(0, ExportDownload::count());

        $this->get('/api/modules/'.self::IDENTIFIER.'/exports/file/short')->assertNotFound();
    }

    /**
     * 범위 밖·원본 조회 권한이 없는 운영자에게는 주소를 발급하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=download, permission=self_scope
     *
     * @effects download_link_rechecks_permission
     */
    public function test_link_is_not_issued_outside_scope(): void
    {
        $export = $this->completedExport();
        $other = $this->createAdminUser(self::PERMISSIONS);
        $this->setExportScope($other, ScopeType::Self);

        $this->actingAs($other)->postJson($this->exportsUrl("/{$export->id}/download-link"))->assertStatus(403);
    }
}
