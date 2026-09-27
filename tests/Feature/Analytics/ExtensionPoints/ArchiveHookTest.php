<?php

namespace Tests\Feature\Analytics\ExtensionPoints;

use App\Enums\Analytics\ArchiveRunStatus;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsDimensionStat;
use App\Services\Analytics\ArchiveCollector;
use App\Services\Analytics\ArchiveService;
use App\Services\Analytics\DimensionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Analytics\Concerns\FakesAnalyticsExtensions;
use Tests\TestCase;

/**
 * 집계 기여 훅 — 확장이 수집기로 넣은 행이 집계 표에 들어간다.
 *
 * 집계는 「지우고 넣기」라, 확장이 집계 표에 직접 쓰면 다음 집계가 지운다. 수집기를 거쳐야
 * 하고, 수집기는 같은 프로세스에서 받아야 한다(큐로 가면 사본이 간다).
 */
class ArchiveHookTest extends TestCase
{
    use FakesAnalyticsExtensions;
    use RefreshDatabase;

    /**
     * 테스트 준비 — 확장 없음.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->activateAnalyticsExtensions([]);
    }

    /**
     * 동기 구독이 넣은 `ext:` 행은 집계와 함께 기록되고, 다시 집계해도 한 벌만 남는다.
     *
     * @scenario point=archive, extension_state=active, input=registered
     *
     * @effects archive_day_hook_passes_collector_and_context, sync_collector_rows_are_written_with_core_rows, collector_rows_are_replaced_on_reaggregation
     *
     * @return void 없음
     */
    public function test_sync_listener_rows_are_written(): void
    {
        $payloads = [];

        HookManager::addAction('core.analytics.archive.day', function (array $payload) use (&$payloads): void {
            $payloads[] = $payload;
            $payload['collector']->addDimensionRow('ext:vendor-shop:category', '가전', 'guest', ['visits' => 4, 'hits' => 9, 'extra' => ['orders' => 2]]);
        });

        $date = now('UTC')->toDateString();
        app(ArchiveService::class)->archiveDay($date);
        app(ArchiveService::class)->archiveDay($date);

        $rows = AnalyticsDimensionStat::query()->where('dimension', 'ext:vendor-shop:category')->get();
        $this->assertCount(1, $rows, '지우고 넣기 — 두 번 집계해도 한 행');
        $this->assertSame(4, (int) $rows->first()->visits);

        $this->assertInstanceOf(ArchiveCollector::class, $payloads[0]['collector']);
        $this->assertSame($date, $payloads[0]['date']);
        $this->assertArrayHasKey('is_final', $payloads[0]);
        $this->assertArrayHasKey('run_type', $payloads[0]);
    }

