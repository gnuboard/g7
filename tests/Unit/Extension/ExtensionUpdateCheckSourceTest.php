<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\ExtensionManager;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\ModuleManager;
use App\Models\Module;
use App\Services\LayoutExtensionService;
use Illuminate\Support\Facades\File;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 확장 업데이트 확인이 소스 지정(`--source`)을 존중하고, 지정이 없으면 GitHub·번들 중 높은 버전을 후보로 본다 (W3 실측).
 *
 * 공개 저장소 최신 릴리스가 번들보다 옛 판일 때(코어와 함께 배포된 번들 1.1.0, 공개 1.0.4) 확인은 GitHub 결과만 믿어
 * 「업데이트 없음」으로 끝났고, `plugin:update … --source=bundled` 도 확인 단계가 소스를 받지 않아 같은 결과였다 —
 * `--force` 를 붙여야만 번들 판이 설치됐다. 문서상 `--source=bundled` 는 번들 단독(GitHub 전면 우회)이다.
 */
class ExtensionUpdateCheckSourceTest extends TestCase
{
    private const ID = 'test-srccheck-mod';

    private string $bundledDir;

    /**
     * 번들 판(1.1.0) 모듈 디렉토리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->bundledDir = base_path('modules/_bundled/'.self::ID);
        File::ensureDirectoryExists($this->bundledDir);
        File::put($this->bundledDir.'/module.json', json_encode([
            'identifier' => self::ID,
            'version' => '1.1.0',
            'vendor' => 'test',
            'name' => ['ko' => '소스 확인', 'en' => 'Source check'],
            'description' => ['ko' => '시험', 'en' => 'Test'],
            'dependencies' => [],
        ]));
    }

    /**
     * 임시 번들 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->bundledDir);
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 소스 지정 없음 — 공개 최신(1.0.4)이 번들(1.1.0)보다 낮으면 번들을 후보로 본다. 공개가 더 높으면 공개를 본다.
     *
     * @scenario source=auto, github=older_than_bundled
     *
     * @effects auto_check_offers_higher_of_github_and_bundled
     *
     * @return void 반환값 없음
     */
    public function test_auto_check_offers_higher_of_github_and_bundled(): void
    {
        $older = $this->manager('1.0.4')->checkModuleUpdate(self::ID);
        $this->assertTrue($older['update_available'], '번들이 더 새로운데 업데이트 없음으로 끝났다');
        $this->assertSame('bundled', $older['update_source']);
        $this->assertSame('1.1.0', $older['latest_version']);

        $newer = $this->manager('1.2.0')->checkModuleUpdate(self::ID);
        $this->assertSame('github', $newer['update_source']);
        $this->assertSame('1.2.0', $newer['latest_version']);
    }

    /**
     * 소스 지정 — bundled 는 GitHub 를 보지 않고, github 는 번들로 폴백하지 않는다.
     *
     * @scenario source=explicit, github=older_than_bundled
     *
     * @effects explicit_source_is_respected_by_update_check
     *
     * @return void 반환값 없음
     */
    public function test_explicit_source_is_respected(): void
    {
        $bundled = $this->manager('1.2.0')->checkModuleUpdate(self::ID, 'bundled');
        $this->assertSame('bundled', $bundled['update_source']);
        $this->assertSame('1.1.0', $bundled['latest_version']);

        $github = $this->manager('1.0.4')->checkModuleUpdate(self::ID, 'github');
        $this->assertFalse($github['update_available'], 'github 지정은 번들로 폴백하면 안 된다');

        $githubDown = $this->manager(null)->checkModuleUpdate(self::ID, 'github');
        $this->assertFalse($githubDown['update_available']);
    }

    /**
     * 갱신 명령 3종이 확인 단계에 소스를 넘기고, 매니저 3종의 확인 함수가 소스를 받는다.
     *
     * @scenario source=explicit, github=any
     *
     * @effects update_commands_pass_source_to_check
     *
     * @return void 반환값 없음
     */
    public function test_update_commands_pass_source_to_check(): void
    {
        foreach ([
            'app/Console/Commands/Module/UpdateModuleCommand.php' => 'checkModuleUpdate($identifier, $sourceOverride)',
            'app/Console/Commands/Plugin/UpdatePluginCommand.php' => 'checkPluginUpdate($identifier, $sourceOverride)',
            'app/Console/Commands/Template/UpdateTemplateCommand.php' => 'checkTemplateUpdate($identifier, $sourceOverride)',
        ] as $file => $call) {
            $this->assertStringContainsString($call, (string) file_get_contents(base_path($file)), "{$file} 의 확인 단계가 --source 를 넘기지 않는다");
        }

        foreach ([[ModuleManager::class, 'checkModuleUpdate'], [\App\Extension\PluginManager::class, 'checkPluginUpdate'], [\App\Extension\TemplateManager::class, 'checkTemplateUpdate']] as [$class, $method]) {
            $params = (new ReflectionMethod($class, $method))->getParameters();
            $this->assertSame('source', $params[1]->getName() ?? null, "{$class}::{$method} 가 소스 인자를 받지 않는다");
        }
    }

    /**
     * GitHub 최신 버전을 정해 둔 모듈 매니저를 만듭니다 (설치 1.0.4, GitHub URL 있음).
     *
     * @param  string|null  $githubLatest  GitHub 최신 버전 (null 이면 조회 실패)
     * @return ModuleManager 매니저
     */
    private function manager(?string $githubLatest): ModuleManager
    {
        $repository = Mockery::mock(ModuleRepositoryInterface::class);
        $repository->shouldReceive('findByIdentifier')->with(self::ID)->andReturn((new Module)->forceFill(['identifier' => self::ID, 'version' => '1.0.4']));

        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getGithubUrl')->andReturn('https://github.com/acme/'.self::ID);
        $module->shouldReceive('getRequiredCoreVersion')->andReturn(null);

        $manager = new class(
            extensionManager: Mockery::mock(ExtensionManager::class),
            moduleRepository: $repository,
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            menuRepository: Mockery::mock(MenuRepositoryInterface::class),
            templateRepository: Mockery::mock(TemplateRepositoryInterface::class),
            pluginRepository: Mockery::mock(PluginRepositoryInterface::class),
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
            layoutRowSynchronizer: app(LayoutRowSynchronizer::class),
            layoutExtensionRepository: app(LayoutExtensionRepositoryInterface::class),
        ) extends ModuleManager
        {
            /** @var string|null 가짜 GitHub 최신 버전 */
            public ?string $fakeLatest = null;

            /** @var ModuleInterface|null 가짜 모듈 */
            public ?ModuleInterface $fakeModule = null;

            /**
             * 가짜 GitHub 최신 버전을 돌려줍니다.
             *
             * @param  ModuleInterface  $module  모듈
             * @return string|null 최신 버전
             */
            protected function fetchLatestVersion(ModuleInterface $module): ?string
            {
                return $this->fakeLatest;
            }

            /**
             * 가짜 모듈을 돌려줍니다.
             *
             * @param  string  $moduleName  모듈 식별자
             * @return ModuleInterface|null 모듈
             */
            public function getModule(string $moduleName): ?ModuleInterface
            {
                return $this->fakeModule;
            }
        };
        $manager->fakeLatest = $githubLatest;
        $manager->fakeModule = $module;

        return $manager;
    }
}
