<?php

namespace Tests\Feature\Console;

use App\Benchmark\BenchmarkProfileRegistry;
use App\Benchmark\DTO\BenchmarkProfile;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Enums\BenchmarkAxis;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `g7:bench` 커맨드 및 프로파일 레지스트리 Feature 테스트.
 *
 * 계측 결과는 시간 값이라 실행마다 달라지므로 **수치는 단언하지 않는다**. 단언 대상은
 * 산출 구조(표 헤더 / JSON 키), 프로파일 수집, 축별 실행 분기, 안전장치 동작이다.
 *
 * 프로파일은 코어 `config/benchmark.php` 와 확장 `getBenchmarkProfiles()` 두 지점에서
 * 수집되므로, 테스트는 config 를 런타임 치환해 "커맨드가 선언을 읽는다"는 성질 자체를
 * 검증한다 — 특정 확장 설치 여부에 의존하면 설치본마다 결과가 갈린다.
 */
class BenchCommandTest extends TestCase
{
    /**
     * 각 테스트가 자기 프로파일만 보도록 코어 선언을 치환하고 레지스트리 캐시를 비운다.
     *
     * 활성 확장의 선언도 함께 차단한다 — 차단하지 않으면 설치된 번들 확장 구성에 따라
     * 정확 집합 단언이 갈리고, 테스트가 검증하려는 성질(커맨드가 선언을 읽는다)과 무관한
     * 이유로 실패한다. 확장 선언 수집 자체는 가짜 확장을 주입하는 테스트가 담당한다.
     *
     * @param  array<string, array<string, mixed>>  $profiles  치환할 코어 프로파일 선언
     */
    private function withCoreProfiles(array $profiles): void
    {
        Config::set('benchmark.profiles', $profiles);

        // 레지스트리는 수집 결과를 인스턴스에 캐시하므로 새 인스턴스를 강제한다
        $this->bindExtensionManagers([], []);
    }

    /**
     * 코어 config 선언이 프로파일 목록에 노출됩니다.
     *
     * @effects core_config_profiles_are_collected
     */
    #[Test]
    public function 코어_config_선언이_프로파일_목록에_노출된다(): void
    {
        $this->withCoreProfiles([
            'bench_users' => [
                'type' => 'list',
                'label' => '테스트 회원 목록',
                'table' => 'users',
                'columns' => ['id'],
                'order' => [['id', 'desc']],
            ],
        ]);

        $this->artisan('g7:bench', ['--list-profiles' => true])
            ->expectsOutputToContain('core/bench_users')
            ->assertExitCode(0);
    }

    /**
     * 확장이 선언한 프로파일도 같은 목록에 함께 수집됩니다.
     *
     * 확장 선언 지점(`getBenchmarkProfiles()`)이 실제로 수집 경로에 연결돼 있는지를
     * 확인합니다. 특정 번들 확장에 의존하지 않도록 가짜 모듈을 매니저에 주입합니다.
     *
     * @effects module_declared_profiles_are_collected, profile_source_kind_and_identifier_are_recorded
     */
    #[Test]
    public function 확장_선언_프로파일이_수집된다(): void
    {
        $this->withCoreProfiles([]);
        $this->fakeModuleWithProfiles([
            'fake_list' => [
                'type' => 'list',
                'label' => '가짜 확장 목록',
                'table' => 'users',
                'columns' => ['id'],
            ],
        ]);

        $profiles = app(BenchmarkProfileRegistry::class)->all();

        $this->assertArrayHasKey('vendor-fake/fake_list', $profiles);
        $this->assertSame('module', $profiles['vendor-fake/fake_list']->sourceKind);
        $this->assertSame(BenchmarkAxis::ListQuery, $profiles['vendor-fake/fake_list']->axis);
    }

    /**
     * 플러그인이 선언한 프로파일도 함께 수집됩니다.
     *
     * @effects plugin_declared_profiles_are_collected
     */
    #[Test]
    public function 플러그인_선언_프로파일이_수집된다(): void
    {
        $this->withCoreProfiles([]);
        $this->fakeExtensionsWithProfiles([], [
            'fake_plugin_list' => ['type' => 'list', 'table' => 'users', 'columns' => ['id']],
        ]);

        $profiles = app(BenchmarkProfileRegistry::class)->all();

        $this->assertArrayHasKey('vendor-fake-plugin/fake_plugin_list', $profiles);
        $this->assertSame('plugin', $profiles['vendor-fake-plugin/fake_plugin_list']->sourceKind);
    }