    /**
     * `ext:` 로 시작하지 않는 차원 이름은 받지 않고 경고를 남긴다.
     *
     * @scenario point=archive, extension_state=active, input=unregistered
     *
     * @effects collector_rejects_core_dimension_names_with_warning
     *
     * @return void 없음
     */
    public function test_collector_rejects_core_dimension_names(): void
    {
        Log::spy();

        $collector = new ArchiveCollector('2026-09-01');
        $collector->addDimensionRow('page_url', '/hijack', 'guest', ['visits' => 1]);

        $this->assertTrue($collector->isEmpty());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'ext:'))->once();
    }

    /**
     * 선언으로 만들 수 있는 가장 긴 확장 차원 이름이 집계 표 열에 들어간다.
     *
     * 식별자 상한은 확장 표의 `identifier` 열 폭에서, 키 상한은 선언 검사가 받는 최대 길이에서
     * 파생한다 — 둘 중 하나가 늘면 이 테스트가 먼저 빨개진다. 열 폭을 넘는 이름은 데이터베이스가
     * 거부하고, 세 표를 한 트랜잭션으로 바꾸는 집계는 그 한 행 때문에 그날 전체가 실패한다.
     *
     * @scenario point=archive, extension_state=active, input=registered
     *
     * @effects longest_declarable_ext_dimension_fits_the_column
     *
     * @return void 없음
     */
    public function test_longest_declarable_dimension_fits_the_column(): void
    {
        $identifierMax = max($this->columnLength('modules', 'identifier'), $this->columnLength('plugins', 'identifier'));

        $isValidKey = new \ReflectionMethod(DimensionRegistry::class, 'isValidDeclarationKey');
        $keyMax = 0;
        while ($isValidKey->invoke(null, str_repeat('a', $keyMax + 1))) {
            $keyMax++;
        }

        $this->assertGreaterThan(0, $keyMax, '선언 키 상한을 파생하지 못했다');
        $this->assertGreaterThan(0, $identifierMax, '식별자 열 폭을 파생하지 못했다');

        $longest = strlen('ext:') + $identifierMax + strlen(':') + $keyMax;
        $column = $this->columnLength('analytics_dimension_stats', 'dimension');

        $this->assertGreaterThanOrEqual($longest, $column, "가장 긴 선언 차원 이름 {$longest}자가 열 {$column}자에 안 들어간다");
        $this->assertSame($column, ArchiveCollector::MAX_DIMENSION_LENGTH, '수집기 상한은 열 폭과 같아야 한다');
    }

    /**
     * 확장 기여 행 하나가 그날 집계를 멈추지 않는다 — 가장 긴 이름은 들어가고, 같은 라벨은
     * 합쳐지고, 열 폭을 넘는 이름·네 값 밖의 방문자 유형은 경고와 함께 빠지고, 음수 지표는 0,
     * 열 최댓값을 넘는 지표(합 포함)는 최댓값, 숫자가 아닌 이름 사전 ID 는 비우고, 깨진 UTF-8 이 든
     * 추가 값은 대체 문자로, JSON 으로 못 바꾸는 추가 값은 그 칸만 비운다(집계 표가 거부하는 값 전부).
     *
     * @scenario point=archive, extension_state=active, input=registered
     *
     * @effects collector_merges_duplicate_labels, collector_drops_overlong_dimension_names, one_collector_row_does_not_fail_the_day
     *
     * @return void 없음
     */
    public function test_one_bad_collector_row_does_not_fail_the_day(): void
    {
        Log::spy();

        $longest = 'ext:'.str_repeat('v', ArchiveCollector::MAX_DIMENSION_LENGTH - strlen('ext:') - strlen(':k')).':k';
        $overlong = $longest.'x';

        HookManager::addAction('core.analytics.archive.day', function (array $payload) use ($longest, $overlong): void {
            $payload['collector']->addDimensionRow($longest, '기타', 'guest', ['visits' => 2, 'hits' => 3, 'extra' => ['revenue_cents' => 100]]);
            $payload['collector']->addDimensionRow($longest, '기타', 'guest', ['visits' => 5, 'hits' => 1, 'extra' => ['revenue_cents' => 50]]);
            $payload['collector']->addDimensionRow($overlong, '가전', 'guest', ['visits' => 1]);
            $payload['collector']->addDimensionRow('ext:vendor-shop:category', '가구', 'visitor_of_some_kind', ['visits' => 1]);
            $payload['collector']->addDimensionRow('ext:vendor-shop:category', '의류', 'member', ['visits' => -3, 'hits' => 2, 'action_name_id' => 'abc']);
            // 열 최댓값을 넘는 수 · 합치면 넘는 두 행 · 한글을 바이트로 자른 추가 값
            $payload['collector']->addDimensionRow('ext:vendor-shop:category', '가방', 'guest', ['visits' => 5e9, 'hits' => 1]);
            $payload['collector']->addDimensionRow('ext:vendor-shop:category', '신발', 'guest', ['visits' => 3000000000, 'hits' => 1]);
            $payload['collector']->addDimensionRow('ext:vendor-shop:category', '신발', 'guest', ['visits' => 3000000000, 'hits' => 1]);
            $payload['collector']->addDimensionRow('ext:vendor-shop:category', '모자', 'guest', ['visits' => 1, 'extra' => ['name' => substr('자유게시판', 0, 4), 'orders' => 2]]);
            $payload['collector']->addDimensionRow('ext:vendor-shop:category', '장갑', 'guest', ['visits' => 1, 'extra' => ['ratio' => INF]]);
        });

        $run = app(ArchiveService::class)->archiveDay(now('UTC')->toDateString());

        $status = $run->status instanceof \BackedEnum ? $run->status->value : $run->status;
        $this->assertSame(ArchiveRunStatus::Success->value, $status, (string) $run->error);

        $rows = AnalyticsDimensionStat::query()->where('dimension', $longest)->get();
        $this->assertCount(1, $rows, '같은 라벨은 한 행');
        $this->assertSame(7, (int) $rows->first()->visits);
        $this->assertSame(4, (int) $rows->first()->hits);
        $this->assertSame(150, (int) (json_decode((string) $rows->first()->getRawOriginal('extra'), true)['revenue_cents'] ?? 0));

        $this->assertSame(0, AnalyticsDimensionStat::query()->where('label', '가전')->count(), '열 폭을 넘는 이름의 행은 빠진다');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, '너무 길어'))->once();

        $this->assertSame(0, AnalyticsDimensionStat::query()->where('label', '가구')->count(), '방문자 유형이 네 값 밖이면 빠진다');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, '방문자 유형'))->once();

        $clothing = AnalyticsDimensionStat::query()->where('label', '의류')->sole();
        $this->assertSame(0, (int) $clothing->visits, '음수 지표는 0 으로');
        $this->assertSame(2, (int) $clothing->hits);
        $this->assertNull($clothing->action_name_id, '숫자가 아닌 이름 사전 ID 는 비운다');

        $max = ArchiveCollector::MAX_COUNTS['visits'];
        $this->assertSame($max, (int) AnalyticsDimensionStat::query()->where('label', '가방')->sole()->visits, '열 최댓값을 넘는 수는 최댓값으로');
        $this->assertSame($max, (int) AnalyticsDimensionStat::query()->where('label', '신발')->sole()->visits, '합이 넘어도 최댓값으로');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, '최댓값'))->once();

        $hat = json_decode((string) AnalyticsDimensionStat::query()->where('label', '모자')->sole()->getRawOriginal('extra'), true);
        $this->assertIsArray($hat, '깨진 글자가 든 추가 값도 JSON 으로 들어간다');
        $this->assertSame(2, $hat['orders'] ?? null);
        $this->assertStringContainsString("\u{FFFD}", (string) ($hat['name'] ?? ''), '깨진 바이트는 대체 문자로');

        $gloves = AnalyticsDimensionStat::query()->where('label', '장갑')->sole();
        $this->assertNull($gloves->getRawOriginal('extra'), 'JSON 으로 바꿀 수 없는 추가 값은 그 칸만 비운다');
        $this->assertSame(1, (int) $gloves->visits);
    }

    /**
     * 표기만 다른 차원 이름(대소문자·끝 공백)은 한 행으로 합쳐 그날 집계를 멈추지 않는다.
     *
     * 차원 열의 대조 규칙은 대소문자·끝 공백을 가리지 않아, 수집기가 두 표기를 다른 행으로 넘기면
     * 유일키에 걸려 그날 집계 전체가 실패한다. 변종이 정말 그 열에서 같은 이름인지를 먼저 DB 로
     * 확인한다 — 열이 이진 대조로 바뀌면 이 전제가 red 로 알린다.
     *
     * @scenario point=archive, extension_state=active, input=registered
     *
     * @effects collector_merges_dimension_names_equal_under_the_column_collation
     *
     * @return void 없음
     */
    public function test_dimension_names_equal_under_the_column_collation_are_merged(): void
    {
        $base = 'ext:vendor-shop:category';
        $variants = ['ext:vendor-shop:Category', $base.' ', strtoupper($base)];

        $column = collect(Schema::getColumns((new AnalyticsDimensionStat)->getTable()))->firstWhere('name', 'dimension');
        $collation = (string) ($column['collation'] ?? '');
        $this->assertNotSame('', $collation, '차원 열의 대조 규칙을 읽지 못했다');
        foreach ($variants as $variant) {
            $same = DB::selectOne("SELECT CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE {$collation} = CAST(? AS CHAR CHARACTER SET utf8mb4) AS same", [$variant, $base])->same ?? 0;
            $this->assertSame(1, (int) $same, "전제: 「{$variant}」 는 차원 열에서 「{$base}」 와 같은 이름이다");
        }

        HookManager::addAction('core.analytics.archive.day', function (array $payload) use ($base, $variants): void {
            $payload['collector']->addDimensionRow($base, '가구', 'guest', ['visits' => 1]);
            foreach ($variants as $variant) {
                $payload['collector']->addDimensionRow($variant, '가구', 'guest', ['visits' => 2]);
            }
        });

        $run = app(ArchiveService::class)->archiveDay(now('UTC')->toDateString());

        $status = $run->status instanceof \BackedEnum ? $run->status->value : $run->status;
        $this->assertSame(ArchiveRunStatus::Success->value, $status, (string) $run->error);
        $row = AnalyticsDimensionStat::query()->where('label', '가구')->sole();
        $this->assertSame($base, $row->dimension, '저장 이름은 소문자 정규형');
        $this->assertSame(1 + 2 * count($variants), (int) $row->visits, '표기만 다른 이름은 한 행으로 합친다');
    }

    /**
     * 수집기의 지표 상한은 집계 표 정수 열이 담는 값과 같다 — 모집단은 표에서 파생한다
     * (기본값 0 인 정수 열 = 지표 열). 열이 늘거나 타입이 바뀌면 상한 표와 어긋나 red.
     *
     * @scenario point=archive, extension_state=active, input=registered
     *
     * @effects collector_counts_fit_the_columns
     *
     * @return void 없음
     */
    public function test_collector_count_bounds_match_the_columns(): void
    {
        $capacity = [
            'int unsigned' => 4294967295,
            'bigint unsigned' => PHP_INT_MAX, // 열은 더 담지만 PHP 정수가 여기까지다
        ];

        $metricColumns = [];
        foreach (Schema::getColumns('analytics_dimension_stats') as $info) {
            if (in_array($info['type_name'], ['int', 'bigint', 'smallint', 'mediumint', 'tinyint'], true)
                && trim((string) $info['default'], "'") === '0') {
                $metricColumns[$info['name']] = strtolower((string) $info['type']);
            }
        }

        $this->assertNotEmpty($metricColumns, '지표 열을 파생하지 못했다');
        $this->assertEqualsCanonicalizing(array_keys($metricColumns), array_keys(ArchiveCollector::MAX_COUNTS), '지표 열 ↔ 수집기 상한 표');

        foreach ($metricColumns as $column => $type) {
            $this->assertArrayHasKey($type, $capacity, "{$column} 열 타입 {$type} 의 용량을 모른다");
            $this->assertSame($capacity[$type], ArchiveCollector::MAX_COUNTS[$column], "{$column} 상한이 열 용량과 다르다");
        }
    }

    /**
     * 테이블 열의 문자 폭을 읽습니다.
     *
     * @param  string  $table  테이블
     * @param  string  $column  열
     * @return int 문자 폭 (없으면 0)
     */
    private function columnLength(string $table, string $column): int
    {
        foreach (Schema::getColumns($table) as $info) {
            if ($info['name'] === $column && preg_match('/\((\d+)\)/', (string) $info['type'], $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * 수집기가 직렬화되면(큐 구독) 경고를 남긴다.
     *
     * 큐 드라이버가 동기여도 라라벨은 잡을 직렬화한다 — 그 순간 확장이 받는 수집기는 사본이다.
     *
     * @scenario point=archive, extension_state=active, input=unregistered
     *
     * @effects queued_archive_subscription_is_warned
     *
     * @return void 없음
     */
    public function test_serializing_the_collector_warns(): void
    {
        Log::spy();

        $collector = new ArchiveCollector('2026-09-01');
        $collector->addDimensionRow('ext:vendor-shop:category', '가전', 'guest', ['visits' => 1]);

        $copy = unserialize(serialize($collector));

        $this->assertSame('2026-09-01', $copy->date);
        $this->assertCount(1, $copy->rows());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'sync'))->once();
    }
}
