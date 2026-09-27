<?php

namespace Tests\Feature\Analytics\ExtensionPoints;

use App\Analytics\AnalyticsConfig;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsDimensionStat;
use App\Services\Analytics\CsvExporter;
use App\Services\Analytics\DiagnosticsService;
use App\Services\Analytics\RealtimeService;
use App\Services\Analytics\StorageStatsService;
use Tests\Feature\Analytics\Api\AnalyticsApiTestCase;
use Tests\Feature\Analytics\Concerns\FakesAnalyticsExtensions;

/**
 * 출력 표면 개방 — CSV 열·진단 섹션·실시간 필드·저장소 표.
 *
 * 확장은 코어 출력에 **붙일** 수만 있어야 한다. 코어 열·섹션을 덮으면 운영자가 보던 숫자의
 * 뜻이 확장 설치 여부에 따라 바뀐다.
 */
class OutputSurfacesTest extends AnalyticsApiTestCase
{
    use FakesAnalyticsExtensions;

    /**
     * 테스트 준비 — 분류 차원을 선언한 확장.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->activateAnalyticsExtensions([
            $this->fakeAnalyticsExtension('vendor-shop', [], [['key' => 'category', 'label' => '분류']]),
        ]);
    }

    /**
     * 확장 차원 CSV 에 확장이 더한 `extra` 열이 붙고, 파일명의 `:` 가 바뀐다.
     *
     * @scenario point=output, extension_state=active, input=registered
     *
     * @effects csv_columns_filter_appends_extension_extra_columns, csv_row_filter_can_format_values, csv_filename_is_safe_for_ext_dimensions
     *
     * @return void 없음
     */
    public function test_csv_extension_columns(): void
    {
        AnalyticsDimensionStat::factory()->create([
            'date' => now('UTC')->toDateString(),
            'visitor_type' => 'guest',
            'dimension' => 'ext:vendor-shop:category',
            'label' => 'tv',
            'label_hash' => md5('tv'),
            'visits' => 3,
            'hits' => 5,
            'extra' => ['orders' => 2],
        ]);

        HookManager::addFilter('core.analytics.csv_columns', fn (array $columns, string $dimension): array => $dimension === 'ext:vendor-shop:category'
            ? $columns + ['orders' => 'orders', 'label' => 'hijack']
            : $columns);
        HookManager::addFilter('core.analytics.csv_row', function (array $line): array {
            $line[1] = strtoupper((string) $line[1]);

            return $line;
        });

        $captured = null;
        $this->mock(CsvExporter::class, function ($mock) use (&$captured): void {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $filename, array $headers, iterable $rows) use (&$captured) {
                $captured = ['filename' => $filename, 'headers' => $headers, 'rows' => iterator_to_array($rows, false)];

                return response()->streamDownload(fn () => null, $filename);
            });
        });

        $this->actingAs($this->readAdmin())
            ->get('/api/admin/analytics/reports/ext:vendor-shop:category?preset=today&format=csv')
            ->assertOk();

        $this->assertStringNotContainsString(':', $captured['filename']);
        $this->assertSame('orders', end($captured['headers']));
        $this->assertSame(10, count($captured['headers']), '코어 9열 + 확장 1열 — 코어 열(label)은 덮지 못한다');
        $this->assertSame('TV', $captured['rows'][0][1]);
        $this->assertSame(2, $captured['rows'][0][9]);
    }

    /**
     * 진단 응답에 확장 섹션이 `extensions` 아래로만 붙는다.
     *
     * @scenario point=output, extension_state=active, input=registered
     *
     * @effects diagnostics_sections_filter_adds_extension_sections
     *
     * @return void 없음
     */
    public function test_diagnostics_sections(): void
    {
        HookManager::addFilter('core.analytics.diagnostics_sections', fn (array $sections): array => $sections + [
            'vendor-shop' => ['tracked_orders_today' => 3],
            'collection' => 'not-an-array',
        ]);

        $diagnostics = app(DiagnosticsService::class)->diagnostics(AnalyticsConfig::fromSettings());

        $this->assertSame(['vendor-shop' => ['tracked_orders_today' => 3]], $diagnostics['extensions']);
        $this->assertIsArray($diagnostics['collection'], '코어 섹션은 그대로');
    }

    /**
     * 실시간 행에 확장 값이 한 칸 아래로 붙는다.
     *
     * @scenario point=output, extension_state=active, input=registered
     *
     * @effects realtime_fields_filter_adds_extension_values
     *
     * @return void 없음
     */
    public function test_realtime_fields(): void
    {
        $this->visit();

        HookManager::addFilter('core.analytics.realtime_fields', fn (array $fields, $visit): array => $fields + ['vendor-shop' => ['cart' => 1]]);

        $snapshot = app(RealtimeService::class)->snapshot(AnalyticsConfig::fromSettings());

        $this->assertSame(['vendor-shop' => ['cart' => 1]], $snapshot['visitors'][0]['extensions']);
    }

    /**
     * 저장소 현황에 확장 표가 더해지고 코어 표는 빠지지 않는다.
     *
     * @scenario point=output, extension_state=active, input=registered
     *
     * @effects storage_tables_filter_adds_extension_tables
     *
     * @return void 없음
     */
    public function test_storage_tables(): void
    {
        HookManager::addFilter('core.analytics.storage_tables', fn (array $tables): array => array_merge($tables, ['vendor_shop_stats', 'bad name;drop']));

        $tables = array_column(app(StorageStatsService::class)->stats(AnalyticsConfig::fromSettings())['tables'], 'table');

        $this->assertContains('vendor_shop_stats', $tables);
        $this->assertContains('analytics_visits', $tables);
        $this->assertNotContains('bad name;drop', $tables);
    }
}
