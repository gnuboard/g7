<?php

namespace Tests\Feature\Console\Commands;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * `core:execute-upgrade-steps` 단독 실행 시 사전·사후 단계 자동 수행 계약 테스트.
 *
 * 부모 `CoreUpdateCommand` 가 spawn 으로 호출할 때는 `--skip-migrations` /
 * `--skip-resync` / `--skip-version-env` / `--skip-cache-clear` /
 * `--skip-bundled-updates` 5개 옵션을 전달하여 중복을 회피한다. 운영자가 단독으로
 * 호출(HANDOFF 안내문 또는 수동 복구 목적) 하는 경우엔 옵션 미전달 → 기본값으로
 * 5단계가 자동 수행되어야 공개 이슈 gnuboard/g7#34 의 수동 절차가 단일 명령으로 통합된다.
 *
 * 본 계약은 CoreUpdateService + 3개 Manager + LanguagePackService 를 Mockery 로
 * swap 하여 호출 여부만 검증한다. 실제 마이그레이션·시더 부작용은 테스트 범위 밖.
 */
class ExecuteUpgradeStepsStandaloneTest extends TestCase
{
    private array $createdPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 빈 upgrade step 디렉토리 보장 — 본 테스트는 step 자체가 아닌 사전/사후 단계만 검증.
        // 임시 dummy step 파일을 작성해 from < to 비교가 ">=" 조건을 통과하도록 한다.
        $this->writeNoopStep('0.9.1', 'standalone_noop');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }
        $this->createdPaths = [];

        Mockery::close();

        parent::tearDown();
    }

    public function test_standalone_invocation_runs_all_five_pre_and_post_steps(): void
    {
        [$service, $module, $plugin, $template, $langPack] = $this->bindMocks();

        $service->shouldReceive('runMigrations')->once();
        $service->shouldReceive('reloadCoreConfigAndResync')->once();
        $service->shouldReceive('runUpgradeSteps')->once();
        $service->shouldReceive('updateVersionInEnv')->once()->with('0.9.1');
        $service->shouldReceive('clearAllCaches')->once();
        $service->shouldReceive('collectBundledExtensionUpdates')->once()->andReturn([
            'modules' => [], 'plugins' => [], 'templates' => [],
        ]);
        $langPack->shouldReceive('collectBundledLangPackUpdates')->once()->andReturn([]);

        $exitCode = $this->runCommand([]);
        $this->assertSame(0, $exitCode);
    }

    public function test_skip_migrations_option_bypasses_migrations(): void
    {
        [$service, $module, $plugin, $template, $langPack] = $this->bindMocks();

        $service->shouldNotReceive('runMigrations');
        $service->shouldReceive('reloadCoreConfigAndResync')->once();
        $service->shouldReceive('runUpgradeSteps')->once();
        $service->shouldReceive('updateVersionInEnv')->once();
        $service->shouldReceive('clearAllCaches')->once();
        $service->shouldReceive('collectBundledExtensionUpdates')->once()->andReturn([
            'modules' => [], 'plugins' => [], 'templates' => [],
        ]);
        $langPack->shouldReceive('collectBundledLangPackUpdates')->once()->andReturn([]);

        $exitCode = $this->runCommand(['--skip-migrations' => true]);
        $this->assertSame(0, $exitCode);
    }

    public function test_skip_resync_option_bypasses_resync(): void
    {
        [$service, $module, $plugin, $template, $langPack] = $this->bindMocks();

        $service->shouldReceive('runMigrations')->once();
        $service->shouldNotReceive('reloadCoreConfigAndResync');
        $service->shouldReceive('runUpgradeSteps')->once();
        $service->shouldReceive('updateVersionInEnv')->once();
        $service->shouldReceive('clearAllCaches')->once();
        $service->shouldReceive('collectBundledExtensionUpdates')->once()->andReturn([
            'modules' => [], 'plugins' => [], 'templates' => [],
        ]);
        $langPack->shouldReceive('collectBundledLangPackUpdates')->once()->andReturn([]);

        $exitCode = $this->runCommand(['--skip-resync' => true]);
        $this->assertSame(0, $exitCode);
    }

    public function test_skip_version_env_option_bypasses_version_env_update(): void
    {
        [$service, $module, $plugin, $template, $langPack] = $this->bindMocks();

        $service->shouldReceive('runMigrations')->once();
        $service->shouldReceive('reloadCoreConfigAndResync')->once();
        $service->shouldReceive('runUpgradeSteps')->once();
        $service->shouldNotReceive('updateVersionInEnv');
        $service->shouldReceive('clearAllCaches')->once();
        $service->shouldReceive('collectBundledExtensionUpdates')->once()->andReturn([
            'modules' => [], 'plugins' => [], 'templates' => [],
        ]);
        $langPack->shouldReceive('collectBundledLangPackUpdates')->once()->andReturn([]);

        $exitCode = $this->runCommand(['--skip-version-env' => true]);
        $this->assertSame(0, $exitCode);
    }

    public function test_skip_cache_clear_option_bypasses_cache_clear(): void
    {
        [$service, $module, $plugin, $template, $langPack] = $this->bindMocks();

        $service->shouldReceive('runMigrations')->once();
        $service->shouldReceive('reloadCoreConfigAndResync')->once();
        $service->shouldReceive('runUpgradeSteps')->once();
        $service->shouldReceive('updateVersionInEnv')->once();
        $service->shouldNotReceive('clearAllCaches');
        $service->shouldReceive('collectBundledExtensionUpdates')->once()->andReturn([
            'modules' => [], 'plugins' => [], 'templates' => [],
        ]);
        $langPack->shouldReceive('collectBundledLangPackUpdates')->once()->andReturn([]);

        $exitCode = $this->runCommand(['--skip-cache-clear' => true]);
        $this->assertSame(0, $exitCode);
    }

    public function test_skip_bundled_updates_option_bypasses_bundled_prompt(): void
    {
        [$service, $module, $plugin, $template, $langPack] = $this->bindMocks();

        $service->shouldReceive('runMigrations')->once();
        $service->shouldReceive('reloadCoreConfigAndResync')->once();
        $service->shouldReceive('runUpgradeSteps')->once();
        $service->shouldReceive('updateVersionInEnv')->once();
        $service->shouldReceive('clearAllCaches')->once();
        // 번들 업데이트 prompt 자체가 호출되지 않으므로 collectBundled* 도 호출 없음.
        $service->shouldNotReceive('collectBundledExtensionUpdates');
        $langPack->shouldNotReceive('collectBundledLangPackUpdates');

        $exitCode = $this->runCommand(['--skip-bundled-updates' => true]);
        $this->assertSame(0, $exitCode);
    }

    public function test_all_skip_options_bypass_all_pre_and_post_steps(): void
    {
        [$service, $module, $plugin, $template, $langPack] = $this->bindMocks();

        // CoreUpdateCommand spawn 호출 시나리오 등가 — runUpgradeSteps 만 호출.
        $service->shouldNotReceive('runMigrations');
        $service->shouldNotReceive('reloadCoreConfigAndResync');
        $service->shouldReceive('runUpgradeSteps')->once();
        $service->shouldNotReceive('updateVersionInEnv');
        $service->shouldNotReceive('clearAllCaches');
        $service->shouldNotReceive('collectBundledExtensionUpdates');
        $langPack->shouldNotReceive('collectBundledLangPackUpdates');

        $exitCode = $this->runCommand([
            '--skip-migrations' => true,
            '--skip-resync' => true,
            '--skip-version-env' => true,
            '--skip-cache-clear' => true,
            '--skip-bundled-updates' => true,
        ]);
        $this->assertSame(0, $exitCode);
    }

    public function test_spawn_child_env_bypasses_all_pre_and_post_steps_without_skip_options(): void
    {
        // 구버전 부모(beta.5) 가 신버전 자식(beta.6+) 을 spawn 할 때 `--skip-*` 옵션을 모르므로
        // 전달하지 않지만, `G7_UPDATE_IN_PROGRESS=1` env 는 beta.3+ 부모부터 항상 설정한다.
        // 자식은 env 시그널만으로 본인이 spawn 컨텍스트임을 감지하여 5단계 모두 자동 스킵해야
        // 한다 (옵션 부재 + non-interactive 환경에서 prompt 호출로 인한 Aborted exit=1 회귀 차단).
        [$service, $module, $plugin, $template, $langPack] = $this->bindMocks();

        $service->shouldNotReceive('runMigrations');
        $service->shouldNotReceive('reloadCoreConfigAndResync');
        $service->shouldReceive('runUpgradeSteps')->once();
        $service->shouldNotReceive('updateVersionInEnv');
        $service->shouldNotReceive('clearAllCaches');
        $service->shouldNotReceive('collectBundledExtensionUpdates');
        $langPack->shouldNotReceive('collectBundledLangPackUpdates');

        $originalEnv = getenv('G7_UPDATE_IN_PROGRESS');
        putenv('G7_UPDATE_IN_PROGRESS=1');

        try {
            $exitCode = $this->runCommand([]);
            $this->assertSame(0, $exitCode);
        } finally {
            if ($originalEnv === false) {
                putenv('G7_UPDATE_IN_PROGRESS');
            } else {
                putenv('G7_UPDATE_IN_PROGRESS='.$originalEnv);
            }
        }
    }

    public function test_spawn_child_env_skips_even_when_force_is_set(): void
    {
        // env=1 + --force 동시 적용 시에도 사후 단계 모두 스킵. force 는 자식이 실행하는 step
        // 자체의 동일 버전 강제 플래그로만 사용되며, env 시그널이 우선한다 (부모가 force 든
        // 아니든 부모가 5단계를 책임진다는 계약 보존).
        [$service, $module, $plugin, $template, $langPack] = $this->bindMocks();

        $service->shouldNotReceive('runMigrations');
        $service->shouldNotReceive('reloadCoreConfigAndResync');
        $service->shouldReceive('runUpgradeSteps')->once();
        $service->shouldNotReceive('updateVersionInEnv');
        $service->shouldNotReceive('clearAllCaches');
        $service->shouldNotReceive('collectBundledExtensionUpdates');
        $langPack->shouldNotReceive('collectBundledLangPackUpdates');

        $originalEnv = getenv('G7_UPDATE_IN_PROGRESS');
        putenv('G7_UPDATE_IN_PROGRESS=1');

        try {
            $exitCode = $this->runCommand(['--force' => true]);
            $this->assertSame(0, $exitCode);
        } finally {
            if ($originalEnv === false) {
                putenv('G7_UPDATE_IN_PROGRESS');
            } else {
                putenv('G7_UPDATE_IN_PROGRESS='.$originalEnv);
            }
        }
    }

    public function test_standalone_invocation_without_env_still_runs_all_steps(): void
    {
        // env 미설정 (운영자 수동 호출) 시 기존 단독 실행 계약 보존 — 5단계 모두 실행.
        // env 가드 도입이 단독 실행 경로에 회귀를 일으키지 않음을 확인.
        [$service, $module, $plugin, $template, $langPack] = $this->bindMocks();

        $service->shouldReceive('runMigrations')->once();
        $service->shouldReceive('reloadCoreConfigAndResync')->once();
        $service->shouldReceive('runUpgradeSteps')->once();
        $service->shouldReceive('updateVersionInEnv')->once();
        $service->shouldReceive('clearAllCaches')->once();
        $service->shouldReceive('collectBundledExtensionUpdates')->once()->andReturn([
            'modules' => [], 'plugins' => [], 'templates' => [],
        ]);
        $langPack->shouldReceive('collectBundledLangPackUpdates')->once()->andReturn([]);

        // env 가 설정되지 않은 상태임을 명시적으로 보장.
        $originalEnv = getenv('G7_UPDATE_IN_PROGRESS');
        putenv('G7_UPDATE_IN_PROGRESS');

        try {
            $exitCode = $this->runCommand([]);
            $this->assertSame(0, $exitCode);
        } finally {
            if ($originalEnv !== false) {
                putenv('G7_UPDATE_IN_PROGRESS='.$originalEnv);
            }
        }
    }

    public function test_spawn_passes_all_five_skip_options_to_child(): void
    {
        // 부모 CoreUpdateCommand::spawnUpgradeStepsProcess 가 자식 command 배열에
        // 5개 `--skip-*` 옵션을 추가하는지 검증 (escapeshellarg 후 commandLine 문자열에 포함).
        $reflection = new \ReflectionClass(\App\Console\Commands\Core\CoreUpdateCommand::class);
        $source = File::get($reflection->getFileName());

        $this->assertStringContainsString("\$command[] = '--skip-migrations';", $source);
        $this->assertStringContainsString("\$command[] = '--skip-resync';", $source);
        $this->assertStringContainsString("\$command[] = '--skip-version-env';", $source);
        $this->assertStringContainsString("\$command[] = '--skip-cache-clear';", $source);
        $this->assertStringContainsString("\$command[] = '--skip-bundled-updates';", $source);
    }

    /**
     * CoreUpdateService + 3개 Manager + LanguagePackService 를 컨테이너에 mock 으로 swap.
     *
     * @return array{0: \Mockery\MockInterface, 1: \Mockery\MockInterface, 2: \Mockery\MockInterface, 3: \Mockery\MockInterface, 4: \Mockery\MockInterface}
     */
    private function bindMocks(): array
    {
        $service = Mockery::mock(CoreUpdateService::class);
        $module = Mockery::mock(ModuleManager::class);
        $plugin = Mockery::mock(PluginManager::class);
        $template = Mockery::mock(TemplateManager::class);
        $langPack = Mockery::mock(LanguagePackService::class);

        $this->app->instance(CoreUpdateService::class, $service);
        $this->app->instance(ModuleManager::class, $module);
        $this->app->instance(PluginManager::class, $plugin);
        $this->app->instance(TemplateManager::class, $template);
        $this->app->instance(LanguagePackService::class, $langPack);

        return [$service, $module, $plugin, $template, $langPack];
    }

    /**
     * 공통 옵션을 적용해 `core:execute-upgrade-steps` 를 호출.
     *
     * @param  array<string, mixed>  $extra  --skip-* 등 추가 옵션
     */
    private function runCommand(array $extra): int
    {
        $params = array_merge([
            '--from' => '0.9.0',
            '--to' => '0.9.1',
            '--force' => true,
        ], $extra);

        ob_start();
        $exitCode = Artisan::call('core:execute-upgrade-steps', $params);
        ob_end_clean();

        return $exitCode;
    }

    /**
     * 본 테스트가 from < to 범위 안에 들도록 더미 upgrade step 파일을 작성.
     * 핸들러 자체는 아무것도 하지 않는다 — 사전/사후 단계 호출만 검증.
     */
    private function writeNoopStep(string $version, string $suffix): void
    {
        $versionSnake = str_replace('.', '_', $version);
        $className = "Upgrade_{$versionSnake}_test_{$suffix}";
        $path = base_path("upgrades/{$className}.php");

        $code = <<<PHP
<?php

namespace App\\Upgrades;

use App\\Contracts\\Extension\\UpgradeStepInterface;
use App\\Extension\\UpgradeContext;

class {$className} implements UpgradeStepInterface
{
    public function run(UpgradeContext \$context): void
    {
        // noop — 본 테스트는 사전/사후 단계 호출만 검증.
    }
}
PHP;

        File::put($path, $code);
        $this->createdPaths[] = $path;
    }
}
