<?php

namespace Tests\Feature\Custom;

use App\Providers\CustomReservedServiceProvider;
use App\Support\Custom\CustomViewFinder;
use Composer\Autoload\ClassLoader;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Tests\TestCase;

/**
 * custom 예약 자리 연결 프로바이더 테스트 (#792 WP-K2 — W9·W10)
 *
 * `.html` 뷰는 custom 뷰에서만 파일 그대로 나가고 코어 뷰 해석은 바뀌지 않으며, `lang/custom`·
 * `database/migrations/custom`·개발자 프로바이더가 있을 때만 연결되는지 잠근다.
 */
class CustomReservedServiceProviderTest extends TestCase
{
    private string $tmpDir;

    private string $originalLangPath;

    private string $originalDatabasePath;

    /**
     * 임시 디렉터리와 원래 경로를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = storage_path('framework/testing/custom_provider_'.uniqid());
        File::ensureDirectoryExists($this->tmpDir);
        $this->originalLangPath = lang_path();
        $this->originalDatabasePath = database_path();
    }

    /**
     * 경로를 되돌리고 임시 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->app->useLangPath($this->originalLangPath);
        $this->app->useDatabasePath($this->originalDatabasePath);
        File::deleteDirectory($this->tmpDir);

        parent::tearDown();
    }

    /**
     * custom 뷰의 `.html` 은 파일 바이트 그대로 렌더되고, custom 이 아닌 뷰 이름은 `.html` 을 찾지 않습니다.
     *
     * @scenario case=html_view
     *
     * @effects html_view_served_raw, html_ignored_outside_custom
     *
     * @return void 반환값 없음
     */
    public function test_html_view_is_raw_and_custom_only(): void
    {
        $html = "<!doctype html>\n<html><body>{{ not_blade }} <?php echo 'no-php'; ?></body></html>\n";
        File::ensureDirectoryExists($this->tmpDir.'/views/custom/jq');
        File::put($this->tmpDir.'/views/custom/jq/index.html', $html);
        File::put($this->tmpDir.'/views/t792plain.html', '<p>plain</p>');
        View::addLocation($this->tmpDir.'/views');

        $this->assertInstanceOf(CustomViewFinder::class, View::getFinder());
        $this->assertSame($html, view('custom.jq.index')->render());

        $this->expectException(\InvalidArgumentException::class);
        view('t792plain')->render();
    }

