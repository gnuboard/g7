<?php

namespace Tests\Feature\Console;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Services\Core\BundledSourcePlanner;
use App\Services\CoreUpdateService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * core:bundled-sources list/place/prune Feature 테스트 (공개 #146)
 *
 * 격리 base_path·storage 와 픽스처 배포본으로 실행해 개발 저장소의 `_bundled` 를 건드리지 않습니다.
 * 설치 상태는 Repository 목으로 흉내 냅니다(sirsoft-board 만 설치).
 */
class CoreBundledSourcesCommandTest extends TestCase
{
    private string $root;

    private string $release;

    private string $fakeBase;

    private string $originalBasePath;

    private string $originalStoragePath;

    /**
     * 픽스처 배포본·격리 base·storage 를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = storage_path('test_bundled_sources_cmd_'.uniqid());
        $this->release = $this->root.DIRECTORY_SEPARATOR.'release';
        $this->fakeBase = $this->root.DIRECTORY_SEPARATOR.'base';
        File::ensureDirectoryExists($this->fakeBase);
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage');
        $this->writeRelease($this->release, '7.1.1');

        $this->originalBasePath = base_path();
        $this->originalStoragePath = storage_path();
        app()->setBasePath($this->fakeBase);
        app()->useStoragePath($this->root.DIRECTORY_SEPARATOR.'storage');
        config(['app.version' => '7.1.1']);

        $this->app->instance(BundledSourcePlanner::class, $this->planner());
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

        parent::tearDown();
    }

    /**
     * list --source 는 배포본 × 디스크 × 설치 표를 출력하고, 미배치 기록(숨김 포함)을 갱신합니다.
     *
     * @scenario action=list,source_kind=source
     *
     * @effects list_shows_inventory_and_refreshes_record
     *
     * @return void 반환값 없음
     */
    public function test_list_with_source_refreshes_record(): void
    {
        $this->placeOnDisk('modules/_bundled/sirsoft-board');

        $this->artisan('core:bundled-sources', ['action' => 'list', '--source' => $this->release])
            ->expectsOutputToContain('g7test-bundled_probe')
            ->expectsOutputToContain('g7test-bundled_hidden (샘플)')
            ->expectsOutputToContain('기록 파일을 갱신했습니다 (미배치 5개)')
            ->assertExitCode(0);

        $record = $this->planner()->readRecord();
        $this->assertSame('core:bundled-sources', $record['source']);
        $this->assertSame('7.1.1', $record['core_version']);
        $hidden = collect($record['unplaced'])->firstWhere('identifier', 'g7test-bundled_hidden');
        $this->assertTrue($hidden['hidden']);
        $this->assertNull(collect($record['unplaced'])->firstWhere('identifier', 'sirsoft-board'));
    }

    /**
     * 배포본을 못 읽으면 같은 버전의 기록 파일로, 그것도 없으면 디스크·설치 상태만 표시합니다.
     *
     * @scenario action=list,source_kind=github_unavailable
     *
     * @effects list_falls_back_to_record_then_disk
     *
     * @return void 반환값 없음
     */
    public function test_list_falls_back_to_record_then_disk(): void
    {
        $service = Mockery::mock(CoreUpdateService::class);
        $service->shouldReceive('downloadUpdate')->andThrow(new \RuntimeException('offline'));
        $this->app->instance(CoreUpdateService::class, $service);
        $this->placeOnDisk('plugins/_bundled/acme-custom');

        $this->artisan('core:bundled-sources', ['action' => 'list'])
            ->expectsOutputToContain('배포본도 기록 파일도 없어 디스크와 설치 상태만 표시합니다')
            ->expectsOutputToContain('acme-custom')
            ->assertExitCode(0);

        $planner = $this->planner();
        $planner->writeRecord($planner->plan($planner->readInventoryFromDirectory($this->release)), '7.1.1', 'core:update');

        $this->artisan('core:bundled-sources', ['action' => 'list'])
            ->expectsOutputToContain('배포본을 읽지 못해 기록 파일')
            ->expectsOutputToContain('g7test-bundled_probe')
            ->assertExitCode(0);
    }

    /**
     * 배포본도 기록도 없을 때 디스크 표는 각 `_bundled` 폴더 manifest 의 판·샘플 표시·의존 대상을 채웁니다.
     *
     * 종전에는 이 갈래의 버전·의존 대상 열이 전부 `-` 였다(7.1.1 출시 전 실측 — GitHub 에 그 판 태그가 아직 없을 때).
     * manifest 가 없는 사용자 폴더는 종전대로 `-` 다.
     *
     * @scenario action=list,source_kind=github_unavailable
     *
     * @effects list_disk_fallback_reads_disk_manifests
     *
     * @return void 반환값 없음
     */
    public function test_list_disk_fallback_shows_disk_manifest_versions(): void
    {
        $service = Mockery::mock(CoreUpdateService::class);
        $service->shouldReceive('downloadUpdate')->andThrow(new \RuntimeException('offline'));
        $this->app->instance(CoreUpdateService::class, $service);
        $this->copyFromRelease('modules/_bundled/sirsoft-board');
        $this->copyFromRelease('plugins/_bundled/g7test-bundled_hidden');
        $this->placeOnDisk('plugins/_bundled/acme-custom');

        $this->artisan('core:bundled-sources', ['action' => 'list'])
            ->expectsOutputToContain('배포본도 기록 파일도 없어 디스크와 설치 상태만 표시합니다')
            ->expectsOutputToContain('1.2.0')
            ->expectsOutputToContain('g7test-bundled_hidden (샘플)')
            ->expectsOutputToContain('acme-custom')
            ->assertExitCode(0);
    }

    /**
     * 배포본을 못 읽고 기록 파일로 표시할 때도 디스크의 번들 전체를 판과 함께 보여 주고, 미배치 수를 알린다.
     *
     * 종전 기록 파일 갈래는 미배치 항목만 나열해, 전부 배치된 상태(미배치 0 — 예: `core:update --bundled-sources=none`
     * 직후 출시 태그에 닿지 못할 때)에는 머리글만 남은 빈 표가 나왔고 의존 대상 열은 늘 `-` 였다. 이제 디스크 폴백과
     * 같은 표(디스크 `_bundled` 전체 + 디스크에 없는 미배치)를 쓰고 「기록 파일 기준 미배치 N개」 를 알린다.
     *
     * @scenario action=list,source_kind=github_unavailable
     *
     * @effects list_record_fallback_lists_disk_bundles_and_unplaced_count
     *
     * @return void 반환값 없음
     */
    public function test_list_record_fallback_lists_disk_bundles_when_nothing_unplaced(): void
    {
        $service = Mockery::mock(CoreUpdateService::class);
        $service->shouldReceive('downloadUpdate')->andThrow(new \RuntimeException('offline'));
        $this->app->instance(CoreUpdateService::class, $service);

        foreach ([
            'modules/_bundled/sirsoft-board',
            'modules/_bundled/g7test-bundled_probe_module',
            'plugins/_bundled/g7test-bundled_probe',
            'plugins/_bundled/g7test-bundled_hidden',
            'templates/_bundled/g7test-bundled_probe_tpl',
            'lang-packs/_bundled/g7-plugin-g7test-bundled_probe-ja',
        ] as $relative) {
            $this->copyFromRelease($relative);
        }
        $planner = $this->planner();
        $record = $planner->writeRecord($planner->plan($planner->readInventoryFromDirectory($this->release)), '7.1.1', 'core:update');
        $this->assertSame([], $record['unplaced'], '픽스처 전제: 전부 배치돼 미배치가 0이어야 한다');

        // 출력 한 줄(doWrite 한 번)은 맞는 기대 하나만 지운다 — 같은 표 행에 든 식별자와 판을 따로 기대하면 앞 기대가 그 줄을 먹어
        // 뒤 기대가 남는다. sirsoft-board 행은 그 판(1.2.0 — 디스크 manifest 값)으로만 확인한다.
        $this->artisan('core:bundled-sources', ['action' => 'list'])
            ->expectsOutputToContain('배포본을 읽지 못해 기록 파일')
            ->expectsOutputToContain('1.2.0')
            ->expectsOutputToContain('g7test-bundled_probe_module')
            ->expectsOutputToContain('g7test-bundled_hidden (샘플)')
            ->expectsOutputToContain('기록 파일 기준 미배치 0개')
            ->assertExitCode(0);
    }

    /**
     * place 는 고른 번들과 의존 모듈·대상 언어팩을 복사하고, 이미 있으면 건너뛰며, 기록의 미배치에서 뺍니다.
     *
     * @scenario action=place,dependency=direct
     *
     * @effects place_copies_bundle_dependencies_and_language_pack
     *
     * @return void 반환값 없음
     */
    public function test_place_copies_with_dependencies(): void
    {
        $planner = $this->planner();
        $planner->writeRecord($planner->plan($planner->readInventoryFromDirectory($this->release)), '7.1.1', 'core:update');

        $this->artisan('core:bundled-sources', ['action' => 'place', 'identifiers' => ['g7test-bundled_probe'], '--source' => $this->release])
            ->expectsOutputToContain('배치함: module:g7test-bundled_probe_module ← plugin:g7test-bundled_probe')
            ->expectsOutputToContain('3개를 배치했습니다')
            ->assertExitCode(0);

        $this->assertFileExists($this->fakeBase.'/plugins/_bundled/g7test-bundled_probe/plugin.json');
        $this->assertFileExists($this->fakeBase.'/modules/_bundled/g7test-bundled_probe_module/module.json');
        $this->assertFileExists($this->fakeBase.'/lang-packs/_bundled/g7-plugin-g7test-bundled_probe-ja/language-pack.json');
        $this->assertDirectoryDoesNotExist($this->fakeBase.'/plugins/_bundled/g7test-bundled_hidden');

        $unplaced = collect($this->planner()->readRecord()['unplaced'])->pluck('identifier')->all();
        $this->assertNotContains('g7test-bundled_probe', $unplaced);
        $this->assertContains('g7test-bundled_hidden', $unplaced);

        $this->artisan('core:bundled-sources', ['action' => 'place', 'identifiers' => ['g7test-bundled_probe'], '--source' => $this->release])
            ->expectsOutputToContain('이미 디스크에 있어 건너뜀: plugin:g7test-bundled_probe')
            ->expectsOutputToContain('0개를 배치했습니다')
            ->assertExitCode(0);
    }

    /**
     * 코어 버전이 다른 배포본·식별자 누락·배포본에 없는 식별자는 거부합니다.
     *
     * @scenario action=place,source_kind=version_mismatch
     *
     * @effects mismatched_or_invalid_release_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_place_rejects_version_mismatch_and_invalid_input(): void
    {
        $other = $this->root.DIRECTORY_SEPARATOR.'release_other';
        $this->writeRelease($other, '7.0.9');

        $this->artisan('core:bundled-sources', ['action' => 'place', 'identifiers' => ['g7test-bundled_probe'], '--source' => $other])
            ->expectsOutputToContain('배포본 코어 버전(7.0.9)이 현재 코어 버전(7.1.1)과 다릅니다')
            ->assertExitCode(1);
        $this->assertDirectoryDoesNotExist($this->fakeBase.'/plugins/_bundled/g7test-bundled_probe');

        $this->artisan('core:bundled-sources', ['action' => 'place', '--source' => $this->release])
            ->expectsOutputToContain('배치할 번들 식별자를 하나 이상 지정하세요')
            ->assertExitCode(1);

        $this->artisan('core:bundled-sources', ['action' => 'place', 'identifiers' => ['acme-nothing'], '--source' => $this->release])
            ->expectsOutputToContain('배포본에 없는 공식 번들입니다: acme-nothing')
            ->assertExitCode(1);
    }

    /**
     * prune 은 기본이 미리보기(삭제 없음)이고 --force 일 때만 지우며, 설치분·사용자 자체 폴더는 건드리지 않습니다.
     *
     * @scenario action=prune,force=false
     *
     * @effects prune_previews_by_default_and_deletes_with_force, prune_never_touches_installed_or_user_dirs
     *
     * @return void 반환값 없음
     */
    public function test_prune_preview_then_force(): void
    {
        $this->copyFromRelease('modules/_bundled/sirsoft-board');
        $this->copyFromRelease('plugins/_bundled/g7test-bundled_hidden');
        $this->placeOnDisk('plugins/_bundled/acme-custom');

        $this->artisan('core:bundled-sources', ['action' => 'prune', '--source' => $this->release])
            ->expectsOutputToContain('정리 대상 미리보기')
            ->expectsOutputToContain('plugin:g7test-bundled_hidden')
            ->assertExitCode(0);
        $this->assertDirectoryExists($this->fakeBase.'/plugins/_bundled/g7test-bundled_hidden');

        $this->artisan('core:bundled-sources', ['action' => 'prune', '--source' => $this->release, '--force' => true])
            ->expectsOutputToContain('삭제함: plugin:g7test-bundled_hidden')
            ->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($this->fakeBase.'/plugins/_bundled/g7test-bundled_hidden');
        $this->assertDirectoryExists($this->fakeBase.'/modules/_bundled/sirsoft-board', '설치된 번들은 지우지 않는다');
        $this->assertDirectoryExists($this->fakeBase.'/plugins/_bundled/acme-custom', '사용자 자체 폴더는 지우지 않는다');
    }

    /**
     * 수정된 폴더는 「수정됨, 보존」과 다른 파일 목록을 보이고, 그 폴더가 의존하는 번들도 남는 번들의 의존 대상으로 보존합니다.
     * --include-modified 일 때만 함께 지웁니다.
     *
     * @scenario action=prune,modified=true
     *
     * @effects prune_keeps_modified_and_their_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_prune_keeps_modified_and_dependencies(): void
    {
        $this->copyFromRelease('plugins/_bundled/g7test-bundled_probe');
        $this->copyFromRelease('modules/_bundled/g7test-bundled_probe_module');
        $this->copyFromRelease('lang-packs/_bundled/g7-plugin-g7test-bundled_probe-ja');
        File::put($this->fakeBase.'/plugins/_bundled/g7test-bundled_probe/plugin.json', '{"identifier":"g7test-bundled_probe","edited":true,"dependencies":{"modules":{"g7test-bundled_probe_module":"*"}}}');

        $this->artisan('core:bundled-sources', ['action' => 'prune', '--source' => $this->release, '--force' => true])
            ->expectsOutputToContain('수정됨, 보존: plugin:g7test-bundled_probe (배포본과 다른 파일 1개)')
            ->expectsOutputToContain('plugin.json')
            ->expectsOutputToContain('의존 대상이라 보존: module:g7test-bundled_probe_module (plugin:g7test-bundled_probe)')
            ->assertExitCode(0);
        $this->assertDirectoryExists($this->fakeBase.'/plugins/_bundled/g7test-bundled_probe');
        $this->assertDirectoryExists($this->fakeBase.'/modules/_bundled/g7test-bundled_probe_module');

        $this->artisan('core:bundled-sources', ['action' => 'prune', '--source' => $this->release, '--force' => true, '--include-modified' => true])
            ->expectsOutputToContain('삭제함: plugin:g7test-bundled_probe')
            ->expectsOutputToContain('삭제함: module:g7test-bundled_probe_module')
            ->expectsOutputToContain('삭제함: language_pack:g7-plugin-g7test-bundled_probe-ja')
            ->assertExitCode(0);
        $this->assertDirectoryDoesNotExist($this->fakeBase.'/plugins/_bundled/g7test-bundled_probe');
        $this->assertDirectoryDoesNotExist($this->fakeBase.'/modules/_bundled/g7test-bundled_probe_module');
    }

    /**
     * 배포본에 없는 파일만 다른 폴더는 「수정됨」과 따로 「배포본에 없는 파일만 다름」으로 알리고 보존합니다(7.1.1 출시 전).
     *
     * 기본(비-prune) 업데이트는 새 판에서 지워진 파일을 지우지 않는다. 그 남은 파일 때문에 운영자가 손대지 않은 번들이
     * 「수정됨, 보존」으로 보여 원인을 알 수 없었다. 지우는 동작은 그대로(보존)이고 안내만 가른다.
     *
     * @scenario action=prune,modified=true
     *
     * @effects prune_keeps_modified_and_their_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_prune_reports_extra_only_folders_separately(): void
    {
        $this->copyFromRelease('modules/_bundled/g7test-bundled_probe_module');
        File::ensureDirectoryExists($this->fakeBase.'/modules/_bundled/g7test-bundled_probe_module/resources');
        File::put($this->fakeBase.'/modules/_bundled/g7test-bundled_probe_module/resources/routes.json', '{}');

        $this->artisan('core:bundled-sources', ['action' => 'prune', '--source' => $this->release, '--force' => true])
            ->expectsOutputToContain('배포본에 없는 파일만 다름, 보존: module:g7test-bundled_probe_module (배포본에 없는 파일 1개)')
            ->expectsOutputToContain('resources/routes.json')
            ->expectsOutputToContain('이전 판에서 남은 파일일 수 있습니다')
            ->doesntExpectOutputToContain('수정됨, 보존: module:g7test-bundled_probe_module')
            ->assertExitCode(0);

        $this->assertDirectoryExists($this->fakeBase.'/modules/_bundled/g7test-bundled_probe_module', '보존 동작은 그대로');
    }

    /**
     * 설치된 확장(번들 밖 포함)의 의존 대상은 정리하지 않습니다.
     *
     * @scenario action=prune,dependency=installed
     *
     * @effects prune_never_touches_installed_or_user_dirs
     *
     * @return void 반환값 없음
     */
    public function test_prune_protects_installed_dependency(): void
    {
        $this->copyFromRelease('modules/_bundled/g7test-bundled_probe_module');
        File::ensureDirectoryExists($this->fakeBase.'/modules/sirsoft-board');
        File::put($this->fakeBase.'/modules/sirsoft-board/module.json', '{"identifier":"sirsoft-board","dependencies":{"modules":{"g7test-bundled_probe_module":"*"}}}');

        $this->artisan('core:bundled-sources', ['action' => 'prune', 'identifiers' => ['g7test-bundled_probe_module'], '--source' => $this->release, '--force' => true])
            ->expectsOutputToContain('의존 대상이라 보존: module:g7test-bundled_probe_module (installed)')
            ->expectsOutputToContain('정리할 번들이 없습니다')
            ->assertExitCode(0);
        $this->assertDirectoryExists($this->fakeBase.'/modules/_bundled/g7test-bundled_probe_module');
    }

    /**
     * GitHub 에 이 버전의 배포본(태그)이 아직 없으면 「추출 실패」가 아니라 「배포본을 찾지 못함」으로 알리고,
     * prune 은 「배치」가 아니라 정리 판정을 못 한다고 끝냅니다(7.1.1 출시 전②).
     *
     * @scenario action=prune,source_kind=github_unavailable
     *
     * @effects github_missing_release_is_reported_as_not_found, prune_without_release_speaks_of_pruning
     *
     * @return void 반환값 없음
     */
    public function test_prune_without_github_release_reports_not_found_in_prune_terms(): void
    {
        config([
            'app.update.github_url' => 'https://github.com/acme/g7',
            'app.update.github_token' => '',
            'app.update.pending_path' => $this->root.DIRECTORY_SEPARATOR.'pending',
        ]);
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->artisan('core:bundled-sources', ['action' => 'prune'])
            ->expectsOutputToContain('GitHub 에서 코어 7.1.1 배포본을 찾지 못했습니다')
            ->doesntExpectOutputToContain('추출 방법이 실패')
            ->expectsOutputToContain('배포본을 읽지 못해 정리 대상을 판정할 수 없습니다')
            ->doesntExpectOutputToContain('배치할 수 없습니다')
            ->assertExitCode(1);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/zipball/') || str_contains($request->url(), '/tarball/'));
    }

    /**
     * 알 수 없는 동작은 실패합니다.
     *
     * @effects mismatched_or_invalid_release_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_unknown_action_fails(): void
    {
        $this->artisan('core:bundled-sources', ['action' => 'wipe'])
            ->expectsOutputToContain('알 수 없는 동작입니다: wipe')
            ->assertExitCode(1);
    }

    /**
     * 설치 상태를 흉내 내는 판정 서비스를 만듭니다(sirsoft-board 만 설치).
     *
     * @return BundledSourcePlanner 판정 서비스
     */
    private function planner(): BundledSourcePlanner
    {
        $repo = function (string $class, array $ids) {
            $mock = Mockery::mock($class);
            $mock->shouldReceive('getInstalledIdentifiers')->andReturn($ids);

            return $mock;
        };
        $lp = Mockery::mock(LanguagePackRepositoryInterface::class);
        $lp->shouldReceive('allForUpdateCheck')->andReturn(new EloquentCollection);

        return new BundledSourcePlanner(
            $repo(ModuleRepositoryInterface::class, ['sirsoft-board']),
            $repo(PluginRepositoryInterface::class, []),
            $repo(TemplateRepositoryInterface::class, []),
            $lp,
        );
    }

    /**
     * 격리 base 에 빈 번들 폴더를 만듭니다(사용자 자체 폴더·설치분 흉내).
     *
     * @param  string  $relative  base 상대경로
     * @return void 반환값 없음
     */
    private function placeOnDisk(string $relative): void
    {
        File::ensureDirectoryExists($this->fakeBase.DIRECTORY_SEPARATOR.$relative);
        File::put($this->fakeBase.DIRECTORY_SEPARATOR.$relative.DIRECTORY_SEPARATOR.'marker.txt', 'x');
    }

    /**
     * 배포본의 번들 폴더를 격리 base 로 그대로 복사합니다(수정 없는 사본).
     *
     * @param  string  $relative  base 상대경로
     * @return void 반환값 없음
     */
    private function copyFromRelease(string $relative): void
    {
        File::copyDirectory($this->release.DIRECTORY_SEPARATOR.$relative, $this->fakeBase.DIRECTORY_SEPARATOR.$relative);
    }

    /**
     * 실측 계획(8.2)과 같은 구성의 픽스처 배포본을 씁니다.
     *
     * @param  string  $dir  배포본 디렉토리
     * @param  string  $version  config/app.php 코어 버전
     * @return void 반환값 없음
     */
    private function writeRelease(string $dir, string $version): void
    {
        $put = function (string $rel, string $content) use ($dir): void {
            $file = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            File::ensureDirectoryExists(dirname($file));
            File::put($file, $content);
        };
        $json = fn (array $data) => (string) json_encode($data, JSON_UNESCAPED_UNICODE);

        $put('config/app.php', "<?php return ['version' => env('APP_VERSION', '{$version}')];");
        $put('modules/_bundled/sirsoft-board/module.json', $json(['identifier' => 'sirsoft-board', 'version' => '1.2.0']));
        $put('modules/_bundled/g7test-bundled_probe_module/module.json', $json(['identifier' => 'g7test-bundled_probe_module', 'version' => '1.0.0']));
        $put('plugins/_bundled/g7test-bundled_probe/plugin.json', $json([
            'identifier' => 'g7test-bundled_probe', 'version' => '1.0.0',
            'dependencies' => ['modules' => ['g7test-bundled_probe_module' => '>=1.0.0']],
        ]));
        $put('plugins/_bundled/g7test-bundled_probe/src/Plugin.php', '<?php // probe');
        $put('plugins/_bundled/g7test-bundled_hidden/plugin.json', $json(['identifier' => 'g7test-bundled_hidden', 'version' => '1.0.0', 'hidden' => true]));
        $put('templates/_bundled/g7test-bundled_probe_tpl/template.json', $json(['identifier' => 'g7test-bundled_probe_tpl', 'version' => '1.0.0', 'type' => 'user']));
        $put('lang-packs/_bundled/g7-plugin-g7test-bundled_probe-ja/language-pack.json', $json([
            'identifier' => 'g7-plugin-g7test-bundled_probe-ja', 'version' => '1.0.0', 'scope' => 'plugin', 'target_identifier' => 'g7test-bundled_probe',
        ]));
    }
}
