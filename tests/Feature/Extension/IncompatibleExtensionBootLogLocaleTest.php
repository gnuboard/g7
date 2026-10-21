<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Extension\CoreVersionChecker;
use App\Extension\IncompatibleExtensionDeactivator;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\LanguagePack;
use App\Providers\LanguagePackServiceProvider;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePack\LanguagePackTranslator;
use App\Support\BootDeferredLog;
use App\Support\CoreVersionDiskReader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 부팅 중 자동 비활성화 기록의 언어 회귀 테스트.
 *
 * 판정은 `CoreServiceProvider::boot()` 에서 돌고, 활성 언어팩의 번역 경로는 `LanguagePackServiceProvider` 의
 * booted 콜백에서 등록된다. 예전에는 판정이 문구를 그 자리에서 만들어, 사이트 언어가 ja 여도 ja 번역이 아직 없어
 * 폴백(ko) 문구로 기록됐다. 이제 부팅 중 기록은 언어팩 등록이 끝난 뒤 한 번에 남긴다.
 *
 * 부팅 순간은 앱의 booted 표시를 잠시 내리고, 번역 경로가 아직 등록되지 않은 새 번역기로 재현한다.
 */
class IncompatibleExtensionBootLogLocaleTest extends TestCase
{
    use RefreshDatabase;

    private CacheInterface $cache;

    /**
     * 판정·알림 캐시를 비우고 현재 코어 버전을 디스크 버전과 같게 맞춥니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = app(CacheInterface::class);
        config()->set('app.version', CoreVersionDiskReader::shippedVersion());
        $this->forgetCaches();
    }

    /**
     * 테스트가 남긴 판정·알림 캐시와 부팅 기록 대기열을 지웁니다.
     *
     * 대기열은 프로세스 정적 상태라, 시험이 비우기(flush) 전에 실패하면 남은 항목이 다음 시험의 기록 횟수 단언에
     * 섞인다 — 남기지 않고 버린다(flush 로 비우면 tearDown 에서 실제 로그가 써진다).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        BootDeferredLog::resetForTesting();
        $this->forgetCaches();

        parent::tearDown();
    }

    /**
     * 판정 캐시(현재 버전 키)와 알림 캐시를 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function forgetCaches(): void
    {
        foreach (['modules', 'plugins', 'templates'] as $type) {
            $this->cache->forget(CoreVersionChecker::getCacheKey($type));
        }
        $this->cache->forget('ext.compatibility_alerts');
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
            // 설치본(lang-packs/g7-core-ja) 유무와 무관하게 번들 원본 디렉터리를 쓴다
            'source_type' => LanguagePackSourceType::BundledWithExtension->value,
            'source_url' => 'lang-packs/_bundled/g7-core-ja',
            'installed_at' => now(),
            'activated_at' => now(),
        ]);
    }

    /**
     * 언어팩 번역 경로가 아직 등록되지 않은 부팅 직후의 번역기로 바꾸고 실행 로케일을 정합니다.
     *
     * @param  string  $locale  실행 로케일 (사이트 언어)
     * @return void 반환값 없음
     */
    private function useTranslatorBeforePackRegistration(string $locale): void
    {
        $translator = new LanguagePackTranslator($this->app->make('translation.loader'), $locale);
        $translator->setFallback((string) config('app.fallback_locale'));
        $this->app->instance('translator', $translator);
        Lang::clearResolvedInstance('translator');
        $this->app->forgetInstance(LanguagePackRegistry::class);
        $this->app->setLocale($locale);
    }

    /**
     * 서비스 공급자 boot 단계(booted 콜백 이전)를 재현해 콜백을 실행합니다.
     *
     * @param  callable  $callback  부팅 중 실행할 코드
     * @return void 반환값 없음
     */
    private function duringProviderBoot(callable $callback): void
    {
        $booted = new ReflectionProperty(Application::class, 'booted');
        $booted->setValue($this->app, false);

        try {
            $callback();
        } finally {
            $booted->setValue($this->app, true);
        }
    }

    /**
     * 언어팩 공급자의 booted 콜백을 실행합니다 (앱이 booted 상태이므로 등록 즉시 실행된다).
     *
     * @return void 반환값 없음
     */
    private function runLanguagePackBootedCallback(): void
    {
        (new LanguagePackServiceProvider($this->app))->boot();
    }

