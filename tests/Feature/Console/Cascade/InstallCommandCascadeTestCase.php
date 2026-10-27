<?php

namespace Tests\Feature\Console\Cascade;

use App\Extension\Vendor\VendorMode;
use App\Services\Extension\CascadeInstallOrchestrator;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * `module:install`·`plugin:install`·`template:install` 의 연쇄 설치 옵션 공통 검증.
 *
 * 연쇄 설치 서비스·설치 미리보기는 대역으로 바꾼다 — 옵션 해석과 서비스 호출 계약만 본다(실제 확장을 설치하지 않는다).
 * 대역이 실패를 돌려주는 경로만 골라 본 확장 설치 단계에 들어가지 않게 한다.
 */
abstract class InstallCommandCascadeTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * 명령 이름.
     *
     * @return string 명령 이름 (module:install 등)
     */
    abstract protected function command(): string;

    /**
     * 확장 종류.
     *
     * @return string 확장 종류 (module|plugin|template)
     */
    abstract protected function type(): string;

    /**
     * 정리.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 계획 한 건(미설치 의존 모듈)을 돌려주는 연쇄 설치 서비스 대역.
     *
     * @return MockInterface 대역
     */
    protected function orchestratorWithOneStep(): MockInterface
    {
        $mock = Mockery::mock(CascadeInstallOrchestrator::class);
        $mock->shouldReceive('resolveDependencyPlan')->with($this->type(), 'cascade-target')->andReturn([
            'steps' => [['type' => 'module', 'identifier' => 'cascade-dep', 'action' => 'install_activate', 'required_version' => '>=1.3.0', 'installed_version' => null, 'available_version' => '1.3.0', 'required_by' => 'cascade-target']],
            'errors' => [],
        ]);
        $this->app->instance(CascadeInstallOrchestrator::class, $mock);

        return $mock;
    }

    /**
     * 옵션 없이 의존이 미충족이면 무엇이 모자란지와 --with-dependencies 안내로 실패한다.
     *
     * @effects install_command_without_option_explains_unmet_dependency
     *
     * @return void 반환값 없음
     */
    public function test_without_option_explains_unmet_dependency(): void
    {
        $orchestrator = $this->orchestratorWithOneStep();
        $orchestrator->shouldNotReceive('installDependencies');

        $this->artisan($this->command(), ['identifier' => 'cascade-target'])
            ->expectsOutputToContain('cascade-dep')
            ->expectsOutputToContain('--with-dependencies')
            ->assertExitCode(1);
    }

    /**
     * --with-dependencies 면 계획을 설치하고, 의존 확장 실패 사유를 그대로 보이며 멈춘다.
     *
     * @effects install_command_with_dependencies_runs_plan_and_surfaces_failure
     *
     * @return void 반환값 없음
     */
    public function test_with_dependencies_runs_plan_and_surfaces_failure(): void
    {
        $orchestrator = $this->orchestratorWithOneStep();
        $expectedMode = $this->type() === 'template' ? VendorMode::Auto : VendorMode::Bundled;
        $orchestrator->shouldReceive('installDependencies')->once()
            ->with(Mockery::on(fn (array $steps) => array_column($steps, 'identifier') === ['cascade-dep']), $expectedMode)
            ->andThrow(new RuntimeException('cascade-dep 활성화 실패: 실제 사유'));

        $args = ['identifier' => 'cascade-target', '--with-dependencies' => true];
        if ($this->type() !== 'template') {
            $args['--vendor-mode'] = 'bundled';
        }

        $this->artisan($this->command(), $args)
            ->expectsOutputToContain('cascade-dep 활성화 실패: 실제 사유')
            ->assertExitCode(1);
    }

    /**
     * 의존 확장은 설치·활성화됐는데 본 확장 설치가 실패하면, 되돌리지 않고 남겨 둔 의존 확장을 알린다(D14).
     * 본 확장(`cascade-target`)은 실제로 없으므로 설치 단계에서 실패한다.
     *
     * @effects install_command_failure_lists_kept_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_main_install_failure_lists_kept_dependencies(): void
    {
        $orchestrator = $this->orchestratorWithOneStep();
        $orchestrator->shouldReceive('installDependencies')->once()
            ->andReturn(['installed' => ['cascade-dep'], 'activated' => ['cascade-dep'], 'skipped' => []]);

        $this->artisan($this->command(), ['identifier' => 'cascade-target', '--with-dependencies' => true])
            ->expectsOutputToContain(__('extensions.errors.cascade_dependencies_kept', ['list' => 'cascade-dep']))
            ->assertExitCode(1);
    }

    /**
     * --with-language-packs 에 후보가 아닌 식별자를 주면 설치 전에 거부한다.
     *
     * @effects install_command_rejects_non_candidate_language_pack
     *
     * @return void 반환값 없음
     */
    public function test_rejects_language_pack_that_is_not_a_candidate(): void
    {
        $this->orchestratorWithOneStep()->shouldNotReceive('installDependencies');
        $preview = Mockery::mock(ExtensionInstallPreviewBuilder::class);
        $preview->shouldReceive('build')->andReturn(['language_packs' => [['bundled_identifier' => 'g7-x-ja']]]);
        $this->app->instance(ExtensionInstallPreviewBuilder::class, $preview);

        $this->artisan($this->command(), ['identifier' => 'cascade-target', '--with-language-packs' => 'g7-x-ja,bogus-pack'])
            ->expectsOutputToContain('bogus-pack')
            ->assertExitCode(1);
    }

    /**
     * --with-language-packs 값별 설치 목록 — none(기본)·빈 값은 없음, all 은 설치 창 후보 전부, 쉼표 목록은 그 식별자.
     *
     * 본 확장 설치가 성공해야 언어팩 단계에 닿으므로 값 해석만 명령 인스턴스에서 직접 잰다.
     *
     * @effects install_command_language_pack_option_values
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_option_values(): void
    {
        $preview = Mockery::mock(ExtensionInstallPreviewBuilder::class);
        $preview->shouldReceive('build')
            ->with(Mockery::on(fn ($scope) => $scope->value === $this->type()), 'cascade-target')
            ->andReturn(['language_packs' => [['bundled_identifier' => 'g7-x-ja'], ['bundled_identifier' => 'g7-x-zh']]]);
        $this->app->instance(ExtensionInstallPreviewBuilder::class, $preview);

        $command = Artisan::all()[$this->command()];
        $resolve = new \ReflectionMethod($command, 'resolveCascadeLanguagePacks');
        $resolved = function (?string $value) use ($command, $resolve): ?array {
            $input = ['identifier' => 'cascade-target'];
            if ($value !== null) {
                $input['--with-language-packs'] = $value;
            }
            $command->setInput($arguments = new ArrayInput($input, $command->getDefinition()));
            $command->setOutput(new OutputStyle($arguments, new BufferedOutput));

            return $resolve->invoke($command, $this->type(), 'cascade-target');
        };

        $this->assertSame([], $resolved(null), '옵션 없음 = none');
        $this->assertSame([], $resolved('none'));
        $this->assertSame([], $resolved(''));
        $this->assertSame(['g7-x-ja', 'g7-x-zh'], $resolved('all'), 'all = 설치 창 후보 전부');
        $this->assertSame(['g7-x-zh'], $resolved(' g7-x-zh ,'), '쉼표 목록은 공백·빈 항목을 뺀 그 식별자');
    }
}