    /**
     * 코어 셸 뷰(`app`·`admin`)는 여전히 blade 파일로 해석되고, 코어 뷰 폴더에는 `.html` 이 없습니다.
     *
     * @scenario case=core_views_unchanged
     *
     * @effects core_view_resolution_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_core_view_resolution_is_unchanged(): void
    {
        $this->assertStringEndsWith('app.blade.php', str_replace('\\', '/', View::getFinder()->find('app')));
        $this->assertStringEndsWith('admin.blade.php', str_replace('\\', '/', View::getFinder()->find('admin')));

        $htmlInCoreViews = array_filter(
            File::allFiles(resource_path('views')),
            fn (\SplFileInfo $f) => str_ends_with($f->getFilename(), '.html')
                && ! str_contains(str_replace('\\', '/', $f->getPathname()), '/resources/views/custom/'),
        );
        $this->assertSame([], array_values(array_map(fn ($f) => $f->getPathname(), $htmlInCoreViews)));
    }

    /**
     * `lang/custom`·`database/migrations/custom`·개발자 프로바이더가 있으면 연결됩니다.
     *
     * @scenario case=wiring_present
     *
     * @effects custom_lang_namespace_loaded, custom_migration_path_registered, developer_provider_registered_last
     *
     * @return void 반환값 없음
     */
    public function test_lang_migrations_and_developer_provider_are_wired(): void
    {
        File::ensureDirectoryExists($this->tmpDir.'/lang/custom/ko');
        File::put($this->tmpDir.'/lang/custom/ko/board.php', "<?php return ['title' => '개발자 게시판'];");
        File::ensureDirectoryExists($this->tmpDir.'/database/migrations/custom');
        $this->app->useLangPath($this->tmpDir.'/lang');
        $this->app->useDatabasePath($this->tmpDir.'/database');
        app()->setLocale('ko');

        $provider = new class($this->app) extends CustomReservedServiceProvider
        {
            /**
             * 시험용 개발자 프로바이더를 돌려줍니다.
             *
             * @return string 시험용 개발자 프로바이더 클래스 이름
             */
            protected function developerProviderClass(): string
            {
                return CustomReservedServiceProviderTestDeveloperProvider::class;
            }
        };
        $provider->boot();

        $this->assertSame('개발자 게시판', __('custom::board.title'));
        $this->assertContains($this->tmpDir.'/database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'custom', app('migrator')->paths());
        $this->assertTrue($this->app->bound('t792.developer_provider_booted'));
    }

    /**
     * 자리가 비어 있으면 아무것도 연결하지 않습니다.
     *
     * @scenario case=wiring_absent
     *
     * @effects missing_reserved_paths_are_noop
     *
     * @return void 반환값 없음
     */
    public function test_nothing_happens_when_paths_are_missing(): void
    {
        $this->app->useLangPath($this->tmpDir.'/lang');
        $this->app->useDatabasePath($this->tmpDir.'/database');
        $pathsBefore = app('migrator')->paths();

        (new CustomReservedServiceProvider($this->app))->boot();

        $this->assertSame($pathsBefore, app('migrator')->paths());
        $this->assertSame('custom::board.title', __('custom::board.title'));
    }

    /**
     * 개발자 composer 로더는 코어 로더 뒤에 붙습니다 — 기준 경로 추론과 같은 패키지의 우선순위가 코어를 따릅니다.
     *
     * Composer 의 `autoload.php` 를 그대로 읽으면 로더가 맨 앞에 붙어 `Application::inferBasePath()` 가 `custom/` 을
     * 가리켰다(설치 smoke 시험의 둘째 앱 생성 실패, 2026-10-17 실측).
     *
     * @scenario case=custom_autoload
     *
     * @effects custom_autoload_appended_after_core
     *
     * @return void 반환값 없음
     */
    public function test_custom_autoload_is_appended_after_core_loader(): void
    {
        $vendor = $this->tmpDir.DIRECTORY_SEPARATOR.'vendor';
        File::ensureDirectoryExists($vendor.'/composer');
        File::ensureDirectoryExists($this->tmpDir.'/src');
        File::put($this->tmpDir.'/src/Probe.php', "<?php namespace T792Custom; class Probe {}\n");
        File::put($vendor.'/composer/autoload_psr4.php', "<?php return ['T792Custom\\\\' => [".var_export($this->tmpDir.'/src', true).']];');
        $firstBefore = array_key_first(ClassLoader::getRegisteredLoaders());

        CustomReservedServiceProvider::registerCustomAutoload($vendor);
        CustomReservedServiceProvider::registerCustomAutoload($vendor);

        $loaders = ClassLoader::getRegisteredLoaders();
        $this->assertSame($firstBefore, array_key_first($loaders), '코어 로더가 맨 앞에 남는다');
        $this->assertSame($vendor, array_key_last($loaders), '개발자 로더는 뒤에 한 번만 붙는다');
        $this->assertSame(base_path(), Application::inferBasePath());
        $this->assertTrue(class_exists('T792Custom\\Probe'));
    }
}

/**
 * 시험용 개발자 프로바이더 — 등록·boot 되면 표식을 남긴다.
 */
class CustomReservedServiceProviderTestDeveloperProvider extends ServiceProvider
{
    /**
     * 표식을 남깁니다.
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        $this->app->instance('t792.developer_provider_booted', true);
    }
}
