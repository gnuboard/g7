<?php

namespace Tests\Feature\Extension;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Services\Core\BundledSourcePlanner;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * 코어 업데이트 번들 정리는 활성 확장의 연동 대상 번들을 남긴다 (#822 D11)
 *
 * 게시판·쇼핑몰을 연동으로만 선언한 사용자 템플릿을 켠 채 쓰지 않는 번들을 정리하면, 나중에 그 연동 확장을 번들에서
 * 설치할 수 없게 된다. 정리(prune)는 활성 확장이 연동으로 선언한 번들을 「연동 대상이라 보존」으로 뺀다.
 * 배치 폐포(필수 의존)에는 넣지 않는다 — 연동은 강제 배치하지 않는다.
 */
class BundledPruneKeepsIntegrationTargetsTest extends TestCase
{
    private string $root;

    private string $release;

    private string $fakeBase;

    private string $originalBasePath;

    private string $originalStoragePath;

    /**
     * 격리 배포본·base·storage 디렉토리를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('test_prune_integrations_'.uniqid());
        $this->release = $this->root.DIRECTORY_SEPARATOR.'release';
        $this->fakeBase = $this->root.DIRECTORY_SEPARATOR.'base';
        File::ensureDirectoryExists($this->release);
        File::ensureDirectoryExists($this->fakeBase);
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage');

        $this->originalBasePath = base_path();
        $this->originalStoragePath = storage_path();
        app()->setBasePath($this->fakeBase);
        app()->useStoragePath($this->root.DIRECTORY_SEPARATOR.'storage');
    }

    /**
     * base·storage 경로를 되돌리고 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->setBasePath($this->originalBasePath);
        app()->useStoragePath($this->originalStoragePath);
        if (File::isDirectory($this->root)) {
            File::deleteDirectory($this->root);
        }
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 활성 템플릿의 연동 대상(게시판)은 보존하고, 아무도 쓰지 않는 번들(페이지)만 정리 대상이 된다.
     *
     * @scenario case=bundled_prune
     *
     * @effects prune_keeps_integration_targets_of_active_extensions
     *
     * @return void 반환값 없음
     */
    public function test_prune_keeps_bundles_integrated_by_active_extensions(): void
    {
        foreach ([$this->release, $this->fakeBase] as $root) {
            $this->writeJson($root, 'modules/_bundled/acme-board/module.json', $this->manifest('acme-board'));
            $this->writeJson($root, 'modules/_bundled/acme-page/module.json', $this->manifest('acme-page'));
            $this->writeJson($root, 'templates/_bundled/acme-basic/template.json', $this->manifest('acme-basic'));
        }
        File::ensureDirectoryExists($this->release.'/config');
        File::put($this->release.'/config/app.php', "<?php return ['version' => '7.1.4'];");
        // 설치·활성된 템플릿의 활성 manifest 가 게시판을 연동으로 선언한다
        $this->writeJson($this->fakeBase, 'templates/acme-basic/template.json', $this->manifest('acme-basic', ['modules' => ['acme-board' => '>=1.0.0']]));

        $planner = $this->planner(installedTemplates: ['acme-basic'], activeTemplates: ['acme-basic']);
        $inventory = $planner->readInventoryFromDirectory($this->release);
        $this->assertNotNull($inventory);

        $this->assertSame(['module:acme-board' => ['template:acme-basic']], $planner->activeIntegrationKeys());

        $plan = $planner->planPrune($inventory, null, true);

        $this->assertArrayHasKey('module:acme-page', $plan['candidates']);
        $this->assertArrayNotHasKey('module:acme-board', $plan['candidates']);
        $this->assertSame(['template:acme-basic'], $plan['protected_integrations']['module:acme-board']);
    }

    /**
     * 연동을 선언한 확장이 꺼져 있으면 보존하지 않는다 — 활성 확장 기준이다.
     *
     * @scenario case=bundled_prune_inactive_declarer
     *
     * @effects prune_keeps_integration_targets_of_active_extensions
     *
     * @return void 반환값 없음
     */
    public function test_inactive_declarer_does_not_protect(): void
    {
        $this->writeJson($this->fakeBase, 'templates/acme-basic/template.json', $this->manifest('acme-basic', ['modules' => ['acme-board']]));

        $this->assertSame([], $this->planner(installedTemplates: ['acme-basic'], activeTemplates: [])->activeIntegrationKeys());
    }

    /**
     * 설치·활성 상태를 흉내 낸 플래너를 만듭니다.
     *
     * @param  array<int, string>  $installedTemplates  설치된 템플릿 식별자
     * @param  array<int, string>  $activeTemplates  활성 템플릿 식별자
     * @return BundledSourcePlanner 플래너
     */
    private function planner(array $installedTemplates, array $activeTemplates): BundledSourcePlanner
    {
        $modules = Mockery::mock(ModuleRepositoryInterface::class);
        $modules->shouldReceive('getInstalledIdentifiers')->andReturn([]);
        $modules->shouldReceive('getActiveModuleIdentifiers')->andReturn([]);

        $plugins = Mockery::mock(PluginRepositoryInterface::class);
        $plugins->shouldReceive('getInstalledIdentifiers')->andReturn([]);
        $plugins->shouldReceive('getActivePluginIdentifiers')->andReturn([]);

        $templates = Mockery::mock(TemplateRepositoryInterface::class);
        $templates->shouldReceive('getInstalledIdentifiers')->andReturn($installedTemplates);
        $templates->shouldReceive('getActive')->andReturn(new EloquentCollection(array_map(
            fn (string $id) => (object) ['identifier' => $id],
            $activeTemplates,
        )));

        $packs = Mockery::mock(LanguagePackRepositoryInterface::class);
        $packs->shouldReceive('allForUpdateCheck')->andReturn(new EloquentCollection);

        return new BundledSourcePlanner($modules, $plugins, $templates, $packs);
    }

    /**
     * 최소 manifest 를 만듭니다.
     *
     * @param  string  $id  식별자
     * @param  array<string, mixed>  $integrations  선택 연동 선언 값
     * @return array<string, mixed> 확장 매니페스트 배열
     */
    private function manifest(string $id, array $integrations = []): array
    {
        return [
            'identifier' => $id, 'version' => '1.0.0', 'name' => ['ko' => $id, 'en' => $id],
            'dependencies' => ['modules' => [], 'plugins' => []],
            'integrations' => $integrations,
        ];
    }

    /**
     * 루트 아래 상대경로에 JSON 파일을 씁니다.
     *
     * @param  string  $root  루트 디렉토리
     * @param  string  $relative  슬래시 구분 상대경로
     * @param  array<string, mixed>  $data  JSON 내용
     * @return void 반환값 없음
     */
    private function writeJson(string $root, string $relative, array $data): void
    {
        $file = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($file));
        File::put($file, (string) json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