    /**
     * 확장 하나의 선언 실패가 전체 목록을 날리지 않습니다.
     *
     * 확장 하나가 던진 예외로 목록이 비면 계측 자체를 못 하게 되므로, 실패한 확장만 사유와
     * 함께 제외하고 나머지는 살립니다.
     *
     * @effects extension_declaration_failure_is_isolated_to_that_extension
     */
    #[Test]
    public function 확장_선언_실패는_그_확장에만_국한된다(): void
    {
        $this->withCoreProfiles([
            'survivor' => ['type' => 'list', 'table' => 'users', 'columns' => ['id']],
        ]);

        $broken = new class
        {
            /**
             * @return string 모듈 식별자
             */
            public function getIdentifier(): string
            {
                return 'vendor-broken';
            }

            /**
             * @return array<string, array<string, mixed>> 선언 (항상 실패)
             */
            public function getBenchmarkProfiles(): array
            {
                throw new \RuntimeException('declaration blew up');
            }
        };

        $this->bindExtensionManagers([$broken], []);

        $registry = app(BenchmarkProfileRegistry::class);

        // 코어 선언은 살아남는다
        $this->assertArrayHasKey('core/survivor', $registry->all());
        // 실패한 확장은 사유와 함께 드러난다
        $this->assertStringContainsString('vendor-broken', implode("\n", $registry->warnings()));
    }

    /**
     * 잘못된 선언은 조용히 버려지지 않고 사유와 함께 경고로 드러납니다.
     *
     * @effects missing_type_declaration_is_rejected_with_reason, unknown_axis_type_is_rejected_with_allowed_values, missing_required_option_is_rejected_naming_the_option, rejected_declarations_appear_in_warnings_not_silently_dropped
     */
    #[Test]
    public function 잘못된_선언은_사유와_함께_경고로_드러난다(): void
    {
        $this->withCoreProfiles([
            'no_type' => ['table' => 'users'],
            'unknown_axis' => ['type' => 'nonsense', 'table' => 'users'],
            'missing_required' => ['type' => 'screen'],
            'valid' => ['type' => 'list', 'table' => 'users', 'columns' => ['id']],
        ]);

        $registry = app(BenchmarkProfileRegistry::class);

        // 정상 선언만 남는다
        $this->assertSame(['core/valid'], array_keys($registry->all()));

        $warnings = implode("\n", $registry->warnings());
        $this->assertStringContainsString('core/no_type', $warnings);
        $this->assertStringContainsString('core/unknown_axis', $warnings);
        $this->assertStringContainsString('core/missing_required', $warnings);
        // screen 축은 route 또는 uri 중 하나가 필요하다는 사유가 드러나야 한다
        $this->assertStringContainsString('route|uri', $warnings);
    }

    /**
     * 짧은 키가 둘 이상의 출처에서 선언되면 후보를 제시하고 실행을 거부합니다.
     *
     * 임의로 하나를 고르면 어느 확장의 대상을 잰 것인지 알 수 없게 됩니다.
     *
     * @effects ambiguous_short_key_lists_candidates_and_refuses, qualified_key_resolves_exactly, unique_short_key_resolves
     */
    #[Test]
    public function 모호한_짧은_키는_후보를_제시하고_거부한다(): void
    {
        $this->withCoreProfiles([
            'orders' => ['type' => 'list', 'table' => 'users', 'columns' => ['id']],
        ]);
        $this->fakeModuleWithProfiles([
            'orders' => ['type' => 'list', 'table' => 'users', 'columns' => ['id']],
        ]);

        $this->artisan('g7:bench', ['--profile' => ['orders']])
            ->expectsOutputToContain('모호')
            ->assertExitCode(1);

        // 정규화 키로 지목하면 정상 해석된다
        $registry = app(BenchmarkProfileRegistry::class);
        $profile = $registry->find('vendor-fake/orders');
        $this->assertInstanceOf(BenchmarkProfile::class, $profile);
        $this->assertSame('vendor-fake', $profile->sourceIdentifier);

        // 겹치지 않는 짧은 키는 그대로 해석된다 (모호할 때만 거부)
        $this->withCoreProfiles([
            'only_here' => ['type' => 'list', 'table' => 'users', 'columns' => ['id']],
        ]);

        [$unique, $error] = app(BenchmarkProfileRegistry::class)->resolve('only_here');
        $this->assertNull($error);
        $this->assertSame('core/only_here', $unique->qualifiedKey());
    }

