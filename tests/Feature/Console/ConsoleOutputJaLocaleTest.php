<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Traits\HasProgressBar;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Extension\HookCacheManager;
use App\Models\LanguagePack;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Providers\LanguagePackServiceProvider;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePack\LanguagePackTranslator;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use App\Support\BootDeferredLog;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 코어 명령 출력과 부팅 중 훅 캐시 경고가 사이트 언어(ja)를 따르는지 검증한다.
 *
 * 예전에는 레이아웃 갱신·컴파일·역변환·설치본 점검 명령의 출력과 진행 표시줄 첫 문구, 훅 캐시 폴백 경고가 코드에
 * 한국어로 적혀 있어 일본어 로케일에서도 한국어였다. 기대 문구는 ja 코어 언어팩 번들 원본에서 직접 읽고, ko 원문과
 * 다른지 함께 확인한다(번역이 빠져 폴백 ko 가 나오면 실패한다).
 */
class ConsoleOutputJaLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 시험이 만든 훅 캐시 임시 파일 경로.
     */
    private ?string $tmpHookCache = null;

    /**
     * 시험이 남긴 부팅 기록 대기열·훅 캐시 메모·임시 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        BootDeferredLog::resetForTesting();

        if ($this->tmpHookCache !== null) {
            (new ConsoleOutputJaLocaleHookCache($this->tmpHookCache))->clear();
            File::delete($this->tmpHookCache);
        }

        parent::tearDown();
    }

    /**
     * 번들 원본을 가리키는 활성 ja 코어 언어팩을 만듭니다.
     *
     * @return void 반환값 없음
     */
    private function activateJaCorePack(): void
    {
        LanguagePack::create([
            'identifier' => 'g7-core-ja',
            'vendor' => 'g7',
            'name' => 'G7 Core Japanese',
            'version' => '1.0.0',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'is_protected' => false,
            'manifest' => ['identifier' => 'g7-core-ja', 'scope' => 'core', 'locale' => 'ja'],
            'status' => LanguagePackStatus::Active->value,
            'source_type' => LanguagePackSourceType::BundledWithExtension->value,
            'source_url' => 'lang-packs/_bundled/g7-core-ja',
            'installed_at' => now(),
            'activated_at' => now(),
        ]);
    }

    /**
     * 언어팩 번역 경로가 아직 등록되지 않은 번역기로 바꾸고 실행 로케일을 정합니다.
     *
     * @param  string  $locale  실행 로케일
     * @return void 반환값 없음
     */
    private function useFreshTranslator(string $locale): void
    {
        $translator = new LanguagePackTranslator($this->app->make('translation.loader'), $locale);
        $translator->setFallback((string) config('app.fallback_locale'));
        $this->app->instance('translator', $translator);
        Lang::clearResolvedInstance('translator');
        $this->app->forgetInstance(LanguagePackRegistry::class);
        $this->app->setLocale($locale);
    }

    /**
     * 활성 ja 코어 언어팩을 번역기에 등록하고 실행 로케일을 ja 로 둡니다 (명령 실행 시점의 상태).
     *
     * @return void 반환값 없음
     */
    private function useJaLocale(): void
    {
        $this->activateJaCorePack();
        $this->useFreshTranslator('ja');
        (new LanguagePackServiceProvider($this->app))->boot();
    }

    /**
     * ja 코어 언어팩 번들 원본의 문구를 읽고, ko 원문과 다른지 확인합니다.
     *
     * @param  string  $file  lang 파일 이름 (예: modules)
     * @param  string  $path  점 표기 키
     * @param  array<string, string|int>  $replace  자리표시자 값
     * @return string ja 문구
     */
    private function ja(string $file, string $path, array $replace = []): string
    {
        $message = data_get(require base_path("lang-packs/_bundled/g7-core-ja/backend/ja/{$file}.php"), $path);
        $this->assertIsString($message, "ja 팩에 {$file}.{$path} 가 없습니다");

        foreach ($replace as $key => $value) {
            $message = str_replace(':'.$key, (string) $value, $message);
        }

        $this->assertNotSame(trans("{$file}.{$path}", $replace, 'ko'), $message, "{$file}.{$path} 의 ja 문구가 ko 와 같습니다");

        return $message;
    }

    /**
     * 모듈·플러그인·템플릿 레이아웃 갱신 명령의 진행·완료·요약 문구가 ja 로 나온다.
     *
     * @scenario surface=refresh_layout_commands
     *
     * @effects command_output_uses_site_locale
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_refresh_layout_commands_print_ja(): void
    {
        $this->useJaLocale();

        $modules = Mockery::mock(ModuleService::class);
        $modules->shouldReceive('refreshModuleLayouts')->with('probe-module')->andReturn(['layout_warnings' => []]);
        $this->app->instance(ModuleService::class, $modules);

        $plugins = Mockery::mock(PluginService::class);
        $plugins->shouldReceive('refreshPluginLayouts')->with('probe-plugin')->andReturn(['success' => true, 'created' => 1, 'updated' => 2, 'deleted' => 0]);
        $this->app->instance(PluginService::class, $plugins);

        $templates = Mockery::mock(TemplateService::class);
        $templates->shouldReceive('refreshTemplateLayouts')->with('probe-template')->andReturn(['layout_warnings' => []]);
        $this->app->instance(TemplateService::class, $templates);

        $this->artisan('module:refresh-layout', ['identifier' => 'probe-module'])
            ->expectsOutputToContain($this->ja('modules', 'commands.refresh_layout.refreshing', ['module' => 'probe-module']))
            ->expectsOutputToContain($this->ja('modules', 'commands.refresh_layout.success', ['module' => 'probe-module']))
            ->assertExitCode(0);

        $this->artisan('plugin:refresh-layout', ['identifier' => 'probe-plugin'])
            ->expectsOutputToContain($this->ja('plugins', 'commands.refresh_layout.success', ['plugin' => 'probe-plugin']))
            ->expectsOutputToContain($this->ja('plugins', 'commands.refresh_layout.stats', ['created' => 1, 'updated' => 2, 'deleted' => 0]))
            ->assertExitCode(0);

        $this->artisan('template:refresh-layout', ['identifier' => 'probe-template'])
            ->expectsOutputToContain($this->ja('templates', 'commands.refresh_layout.success', ['template' => 'probe-template']))
            ->assertExitCode(0);

        // 활성 확장이 없을 때의 안내
        Module::query()->where('status', 'active')->update(['status' => 'inactive']);
        Plugin::query()->where('status', 'active')->update(['status' => 'inactive']);
        Template::query()->where('status', 'active')->update(['status' => 'inactive']);

        $this->artisan('module:refresh-layout')->expectsOutputToContain($this->ja('modules', 'commands.refresh_layout.none_active'))->assertExitCode(0);
        $this->artisan('plugin:refresh-layout')->expectsOutputToContain($this->ja('plugins', 'commands.refresh_layout.none_active'))->assertExitCode(0);
        $this->artisan('template:refresh-layout')->expectsOutputToContain($this->ja('templates', 'commands.refresh_layout.none_active'))->assertExitCode(0);
    }

    /**
     * 레이아웃 컴파일·역변환·설치본 점검 명령의 안내·요약 문구가 ja 로 나온다.
     *
     * @scenario surface=layout_markup_commands
     *
     * @effects command_output_uses_site_locale
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_layout_commands_print_ja(): void
    {
        $this->useJaLocale();

        $this->artisan('layout:compile')
            ->expectsOutputToContain($this->ja('layouts', 'console.path_or_all'))
            ->assertExitCode(1);

        $this->artisan('layout:decompile', ['--all' => true, '--sync' => true, '--check' => true])
            ->expectsOutputToContain($this->ja('layouts', 'console.sync_check_exclusive'))
            ->assertExitCode(1);

        $missing = storage_path('framework/testing/r1-missing-'.uniqid().'.g7.html');
        $this->artisan('layout:decompile', ['path' => $missing])
            ->expectsOutputToContain($this->ja('layouts', 'console.file_not_found', ['file' => $missing]))
            ->expectsOutputToContain($this->ja('layouts', 'console.decompile_done', ['files' => 1, 'failed' => 1, 'warned' => 0]))
            ->assertExitCode(1);

        $this->artisan('layout:check-installed', ['--template' => 'r1-no-such-template'])
            ->expectsOutputToContain($this->ja('layouts', 'console.check_no_templates'))
            ->assertExitCode(0);
    }

    /**
     * 설치·업데이트·삭제 명령의 진행 표시줄 첫 문구가 ja 로 나온다.
     *
     * @scenario surface=progress_bar
     *
     * @effects command_output_uses_site_locale
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_progress_bar_start_message_is_ja(): void
    {
        $this->useJaLocale();

        $buffer = new BufferedOutput;
        $command = new class extends Command
        {
            use HasProgressBar;

            /**
             * 진행 표시줄을 시작하고 바로 끝냅니다.
             *
             * @return void 반환값 없음
             */
            public function startAndFinish(): void
            {
                $this->createProgressCallback(1);
                $this->finishProgress();
            }
        };
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $command->startAndFinish();

        $this->assertStringContainsString($this->ja('extensions', 'progress.starting'), $buffer->fetch());
    }

    /**
     * 부팅 중 훅 캐시 폴백 경고(구조 오류·형식·코어 버전 불일치)가 언어팩 등록 뒤 ja 문구로 남는다.
     *
     * @scenario surface=boot_hook_cache_warning
     *
     * @effects boot_time_hook_cache_warning_uses_active_language_pack_translation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_boot_time_hook_cache_fallback_warnings_use_ja_pack(): void
    {
        $this->activateJaCorePack();
        $this->useFreshTranslator('ja');
        Log::spy();

        $this->tmpHookCache = storage_path('framework/testing/r1-hooks-'.uniqid().'.php');
        File::ensureDirectoryExists(dirname($this->tmpHookCache));
        $manager = new ConsoleOutputJaLocaleHookCache($this->tmpHookCache);

        $booted = new ReflectionProperty(Application::class, 'booted');
        $booted->setValue($this->app, false);
        try {
            // clear() 는 요청 단위 메모와 함께 파일도 지운다 — 비운 뒤에 파일을 쓴다
            $manager->clear();
            File::put($this->tmpHookCache, "<?php return ['broken' => true];");
            $this->assertNull($manager->read());

            $manager->clear();
            File::put($this->tmpHookCache, "<?php return ['format' => 0, 'core_version' => '0.0.1', 'core' => [], 'modules' => [], 'plugins' => []];");
            $this->assertNull($manager->read());
        } finally {
            $booted->setValue($this->app, true);
        }

        Log::shouldNotHaveReceived('warning');

        (new LanguagePackServiceProvider($this->app))->boot();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === $this->ja('hooks', 'cache_invalid_structure')
            && $context['path'] === $this->tmpHookCache)->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === $this->ja('hooks', 'cache_stale')
            && $context['core_version'] === '0.0.1')->once();
    }
}

/**
 * 임시 경로를 쓰는 훅 캐시 관리자 (실제 bootstrap/cache/hooks.php 를 건드리지 않는다).
 */
class ConsoleOutputJaLocaleHookCache extends HookCacheManager
{
    /**
     * 임시 캐시 경로를 주입합니다.
     *
     * @param  string  $path  캐시 파일 경로
     */
    public function __construct(string $path)
    {
        $this->cacheFilePath = $path;
    }
}
