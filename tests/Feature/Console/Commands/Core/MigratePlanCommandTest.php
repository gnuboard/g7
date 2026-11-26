<?php

namespace Tests\Feature\Console\Commands\Core;

use App\Extension\AbstractUpgradeStep;
use App\Models\Module;
use App\Models\Plugin;
use App\Services\Upgrade\MigrationPlanService;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `g7:migrate-plan` — 실행 대기 마이그레이션의 SQL·파괴 여부·출처를 실행 없이 계산한다.
 *
 * 마이그레이션은 연결의 pretend 로 SQL 만 모은다. 컬럼 삭제·이름 변경·타입 변경·NOT NULL 전환은 스테이지 기간 미루기 권장.
 * core 범위는 마이그레이터에 등록된 모든 비확장 경로라 `database/migrations/custom` 같은 경로도 `source=custom` 으로 나온다.
 * 픽스처는 임시 폴더에만 만든다 — 실제 마이그레이션 폴더·DB 는 바뀌지 않는다.
 *
 * @scenario-manifest tests/scenarios/migrate-plan.yaml
 */
class MigratePlanCommandTest extends TestCase
{
    use DatabaseTransactions;

    /** 임시 픽스처 폴더 */
    private string $dir = '';

    /** @var array<int, string> 시험이 만든 확장 폴더 (tearDown 에서 지운다) */
    private array $extensionDirs = [];

    /**
     * 임시 픽스처 폴더를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/g7-migrate-plan-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
    }

    /**
     * 임시 픽스처 폴더를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        foreach ($this->extensionDirs as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    /**
     * 픽스처 마이그레이션을 씁니다.
     *
     * @param  string  $name  파일 이름 (확장자 제외)
     * @param  string  $up  up() 본문
     * @return void 반환값 없음
     */
    private function fixture(string $name, string $up): void
    {
        $this->fixtureAt($this->dir, $name, $up);
    }

    /**
     * 주어진 폴더에 픽스처 마이그레이션을 씁니다.
     *
     * @param  string  $dir  폴더
     * @param  string  $name  파일 이름 (확장자 제외)
     * @param  string  $up  up() 본문
     * @return void 반환값 없음
     */
    private function fixtureAt(string $dir, string $name, string $up): void
    {
        File::ensureDirectoryExists($dir);
        File::put($dir."/{$name}.php", "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration {\n    public function up(): void\n    {\n{$up}\n    }\n    public function down(): void {}\n};\n");
    }

    /**
     * 추가형·파괴형·분기형 픽스처를 분류하고 DB 는 바꾸지 않는다.
     *
     * @scenario case=classification
     *
     * @effects migrate_plan_classifies_destructive_sql, migrate_plan_changes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_classifies_additive_destructive_and_branching_without_running(): void
    {
        $this->fixture('2099_01_01_000001_create_acme_plan_probe', "        Schema::create('acme_plan_probe', function (Blueprint \$t) { \$t->id(); \$t->string('name')->comment('이름'); });");
        $this->fixture('2099_01_01_000002_drop_acme_plan_column', "        Schema::table('users', function (Blueprint \$t) { \$t->dropColumn('acme_plan_gone'); });");
        $this->fixture('2099_01_01_000003_branching_acme_plan', "        if (! Schema::hasTable('acme_plan_missing')) { Schema::create('acme_plan_branch', function (Blueprint \$t) { \$t->id(); }); }");

        $plan = app(MigrationPlanService::class)->plan('core', [$this->dir => 'core']);
        $byName = collect($plan['migrations'])->keyBy('name');

        $this->assertFalse($byName['2099_01_01_000001_create_acme_plan_probe']['destructive']);
        $this->assertStringContainsStringIgnoringCase('create table', implode(';', $byName['2099_01_01_000001_create_acme_plan_probe']['queries']));
        $this->assertTrue($byName['2099_01_01_000002_drop_acme_plan_column']['destructive']);
        $this->assertContains('drop_column', $byName['2099_01_01_000002_drop_acme_plan_column']['kinds']);
        $this->assertTrue($byName['2099_01_01_000002_drop_acme_plan_column']['defer_recommended']);
        $this->assertNotEmpty($byName['2099_01_01_000003_branching_acme_plan']['queries'], '분기형도 pretend 로 SQL 이 모인다');
        $this->assertNotSame('', $plan['warning'], '분기형 경고가 상단에 있다');

        $this->assertFalse(Schema::hasTable('acme_plan_probe'), '미리보기는 DB 를 바꾸지 않는다');
        $this->assertFalse(Schema::hasTable('acme_plan_branch'));
    }

    /**
     * 이름 변경·NOT NULL 전환도 미루기 권장으로 가른다.
     *
     * @scenario case=classification
     *
     * @effects migrate_plan_classifies_destructive_sql
     *
     * @return void 반환값 없음
     */
    public function test_rename_and_not_null_are_defer_recommended(): void
    {
        $service = app(MigrationPlanService::class);

        $this->assertContains('rename', $service->classify(['alter table `users` rename column `a` to `b`']));
        $this->assertContains('not_null', $service->classify(['alter table `users` modify `a` varchar(10) not null']));
        $this->assertSame([], $service->classify(['alter table `users` add `a` varchar(10) null']));
    }