    /**
     * 미등록 프로파일 키는 실패로 끝납니다.
     *
     * @effects unknown_key_fails_with_list_profiles_hint
     */
    #[Test]
    public function 미등록_프로파일_키는_실패한다(): void
    {
        $this->withCoreProfiles([]);

        $this->artisan('g7:bench', ['--profile' => ['nope']])
            ->expectsOutputToContain('등록되지 않은 프로파일')
            ->assertExitCode(1);
    }

    /**
     * 대상 지정이 없으면 무엇을 잴지 알 수 없으므로 실패합니다.
     *
     * @effects no_selection_fails_asking_for_profile_or_axis_or_all
     */
    #[Test]
    public function 대상_미지정_실행은_실패한다(): void
    {
        $this->withCoreProfiles([
            'bench_users' => ['type' => 'list', 'table' => 'users', 'columns' => ['id']],
        ]);

        $this->artisan('g7:bench')
            ->expectsOutputToContain('--profile / --axis / --all')
            ->assertExitCode(1);
    }

    /**
     * 목록 축은 컬럼 폭 3축 표를 산출합니다. (수치는 단언하지 않음)
     *
     * @effects list_axis_reports_all_list_and_id_only_columns
     */
    #[Test]
    public function 목록_축은_컬럼_폭_3축_표를_산출한다(): void
    {
        $this->withCoreProfiles([
            'bench_users' => [
                'type' => 'list',
                'table' => 'users',
                'columns' => ['id', 'email'],
                'order' => [['id', 'desc']],
                'soft_delete' => true,
            ],
        ]);

        $exitCode = Artisan::call('g7:bench', [
            '--profile' => ['core/bench_users'],
            '--offsets' => '0',
            '--runs' => 1,
        ]);

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();

        // 세 축이 함께 나와야 배수(전체÷ID)의 근거가 성립한다
        $this->assertStringContainsString('전체 컬럼(ms)', $output);
        $this->assertStringContainsString('목록 컬럼(ms)', $output);
        $this->assertStringContainsString('ID만(ms)', $output);
        $this->assertStringContainsString('전체÷ID', $output);
    }

    /**
     * 없는 테이블을 가리키는 목록 프로파일은 사유와 함께 건너뛰어집니다.
     *
     * 확장이 제거된 설치본에서 계측이 죽지 않아야 하고, 동시에 "측정했다"로 읽혀서도
     * 안 되므로 사유가 남고 종료 코드는 실패입니다.
     *
     * @effects skipped_profiles_remain_in_output_with_reason
     */
    #[Test]
    public function 없는_테이블_프로파일은_사유와_함께_건너뛴다(): void
    {
        $this->withCoreProfiles([
            'ghost' => ['type' => 'list', 'table' => 'table_that_does_not_exist', 'columns' => ['id']],
        ]);

        $this->artisan('g7:bench', ['--profile' => ['core/ghost'], '--offsets' => '0', '--runs' => 1])
            ->expectsOutputToContain('테이블이 없습니다')
            ->assertExitCode(1);
    }

    /**
     * 쓰기 축은 `--allow-write` 없이 실행을 거부합니다.
     *
     * @effects mutating_axes_refuse_without_allow_write
     */
    #[Test]
    public function 쓰기_축은_allow_write_없이_거부된다(): void
    {
        $this->withCoreProfiles([
            'bench_write' => [
                'type' => 'write',
                'callback' => [BenchCommandWriteSubject::class, 'run'],
            ],
        ]);

        $this->artisan('g7:bench', ['--profile' => ['core/bench_write']])
            ->expectsOutputToContain('--allow-write')
            ->assertExitCode(1);
    }

    /**
     * 데이터를 변경하는 배치도 `--allow-write` 없이 거부됩니다.
     *
     * @effects mutating_axes_refuse_without_allow_write
     */
    #[Test]
    public function 변경하는_배치는_allow_write_없이_거부된다(): void
    {
        $this->withCoreProfiles([
            'bench_batch' => ['type' => 'batch', 'command' => 'seo:clear'],
        ]);

        $this->artisan('g7:bench', ['--profile' => ['core/bench_batch']])
            ->expectsOutputToContain('--allow-write')
            ->assertExitCode(1);
    }

