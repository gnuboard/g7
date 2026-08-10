<?php

namespace Tests\Unit\Benchmark;

use App\Benchmark\Axes\BatchAxisRunner;
use App\Benchmark\Axes\ListAxisRunner;
use App\Benchmark\Axes\ScreenAxisRunner;
use App\Benchmark\Axes\WriteAxisRunner;
use App\Benchmark\DTO\BenchmarkProfile;
use App\Benchmark\DTO\BenchmarkRunOptions;
use App\Benchmark\QueryCollector;
use App\Enums\BenchmarkAxis;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 축 실행기 단위 테스트.
 *
 * 계측 결과는 시간 값이라 **수치를 단언하지 않는다**. 단언 대상은 각 축이 무엇을 실행하고
 * 무엇을 산출하는지, 그리고 계측이 데이터에 흔적을 남기지 않는지다.
 */
class BenchmarkAxisRunnerTest extends TestCase
{
    /**
     * 프로파일을 만듭니다.
     *
     * @param  BenchmarkAxis  $axis  계측 축
     * @param  array<string, mixed>  $options  축 고유 옵션
     * @return BenchmarkProfile 프로파일
     */
    private function profile(BenchmarkAxis $axis, array $options): BenchmarkProfile
    {
        return new BenchmarkProfile(
            key: 'sample',
            axis: $axis,
            sourceKind: 'core',
            sourceIdentifier: 'core',
            options: $options,
        );
    }

    /**
     * 목록 축은 컬럼 폭 3축을 재고 배수를 함께 산출합니다.
     *
     * @effects list_axis_reports_all_list_and_id_only_columns
     */
    #[Test]
    public function 목록_축은_3축_결과와_배수를_산출한다(): void
    {
        $result = app(ListAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::ListQuery, [
                'table' => 'users',
                'columns' => ['id', 'email'],
                'order' => [['id', 'desc']],
                'soft_delete' => true,
            ]),
            new BenchmarkRunOptions(offsets: [0], runs: 1),
        );

        $this->assertFalse($result->skipped);
        $this->assertSame(['OFFSET', '전체 컬럼(ms)', '목록 컬럼(ms)', 'ID만(ms)', '전체÷ID'], $result->headers);
        $this->assertCount(1, $result->rows);
        $this->assertSame('users', $result->metrics['table']);
        $this->assertSame(['id', 'email'], $result->metrics['columns']);