    /**
     * ja 코어 언어팩 번들 원본의 extensions 문구를 읽습니다.
     *
     * @param  string  $path  점 표기 키 (예: warnings.auto_deactivate_blocked)
     * @param  array<string, string>  $replace  자리표시자 값
     * @return string ja 문구
     */
    private function jaMessage(string $path, array $replace = []): string
    {
        $messages = require base_path('lang-packs/_bundled/g7-core-ja/backend/ja/extensions.php');
        $message = data_get($messages, $path);
        foreach ($replace as $key => $value) {
            $message = str_replace(':'.$key, $value, $message);
        }

        return $message;
    }

    /**
     * 요구 코어 버전만 답하는 확장 대역을 만듭니다.
     *
     * @param  string  $required  요구 코어 버전 제약
     * @return object 확장 대역
     */
    private function extension(string $required): object
    {
        return new class($required)
        {
            /**
             * 요구 코어 버전을 담은 확장 대역을 만듭니다.
             *
             * @param  string  $required  요구 코어 버전 제약
             */
            public function __construct(private string $required) {}

            /**
             * 요구 코어 버전을 반환합니다.
             *
             * @return string 버전 제약
             */
            public function getRequiredCoreVersion(): string
            {
                return $this->required;
            }
        };
    }

    /**
     * config 캐시가 없다고 답하는 서비스를 만듭니다 (안전망 갈래에서 실제 config:clear 를 부르지 않기 위해).
     *
     * @return IncompatibleExtensionDeactivator 서비스
     */
    private function deactivator(): IncompatibleExtensionDeactivator
    {
        return new class($this->cache, app(TemplateRepositoryInterface::class)) extends IncompatibleExtensionDeactivator
        {
            /**
             * config 캐시가 없다고 답합니다.
             *
             * @return bool 항상 false
             */
            protected function isConfigurationCached(): bool
            {
                return false;
            }
        };
    }

    /**
     * 의존 확장 때문에 끄지 못하는 모듈 매니저 대역을 만듭니다.
     *
     * @return ModuleManager 매니저 대역
     */
    private function blockedModuleManager(): ModuleManager
    {
        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('getActiveModules')->andReturn(['sirsoft-page' => $this->extension('>=99.0.0')]);
        $manager->shouldReceive('deactivateModule')->andReturn([
            'success' => false,
            'warning' => true,
            'dependent_templates' => [['identifier' => 'sirsoft-basic']],
        ]);

        return $manager;
    }

