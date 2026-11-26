<?php

namespace Tests\Feature\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * GitHub 최신 판 조회 실패가 「업데이트 없음」으로 숨지 않는다.
 *
 * 업데이트 확인(check*Update)은 조회에 실패하면 응답에 `check_error` 를 싣는다. `{type}:update --source=github` 는 종료 코드 1,
 * 소스 자동은 번들 판정 + 경고 한 줄, `{type}:check-updates` 는 실패 표 + 종료 코드 1 이고 DB 의 업데이트 정보를 덮지 않는다.
 * 릴리스가 아직 없는 것(HTTP 404)과 조회 성공은 종전대로 0 이다.
 *
 * 픽스처 확장은 저장소 주소를 가진 가짜 식별자(`testmerge-*`)로 만들고 tearDown 에서 지운다. GitHub 응답은 Http::fake 로 고정한다.
 */
class ExtensionCheckUpdateErrorTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use DatabaseTransactions;

    /**
     * 픽스처 디스크 산출물을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();

        parent::tearDown();
    }

    /**
     * 확장 종류 3종 데이터 공급자.
     *
     * @return array<string, array{0: string}> [종류]
     */
    public static function extensionTypes(): array
    {
        return ['module' => ['module'], 'plugin' => ['plugin'], 'template' => ['template']];
    }

    /**
     * GitHub 가 500 으로 답하면 확인 응답에 `check_error` 가 실리고, 소스 지정(github)이어도 같다.
     *
     * @scenario case=check_error_on_github_failure
     *
     * @effects update_check_carries_check_error
     *
     * @param  string  $type  확장 종류
     * @return void 반환값 없음
     */
    #[DataProvider('extensionTypes')]
    public function test_github_failure_adds_check_error(string $type): void
    {
        $id = $this->makeFixtureWithGithubUrl($type);
        $this->fakeGithub(500);

        $auto = $this->check($type, $id, null);
        $this->assertArrayHasKey('check_error', $auto, 'GitHub 조회 실패가 응답에 드러나야 한다');
        $this->assertStringContainsString('500', $auto['check_error']);
        $this->assertFalse($auto['update_available']);

        $github = $this->check($type, $id, 'github');
        $this->assertArrayHasKey('check_error', $github);
        $this->assertFalse($github['update_available']);
    }

    /**
     * 연결 실패도 `check_error` 다.
     *
     * @scenario case=check_error_on_connection_failure
     *
     * @effects update_check_carries_check_error
     *
     * @return void 반환값 없음
     */
    public function test_connection_failure_adds_check_error(): void
    {
        $id = $this->makeFixtureWithGithubUrl('module');
        Http::fake(fn () => throw new ConnectionException('Network unreachable'));

        $result = $this->check('module', $id, null);

        $this->assertSame((string) __('common.errors.github_api_failed'), $result['check_error'] ?? null);
    }

    /**
     * `{type}:update --source=github` 는 조회 실패 사유를 출력하고 종료 코드 1 이다(「업데이트 없음」 0 이 아니다).
     *
     * @scenario case=update_source_github_fails
     *
     * @effects update_source_github_exits_one_on_check_error
     *
     * @param  string  $type  확장 종류
     * @return void 반환값 없음
     */
    #[DataProvider('extensionTypes')]
    public function test_update_with_source_github_exits_one(string $type): void
    {
        $id = $this->makeFixtureWithGithubUrl($type);
        $this->fakeGithub(503);

        $exit = Artisan::call("{$type}:update", ['identifier' => $id, '--source' => 'github', '--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString($id, $output);
        $this->assertStringContainsString('503', $output);
        $this->assertStringNotContainsString((string) __("{$type}s.commands.update.no_update", [$type => $id]), $output, '조회 실패를 「업데이트 없음」으로 안내하면 안 된다');

        $dryRunExit = Artisan::call("{$type}:update", ['identifier' => $id, '--source' => 'github', '--dry-run' => true, '--json' => true]);
        $this->assertSame(1, $dryRunExit, '미리보기도 GitHub 명시 + 조회 실패면 1');
    }

    /**
     * 소스 자동이면 번들 판정을 그대로 쓰고 「GitHub 조회 실패, 번들 기준으로 판정」 경고를 한 줄 낸다.
     *
     * @scenario case=update_auto_falls_back_with_warning
     *
     * @effects update_auto_uses_bundled_with_warning
     *
     * @param  string  $type  확장 종류
     * @return void 반환값 없음
     */
    #[DataProvider('extensionTypes')]
    public function test_update_auto_uses_bundled_with_warning(string $type): void
    {
        $id = $this->makeFixtureWithGithubUrl($type);
        $this->writeBundledCopy($type, $id, '1.0.1');
        $this->fakeGithub(500);

        $result = $this->check($type, $id, null);
        $this->assertTrue($result['update_available']);
        $this->assertSame('bundled', $result['update_source']);
        $this->assertSame('1.0.1', $result['latest_version']);
        $this->assertArrayHasKey('check_error', $result);

        $exit = Artisan::call("{$type}:update", ['identifier' => $id, '--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString((string) __('extensions.commands.update.github_check_fallback', ['identifier' => $id, 'reason' => $result['check_error']]), $output);
    }

    /**
     * `{type}:check-updates` 는 조회 실패 표를 출력하고 종료 코드 1 이며, DB 의 업데이트 정보를 덮지 않는다(단건·전체).
     *
     * @scenario case=check_updates_fails_and_keeps_db
     *
     * @effects check_updates_exits_one_and_keeps_db_on_check_error
     *
     * @param  string  $type  확장 종류
     * @return void 반환값 없음
     */
    #[DataProvider('extensionTypes')]
    public function test_check_updates_exits_one_and_keeps_db(string $type): void
    {
        $id = $this->makeFixtureWithGithubUrl($type);
        $this->model($type)::query()->where('identifier', $id)->update(['update_available' => true, 'latest_version' => '9.9.9']);
        $this->fakeGithub(500);

        $single = Artisan::call("{$type}:check-updates", ['identifier' => $id]);
        $singleOutput = Artisan::output();

        $this->assertSame(1, $single, $singleOutput);
        $this->assertStringContainsString((string) __('extensions.commands.check_updates.failed_header', ['count' => 1]), $singleOutput);
        $this->assertDbUpdateInfo($type, $id, true, '9.9.9');

        $all = Artisan::call("{$type}:check-updates");
        $allOutput = Artisan::output();

        $this->assertSame(1, $all, $allOutput);
        $this->assertStringContainsString($id, $allOutput);
        $this->assertDbUpdateInfo($type, $id, true, '9.9.9');
    }

    /**
     * 조회에 실패했으면 번들에 더 새 판이 있어도 DB 의 업데이트 정보(`update_available`·`latest_version`·`update_source`)를
     * 바꾸지 않는다 — 번들 판은 출력에만 보인다(단건·전체).
     *
     * 번들 대체 판정을 DB 에 쓰면 지난 확인에서 알아 둔 GitHub 판 정보가 조회 실패 한 번으로 번들 판으로 바뀐다.
     *
     * @scenario case=check_updates_fails_with_newer_bundled
     *
     * @effects check_updates_exits_one_and_keeps_db_on_check_error
     *
     * @param  string  $type  확장 종류
     * @return void 반환값 없음
     */
    #[DataProvider('extensionTypes')]
    public function test_check_updates_failure_keeps_db_even_if_bundled_is_newer(string $type): void
    {
        $id = $this->makeFixtureWithGithubUrl($type);
        $this->writeBundledCopy($type, $id, '1.0.1');
        $this->model($type)::query()->where('identifier', $id)->update(['update_available' => false, 'latest_version' => '1.0.0', 'update_source' => 'github']);
        $this->fakeGithub(500);

        $single = Artisan::call("{$type}:check-updates", ['identifier' => $id]);
        $singleOutput = Artisan::output();

        $this->assertSame(1, $single, $singleOutput);
        $this->assertStringContainsString('1.0.1', $singleOutput, '번들 판은 출력으로 보인다');
        $this->assertDbUpdateInfo($type, $id, false, '1.0.0');
        $this->assertSame('github', $this->model($type)::query()->where('identifier', $id)->value('update_source'));

        $all = Artisan::call("{$type}:check-updates");
        $allOutput = Artisan::output();

        $this->assertSame(1, $all, $allOutput);
        $this->assertStringContainsString('1.0.1', $allOutput, '전체 확인 표에도 번들 판이 보인다');
        $this->assertDbUpdateInfo($type, $id, false, '1.0.0');
        $this->assertSame('github', $this->model($type)::query()->where('identifier', $id)->value('update_source'));
    }

    /**
     * 회귀 — 조회 성공과 릴리스 없음(404)은 `check_error` 없이 종료 코드 0 이다.
     *
     * @scenario case=successful_check_regression
     *
     * @effects successful_check_exits_zero
     *
     * @param  string  $type  확장 종류
     * @return void 반환값 없음
     */
    #[DataProvider('extensionTypes')]
    public function test_successful_or_no_release_check_exits_zero(string $type): void
    {
        $id = $this->makeFixtureWithGithubUrl($type);

        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.0', 'zipball_url' => null], 200)]);
        Http::preventStrayRequests();
        $this->assertArrayNotHasKey('check_error', $this->check($type, $id, null));
        $this->assertSame(0, Artisan::call("{$type}:check-updates", ['identifier' => $id]), Artisan::output());
        $this->assertSame(0, Artisan::call("{$type}:update", ['identifier' => $id, '--source' => 'github', '--no-interaction' => true]), Artisan::output());

        $this->fakeGithub(404);
        $this->assertArrayNotHasKey('check_error', $this->check($type, $id, null), '릴리스가 아직 없는 것은 조회 실패가 아니다');
        $this->assertSame(0, Artisan::call("{$type}:check-updates", ['identifier' => $id]), Artisan::output());
    }

    /**
     * 저장소 주소를 가진 픽스처 확장(활성 폴더 + DB 행, 판 1.0.0)을 만듭니다.
     *
     * @param  string  $type  확장 종류
     * @return string 식별자
     */
    private function makeFixtureWithGithubUrl(string $type): string
    {
        if ($type === 'template') {
            $this->makeFixtureTemplate();
            $this->writeFixtureTemplateManifest();
            $this->addGithubUrlToManifest(base_path("templates/{$this->fixtureTemplate}/template.json"), $this->fixtureTemplate);
            Template::query()->where('identifier', $this->fixtureTemplate)->update(['github_url' => 'https://github.com/acme/'.$this->fixtureTemplate]);
            app()->forgetInstance(TemplateManager::class);
            app(TemplateManager::class)->loadTemplates();

            return $this->fixtureTemplate;
        }

        if ($type === 'module') {
            $this->makeFixtureModule([]);
            $this->addGithubUrlToManifest(base_path("modules/{$this->fixtureModule}/module.json"), $this->fixtureModule);
            $this->reloadFixtureModuleManager();

            return $this->fixtureModule;
        }

        $this->makeFixturePlugin([]);
        $this->addGithubUrlToManifest(base_path("plugins/{$this->fixturePlugin}/plugin.json"), $this->fixturePlugin);
        $this->reloadFixturePluginManager();

        return $this->fixturePlugin;
    }

    /**
     * 매니페스트에 저장소 주소를 더합니다.
     *
     * @param  string  $manifestPath  매니페스트 경로
     * @param  string  $identifier  식별자
     * @return void 반환값 없음
     */
    private function addGithubUrlToManifest(string $manifestPath, string $identifier): void
    {
        $data = json_decode((string) File::get($manifestPath), true);
        $data['github_url'] = 'https://github.com/acme/'.$identifier;
        File::put($manifestPath, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        clearstatcache();
    }

    /**
     * 활성 폴더를 번들(`_bundled`)로 복사하고 판을 올립니다.
     *
     * @param  string  $type  확장 종류
     * @param  string  $identifier  식별자
     * @param  string  $version  번들 판
     * @return void 반환값 없음
     */
    private function writeBundledCopy(string $type, string $identifier, string $version): void
    {
        $bundled = base_path("{$type}s/_bundled/{$identifier}");
        $this->fixturePaths[] = $bundled;
        File::copyDirectory(base_path("{$type}s/{$identifier}"), $bundled);

        $manifest = $bundled.'/'.$type.'.json';
        $data = json_decode((string) File::get($manifest), true);
        $data['version'] = $version;
        File::put($manifest, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        clearstatcache();
    }

    /**
     * GitHub 릴리스 조회를 주어진 상태 코드로 고정하고 그 밖의 실제 요청을 막습니다.
     *
     * @param  int  $status  HTTP 상태 코드
     * @return void 반환값 없음
     */
    private function fakeGithub(int $status): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'fake'], $status)]);
        Http::preventStrayRequests();
    }

    /**
     * 종류의 관리자로 업데이트를 확인합니다.
     *
     * @param  string  $type  확장 종류
     * @param  string  $identifier  식별자
     * @param  string|null  $source  소스 지정
     * @return array<string, mixed> 확인 결과
     */
    private function check(string $type, string $identifier, ?string $source): array
    {
        return match ($type) {
            'module' => app(ModuleManager::class)->checkModuleUpdate($identifier, $source),
            'plugin' => app(PluginManager::class)->checkPluginUpdate($identifier, $source),
            'template' => app(TemplateManager::class)->checkTemplateUpdate($identifier, $source),
        };
    }

    /**
     * 종류의 모델 클래스.
     *
     * @param  string  $type  확장 종류
     * @return class-string<Module|Plugin|Template> 모델 클래스
     */
    private function model(string $type): string
    {
        return match ($type) {
            'module' => Module::class,
            'plugin' => Plugin::class,
            'template' => Template::class,
        };
    }

    /**
     * DB 의 업데이트 정보가 기대값인지 확인합니다.
     *
     * @param  string  $type  확장 종류
     * @param  string  $identifier  식별자
     * @param  bool  $available  기대 update_available
     * @param  string  $latest  기대 latest_version
     * @return void 반환값 없음
     */
    private function assertDbUpdateInfo(string $type, string $identifier, bool $available, string $latest): void
    {
        $row = $this->model($type)::query()->where('identifier', $identifier)->first();

        $this->assertNotNull($row);
        $this->assertSame($available, (bool) $row->update_available, '조회 실패가 DB 의 업데이트 여부를 덮었다');
        $this->assertSame($latest, $row->latest_version, '조회 실패가 DB 의 최신 판을 덮었다');
    }
}
