<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests;

use App\Extension\HookListenerRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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

    /** 테스트 격리용 임시 plugins 스토리지 루트 (setUp 에서 생성, tearDown 에서 제거). */
    private ?string $isolatedPluginsRoot = null;

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

        $this->isolatePluginStorage();

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

    protected function tearDown(): void
    {
        $this->restorePluginStorage();

        parent::tearDown();
    }

    /**
     * 플러그인 스토리지('plugins' 디스크)를 테스트 전용 임시 디렉토리로 격리한다.
     *
     * 설정 저장 테스트(PluginSettingsService::save)는 코어 'plugins' 디스크
     * (root = storage_path('app/plugins'))에 setting.json 을 쓴다. 격리하지 않으면
     * 테스트가 실제 로컬 런타임 설정 파일을 덮어써 라이브 모드/자격증명이 오염된다
     * (RefreshDatabase 는 DB 만 롤백하고 파일시스템은 되돌리지 않음). 디스크 root 를
     * 임시 경로로 바꾸고 resolved 인스턴스를 purge 하여 실제 파일을 원천적으로 못
     * 건드리게 한다.
     */
    private function isolatePluginStorage(): void
    {
        // Laravel 이 테스트용 쓰기 공간으로 보장하는 storage/framework/testing 하위를 사용한다.
        // sys_get_temp_dir() 은 CI/컨테이너/open_basedir 제약 환경에서 위치가 다르거나
        // 쓰기 불가일 수 있어 프로젝트 내부의 격리 경로를 쓴다 (Laravel 규약 준수).
        $this->isolatedPluginsRoot = storage_path(
            'framework/testing/plugin-storage-'.uniqid('', true)
        );

        File::ensureDirectoryExists($this->isolatedPluginsRoot);

        config(['filesystems.disks.plugins.root' => $this->isolatedPluginsRoot]);

        // 이미 resolve 된 'plugins' 디스크 인스턴스를 폐기해 새 root 로 재생성되게 한다.
        Storage::forgetDisk('plugins');
    }

    /**
     * 격리 임시 디렉토리를 제거한다 (테스트 간 잔여 파일 격리).
     */
    private function restorePluginStorage(): void
    {
        if ($this->isolatedPluginsRoot !== null && is_dir($this->isolatedPluginsRoot)) {
            File::deleteDirectory($this->isolatedPluginsRoot);
        }

        $this->isolatedPluginsRoot = null;

        Storage::forgetDisk('plugins');
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