    /**
     * 활성 ja 코어 언어팩이 있으면 부팅 중 남긴 「못 끔」·「자동 해제」 기록이 언어팩 등록 뒤 ja 문구로 한 번씩 남는다.
     *
     * @scenario case=boot_log_with_ja_pack
     *
     * @effects boot_time_logs_use_active_language_pack_translation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_boot_time_blocked_and_deactivated_logs_use_ja_pack(): void
    {
        $this->activateJaCorePack();
        $this->useTranslatorBeforePackRegistration('ja');
        Log::spy();

        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('getActivePlugins')->andReturn(['vendor-new' => $this->extension('>=99.0.0')]);
        $plugins->shouldReceive('deactivatePlugin')->andReturn(['success' => true, 'layouts_deleted' => 0]);

        $this->duringProviderBoot(function () use ($plugins) {
            $deactivator = $this->deactivator();
            $deactivator->deactivateIncompatibleExtensions($this->blockedModuleManager(), 'modules');
            $deactivator->deactivateIncompatibleExtensions($plugins, 'plugins');
        });

        Log::shouldNotHaveReceived('warning');

        $this->runLanguagePackBootedCallback();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === $this->jaMessage('warnings.auto_deactivate_blocked')
            && $context['identifier'] === 'sirsoft-page'
            && $context['dependents'] === ['sirsoft-basic'])->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === $this->jaMessage('warnings.auto_deactivated', ['type' => 'plugins', 'identifier' => 'vendor-new'])
            && $context['identifier'] === 'vendor-new')->once();
    }

    /**
     * 활성 ja 코어 언어팩이 있으면 부팅 중 안전망 「건너뜀」 기록도 ja 문구로 한 번만 남는다.
     *
     * @scenario case=boot_log_with_ja_pack
     *
     * @effects boot_time_logs_use_active_language_pack_translation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_boot_time_stale_version_log_uses_ja_pack(): void
    {
        $this->activateJaCorePack();
        $this->useTranslatorBeforePackRegistration('ja');
        config()->set('app.version', '7.0.0');
        Log::spy();

        $this->duringProviderBoot(function () {
            $deactivator = $this->deactivator();
            // 같은 부팅에서 두 번 판정해도(모듈·플러그인 순서와 같다) 안전망 기록은 한 번이다
            $deactivator->deactivateIncompatibleExtensions($this->blockedModuleManager(), 'modules');
            $deactivator->deactivateIncompatibleExtensions($this->blockedModuleManager(), 'modules');
        });

        $this->runLanguagePackBootedCallback();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === $this->jaMessage('warnings.auto_deactivate_skipped_stale_version')
            && $context['current'] === '7.0.0'
            && $context['config_cached'] === false)->once();
    }

    /**
     * 언어팩이 없으면 부팅 중 기록이 실행 로케일(ko·en) 문구로 정확히 한 번 남는다 — 다시 비워도 반복되지 않는다.
     *
     * @scenario case=boot_log_without_pack
     *
     * @effects boot_time_logs_written_once_without_language_pack
     *
     * @param  string  $locale  실행 로케일
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('bundledLocales')]
    public function test_boot_time_log_without_pack_uses_bundled_locale_once(string $locale): void
    {
        $this->useTranslatorBeforePackRegistration($locale);
        Log::spy();

        $this->duringProviderBoot(fn () => $this->deactivator()->deactivateIncompatibleExtensions($this->blockedModuleManager(), 'modules'));

        $this->runLanguagePackBootedCallback();
        $this->runLanguagePackBootedCallback();

        $expected = trans('extensions.warnings.auto_deactivate_blocked', [], $locale);
        $this->assertNotSame('extensions.warnings.auto_deactivate_blocked', $expected);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === $expected)->once();
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * 번들 로케일 목록.
     *
     * @return array<string, array{0: string}> 로케일
     */
    public static function bundledLocales(): array
    {
        return ['ko' => ['ko'], 'en' => ['en']];
    }

