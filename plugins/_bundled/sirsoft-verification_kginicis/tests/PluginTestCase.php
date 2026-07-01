<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests;

use App\Extension\HookListenerRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\VerificationKginicis\Listeners\AssertNoDuplicateInicisIdentity;
use Plugins\Sirsoft\VerificationKginicis\Listeners\CleanInicisRecordOnUserDelete;
use Plugins\Sirsoft\VerificationKginicis\Listeners\CleanInicisRecordOnUserWithdraw;
use Plugins\Sirsoft\VerificationKginicis\Listeners\CompleteInicisRecordAfterRegister;
use Plugins\Sirsoft\VerificationKginicis\Listeners\RegisterInicisProviderListener;
use Plugins\Sirsoft\VerificationKginicis\Listeners\ValidateInicisSettingsListener;
use Plugins\Sirsoft\VerificationKginicis\Providers\InicisVerificationServiceProvider;
use Tests\TestCase;

/**
 * KG이니시스 본인인증 플러그인 테스트 베이스.
 *
 * RefreshDatabase 로 매 테스트마다 DB 초기화 + plugin migration 포함.
 * ServiceProvider + plugin route 도 setUp 시점에 등록 — 코어 plugin 시스템이
 * 테스트 환경에서는 자동 부팅되지 않으므로 수동 등록 필수.
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    protected function migrateFreshUsing(): array
    {
        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => false,
            '--path' => [
                'database/migrations',
                'plugins/sirsoft-verification_kginicis/database/migrations',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerPluginAutoload();

        // 플러그인 lang 네임스페이스 등록 — 실 환경에서는 코어 TranslationServiceProvider::boot()
        // 의 loadPluginTranslations() 가 수행하나 테스트 환경에서는 미등록이므로 코어와 동일한
        // 경로로 register namespace 한다 (translator->getLoader()->addNamespace 직접 호출).
        // ServiceProvider boot(__()) 와 검증 attribute 해석이 가능하도록 register 보다 먼저 등록.
        $translator = $this->app['translator'];
        $translator->getLoader()->addNamespace(
            'sirsoft-verification_kginicis',
            base_path('plugins/sirsoft-verification_kginicis/lang'),
        );

        // 부팅 과정에서 plugin 네임스페이스 + 코어 validation 그룹이 namespace 등록/파일 준비 전에
        // 빈 값으로 캐시(loaded)되어 원본 키가 노출되므로 무효화한다. 무효화로 실제 파일을 다시
        // 읽도록 한 뒤 ServiceProvider 를 register 하여 boot 의 Lang::addLines(validation.attributes)
        // 가 채워진 validation 그룹 위에 얹히도록 순서를 보장한다.
        $loadedProp = new \ReflectionProperty($translator, 'loaded');
        $loadedProp->setAccessible(true);
        $loaded = $loadedProp->getValue($translator);
        unset($loaded['sirsoft-verification_kginicis']);
        unset($loaded['*']['validation']);
        $loadedProp->setValue($translator, $loaded);

        // validation 그룹을 실제 파일에서 먼저 로드시켜 캐시를 채운다.
        $translator->get('validation.required');

        $this->app->register(InicisVerificationServiceProvider::class);

        // ServiceProvider 의 validation.attributes 등록은 booted 콜백에서 수행되는데, 테스트
        // 환경의 캐시 무효화/등록 순서와 어긋날 수 있으므로 무효화 이후 시점에 동일 라벨을
        // 재적용한다. (실 환경에서는 booted 콜백이 plugin lang 준비 후 유효하므로 본 보정은 테스트 전용)
        foreach (['ko', 'en'] as $locale) {
            $translator->addLines([
                'validation.attributes.live_mid' => __('sirsoft-verification_kginicis::messages.settings.live_mid_attribute', [], $locale),
                'validation.attributes.live_api_key' => __('sirsoft-verification_kginicis::messages.settings.live_api_key_attribute', [], $locale),
            ], $locale);
        }

        $this->registerPluginRoutes();
        $this->registerPluginHookListeners();
    }

    /**
     * Plugin 의 hook listener 들을 코어 HookManager 에 등록.
     *
     * 실 환경: 코어 PluginManager 가 부팅 시 HookListenerRegistrar 를 호출.
     * 테스트 환경: plugin 이 미설치 상태이므로 수동 등록 필수.
     */
    protected function registerPluginHookListeners(): void
    {
        HookListenerRegistrar::register(
            RegisterInicisProviderListener::class,
            'plugin:sirsoft-verification_kginicis',
        );
        HookListenerRegistrar::register(
            CompleteInicisRecordAfterRegister::class,
            'plugin:sirsoft-verification_kginicis',
        );
        HookListenerRegistrar::register(
            CleanInicisRecordOnUserWithdraw::class,
            'plugin:sirsoft-verification_kginicis',
        );
        HookListenerRegistrar::register(
            CleanInicisRecordOnUserDelete::class,
            'plugin:sirsoft-verification_kginicis',
        );
        HookListenerRegistrar::register(
            AssertNoDuplicateInicisIdentity::class,
            'plugin:sirsoft-verification_kginicis',
        );
        HookListenerRegistrar::register(
            ValidateInicisSettingsListener::class,
            'plugin:sirsoft-verification_kginicis',
        );
    }

    /**
     * 활성 디렉토리(plugins/sirsoft-verification_kginicis/src/) PSR-4 자동 로드.
     */
    protected function registerPluginAutoload(): void
    {
        $base = base_path('plugins/sirsoft-verification_kginicis/src/');

        spl_autoload_register(function (string $class) use ($base): void {
            $prefix = 'Plugins\\Sirsoft\\VerificationKginicis\\';
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }
            $file = $base.str_replace('\\', '/', substr($class, $len)).'.php';
            if (file_exists($file)) {
                require $file;
            }
        });
    }

    /**
     * Plugin 라우트 등록 — 코어 PluginManager 의 자동 prefix 흉내.
     *
     * 실 환경: `/plugins/sirsoft-verification_kginicis/{path}` + 이름 prefix `web.plugins.sirsoft-verification_kginicis.`
     */
    protected function registerPluginRoutes(): void
    {
        $webRoutesFile = base_path('plugins/sirsoft-verification_kginicis/src/routes/web.php');

        if (file_exists($webRoutesFile)) {
            Route::prefix('plugins/sirsoft-verification_kginicis')
                ->name('web.plugins.sirsoft-verification_kginicis.')
                ->middleware('web')
                ->group($webRoutesFile);
        }
    }
}
