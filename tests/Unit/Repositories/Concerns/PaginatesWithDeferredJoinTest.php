<?php

namespace Tests\Unit\Repositories\Concerns;

use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 지연 조인 페이지네이션 Trait 단위 테스트
 *
 * 성능 수치가 아니라 **쿼리 구조**를 단언한다. 절대 시간 임계값은 CI 머신 성능에 좌우되지만
 * "넓은 컬럼이 OFFSET 스캔에 포함되지 않는다"는 구조는 기계가 판정할 수 있고, 누군가 나중에
 * inner 에 `with()` 를 다시 붙이거나 목록 컬럼을 되돌리면 즉시 실패한다.
 */
class PaginatesWithDeferredJoinTest extends TestCase
{
    use RefreshDatabase;

    /** 수집된 실행 쿼리 SQL 목록 */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('deferred_join_samples', function ($table) {
            $table->id();
            $table->unsignedBigInteger('group_id')->default(1);
            $table->string('title', 100);
            $table->unsignedInteger('view_count')->default(0);
            $table->longText('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('deferred_join_sample_comments', function ($table) {
            $table->id();
            $table->unsignedBigInteger('sample_id');
            $table->string('content', 100);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('deferred_join_sample_comments');
        Schema::dropIfExists('deferred_join_samples');

        parent::tearDown();
    }

    /**
     * 샘플 행을 생성합니다.
     *
     * @param  int  $count  생성 건수
     * @param  int  $viewCount  조회수 (동률 tie-break 검증용 고정값)
     */
    private function seedSamples(int $count, int $viewCount = 0): void
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'group_id' => 1,
                'title' => "제목 {$i}",
                'view_count' => $viewCount,
                'body' => str_repeat('본문 ', 200),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('deferred_join_samples')->insert($rows);
    }

    /** 쿼리 로그 수집을 시작합니다. */
    private function startCapture(): void
    {
        $this->queries = [];

        DB::listen(function ($query) {
            $this->queries[] = $query->sql;
        });
    }

    /**
     * 수집된 쿼리 중 조건에 맞는 것만 돌려줍니다.
     *
     * @param  string  $needle  포함 문자열
     * @return array<int, string> 일치 쿼리 목록
     */
    private function queriesContaining(string $needle): array
    {
        return array_values(array_filter($this->queries, fn (string $sql) => str_contains($sql, $needle)));
    }

    /** 테스트용 리포지토리를 생성합니다. */
    private function repository(): object
    {
        return new class
        {
            use PaginatesWithDeferredJoin;

            /**
             * 지연 조인 페이지네이션을 그대로 노출합니다. (Trait 메서드는 protected)
             *
             * @param  Builder  $query  대상 쿼리
             * @param  array  $args  나머지 인자
             */
            public function paginate(Builder $query, array $columns, array $sort, int $perPage, ...$args)
            {
                return $this->paginateWithDeferredJoin($query, $columns, $sort, $perPage, ...$args);
            }
        };
    }

    /**
     * @scale n=200000 asserts=id_only_inner_query
     */
    public function test_inner_query_selects_only_key_column_without_wide_columns(): void
    {
        $this->seedSamples(30);
        $this->startCapture();

        $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'asc']],
            10,
            2,
        );

        $offsetQueries = $this->queriesContaining('offset');

        $this->assertCount(1, $offsetQueries, 'OFFSET 스캔은 정확히 한 번(inner)만 일어나야 한다');
        // 테이블 프리픽스는 환경 설정값이므로 패턴으로 판정한다
        $this->assertMatchesRegularExpression('/^select `[^`]*deferred_join_samples`\.`id` from/', $offsetQueries[0]);
        $this->assertStringNotContainsString('body', $offsetQueries[0], 'inner 는 넓은 컬럼을 읽지 않아야 한다');
        $this->assertStringNotContainsString('title', $offsetQueries[0]);
    }

    /**
     * @scale n=200000 asserts=no_wide_column_in_offset_scan
     */
    public function test_outer_query_filters_by_id_set_without_offset(): void
    {
        $this->seedSamples(30);
        $this->startCapture();

        $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'asc']],
            10,
            2,
        );

        $outer = $this->queriesContaining('`id` in (');

        $this->assertNotEmpty($outer, 'outer 는 ID 집합으로 조회해야 한다');
        $this->assertStringNotContainsString('offset', $outer[0], 'outer 에는 OFFSET 이 없어야 한다');
    }

    public function test_relations_are_not_eager_loaded_for_inner_query(): void
    {
        $this->seedSamples(5);
        DB::table('deferred_join_sample_comments')->insert([
            ['sample_id' => 1, 'content' => '댓글', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->startCapture();

        $result = $this->repository()->paginate(
            DeferredJoinSample::query()->with('comments'),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'asc']],
            10,
            1,
            ['comments'],
        );

        $commentQueries = $this->queriesContaining('deferred_join_sample_comments');

        $this->assertCount(1, $commentQueries, '관계 쿼리는 outer 에서 한 번만 실행되어야 한다');
        $this->assertTrue($result->getCollection()->first()->relationLoaded('comments'));
    }

    public function test_count_query_is_skipped_when_total_is_injected(): void
    {
        $this->seedSamples(30);
        $this->startCapture();

        $result = $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'asc']],
            10,
            1,
            [],
            [],
            'id',
            30,
        );

        $this->assertEmpty($this->queriesContaining('count(*)'), '주입된 total 이 있으면 COUNT 를 실행하지 않아야 한다');
        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(30, $result->total());
    }

    public function test_simple_mode_skips_count_and_detects_next_page(): void
    {
        $this->seedSamples(25);
        $this->startCapture();

        $result = $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'asc']],
            10,
            1,
            [],
            [],
            'id',
            null,
            true,
        );

        $this->assertEmpty($this->queriesContaining('count(*)'));
        $this->assertInstanceOf(Paginator::class, $result);
        $this->assertCount(10, $result->items(), 'perPage + 1 건을 읽어도 페이지 항목은 perPage 건이어야 한다');
        $this->assertTrue($result->hasMorePages());
    }

    public function test_simple_mode_reports_last_page_correctly(): void
    {
        $this->seedSamples(15);

        $result = $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'asc']],
            10,
            2,
            [],
            [],
            'id',
            null,
            true,
        );

        $this->assertCount(5, $result->items());
        $this->assertFalse($result->hasMorePages(), '마지막 페이지에서 다음 페이지가 있다고 보고하면 안 된다');
    }

    public function test_tie_breaking_key_is_appended_to_sort_spec(): void
    {
        // 모든 행의 view_count 가 동일 — 키 컬럼 tie-break 가 없으면 페이지 경계가 흔들린다
        $this->seedSamples(20, viewCount: 7);

        $page1 = $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'view_count'],
            [['column' => 'view_count', 'direction' => 'desc']],
            10,
            1,
        );
        $page2 = $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'view_count'],
            [['column' => 'view_count', 'direction' => 'desc']],
            10,
            2,
        );

        $firstIds = $page1->getCollection()->pluck('id')->all();
        $secondIds = $page2->getCollection()->pluck('id')->all();

        $this->assertSame([20, 19, 18, 17, 16, 15, 14, 13, 12, 11], $firstIds);
        $this->assertSame([10, 9, 8, 7, 6, 5, 4, 3, 2, 1], $secondIds);
        $this->assertEmpty(array_intersect($firstIds, $secondIds), '페이지 간 중복 행이 없어야 한다');
    }

    public function test_outer_result_order_matches_inner_order(): void
    {
        $this->seedSamples(12);

        $result = $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'title'],
            [['column' => 'title', 'direction' => 'asc']],
            5,
            1,
        );

        $ids = $result->getCollection()->pluck('id')->all();
        $sorted = $result->getCollection()->sortBy('title')->pluck('id')->all();

        $this->assertSame(array_values($sorted), $ids, 'outer 결과 순서가 정렬 스펙과 일치해야 한다');
    }

    public function test_soft_deleted_rows_stay_excluded(): void
    {
        $this->seedSamples(10);
        DeferredJoinSample::query()->whereIn('id', [3, 7])->delete();

        $result = $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'asc']],
            20,
            1,
        );

        $ids = $result->getCollection()->pluck('id')->all();

        $this->assertNotContains(3, $ids);
        $this->assertNotContains(7, $ids);
        $this->assertSame(8, $result->total());
    }

    public function test_with_trashed_scope_is_preserved(): void
    {
        $this->seedSamples(10);
        DeferredJoinSample::query()->whereIn('id', [3, 7])->delete();

        $result = $this->repository()->paginate(
            DeferredJoinSample::query()->withTrashed(),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'asc']],
            20,
            1,
        );

        $this->assertSame(10, $result->total());
        $this->assertContains(3, $result->getCollection()->pluck('id')->all());
    }

    public function test_filters_applied_to_query_are_preserved(): void
    {
        $this->seedSamples(10);
        DB::table('deferred_join_samples')->whereIn('id', [2, 4, 6])->update(['group_id' => 9]);

        $result = $this->repository()->paginate(
            DeferredJoinSample::query()->where('group_id', 9),
            ['id', 'group_id'],
            [['column' => 'id', 'direction' => 'asc']],
            10,
            1,
        );

        $this->assertSame([2, 4, 6], $result->getCollection()->pluck('id')->all());
        $this->assertSame(3, $result->total());
    }

    public function test_preserve_id_order_restores_inner_sequence(): void
    {
        $this->seedSamples(6);

        $result = $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'desc']],
            3,
            1,
            [],
            [],
            'id',
            null,
            false,
            true,
        );

        $this->assertSame([6, 5, 4], $result->getCollection()->pluck('id')->all());
    }

    public function test_with_count_is_applied_on_outer_only(): void
    {
        $this->seedSamples(3);
        DB::table('deferred_join_sample_comments')->insert([
            ['sample_id' => 1, 'content' => 'a', 'created_at' => now(), 'updated_at' => now()],
            ['sample_id' => 1, 'content' => 'b', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'asc']],
            10,
            1,
            [],
            ['comments'],
        );

        $this->assertSame(2, (int) $result->getCollection()->firstWhere('id', 1)->comments_count);
    }

    public function test_grouped_query_total_counts_groups_not_first_group_rows(): void
    {
        // 조인으로 행이 불어난 뒤 groupBy 로 접히는 형태 — 조인 행 7건, 그룹 5건
        $this->seedSamples(5);
        DB::table('deferred_join_sample_comments')->insert([
            ['sample_id' => 1, 'content' => 'a', 'created_at' => now(), 'updated_at' => now()],
            ['sample_id' => 1, 'content' => 'b', 'created_at' => now(), 'updated_at' => now()],
            ['sample_id' => 1, 'content' => 'c', 'created_at' => now(), 'updated_at' => now()],
            ['sample_id' => 2, 'content' => 'd', 'created_at' => now(), 'updated_at' => now()],
            ['sample_id' => 3, 'content' => 'e', 'created_at' => now(), 'updated_at' => now()],
            ['sample_id' => 4, 'content' => 'f', 'created_at' => now(), 'updated_at' => now()],
            ['sample_id' => 5, 'content' => 'g', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = $this->repository()->paginate(
            DeferredJoinSample::query()
                ->join('deferred_join_sample_comments as c', 'c.sample_id', '=', 'deferred_join_samples.id')
                ->groupBy('deferred_join_samples.id'),
            ['deferred_join_samples.id'],
            [['column' => 'deferred_join_samples.id', 'direction' => 'asc']],
            10,
            1,
        );

        // 집계 쿼리를 서브쿼리로 감싸지 않으면 첫 그룹의 행 수(3)가 총 건수로 잡힌다
        $this->assertSame(5, $result->total(), '그룹 쿼리의 총 건수는 그룹 수여야 한다');
        $this->assertCount(5, $result->items());
        $this->assertSame(1, $result->lastPage());
    }

    public function test_grouped_query_paginates_across_pages_with_correct_total(): void
    {
        $this->seedSamples(12);

        $rows = [];
        for ($sampleId = 1; $sampleId <= 12; $sampleId++) {
            // 1번 샘플만 댓글 5건 — 첫 그룹 행 수와 그룹 수를 다르게 만든다
            $repeat = $sampleId === 1 ? 5 : 1;
            for ($i = 0; $i < $repeat; $i++) {
                $rows[] = ['sample_id' => $sampleId, 'content' => "c{$sampleId}-{$i}", 'created_at' => now(), 'updated_at' => now()];
            }
        }
        DB::table('deferred_join_sample_comments')->insert($rows);

        $grouped = fn () => DeferredJoinSample::query()
            ->join('deferred_join_sample_comments as c', 'c.sample_id', '=', 'deferred_join_samples.id')
            ->groupBy('deferred_join_samples.id');

        $page2 = $this->repository()->paginate(
            $grouped(),
            ['deferred_join_samples.id'],
            [['column' => 'deferred_join_samples.id', 'direction' => 'asc']],
            5,
            2,
        );

        $this->assertSame(12, $page2->total());
        $this->assertSame(3, $page2->lastPage());
        $this->assertSame([6, 7, 8, 9, 10], $page2->getCollection()->pluck('id')->all());
    }

    public function test_returned_paginator_supports_query_string_appending(): void
    {
        // 필터 조건을 페이지 링크에 보존하는 책임은 호출자의 appends()/withQueryString() 에 있다.
        // 지연 조인 전환 후에도 그 계약이 유지되는지 확인한다 (Laravel paginate() 도 동일).
        $this->seedSamples(30);

        $result = $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'asc']],
            10,
            1,
        );

        $url = $result->appends(['search' => '검색어', 'sort' => 'latest'])->nextPageUrl();

        $this->assertStringContainsString('search=', (string) $url);
        $this->assertStringContainsString('sort=latest', (string) $url);
        $this->assertStringContainsString('page=2', (string) $url);
    }

    public function test_custom_page_name_is_reflected_in_paginator_links(): void
    {
        $this->seedSamples(30);

        $result = $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'asc']],
            10,
            1,
            [],
            [],
            'id',
            null,
            false,
            false,
            'p',
        );

        $this->assertStringContainsString('p=2', (string) $result->nextPageUrl());
    }

    /**
     * 깊은 OFFSET 의 비용 증가가 유계인지 확인합니다.
     *
     * 절대 시간 임계값(`assertLessThan(50, $ms)`)은 CI 머신 성능에 좌우되므로 쓰지 않는다.
     * 대신 **같은 머신 안에서의 비율**만 단언한다 — 뒤쪽 페이지 소요 시간이 첫 페이지의 몇 배인가.
     * 지연 조인 이전에는 이 비율이 20배를 넘었고(OFFSET 이 커질수록 넓은 컬럼을 그만큼 더 읽는다),
     * 이후에는 넓은 컬럼을 읽는 행 수가 페이지 크기로 고정되므로 배수가 상수에 가깝게 붙는다.
     * 상한 5배는 실측(21.6배 → ~2배)에 여유를 둔 값이다.
     *
     * @scale n=200000 asserts=deep_offset_bounded_growth
     */
    public function test_deep_offset_cost_growth_is_bounded(): void
    {
        $rows = 20000;
        $perPage = 20;

        // 넓은 컬럼(longText)이 있어야 OFFSET 구간의 읽기 비용 차이가 드러난다
        $body = str_repeat('본문 ', 250);
        $now = now();

        for ($chunk = 0; $chunk < $rows / 1000; $chunk++) {
            $batch = [];

            for ($i = 1; $i <= 1000; $i++) {
                $batch[] = [
                    'group_id' => 1,
                    'title' => '제목 '.($chunk * 1000 + $i),
                    'view_count' => 0,
                    'body' => $body,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('deferred_join_samples')->insert($batch);
        }

        $measure = function (int $page) use ($perPage): float {
            $run = fn () => $this->repository()->paginate(
                DeferredJoinSample::query(),
                ['id', 'title', 'body'],
                [['column' => 'id', 'direction' => 'asc']],
                $perPage,
                $page,
            );

            $run(); // 버퍼 풀 워밍 — 첫 회는 버린다

            $samples = [];

            for ($i = 0; $i < 5; $i++) {
                $start = microtime(true);
                $run();
                $samples[] = (microtime(true) - $start) * 1000;
            }

            sort($samples);

            return $samples[2]; // 중앙값
        };

        $firstPage = $measure(1);
        $lastPage = $measure((int) ($rows / $perPage));

        $this->assertGreaterThan(0.0, $firstPage, '측정값이 0 이면 비율 판정이 무의미하다');

        $ratio = $lastPage / $firstPage;

        $this->assertLessThanOrEqual(
            5.0,
            $ratio,
            sprintf(
                '마지막 페이지가 첫 페이지의 %.1f배 — 깊은 OFFSET 비용이 유계가 아니다 (첫 %.2fms / 마지막 %.2fms)',
                $ratio,
                $firstPage,
                $lastPage
            )
        );
    }

    /**
     * 지연 조인 결과가 기존 `paginate()` 와 **같은 ID 시퀀스**인지 확인합니다.
     *
     * 정적 골든 fixture 를 파일로 떠 두면 기대값을 사람이 다시 만들어야 해서, 정렬 규칙이
     * 바뀔 때 fixture 를 함께 고쳐 회귀를 덮어버리기 쉽다. 여기서는 **교체 전 경로 자체**
     * (`Builder::paginate`)를 오라클로 두고 매 실행마다 기대값을 다시 얻는다. 순서까지
     * 비교하므로 tie-break 회귀도 잡힌다.
     */
    public function test_id_sequence_matches_plain_paginate_oracle(): void
    {
        // 조회수가 겹치도록 만들어 tie-break 가 개입하게 한다
        for ($i = 1; $i <= 47; $i++) {
            DB::table('deferred_join_samples')->insert([
                'group_id' => $i % 3,
                'title' => sprintf('제목 %03d', $i),
                'view_count' => $i % 5,
                'body' => str_repeat('본문 ', 50),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $perPage = 10;
        $sort = [
            ['column' => 'view_count', 'direction' => 'desc'],
            ['column' => 'id', 'direction' => 'asc'],
        ];

        foreach ([1, 2, 3, 5] as $page) {
            $oracle = DeferredJoinSample::query()
                ->where('group_id', '!=', 2)
                ->orderBy('view_count', 'desc')
                ->orderBy('id', 'asc')
                ->paginate($perPage, ['id'], 'page', $page);

            $actual = $this->repository()->paginate(
                DeferredJoinSample::query()->where('group_id', '!=', 2),
                ['id', 'title'],
                $sort,
                $perPage,
                $page,
            );

            $this->assertSame(
                $oracle->getCollection()->pluck('id')->all(),
                $actual->getCollection()->pluck('id')->all(),
                "{$page}페이지의 ID 시퀀스가 기존 paginate() 결과와 달라졌다"
            );
            $this->assertSame($oracle->total(), $actual->total(), "{$page}페이지의 총 건수가 달라졌다");
            $this->assertSame($oracle->lastPage(), $actual->lastPage());
        }
    }

    public function test_empty_result_does_not_run_outer_query(): void
    {
        $this->startCapture();

        $result = $this->repository()->paginate(
            DeferredJoinSample::query(),
            ['id', 'title'],
            [['column' => 'id', 'direction' => 'asc']],
            10,
            1,
        );

        $this->assertTrue($result->getCollection()->isEmpty());
        $this->assertEmpty($this->queriesContaining('`id` in ('), '빈 결과에서는 outer 쿼리를 실행하지 않아야 한다');
    }
}

/**
 * 지연 조인 테스트용 샘플 모델
 */
class DeferredJoinSample extends Model
{
    use SoftDeletes;

    protected $table = 'deferred_join_samples';

    protected $guarded = [];

    /**
     * 샘플 댓글 관계
     *
     * @return HasMany 댓글 관계
     */
    public function comments()
    {
        return $this->hasMany(DeferredJoinSampleComment::class, 'sample_id');
    }
}

/**
 * 지연 조인 테스트용 샘플 댓글 모델
 */
class DeferredJoinSampleComment extends Model
{
    protected $table = 'deferred_join_sample_comments';

    protected $guarded = [];
}
