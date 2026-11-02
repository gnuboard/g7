<?php

namespace Tests\Feature\Console\Cascade;

use App\Extension\Vendor\VendorMode;
use App\Services\Extension\CascadeInstallOrchestrator;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use Illuminate\Console\Command;
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

    /**
     * 옵션 값 해석용 명령 인스턴스 — 입력·출력을 붙이고 출력 버퍼를 돌려준다.
     *
     * @param  string|null  $languagePacks  --with-language-packs 값 (null 이면 옵션 없음)
     * @return array{0: Command, 1: BufferedOutput} 명령과 출력 버퍼
     */
    private function preparedCommand(?string $languagePacks): array
    {
        $command = Artisan::all()[$this->command()];
        $input = ['identifier' => 'cascade-target'];
        if ($languagePacks !== null) {
            $input['--with-language-packs'] = $languagePacks;
        }
        $buffer = new BufferedOutput;
        $command->setInput($arguments = new ArrayInput($input, $command->getDefinition()));
        $command->setOutput(new OutputStyle($arguments, $buffer));

        return [$command, $buffer];
    }

    /**
     * all 은 설치할 수 없는 후보(코어 언어팩이 꺼진 로케일 — available=false)를 시도하지 않고 사유와 함께 건너뛴다.
     * 직접 지정한 식별자는 후보이기만 하면 그대로 시도한다(실패는 종료 코드로 알린다).
     *
     * @effects install_command_all_language_packs_skips_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_all_skips_language_packs_that_cannot_be_installed(): void
    {
        $preview = Mockery::mock(ExtensionInstallPreviewBuilder::class);
        $preview->shouldReceive('build')->andReturn(['language_packs' => [
            ['bundled_identifier' => 'g7-x-ja', 'locale' => 'ja', 'available' => false, 'blocked_reason' => 'core_locale_missing'],
            ['bundled_identifier' => 'g7-x-zh', 'locale' => 'zh', 'available' => true, 'blocked_reason' => null],
        ]]);
        $this->app->instance(ExtensionInstallPreviewBuilder::class, $preview);

        $resolve = new \ReflectionMethod(Artisan::all()[$this->command()], 'resolveCascadeLanguagePacks');

        [$command, $buffer] = $this->preparedCommand('all');
        $this->assertSame(['g7-x-zh'], $resolve->invoke($command, $this->type(), 'cascade-target'), 'all = 설치할 수 있는 후보만');
        $this->assertStringContainsString(
            __('extensions.commands.cascade.language_pack_skipped', [
                'identifier' => 'g7-x-ja',
                'reason' => __('language_packs.errors.core_locale_missing', ['locale' => 'ja']),
            ]),
            $buffer->fetch(),
            '건너뛴 언어팩과 사유를 알린다'
        );

        [$command] = $this->preparedCommand('g7-x-ja');
        $this->assertSame(['g7-x-ja'], $resolve->invoke($command, $this->type(), 'cascade-target'), '직접 지정은 그대로 시도');
    }

    /**
     * all 은 이미 설치된 대상 확장의 버전이 언어팩 요구보다 낮은 후보(target_version_too_old)도 건너뛰고,
     * 사유는 사유 코드가 아니라 범위·대상·요구 버전을 채운 문장으로 알린다.
     *
     * @effects install_command_all_language_packs_skips_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_all_skips_language_pack_whose_target_version_is_too_old(): void
    {
        $preview = Mockery::mock(ExtensionInstallPreviewBuilder::class);
        $preview->shouldReceive('build')->andReturn(['language_packs' => [
            [
                'bundled_identifier' => 'g7-module-acme-dep-ja', 'locale' => 'ja', 'scope' => 'module',
                'target_identifier' => 'acme-dep', 'required_target_version' => '>=2.0.0',
                'available' => false, 'blocked_reason' => 'target_version_too_old',
            ],
            ['bundled_identifier' => 'g7-x-zh', 'locale' => 'zh', 'available' => true, 'blocked_reason' => null],
        ]]);
        $this->app->instance(ExtensionInstallPreviewBuilder::class, $preview);

        $resolve = new \ReflectionMethod(Artisan::all()[$this->command()], 'resolveCascadeLanguagePacks');

        [$command, $buffer] = $this->preparedCommand('all');
        $this->assertSame(['g7-x-zh'], $resolve->invoke($command, $this->type(), 'cascade-target'), 'all = 설치할 수 있는 후보만');

        $output = $buffer->fetch();
        $this->assertStringContainsString(
            __('extensions.commands.cascade.language_pack_skipped', [
                'identifier' => 'g7-module-acme-dep-ja',
                'reason' => __('language_packs.errors.target_version_too_old', [
                    'scope' => 'module', 'target' => 'acme-dep', 'constraint' => '>=2.0.0',
                ]),
            ]),
            $output,
            '건너뛴 언어팩과 사유 문장(범위·대상·요구 버전)을 알린다'
        );
        $this->assertStringNotContainsString(':scope', $output, '사유 문장의 자리표시자가 채워져야 한다');
        $this->assertStringNotContainsString(':constraint', $output, '사유 문장의 자리표시자가 채워져야 한다');
    }

    /**
     * 언어팩 설치가 하나라도 실패하면 단계 결과가 실패다 — 명령은 이것으로 실패 종료 코드를 낸다(본 확장 설치는 유지).
     *
     * @effects install_command_language_pack_failure_fails_exit_code
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_failure_is_reported_as_failure(): void
    {
        $orchestrator = Mockery::mock(CascadeInstallOrchestrator::class);
        $orchestrator->shouldReceive('installLanguagePacks')->once()
            ->with(['g7-x-ja', 'g7-x-zh'], null, Mockery::any())
            ->andReturn([['identifier' => 'g7-x-ja', 'reason' => '코어 언어팩(ja)이 먼저 활성화되어야 합니다.']]);
        $this->app->instance(CascadeInstallOrchestrator::class, $orchestrator);

        [$command, $buffer] = $this->preparedCommand('g7-x-ja,g7-x-zh');
        (new \ReflectionProperty($command, 'cascadeLanguagePackCandidates'))->setValue($command, ['g7-x-ja', 'g7-x-zh']);
        $run = new \ReflectionMethod($command, 'runCascadeLanguagePacks');

        $this->assertFalse($run->invoke($command), '언어팩 설치 실패는 실패로 알린다');
        $this->assertStringContainsString('코어 언어팩(ja)이 먼저 활성화되어야 합니다.', $buffer->fetch());

        $orchestrator->shouldReceive('installLanguagePacks')->once()->andReturn([]);
        $this->assertTrue($run->invoke($command), '모두 설치되면 성공');

        (new \ReflectionProperty($command, 'cascadeLanguagePackCandidates'))->setValue($command, []);
        $this->assertTrue($run->invoke($command), '고른 언어팩이 없으면 성공');
    }

    /**
     * 명령은 언어팩 단계 결과를 종료 코드에 싣는다 — 성공 경로가 단계 결과를 버리고 늘 SUCCESS 를 돌려주지 않는다.
     *
     * 본 확장 설치 성공까지 실제로 가려면 번들 확장을 설치해야 해서, 성공 경로의 반환 식을 소스로 고정한다.
     *
     * @effects install_command_language_pack_failure_fails_exit_code
     *
     * @return void 반환값 없음
     */
    public function test_command_returns_language_pack_step_result_as_exit_code(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(Artisan::all()[$this->command()]))->getFileName());

        $this->assertSame(1, preg_match('/\$languagePacksInstalled\s*=\s*\$this->runCascadeLanguagePacks\(\);/', $source), '언어팩 단계 결과를 받아야 한다');
        $this->assertSame(1, preg_match('/return\s+\$languagePacksInstalled\s*\?\s*Command::SUCCESS\s*:\s*Command::FAILURE;/', $source), '언어팩 단계 결과를 종료 코드로 돌려줘야 한다');
    }
}