        $offset = $result->metrics['offsets'][0];
        foreach (['offset', 'all_ms', 'list_ms', 'id_only_ms', 'ratio'] as $key) {
            $this->assertArrayHasKey($key, $offset);
        }
    }

    /**
     * 배수는 키 컬럼 조회를 기준선으로 산출됩니다.
     *
     * 이 기준선이 지연 조인의 inner 쿼리에 해당하므로, 배수가 곧 적용 기대 효과입니다.
     *
     * @effects list_axis_ratio_is_derived_from_id_only_baseline
     */
    #[Test]
    public function 배수는_키_컬럼_기준선으로_산출된다(): void
    {
        $result = app(ListAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::ListQuery, ['table' => 'users', 'columns' => ['*']]),
            new BenchmarkRunOptions(offsets: [0], runs: 1),
        );

        $offset = $result->metrics['offsets'][0];

        $this->assertSame(
            round($offset['all_ms'] / $offset['id_only_ms'], 1),
            $offset['ratio'],
            '배수는 전체 컬럼 ÷ 키 컬럼이어야 한다.'
        );
    }

    /**
     * 선언된 필터와 소프트 삭제가 실제 계측 쿼리에 반영됩니다.
     *
     * 필터 없이 재면 인덱스 선택이 달라져 화면에서 일어나는 일과 다른 것을 잽니다.
     *
     * @effects declared_filters_are_applied_to_measured_query, soft_delete_declaration_adds_deleted_at_predicate
     */
    #[Test]
    public function 선언된_필터와_소프트_삭제가_계측_쿼리에_반영된다(): void
    {
        // 확장 설치 여부에 의존하지 않도록 소프트 삭제 컬럼이 있는 코어 테이블로 검증한다.
        // 컬럼이 없는 테이블에서 술어가 붙지 않는 성질은 다음 테스트가 다룬다.
        $collected = app(QueryCollector::class)->collect(function () {
            app(ListAxisRunner::class)->run(
                $this->profile(BenchmarkAxis::ListQuery, [
                    'table' => 'attachments',
                    'columns' => ['id'],
                    'filters' => ['disk' => 'public'],
                    'soft_delete' => true,
                ]),
                new BenchmarkRunOptions(offsets: [0], runs: 1),
            );
        });

        $sql = str_replace('`', '"', implode("\n", array_column($collected['queries'], 'sql')));

        $this->assertStringContainsString('"deleted_at" is null', $sql);
        $this->assertStringContainsString('"disk" = ?', $sql);
    }

    /**
     * 연산자 형태 필터가 계측 쿼리에 반영됩니다.
     *
     * 등가 비교만 지원하면 화면이 실제로 거는 필터를 선언할 수 없는 목록이 생깁니다 —
     * 주문 목록은 상태 미지정 시 임시 주문 상태를 `NOT IN` 으로 제외합니다.
     *
     * @effects operator_filters_are_applied_to_measured_query
     */
    #[Test]
    public function 연산자_형태_필터가_계측_쿼리에_반영된다(): void
    {
        $collected = app(QueryCollector::class)->collect(function () {
            app(ListAxisRunner::class)->run(
                $this->profile(BenchmarkAxis::ListQuery, [
                    'table' => 'attachments',
                    'columns' => ['id'],
                    'filters' => [
                        'disk' => ['not in', ['public', 'local']],
                        'id' => ['>=', 10],
                    ],
                ]),
                new BenchmarkRunOptions(offsets: [0], runs: 1),
            );
        });

        $sql = str_replace('`', '"', implode("\n", array_column($collected['queries'], 'sql')));

        $this->assertStringContainsString('"disk" not in (?, ?)', $sql);
        $this->assertStringContainsString('"id" >= ?', $sql);
    }

    /**
     * 배열 값을 등가 비교로 선언하면 IN 으로 오해석하지 않습니다.
     *
     * 형태(2원소 + 첫 원소가 문자열)로만 연산자 선언을 판정하므로, 값 자체가 배열인 등가
     * 비교와 구분됩니다.
     *
     * @effects equality_filter_is_not_misread_as_operator_form
     */
    #[Test]
    public function 세_원소_배열은_연산자_선언으로_보지_않는다(): void
    {
        $result = app(ListAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::ListQuery, [
                'table' => 'attachments',
                'columns' => ['id'],
                // 2원소가 아니라 연산자 선언 형태가 아니다 → 등가 비교로 처리(연산자 검증 통과)
                'filters' => ['disk' => ['a', 'b', 'c']],
            ]),
            new BenchmarkRunOptions(offsets: [0], runs: 1),
        );

        // 연산자 검증에 걸리지 않고 실행된다
        $this->assertFalse($result->skipped, '건너뜀 사유: '.(string) $result->skipReason);
    }

    /**
     * 알 수 없는 연산자는 측정 전에 사유와 함께 거부됩니다.
     *
     * 조용히 무시하면 그 필터가 빠진 채로 측정되어, 화면과 다른 것을 재면서도 정상 측정으로
     * 보고됩니다.
     *
     * @effects unknown_filter_operator_is_refused_before_measuring
     */
    #[Test]
    public function 알_수_없는_필터_연산자는_측정_전에_거부된다(): void
    {
        $result = app(ListAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::ListQuery, [
                'table' => 'attachments',
                'columns' => ['id'],
                'filters' => ['disk' => ['betwixt', ['a', 'b']]],
            ]),
            new BenchmarkRunOptions(offsets: [0], runs: 1),
        );

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('연산자를 알 수 없습니다', (string) $result->skipReason);
        $this->assertStringContainsString('betwixt', (string) $result->skipReason);
    }

    /**
     * 소프트 삭제 컬럼이 없는 테이블에는 술어를 붙이지 않습니다.
     *
     * 선언을 그대로 믿고 붙이면 컬럼 없는 테이블에서 SQL 오류로 계측이 죽습니다.
     *
     * @effects soft_delete_declaration_adds_deleted_at_predicate
     */
    #[Test]
    public function 소프트_삭제_컬럼이_없으면_술어를_붙이지_않는다(): void
    {
        $collected = app(QueryCollector::class)->collect(function () {
            app(ListAxisRunner::class)->run(
                // users 에는 deleted_at 이 없다 (탈퇴는 status 로 표현)
                $this->profile(BenchmarkAxis::ListQuery, [
                    'table' => 'users',
                    'columns' => ['id'],
                    'soft_delete' => true,
                ]),
                new BenchmarkRunOptions(offsets: [0], runs: 1),
            );
        });

        $sql = str_replace('`', '"', implode("\n", array_column($collected['queries'], 'sql')));

        $this->assertStringNotContainsString('"deleted_at" is null', $sql);
    }

    /**
     * `--explain` 은 목록 컬럼과 키 컬럼 두 폭의 실행 계획을 모두 수집합니다.
     *
     * 키 컬럼 계획이 지연 조인 inner 의 계획이라, 인덱스 설계 근거는 두 계획의 대조입니다.
     *
     * @effects explain_option_collects_plans_for_both_column_widths
     */
    #[Test]
    public function explain_은_두_폭의_실행_계획을_수집한다(): void
    {
        $result = app(ListAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::ListQuery, ['table' => 'users', 'columns' => ['id', 'email']]),
            new BenchmarkRunOptions(offsets: [0], runs: 1, explain: true),
        );

        $notes = implode("\n", $result->notes);

        $this->assertStringContainsString('EXPLAIN @ OFFSET 0 — 목록 컬럼', $notes);
        $this->assertStringContainsString('EXPLAIN @ OFFSET 0 — ID 만 (지연 조인 inner)', $notes);
    }

    /**
     * `['*']` 선언은 실제 스키마 컬럼으로 펼쳐집니다.
     *
     * 응답 계약상 전 컬럼을 노출하는 목록의 비교축이 `select *` vs `select id` 임을 고정합니다.
     *
     * @effects wildcard_columns_expand_to_actual_schema_columns
     */
    #[Test]
    public function 와일드카드_컬럼은_실제_스키마로_펼쳐진다(): void
    {
        $result = app(ListAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::ListQuery, ['table' => 'users', 'columns' => ['*']]),
            new BenchmarkRunOptions(offsets: [0], runs: 1),
        );

        $this->assertContains('id', $result->metrics['columns']);
        $this->assertContains('email', $result->metrics['columns']);
        $this->assertGreaterThan(2, count($result->metrics['columns']));
    }

    /**
     * 스키마에 없는 선언 컬럼은 걸러집니다.
     *
     * 확장 버전에 따라 컬럼 구성이 달라도 계측이 죽지 않아야 합니다.
     *
     * @effects nonexistent_declared_columns_are_filtered_out
     */
    #[Test]
    public function 없는_컬럼은_걸러진다(): void
    {
        $result = app(ListAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::ListQuery, [
                'table' => 'users',
                'columns' => ['id', 'column_that_does_not_exist'],
            ]),
            new BenchmarkRunOptions(offsets: [0], runs: 1),
        );

        $this->assertSame(['id'], $result->metrics['columns']);
    }

    /**
     * 없는 테이블은 사유와 함께 건너뛰어집니다.
     */
    #[Test]
    public function 없는_테이블은_사유와_함께_건너뛴다(): void
    {
        $result = app(ListAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::ListQuery, ['table' => 'nope_missing_table']),
            new BenchmarkRunOptions(offsets: [0], runs: 1),
        );

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('테이블이 없습니다', (string) $result->skipReason);
    }

    /**
     * 화면 축은 상태 코드·응답 시간·쿼리 건수를 산출하고 계측 흔적을 남기지 않습니다.
     *
     * @effects screen_axis_dispatches_through_http_kernel_with_middleware, screen_axis_reports_status_response_time_and_query_count, absence_of_n_plus_one_is_stated_explicitly, measurement_leaves_no_residual_rows_after_rollback, container_request_and_auth_guards_are_restored_after_each_dispatch, route_name_declaration_resolves_uri_without_prefix_assembly
     */
    #[Test]
    public function 화면_축은_응답과_쿼리_요약을_산출하고_흔적을_남기지_않는다(): void
    {
        $usersBefore = User::count();

        $result = app(ScreenAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::Screen, [
                'route' => 'api.admin.users.index',
                'query' => ['per_page' => 5],
                'permissions' => ['core.users.read'],
            ]),
            new BenchmarkRunOptions(runs: 1),
        );

        $this->assertFalse($result->skipped, '건너뜀 사유: '.(string) $result->skipReason);
        $this->assertSame(['요청', '상태', '응답(ms)', '쿼리(건)', 'DB(ms)'], $result->headers);
        $this->assertSame(200, $result->metrics['status']);
        $this->assertGreaterThan(0, $result->metrics['query_count'], '화면 1장은 최소 1건 이상 쿼리를 실행한다.');
        $this->assertTrue($result->metrics['acting_user']['ephemeral'], '기본은 계측용 임시 계정이다.');

        // N+1 후보가 없으면 없다고 명시해야 한다 — 침묵은 "확인 안 함"과 구분되지 않는다
        $notes = implode("\n", $result->notes);
        $this->assertTrue(
            str_contains($notes, 'N+1 후보'),
            'N+1 후보 유무가 결과에 명시되어야 한다.'
        );

        // 계측 계정 생성부터 요청 처리까지 롤백되므로 잔여 계정/역할/토큰이 없어야 한다
        $this->assertSame($usersBefore, User::count());
        $this->assertSame(0, DB::table('roles')->where('identifier', 'like', 'g7_bench_%')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('name', 'like', 'g7-bench-%')->count());

        // 인증 가드가 복구되지 않으면 이어지는 계측이 앞 요청의 인증을 물려받는다
        $this->assertFalse(Auth::check(), '계측 후 인증 가드가 복구되어야 한다.');
    }

    /**
     * 선언한 권한만 임시 계정에 부여됩니다.
     *
     * 전권 계정으로 재면 권한 검사 비용과 분기가 실제와 달라집니다.
     *
     * @effects ephemeral_admin_receives_only_declared_permissions
     */
    #[Test]
    public function 임시_계정은_선언한_권한만_받는다(): void
    {
        // 선언한 권한이 없으면 관리자 목록 화면의 권한 미들웨어를 통과하지 못해야 한다
        $result = app(ScreenAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::Screen, [
                'route' => 'api.admin.users.index',
                'permissions' => [],
            ]),
            new BenchmarkRunOptions(runs: 1),
        );

        $this->assertFalse($result->skipped, '건너뜀 사유: '.(string) $result->skipReason);
        $this->assertSame(
            403,
            $result->metrics['status'],
            '권한을 선언하지 않으면 권한 미들웨어에서 막혀야 한다 — 전권 계정이 아니라는 실증.'
        );
    }

    /**
     * `--as` 로 지정한 기존 계정으로 계측할 수 있습니다.
     *
     * @effects as_option_measures_with_the_named_existing_account
     */
    #[Test]
    public function as_옵션은_지정_계정으로_계측한다(): void
    {
        $user = User::factory()->create();

        $result = app(ScreenAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::Screen, ['route' => 'api.admin.users.index']),
            new BenchmarkRunOptions(runs: 1, asUser: $user->email),
        );

        $this->assertFalse($result->skipped, '건너뜀 사유: '.(string) $result->skipReason);
        $this->assertFalse($result->metrics['acting_user']['ephemeral']);
        $this->assertSame($user->email, $result->metrics['acting_user']['email']);
    }

    /**
     * 없는 계정을 지정하면 사유와 함께 건너뛰어집니다.
     */
    #[Test]
    public function 없는_지정_계정은_사유와_함께_건너뛴다(): void
    {
        $result = app(ScreenAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::Screen, ['route' => 'api.admin.users.index']),
            new BenchmarkRunOptions(runs: 1, asUser: 'no-such-account@example.test'),
        );

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('계정을 찾을 수 없습니다', (string) $result->skipReason);
    }

    /**
     * 등록되지 않은 라우트명은 404 를 재는 대신 즉시 건너뛰어집니다.
     *
     * @effects missing_route_name_fails_instead_of_measuring_a_404
     */
    #[Test]
    public function 없는_라우트명은_사유와_함께_건너뛴다(): void
    {
        $result = app(ScreenAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::Screen, ['route' => 'api.route.that.does.not.exist']),
            new BenchmarkRunOptions(runs: 1),
        );

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('등록되지 않은 라우트명', (string) $result->skipReason);
    }

    /**
     * 쓰기 축의 `prepare` 는 계측 구간 밖에서 회차마다 실행되고 결과가 콜백에 전달됩니다.
     *
     * @effects prepare_runs_once_per_iteration, callback_receives_prepare_result, write_axis_reports_first_run_and_median_separately
     */
    #[Test]
    public function 쓰기_축은_prepare_를_회차마다_계측_밖에서_실행한다(): void
    {
        BenchmarkWriteSpy::reset();

        $result = app(WriteAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::Write, [
                'prepare' => [BenchmarkWriteSpy::class, 'prepare'],
                'callback' => [BenchmarkWriteSpy::class, 'create'],
                'cleanup' => [BenchmarkWriteSpy::class, 'cleanup'],
            ]),
            new BenchmarkRunOptions(runs: 3, allowWrite: true),
        );

        $this->assertFalse($result->skipped, '건너뜀 사유: '.(string) $result->skipReason);
        $this->assertSame(3, BenchmarkWriteSpy::$prepareCalls, 'prepare 는 회차마다 실행된다.');
        $this->assertSame(3, BenchmarkWriteSpy::$createCalls);
        $this->assertSame([1, 2, 3], BenchmarkWriteSpy::$receivedContexts, 'prepare 반환값이 콜백에 전달된다.');
        $this->assertSame(1, BenchmarkWriteSpy::$cleanupCalls, 'cleanup 은 계측 종료 후 1회 실행된다.');

        $this->assertSame(['첫 회(ms)', '중앙값(ms)', '회차', '쿼리(건)', 'DB(ms)'], $result->headers);
        $this->assertCount(3, $result->metrics['samples_ms']);
    }

    /**
     * `prepare` 실행은 계측 창 밖이라 쿼리 집계에 섞이지 않습니다.
     *
     * 시간이 아니라 쿼리 건수로 확인합니다 — 시간 기반 확인은 실행 환경에 따라 흔들립니다.
     *
     * @effects prepare_runs_outside_the_measured_window
     */
    #[Test]
    public function prepare_쿼리는_계측_집계에_섞이지_않는다(): void
    {
        BenchmarkWriteSpy::reset();
        BenchmarkWriteSpy::$prepareQueries = 3;
        BenchmarkWriteSpy::$createQueries = 1;

        $result = app(WriteAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::Write, [
                'prepare' => [BenchmarkWriteSpy::class, 'prepare'],
                'callback' => [BenchmarkWriteSpy::class, 'create'],
            ]),
            new BenchmarkRunOptions(runs: 1, allowWrite: true),
        );

        $this->assertFalse($result->skipped, '건너뜀 사유: '.(string) $result->skipReason);
        $this->assertSame(
            1,
            $result->metrics['query_count'],
            'prepare 가 실행한 3건은 집계에서 빠지고 계측 대상 1건만 남아야 한다.'
        );
    }

    /**
     * 쓰기 축은 `--allow-write` 없이 실행을 거부합니다.
     */
    #[Test]
    public function 쓰기_축은_allow_write_없이_거부한다(): void
    {
        BenchmarkWriteSpy::reset();

        $result = app(WriteAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::Write, ['callback' => [BenchmarkWriteSpy::class, 'create']]),
            new BenchmarkRunOptions(runs: 1),
        );

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('--allow-write', (string) $result->skipReason);
        $this->assertSame(0, BenchmarkWriteSpy::$createCalls, '거부되면 콜백이 실행되지 않아야 한다.');
    }

    /**
     * `cleanup` 실패가 계측 결과를 삼키지 않습니다.
     *
     * @effects cleanup_failure_does_not_swallow_the_measurement
     */
    #[Test]
    public function cleanup_실패가_계측_결과를_삼키지_않는다(): void
    {
        BenchmarkWriteSpy::reset();
        BenchmarkWriteSpy::$cleanupThrows = true;

        $messages = [];

        $result = app(WriteAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::Write, [
                'callback' => [BenchmarkWriteSpy::class, 'create'],
                'cleanup' => [BenchmarkWriteSpy::class, 'cleanup'],
            ]),
            new BenchmarkRunOptions(runs: 1, allowWrite: true),
            function (string $message) use (&$messages) {
                $messages[] = $message;
            },
        );

        $this->assertFalse($result->skipped, '건너뜀 사유: '.(string) $result->skipReason);
        $this->assertStringContainsString('cleanup 실패', implode("\n", $messages));
    }

    /**
     * 배치 축은 소요 시간과 피크 메모리(실행 전/후)를 산출합니다.
     *
     * @effects batch_axis_reports_elapsed_time_and_peak_memory, peak_memory_before_and_after_are_both_reported
     */
    #[Test]
    public function 배치_축은_시간과_피크_메모리를_산출한다(): void
    {
        $result = app(BatchAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::Batch, ['command' => 'seo:clear']),
            new BenchmarkRunOptions(allowWrite: true),
        );

        $this->assertFalse($result->skipped, '건너뜀 사유: '.(string) $result->skipReason);
        $this->assertSame(['커맨드', '종료코드', '소요(ms)', '피크 메모리', '메모리 증가', '쿼리(건)'], $result->headers);
        $this->assertSame('seo:clear', $result->metrics['command']);
        // 피크는 프로세스 누적값이라 실행 전 값이 함께 있어야 이 배치의 기여를 판정할 수 있다
        $this->assertArrayHasKey('peak_memory_before_bytes', $result->metrics);
        $this->assertArrayHasKey('peak_memory_after_bytes', $result->metrics);
        $this->assertGreaterThanOrEqual(
            $result->metrics['peak_memory_before_bytes'],
            $result->metrics['peak_memory_after_bytes'],
            '피크 메모리는 감소하지 않는다.'
        );
    }

    /**
     * 실패 종료한 배치는 부분 측정임을 사유로 남깁니다.
     *
     * 실패를 감추면 "이 시간이 전체 처리 비용"으로 읽힙니다.
     *
     * @effects batch_axis_reports_nonzero_exit_code_as_partial_measurement
     */
    #[Test]
    public function 실패_종료_배치는_부분_측정임을_남긴다(): void
    {
        Artisan::command('bench:always-fails', fn () => 3)->describe('계측 테스트용 실패 커맨드');

        $result = app(BatchAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::Batch, ['command' => 'bench:always-fails']),
            new BenchmarkRunOptions(allowWrite: true),
        );

        $this->assertFalse($result->skipped, '건너뜀 사유: '.(string) $result->skipReason);
        $this->assertSame(3, $result->metrics['exit_code']);
        $this->assertStringContainsString('실패 종료', implode("\n", $result->notes));
    }

    /**
     * 등록되지 않은 커맨드는 사유와 함께 건너뛰어집니다.
     *
     * @effects unregistered_command_is_skipped_with_reason
     */
    #[Test]
    public function 없는_커맨드는_사유와_함께_건너뛴다(): void
    {
        $result = app(BatchAxisRunner::class)->run(
            $this->profile(BenchmarkAxis::Batch, ['command' => 'no:such-command']),
            new BenchmarkRunOptions(allowWrite: true),
        );

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('등록되지 않은 커맨드', (string) $result->skipReason);
    }

    /**
     * 쿼리 수집기는 계측 구간의 쿼리만 모으고 반복 SQL 을 N+1 후보로 보고합니다.
     *
     * @effects repeated_identical_sql_is_reported_as_n_plus_one_candidate
     */
    #[Test]
    public function 쿼리_수집기는_구간_밖_쿼리를_섞지_않는다(): void
    {
        $collector = app(QueryCollector::class);

        // 구간 밖 쿼리
        DB::table('users')->limit(1)->get();

        $collected = $collector->collect(function () {
            for ($i = 0; $i < 6; $i++) {
                DB::table('users')->where('id', $i)->limit(1)->get();
            }
        });

        // 구간 밖 쿼리를 제외한 6건만 모인다
        $this->assertCount(6, $collected['queries']);

        $summary = $collector->summarize($collected['queries']);
        $this->assertSame(6, $summary['count']);
        // 같은 SQL 6회(임계 5회 이상) → N+1 후보로 보고
        $this->assertNotEmpty($summary['n_plus_one']);
        $this->assertSame(6, $summary['n_plus_one'][0]['count']);
    }

    /**
     * 반복이 임계 미만이면 N+1 후보로 보고하지 않습니다.
     */
    #[Test]
    public function 임계_미만_반복은_후보로_보고하지_않는다(): void
    {
        $collector = app(QueryCollector::class);

        $collected = $collector->collect(function () {
            for ($i = 0; $i < 3; $i++) {
                DB::table('users')->where('id', $i)->limit(1)->get();
            }
        });

        $this->assertSame([], $collector->summarize($collected['queries'])['n_plus_one']);
    }
}