    /**
     * 언어팩 레지스트리가 준비되지 않아 언어팩 공급자가 일찍 돌아가는 갈래(마이그레이션 명령 등)에서도 기록이 사라지지 않는다.
     *
     * @scenario case=boot_log_registry_not_ready
     *
     * @effects boot_time_logs_written_once_without_language_pack
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_boot_time_log_is_written_when_language_pack_provider_returns_early(): void
    {
        $this->useTranslatorBeforePackRegistration('ko');
        Log::spy();

        $this->duringProviderBoot(fn () => $this->deactivator()->deactivateIncompatibleExtensions($this->blockedModuleManager(), 'modules'));

        $argv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'migrate'];
        try {
            $this->runLanguagePackBootedCallback();
        } finally {
            $_SERVER['argv'] = $argv;
        }

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === trans('extensions.warnings.auto_deactivate_blocked', [], 'ko'))->once();
    }

    /**
     * 부팅 중 버전 제약 해석 실패 기록도 언어팩 등록 뒤 ja 문구로 남는다 (같은 결함군).
     *
     * @scenario case=boot_log_with_ja_pack
     *
     * @effects boot_time_logs_use_active_language_pack_translation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_boot_time_version_check_failure_log_uses_ja_pack(): void
    {
        $this->activateJaCorePack();
        $this->useTranslatorBeforePackRegistration('ja');
        Log::spy();

        $this->duringProviderBoot(fn () => $this->assertFalse(CoreVersionChecker::satisfies('not a constraint')));

        $this->runLanguagePackBootedCallback();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === $this->jaMessage('errors.version_check_failed')
            && $context['constraint'] === 'not a constraint')->once();
    }

    /**
     * 「못 끔」 기록의 맥락 `reason` 도 본문과 같이 ja 문구로 남는다 — 매니저가 부팅 중 그 자리에서 만든 사유 문장(폴백 ko)을
     * 번역 키로 되짚어 기록 시점에 번역한다. 키로 되짚을 수 없는 사유(확장이 직접 만든 문장)는 그대로 남긴다.
     *
     * @scenario case=boot_log_with_ja_pack
     *
     * @effects boot_time_logs_use_active_language_pack_translation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_boot_time_blocked_log_reason_uses_ja_pack(): void
    {
        $this->activateJaCorePack();
        $this->useTranslatorBeforePackRegistration('ja');
        Log::spy();

        // 매니저는 사유 문장을 부팅 중 그 자리에서 만든다 — ja 번역 등록 전이라 폴백(ko) 문장이 된다
        $modules = Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('getActiveModules')->andReturn(['sirsoft-page' => $this->extension('>=99.0.0')]);
        $modules->shouldReceive('deactivateModule')->andReturnUsing(fn () => [
            'success' => false,
            'warning' => true,
            'dependent_templates' => [['identifier' => 'sirsoft-basic']],
            'message' => __('modules.warnings.has_dependents'),
            'layouts_deleted' => 0,
        ]);

        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('getActivePlugins')->andReturn([
            'vendor-gone' => $this->extension('>=99.0.0'),
            'vendor-custom' => $this->extension('>=99.0.0'),
        ]);
        $plugins->shouldReceive('deactivatePlugin')->andReturnUsing(fn (string $identifier) => [
            'success' => false,
            'layouts_deleted' => 0,
            'reason' => $identifier === 'vendor-gone'
                ? __('plugins.errors.not_found', ['plugin' => $identifier])
                : 'vendor-custom lifecycle refused',
        ]);

        $this->duringProviderBoot(function () use ($modules, $plugins) {
            $deactivator = $this->deactivator();
            $deactivator->deactivateIncompatibleExtensions($modules, 'modules');
            $deactivator->deactivateIncompatibleExtensions($plugins, 'plugins');
        });

        $this->runLanguagePackBootedCallback();

        $hasDependents = $this->jaLine('modules', 'warnings.has_dependents');
        $notFound = $this->jaLine('plugins', 'errors.not_found', ['plugin' => 'vendor-gone']);
        $this->assertNotSame(trans('modules.warnings.has_dependents', [], 'ko'), $hasDependents);
        $this->assertNotSame(trans('plugins.errors.not_found', ['plugin' => 'vendor-gone'], 'ko'), $notFound);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ($context['identifier'] ?? null) === 'sirsoft-page'
            && $context['reason'] === $hasDependents)->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ($context['identifier'] ?? null) === 'vendor-gone'
            && $context['reason'] === $notFound)->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ($context['identifier'] ?? null) === 'vendor-custom'
            && $context['reason'] === 'vendor-custom lifecycle refused')->once();
    }

    /**
     * ja 코어 언어팩 번들 원본의 문구를 파일·점 표기 키로 읽습니다.
     *
     * @param  string  $file  lang 파일 이름 (예: modules)
     * @param  string  $path  점 표기 키
     * @param  array<string, string>  $replace  자리표시자 값
     * @return string ja 문구
     */
    private function jaLine(string $file, string $path, array $replace = []): string
    {
        $message = data_get(require base_path("lang-packs/_bundled/g7-core-ja/backend/ja/{$file}.php"), $path);
        foreach ($replace as $key => $value) {
            $message = str_replace(':'.$key, $value, $message);
        }

        return $message;
    }

    /**
     * 시험 전용 비우기는 부팅 중 미뤄 둔 기록을 남기지 않고 버린다 — 중간에 실패한 시험의 대기열이 다음 시험으로 새지 않게 tearDown 이 부른다.
     *
     * @scenario case=boot_log_without_pack
     *
     * @effects boot_time_logs_written_once_without_language_pack
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_reset_for_testing_discards_pending_boot_logs_without_writing(): void
    {
        $this->useTranslatorBeforePackRegistration('ko');
        Log::spy();

        $this->duringProviderBoot(fn () => $this->deactivator()->deactivateIncompatibleExtensions($this->blockedModuleManager(), 'modules'));
        $this->assertSame(1, BootDeferredLog::pendingCount());

        BootDeferredLog::resetForTesting();

        $this->assertSame(0, BootDeferredLog::pendingCount());
        BootDeferredLog::flush();
        Log::shouldNotHaveReceived('warning');
    }
}