    /**
     * 마이그레이터에 등록된 비확장 경로(custom 등)는 core 범위에 들어가고 출처가 custom 이다.
     *
     * @scenario case=custom_path
     *
     * @effects migrate_plan_includes_custom_migrations_with_source
     *
     * @return void 반환값 없음
     */
    public function test_registered_custom_path_is_included_with_custom_source(): void
    {
        $this->fixture('2099_01_01_000004_custom_acme_plan', "        Schema::create('acme_plan_custom', function (Blueprint \$t) { \$t->id(); });");
        app(Migrator::class)->path($this->dir);

        $service = app(MigrationPlanService::class);
        $this->assertSame('custom', $service->defaultPathSources('core')[$this->dir] ?? null);

        $plan = $service->plan('core', $service->defaultPathSources('core'));
        $custom = collect($plan['migrations'])->firstWhere('name', '2099_01_01_000004_custom_acme_plan');
        $this->assertSame('custom', $custom['source'] ?? null);
    }

    /**
     * 확장 경로 — 설치된 모듈·플러그인의 마이그레이션은 번들(새 판) 경로를 우선해 `module:{id}`·`plugin:{id}` 출처로 들어가고,
     * 번들이 없으면 활성 경로를 본다. 확장 경로는 마이그레이터에 등록돼 있어도 core 범위에 섞이지 않는다.
     *
     * @scenario case=extension_path
     *
     * @effects migrate_plan_includes_extension_migrations_with_source, migrate_plan_changes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_extension_paths_prefer_bundled_and_stay_out_of_core(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $moduleId = 'acme-planmod'.$suffix;
        $pluginId = 'acme-planplug'.$suffix;
        Module::factory()->create(['identifier' => $moduleId]);
        Plugin::factory()->create(['identifier' => $pluginId]);

        $moduleActive = base_path("modules/{$moduleId}/database/migrations");
        $moduleBundled = base_path("modules/_bundled/{$moduleId}/database/migrations");
        $pluginActive = base_path("plugins/{$pluginId}/database/migrations");
        $this->extensionDirs = [base_path("modules/{$moduleId}"), base_path("modules/_bundled/{$moduleId}"), base_path("plugins/{$pluginId}")];

        $this->fixtureAt($moduleActive, '2099_01_01_000005_acme_plan_module_old', "        Schema::create('acme_plan_module_old', function (Blueprint \$t) { \$t->id(); });");
        $this->fixtureAt($moduleBundled, '2099_01_01_000006_acme_plan_module_new', "        Schema::table('users', function (Blueprint \$t) { \$t->dropColumn('acme_plan_module_gone'); });");
        $this->fixtureAt($pluginActive, '2099_01_01_000007_acme_plan_plugin', "        Schema::create('acme_plan_plugin', function (Blueprint \$t) { \$t->id(); });");

        $service = app(MigrationPlanService::class);
        $sources = $service->defaultPathSources('extensions');
        $this->assertSame("module:{$moduleId}", $sources[$moduleBundled] ?? null, '번들(새 판) 경로가 있으면 그쪽을 본다');
        $this->assertArrayNotHasKey($moduleActive, $sources);
        $this->assertSame("plugin:{$pluginId}", $sources[$pluginActive] ?? null, '번들이 없으면 활성 경로를 본다');

        $plan = collect($service->plan('extensions', $sources)['migrations'])->keyBy('name');
        $this->assertSame("module:{$moduleId}", $plan['2099_01_01_000006_acme_plan_module_new']['source'] ?? null);
        $this->assertTrue($plan['2099_01_01_000006_acme_plan_module_new']['destructive']);
        $this->assertStringContainsString("modules/_bundled/{$moduleId}/", $plan['2099_01_01_000006_acme_plan_module_new']['file']);
        $this->assertSame("plugin:{$pluginId}", $plan['2099_01_01_000007_acme_plan_plugin']['source'] ?? null);
        $this->assertArrayNotHasKey('2099_01_01_000005_acme_plan_module_old', $plan->all(), '번들이 있으면 옛 활성 경로는 보지 않는다');

        // 마이그레이터에 등록된 확장 경로도 core 범위에는 들어가지 않는다
        app(Migrator::class)->path($moduleActive);
        $this->assertArrayNotHasKey($moduleActive, $service->defaultPathSources('core'));

        $this->assertFalse(Schema::hasTable('acme_plan_plugin'), '미리보기는 DB 를 바꾸지 않는다');
    }

    /**
     * custom 폴더가 없어도 명령은 0 으로 끝나고 JSON 을 낸다.
     *
     * @scenario case=no_custom_path
     *
     * @effects migrate_plan_includes_custom_migrations_with_source, migrate_plan_changes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_command_succeeds_without_custom_folder(): void
    {
        $code = Artisan::call('g7:migrate-plan', ['--scope' => 'core', '--json' => true]);
        $json = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $code);
        $this->assertIsArray($json['migrations'] ?? null);
        $this->assertArrayHasKey('upgrade_steps', $json);
    }

    /**
     * 스텝의 describe() 가 예외를 던지면 그 스텝만 「설명 없음」이 되고 계획은 계속 나온다(확장 스텝도 같은 경로).
     *
     * @scenario case=step_describe_error
     *
     * @effects migrate_plan_survives_step_describe_error
     *
     * @return void 반환값 없음
     */
    public function test_step_describe_exception_falls_back_to_no_description(): void
    {
        $step = new class extends AbstractUpgradeStep
        {
            /**
             * 설명을 만들다 실패하는 스텝.
             *
             * @return array{summary: string, destructive: bool}|null 설명
             */
            public function describe(): ?array
            {
                throw new \RuntimeException('describe failed');
            }
        };

        $method = new \ReflectionMethod(MigrationPlanService::class, 'describeStep');
        $described = $method->invoke(app(MigrationPlanService::class), $step);

        $this->assertSame(['summary' => null, 'destructive' => null], $described);
    }
}
