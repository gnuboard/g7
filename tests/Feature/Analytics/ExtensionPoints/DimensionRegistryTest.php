<?php

namespace Tests\Feature\Analytics\ExtensionPoints;

use App\Analytics\TrackingRequest;
use App\Contracts\Analytics\DimensionRegistryInterface;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\CollectFailureReason;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Analytics\AnalyticsDimensionStat;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\ArchiveService;
use App\Services\Analytics\VisitService;
use Tests\Feature\Analytics\Api\AnalyticsApiTestCase;
use Tests\Feature\Analytics\Concerns\FakesAnalyticsExtensions;

/**
 * 커스텀 차원 — 등록·주입·거부·집계·조회.
 *
 * 차원은 네 곳을 지난다: 브라우저·서버가 값을 싣고(주입), 등록부가 거르고(판정), 집계가
 * `ext:` 행을 만들고(집계), 리포트 API 가 읽는다(조회). 한 곳이라도 끊기면 확장이 넣은 값이
 * 화면에 도달하지 않는다 — 네 곳을 한 흐름으로 잰다.
 */
class DimensionRegistryTest extends AnalyticsApiTestCase
{
    use FakesAnalyticsExtensions;

    /**
     * 테스트 준비 — 게시판 차원(액션)과 회원 등급 차원(방문, 개인정보)을 선언한 확장을 켠다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->activateAnalyticsExtensions([
            $this->fakeAnalyticsExtension('vendor-board', [], [
                ['key' => 'board_id', 'label' => ['ko' => '게시판', 'en' => 'Board'], 'value_type' => 'int', 'max_cardinality' => 2, 'label_resolver' => 'resolveLabels'],
                ['key' => 'tier', 'scope' => 'visit', 'label' => '등급', 'value_type' => 'enum', 'values' => ['gold', 'silver'], 'pii' => true],
            ], ['resolveLabels' => fn (array $values): array => array_combine($values, array_map(fn ($v) => "게시판 {$v}", $values))]),
        ]);
    }

    /**
     * 수집 요청을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return TrackingRequest 수집 요청
     */
    private function request(array $overrides = []): TrackingRequest
    {
        return new TrackingRequest(...array_merge([
            'type' => 'pageview',
            'url' => 'https://example.test/board/free',
            'title' => '자유게시판',
            'ip' => '203.0.113.50',
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.6613.84 Safari/537.36',
            'browserLanguage' => 'ko-KR',
            'cookies' => ['g7_vid' => str_repeat('c', 32)],
            'receivedAt' => now()->format('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * 등록된 차원은 원장 이름으로, 범위에 맞는 행에 남는다.
     *
     * @scenario point=dimension, extension_state=active, input=registered
     *
     * @effects registered_dimension_is_stored_under_qualified_name, dimension_scope_decides_visit_or_action_row
     *
     * @return void 없음
     */
    public function test_registered_dimensions_are_stored_by_scope(): void
    {
        app(VisitService::class)->track($this->request(['dimensions' => ['board_id' => '12', 'vendor-board:tier' => 'gold']]));

        $this->assertSame(['vendor-board:tier' => 'gold'], AnalyticsVisit::first()->custom_dimensions);
        $this->assertSame(['vendor-board:board_id' => '12'], AnalyticsAction::first()->custom_dimensions);
        $this->assertSame(0, AnalyticsCollectFailure::count());
    }

    /**
     * 등록되지 않았거나 값 종류가 틀린 차원은 그 차원만 버리고 사유를 남긴다.
     *
     * @scenario point=dimension, extension_state=active, input=unregistered
     *
     * @effects unregistered_dimension_is_dropped_and_logged, dimension_value_type_violation_is_logged, other_dimensions_survive_a_rejected_sibling
     *
     * @return void 없음
     */
    public function test_unregistered_dimensions_are_dropped_and_logged(): void
    {
        $result = app(VisitService::class)->track($this->request([
            'dimensions' => ['board_id' => '12', 'color' => 'red', 'vendor-board:tier' => 'platinum'],
        ]));

        $this->assertNotNull($result['action_id'], '차원 거부가 페이지뷰까지 버리면 안 된다');
        $this->assertSame(['vendor-board:board_id' => '12'], AnalyticsAction::first()->custom_dimensions);

        $failure = AnalyticsCollectFailure::first();
        $this->assertSame(CollectFailureReason::UnknownDimension, $failure->reason);
        $this->assertStringContainsString('color', $failure->message);
        $this->assertStringContainsString('vendor-board:tier', $failure->message);
    }

    /**
     * 짧은 키를 두 확장이 선언하면 짧은 키로는 받지 않는다.
     *
     * @scenario point=dimension, extension_state=active, input=unregistered
     *
     * @effects ambiguous_short_dimension_key_is_rejected
     *
     * @return void 없음
     */
    public function test_ambiguous_short_key_is_rejected(): void
    {
        $this->activateAnalyticsExtensions([
            $this->fakeAnalyticsExtension('vendor-a', [], [['key' => 'category_id', 'label' => 'A']]),
            $this->fakeAnalyticsExtension('vendor-b', [], [['key' => 'category_id', 'label' => 'B']]),
        ]);

        [$definition, $reason] = app(DimensionRegistryInterface::class)->resolve('category_id');
        $this->assertNull($definition);
        $this->assertStringContainsString('vendor-a:category_id', (string) $reason);

        [$qualified] = app(DimensionRegistryInterface::class)->resolve('vendor-b:category_id');
        $this->assertSame('vendor-b', $qualified?->ownerIdentifier);
    }

    /**
     * 익명 모드에서는 개인정보 차원을 싣지 않는다.
     *
     * @scenario point=dimension, extension_state=active, input=registered
     *
     * @effects pii_dimension_is_not_stored_in_anonymous_mode
     *
     * @return void 없음
     */
    public function test_pii_dimension_is_skipped_in_anonymous_mode(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'anonymous']);

        app(VisitService::class)->track($this->request(['dimensions' => ['tier' => 'gold', 'board_id' => '3']]));

        $this->assertNull(AnalyticsVisit::first()->custom_dimensions);
        $this->assertSame(['vendor-board:board_id' => '3'], AnalyticsAction::first()->custom_dimensions);
        $this->assertSame(0, AnalyticsCollectFailure::count(), '규칙에 따른 생략은 실패가 아니다');
    }

    /**
     * 개인정보 차원은 원장에서 모으든 확장이 수집기로 넘기든 집계 표에 남지 않는다.
     *
     * 집계 표는 영구 보존이고 라벨이 값 원문이다 — 남으면 정보주체 삭제·소급 익명화가 닿지 않고
     * 조회 권한 계정이 리포트·CSV 로 개인정보를 본다. 개인정보 차원 목록은 등록부에서 파생한다.
     *
     * @scenario point=archive, extension_state=active, input=registered
     *
     * @effects pii_dimension_is_never_archived
     *
     * @return void 없음
     */
    public function test_pii_dimensions_are_never_archived(): void
    {
        $pii = array_values(array_map(
            fn ($definition): string => $definition->statsDimension(),
            array_filter(app(DimensionRegistryInterface::class)->all(), fn ($definition): bool => $definition->pii),
        ));
        $this->assertNotEmpty($pii, '개인정보 차원 모집단이 비면 이 가드는 아무것도 재지 않는다');

        app(VisitService::class)->track($this->request(['dimensions' => ['board_id' => '12', 'tier' => 'gold']]));
        $this->assertSame(['vendor-board:tier' => 'gold'], AnalyticsVisit::first()->custom_dimensions, '원장에는 남는다');

        HookManager::addAction('core.analytics.archive.day', function (array $payload) use ($pii): void {
            foreach ($pii as $dimension) {
                $payload['collector']->addDimensionRow($dimension, 'silver', 'guest', ['visits' => 1]);
            }
        });

        $run = app(ArchiveService::class)->archiveDay(now('UTC')->toDateString());

        $status = $run->status instanceof \BackedEnum ? $run->status->value : $run->status;
        $this->assertSame(ArchiveRunStatus::Success->value, $status, (string) $run->error);
        $this->assertSame(1, AnalyticsDimensionStat::query()->where('dimension', 'ext:vendor-board:board_id')->count(), '개인정보가 아닌 차원은 집계된다');

        foreach ($pii as $dimension) {
            $this->assertSame(0, AnalyticsDimensionStat::query()->where('dimension', $dimension)->count(), "{$dimension} 이 집계 표에 남았다");
        }
    }

    /**
     * 서버 필터로 차원 값을 보탤 수 있다.
     *
     * @scenario point=dimension, extension_state=active, input=registered
     *
     * @effects server_filter_can_inject_dimension_values
     *
     * @return void 없음
     */
    public function test_server_filter_injects_dimensions(): void
    {
        HookManager::addFilter('core.analytics.resolve_dimensions', function (array $dims, array $dto): array {
            if (str_contains($dto['url'], '/board/')) {
                $dims['board_id'] = '7';
            }

            return $dims;
        });

        app(VisitService::class)->track($this->request());

        $this->assertSame(['vendor-board:board_id' => '7'], AnalyticsAction::first()->custom_dimensions);
    }

    /**
     * 집계가 `ext:` 차원 행을 만들고, 리포트 API 가 등록된 이름으로 읽는다.
     *
     * 라벨 수 상한(2)을 넘는 값은 방문 수가 적은 것부터 빠진다. 풀이 함수가 라벨을 이름으로 푼다.
     *
     * @scenario point=dimension, extension_state=active, input=registered
     *
     * @effects extension_dimension_is_archived_as_ext_row, dimension_max_cardinality_keeps_top_labels, ext_dimension_report_is_readable, ext_dimension_label_resolver_fills_display
     *
     * @return void 없음
     */
    public function test_archive_writes_ext_rows_and_report_reads_them(): void
    {
        $service = app(VisitService::class);

        foreach ([['12', 'a'], ['12', 'b'], ['12', 'c'], ['7', 'd'], ['7', 'e'], ['3', 'f']] as [$board, $cookie]) {
            $service->track($this->request([
                'dimensions' => ['board_id' => $board],
                'cookies' => ['g7_vid' => str_repeat($cookie, 32)],
            ]));
        }

        app(ArchiveService::class)->archiveDay(now('UTC')->toDateString());

        $labels = AnalyticsDimensionStat::query()->where('dimension', 'ext:vendor-board:board_id')->pluck('label')->all();
        sort($labels);
        $this->assertSame(['7', '12'], $labels, '상한 2 — 방문 1건뿐인 3 은 빠진다');

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/ext:vendor-board:board_id?preset=today')
            ->assertOk();

        $rows = collect($response->json('data.data'))->keyBy('label');
        $this->assertSame(3, $rows['12']['visits']);
        $this->assertSame('게시판 12', $rows['12']['label_display']);
    }

    /**
     * 코어가 원장에서 집계한 등록 차원 행과 같은 라벨을 확장이 수집기로 또 넘겨도 그날 집계는
     * 성공하고 코어 행이 남는다.
     *
     * @scenario point=archive, extension_state=active, input=registered
     *
     * @effects collector_row_colliding_with_core_row_is_dropped
     *
     * @return void 없음
     */
    public function test_collector_row_colliding_with_core_row_keeps_the_core_row(): void
    {
        app(VisitService::class)->track($this->request(['dimensions' => ['board_id' => '12']]));

        HookManager::addAction('core.analytics.archive.day', function (array $payload): void {
            $payload['collector']->addDimensionRow('ext:vendor-board:board_id', '12', 'guest', ['visits' => 99]);
        });

        $run = app(ArchiveService::class)->archiveDay(now('UTC')->toDateString());

        $status = $run->status instanceof \BackedEnum ? $run->status->value : $run->status;
        $this->assertSame(ArchiveRunStatus::Success->value, $status, (string) $run->error);

        $row = AnalyticsDimensionStat::query()->where('dimension', 'ext:vendor-board:board_id')->where('label', '12')->sole();
        $this->assertSame(1, (int) $row->visits, '원장에서 센 코어 값이 남는다');
    }

    /**
     * 꺼졌거나 데이터를 보존하고 지운 확장의 차원도 집계가 남아 있으면 원값 라벨로 읽히고
     * 선택 목록에 「꺼진 확장」으로 실린다. 등록도 집계도 없는 이름만 404 다.
     *
     * @scenario point=dimension, extension_state=inactive, input=unregistered
     *
     * @effects inactive_extension_rows_are_kept, inactive_extension_dimension_stays_readable, inactive_extension_dimension_is_listed, unknown_ext_dimension_report_is_404
     *
     * @return void 없음
     */
    public function test_inactive_extension_dimension_stays_readable(): void
    {
        AnalyticsDimensionStat::factory()->create([
            'date' => now('UTC')->toDateString(),
            'visitor_type' => 'guest',
            'dimension' => 'ext:vendor-gone:thing',
            'label' => 'x',
            'label_hash' => md5('x'),
            'visits' => 3,
        ]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/ext:vendor-gone:thing?preset=today')
            ->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('label', 'x');
        $this->assertNotNull($row, '보존된 집계가 읽혀야 한다');
        $this->assertSame(3, $row['visits']);
        $this->assertSame('x', $row['label_display'] ?? $row['label'], '풀이 함수가 없으니 원값');

        $listed = collect($this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/registered')
            ->assertOk()
            ->json('data.dimensions'))->keyBy('key');
        $this->assertTrue($listed['vendor-gone:thing']['inactive'] ?? false, '꺼진 확장 표시');
        $this->assertSame('ext:vendor-gone:thing', $listed['vendor-gone:thing']['dimension']);
        $this->assertSame('vendor-gone', $listed['vendor-gone:thing']['owner_identifier']);
        $this->assertStringContainsString('thing', $listed['vendor-gone:thing']['label']);
        $this->assertArrayNotHasKey('inactive', $listed['vendor-board:board_id'], '활성 확장 차원에는 표시가 없다');

        $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/ext:vendor-never:thing?preset=today')
            ->assertNotFound();

        $this->assertSame(1, AnalyticsDimensionStat::query()->where('dimension', 'ext:vendor-gone:thing')->count(), '행은 남는다');
    }

    /**
     * 등록 목록 API 가 차원과 목표를 돌려준다.
     *
     * @scenario point=dimension, extension_state=active, input=registered
     *
     * @effects registered_list_api_returns_dimensions_and_goals
     *
     * @return void 없음
     */
    public function test_registered_list_api(): void
    {
        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/registered')
            ->assertOk();

        $dimensions = collect($response->json('data.dimensions'))->keyBy('key');
        $this->assertSame('ext:vendor-board:board_id', $dimensions['vendor-board:board_id']['dimension']);
        $this->assertSame('게시판', $dimensions['vendor-board:board_id']['label']);
        $this->assertContains('core.user_registered', collect($response->json('data.goals'))->pluck('key')->all());

        $this->actingAs($this->adminWith(['core.analytics.privacy']))
            ->getJson('/api/admin/analytics/registered')
            ->assertForbidden();
    }

    /**
     * 잘못된 차원 선언은 사유와 함께 배제된다.
     *
     * @scenario point=dimension, extension_state=active, input=unregistered
     *
     * @effects malformed_dimension_declaration_is_rejected_with_reason
     *
     * @return void 없음
     */
    public function test_malformed_dimension_declarations_are_rejected(): void
    {
        $this->activateAnalyticsExtensions([$this->fakeAnalyticsExtension('vendor-x', [], [
            ['key' => 'ok', 'label' => 'ok'],
            ['key' => 'bad scope', 'label' => 'x'],
            ['key' => 'scope', 'label' => 'x', 'scope' => 'session'],
            ['key' => 'type', 'label' => 'x', 'value_type' => 'float'],
            ['key' => 'enum', 'label' => 'x', 'value_type' => 'enum'],
            ['key' => 'card', 'label' => 'x', 'max_cardinality' => 0],
            ['key' => 'huge', 'label' => 'x', 'max_cardinality' => 999999],
            ['key' => 'resolver', 'label' => 'x', 'label_resolver' => 'noSuchMethod'],
        ])]);

        $registry = app(DimensionRegistryInterface::class);
        $keys = array_keys($registry->all());
        sort($keys);

        $this->assertSame(['vendor-x:huge', 'vendor-x:ok', 'vendor-x:resolver'], $keys);
        $this->assertSame(10000, $registry->all()['vendor-x:huge']->maxCardinality, '상한은 코어가 자른다');

        $warnings = implode("\n", $registry->warnings());
        foreach (['bad scope', 'session', 'float', 'enum', 'max_cardinality', 'noSuchMethod'] as $needle) {
            $this->assertStringContainsString($needle, $warnings);
        }
    }

    /**
     * 등록부 필터로 차원 선언을 숨기면 그 차원 값은 기록되지 않는다.
     *
     * @scenario point=dimension, extension_state=active, input=registered
     *
     * @effects registered_dimensions_filter_can_hide_dimensions
     *
     * @return void 없음
     */
    public function test_registered_dimensions_filter_can_hide_dimensions(): void
    {
        HookManager::addFilter('core.analytics.registered_dimensions', function (array $definitions): array {
            unset($definitions['vendor-board:board_id']);

            return $definitions;
        });
        app()->forgetScopedInstances();

        $this->assertArrayNotHasKey('vendor-board:board_id', app(DimensionRegistryInterface::class)->all());

        app(VisitService::class)->track($this->request(['dimensions' => ['board_id' => '7']]));

        $this->assertNull(AnalyticsAction::first()->custom_dimensions);
    }

    /**
     * 브라우저 비콘의 `dims` 가 수집 API 를 거쳐 원장까지 간다(요청 조립 → 등록부 판정 → 저장).
     *
     * 트래커 단위 테스트는 보내는 쪽만, 서비스 테스트는 조립된 요청만 잰다 — 그 사이의 HTTP 경로가
     * 끊기면 두 쪽 테스트가 모두 초록인 채로 차원이 사라진다.
     *
     * @scenario point=dimension, extension_state=active, input=registered
     *
     * @effects beacon_dims_reach_the_ledger_over_http
     *
     * @return void 없음
     */
    public function test_beacon_dims_reach_the_ledger_over_http(): void
    {
        $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/board/free',
            'dims' => ['board_id' => '12', 'unknown_key' => 'x'],
        ])->assertNoContent();

        $this->assertSame(['vendor-board:board_id' => '12'], AnalyticsAction::first()?->custom_dimensions);
    }
}