/**
 * 쓰기 축 계측 대상 스파이 — prepare/callback/cleanup 호출 순서와 인자 전달을 관찰한다.
 */
class BenchmarkWriteSpy
{
    public static int $prepareCalls = 0;

    public static int $createCalls = 0;

    public static int $cleanupCalls = 0;

    /**
     * @var array<int, mixed>
     */
    public static array $receivedContexts = [];

    public static bool $cleanupThrows = false;

    public static int $prepareQueries = 0;

    public static int $createQueries = 0;

    /**
     * 관찰 상태를 초기화합니다.
     */
    public static function reset(): void
    {
        self::$prepareCalls = 0;
        self::$createCalls = 0;
        self::$cleanupCalls = 0;
        self::$receivedContexts = [];
        self::$cleanupThrows = false;
        self::$prepareQueries = 0;
        self::$createQueries = 0;
    }

    /**
     * 선행 준비 (계측 구간 밖).
     *
     * @param  int  $run  회차 번호
     * @return int 회차 번호를 그대로 컨텍스트로 넘긴다
     */
    public function prepare(int $run): int
    {
        self::$prepareCalls++;
        $this->runQueries(self::$prepareQueries);

        return $run;
    }

    /**
     * 계측 대상.
     *
     * @param  mixed  $context  prepare 반환값
     */
    public function create(mixed $context = null): void
    {
        self::$createCalls++;
        self::$receivedContexts[] = $context;
        $this->runQueries(self::$createQueries);
    }

    /**
     * 관찰용 쿼리를 지정 횟수만큼 실행합니다.
     *
     * @param  int  $count  실행할 쿼리 수
     */
    private function runQueries(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('users')->where('id', -($i + 1))->limit(1)->get();
        }
    }

    /**
     * 잔여물 정리.
     */
    public function cleanup(): void
    {
        self::$cleanupCalls++;

        if (self::$cleanupThrows) {
            throw new \RuntimeException('정리 실패 재현');
        }
    }
}