    /**
     * 쓰기 축 콜백에 클로저를 쓸 수 없다는 사유가 드러납니다.
     *
     * 코어 선언은 `config:cache` 대상이라 클로저를 담을 수 없으므로 형식을 통일합니다.
     *
     * @effects closure_callback_is_rejected_with_config_cache_reason
     */
    #[Test]
    public function 쓰기_축_클로저_콜백은_사유와_함께_거부된다(): void
    {
        $this->withCoreProfiles([
            'bench_closure' => [
                'type' => 'write',
                'callback' => fn () => null,
            ],
        ]);

        $this->artisan('g7:bench', [
            '--profile' => ['core/bench_closure'],
            '--allow-write' => true,
        ])
            ->expectsOutputToContain('클로저는 쓸 수 없습니다')
            ->assertExitCode(1);
    }

    /**
     * 알 수 없는 축 이름은 허용 목록과 함께 거부됩니다.
     */
    #[Test]
    public function 알_수_없는_축_이름은_거부된다(): void
    {
        $this->withCoreProfiles([]);

        $this->artisan('g7:bench', ['--axis' => 'nonsense'])
            ->expectsOutputToContain('알 수 없는 축')
            ->assertExitCode(1);
    }

    /**
     * `--json` 출력이 약속된 최상위 키와 결과 키를 담습니다.
     *
     * @effects json_output_carries_environment_options_warnings_results
     */
    #[Test]
    public function json_출력이_약속된_스키마를_담는다(): void
    {
        $this->withCoreProfiles([
            'bench_users' => [
                'type' => 'list',
                'label' => '테스트 회원 목록',
                'table' => 'users',
                'columns' => ['id'],
                'order' => [['id', 'desc']],
            ],
        ]);

        $exitCode = Artisan::call('g7:bench', [
            '--profile' => ['core/bench_users'],
            '--offsets' => '0',
            '--runs' => 1,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload, 'JSON 출력이 파싱 가능해야 한다.');
        foreach (['generated_at', 'environment', 'options', 'warnings', 'results'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }

        $result = $payload['results'][0];
        foreach (['profile', 'axis', 'label', 'source', 'skipped', 'headers', 'rows', 'metrics', 'notes'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame('core/bench_users', $result['profile']);
        $this->assertSame('list', $result['axis']);
        $this->assertFalse($result['skipped']);
    }

    /**
     * `--report` 는 환경 정보와 축 절을 담은 마크다운을 지정 경로에 씁니다.
     *
     * @effects report_includes_environment_and_run_conditions, report_writes_to_given_path
     */
    #[Test]
    public function report_는_환경_정보를_담은_마크다운을_쓴다(): void
    {
        $this->withCoreProfiles([
            'bench_users' => [
                'type' => 'list',
                'label' => '테스트 회원 목록',
                'table' => 'users',
                'columns' => ['id'],
                'order' => [['id', 'desc']],
            ],
        ]);

        $path = storage_path('app/benchmarks/test-'.uniqid().'.md');

        $this->artisan('g7:bench', [
            '--profile' => ['core/bench_users'],
            '--offsets' => '0',
            '--runs' => 1,
            '--report' => $path,
        ])->assertExitCode(0);

        $this->assertFileExists($path);

        $markdown = (string) file_get_contents($path);

        // 환경 정보가 빠진 리포트는 다른 리포트와 비교할 수 없으므로 필수 항목이다
        $this->assertStringContainsString('## 실행 환경', $markdown);
        $this->assertStringContainsString('DB 버전', $markdown);
        $this->assertStringContainsString('config:cache', $markdown);
        $this->assertStringContainsString('## 실행 조건', $markdown);
        $this->assertStringContainsString('## 목록 조회 (list)', $markdown);
        $this->assertStringContainsString('core/bench_users — 테스트 회원 목록', $markdown);

        @unlink($path);
    }

    /**
     * 운영 환경에서는 시딩/비움이 거부됩니다.
     *
     * @effects seeding_and_truncation_are_refused_in_production
     */
    #[Test]
    public function 운영_환경에서는_시딩이_거부된다(): void
    {
        $this->withCoreProfiles([
            'bench_users' => ['type' => 'list', 'table' => 'users', 'columns' => ['id']],
        ]);

        // 계측 대상 테이블을 실제로 건드리기 전에 거부되는지를 본다
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('g7:bench', [
                '--profile' => ['core/bench_users'],
                '--offsets' => '0',
                '--runs' => 1,
                '--seed' => 10,
            ])
                ->expectsOutputToContain('운영 환경에서는 시딩/비움을 사용할 수 없습니다')
                ->assertExitCode(1);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    /**
     * `--report` 를 값 없이 주면 저장소 미추적 기본 경로에 리포트를 씁니다.
     *
     * @effects report_defaults_to_untracked_storage_path
     */
    #[Test]
    public function report_는_값_없이_주면_기본_경로에_쓴다(): void
    {
        $this->withCoreProfiles([
            'bench_users' => ['type' => 'list', 'table' => 'users', 'columns' => ['id']],
        ]);

        $directory = storage_path('app/benchmarks');
        $before = is_dir($directory) ? glob($directory.'/bench-*.md') : [];

        $exitCode = Artisan::call('g7:bench', [
            '--profile' => ['core/bench_users'],
            '--offsets' => '0',
            '--runs' => 1,
            '--report' => null,
        ]);

        $this->assertSame(0, $exitCode);

        $after = glob($directory.'/bench-*.md');
        $created = array_values(array_diff($after ?: [], $before ?: []));

        $this->assertCount(1, $created, '기본 경로(storage/app/benchmarks)에 리포트가 생성되어야 한다.');
        $this->assertStringContainsString('## 실행 환경', (string) file_get_contents($created[0]));

        @unlink($created[0]);
    }

    /**
     * `--database` 는 계측에 사용할 연결을 다시 지목합니다.
     *
     * config 가 캐시된 환경에서 연결을 바꿀 유일한 수단이라, 이 치환이 동작하지 않으면
     * 대량 시딩이 개발 DB 로 들어갑니다.
     *
     * @effects database_override_option_repoints_the_connection
     */
    #[Test]
    public function database_옵션이_연결을_다시_지목한다(): void
    {
        $this->withCoreProfiles([
            'bench_users' => ['type' => 'list', 'table' => 'users', 'columns' => ['id']],
        ]);

        $connection = (string) config('database.default');
        // 실제 접속이 끊기지 않도록 현재 스키마명으로 재지목한다 (치환 경로 자체를 검증)
        $current = (string) (config("database.connections.{$connection}.database")
            ?: config("database.connections.{$connection}.write.database"));

        Artisan::call('g7:bench', [
            '--profile' => ['core/bench_users'],
            '--offsets' => '0',
            '--runs' => 1,
            '--database' => $current,
        ]);

        // 최상위와 읽기/쓰기 하위 모두 지목되어야 한다 — 하나라도 남으면 그 경로만 다른 DB 를 본다
        $this->assertSame($current, config("database.connections.{$connection}.database"));
        $this->assertSame($current, config("database.connections.{$connection}.write.database"));
        $this->assertSame($current, config("database.connections.{$connection}.read.database"));
    }

    /**
     * 전부 건너뛴 실행은 실패로 끝납니다. (성공으로 보고하면 "측정했다"로 읽힘)
     *
     * @effects all_skipped_run_exits_nonzero, skipped_profiles_remain_in_output_with_reason
     */
    #[Test]
    public function 전부_건너뛴_실행은_실패로_끝난다(): void
    {
        $this->withCoreProfiles([
            'ghost_a' => ['type' => 'list', 'table' => 'missing_table_a', 'columns' => ['id']],
            'ghost_b' => ['type' => 'list', 'table' => 'missing_table_b', 'columns' => ['id']],
        ]);

        $exitCode = Artisan::call('g7:bench', ['--axis' => 'list', '--offsets' => '0', '--runs' => 1]);

        $this->assertSame(1, $exitCode);

        $output = Artisan::output();

        // 건너뛴 프로파일이 목록에서 빠지지 않고 사유와 함께 남는다
        $this->assertStringContainsString('core/ghost_a', $output);
        $this->assertStringContainsString('core/ghost_b', $output);
        $this->assertStringContainsString('테이블이 없습니다', $output);
    }

    /**
     * 축 필터가 해당 축의 프로파일만 고릅니다.
     *
     * @effects axis_filter_selects_only_that_axis
     */
    #[Test]
    public function 축_필터는_해당_축만_고른다(): void
    {
        $this->withCoreProfiles([
            'bench_users' => ['type' => 'list', 'table' => 'users', 'columns' => ['id']],
            'bench_screen' => ['type' => 'screen', 'route' => 'api.admin.users.index'],
            'bench_batch' => ['type' => 'batch', 'command' => 'seo:clear'],
        ]);

        $registry = app(BenchmarkProfileRegistry::class);

        $this->assertSame(['core/bench_users'], array_keys($registry->byAxis(BenchmarkAxis::ListQuery)));
        $this->assertSame(['core/bench_screen'], array_keys($registry->byAxis(BenchmarkAxis::Screen)));
        $this->assertSame(['core/bench_batch'], array_keys($registry->byAxis(BenchmarkAxis::Batch)));
        $this->assertSame([], array_keys($registry->byAxis(BenchmarkAxis::Write)));
    }

    /**
     * GET 화면은 변경으로 보지 않고, 비-GET 화면은 변경으로 봅니다.
     */
    #[Test]
    public function screen_축의_변경_판정은_http_메서드를_따른다(): void
    {
        $this->withCoreProfiles([
            'get_screen' => ['type' => 'screen', 'route' => 'api.admin.users.index'],
            'post_screen' => ['type' => 'screen', 'route' => 'api.admin.users.store', 'method' => 'POST'],
            'declared_readonly' => ['type' => 'screen', 'route' => 'api.admin.users.store', 'method' => 'POST', 'mutating' => false],
        ]);

        $registry = app(BenchmarkProfileRegistry::class);

        $this->assertFalse($registry->find('core/get_screen')->mutates());
        $this->assertTrue($registry->find('core/post_screen')->mutates());
        // 선언이 축 기본값을 덮어쓴다
        $this->assertFalse($registry->find('core/declared_readonly')->mutates());
    }

    /**
     * 프로파일을 선언한 가짜 모듈을 모듈 매니저에 주입합니다.
     *
     * 특정 번들 확장 설치 여부에 의존하지 않고 "확장 선언이 수집된다"는 성질만 검증합니다.
     *
     * @param  array<string, array<string, mixed>>  $profiles  가짜 모듈이 선언할 프로파일
     */
    private function fakeModuleWithProfiles(array $profiles): void
    {
        $this->fakeExtensionsWithProfiles($profiles, []);
    }

    /**
     * 프로파일을 선언한 가짜 모듈/플러그인을 각 매니저에 주입합니다.
     *
     * @param  array<string, array<string, mixed>>  $modileProfiles  가짜 모듈이 선언할 프로파일
     * @param  array<string, array<string, mixed>>  $pluginProfiles  가짜 플러그인이 선언할 프로파일
     */
    private function fakeExtensionsWithProfiles(array $modileProfiles, array $pluginProfiles): void
    {
        $this->bindExtensionManagers(
            $modileProfiles === [] ? [] : [$this->fakeExtension('vendor-fake', $modileProfiles)],
            $pluginProfiles === [] ? [] : [$this->fakeExtension('vendor-fake-plugin', $pluginProfiles)],
        );
    }

    /**
     * 프로파일만 선언하는 가짜 확장 인스턴스를 만듭니다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  array<string, array<string, mixed>>  $profiles  선언할 프로파일
     * @return object 가짜 확장
     */
    private function fakeExtension(string $identifier, array $profiles): object
    {
        return new class($identifier, $profiles)
        {
            /**
             * @param  string  $identifier  확장 식별자
             * @param  array<string, array<string, mixed>>  $profiles  선언할 프로파일
             */
            public function __construct(
                private readonly string $identifier,
                private readonly array $profiles,
            ) {}

            /**
             * @return string 확장 식별자
             */
            public function getIdentifier(): string
            {
                return $this->identifier;
            }

            /**
             * @return array<string, array<string, mixed>> 선언한 프로파일
             */
            public function getBenchmarkProfiles(): array
            {
                return $this->profiles;
            }
        };
    }

    /**
     * 확장 매니저를 가짜로 바인딩하고 레지스트리 캐시를 비웁니다.
     *
     * @param  array<int, object>  $modules  활성 모듈 목록
     * @param  array<int, object>  $plugins  활성 플러그인 목록
     */
    private function bindExtensionManagers(array $modules, array $plugins): void
    {
        $moduleManager = \Mockery::mock(ModuleManagerInterface::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn($modules);

        $pluginManager = \Mockery::mock(PluginManagerInterface::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn($plugins);

        $this->app->instance(ModuleManagerInterface::class, $moduleManager);
        $this->app->instance(PluginManagerInterface::class, $pluginManager);
        $this->app->forgetInstance(BenchmarkProfileRegistry::class);
    }
}

/**
 * 쓰기 축 콜백 형식 검증용 대상 (실행되지 않음 — 가드에서 먼저 막힘).
 */
class BenchCommandWriteSubject
{
    /**
     * 계측 대상 자리표시자.
     */
    public function run(mixed $context = null): void {}
}
