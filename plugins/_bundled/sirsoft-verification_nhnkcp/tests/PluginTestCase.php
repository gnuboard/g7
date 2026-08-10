<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests;

use App\Extension\HookListenerRegistrar;
use App\Extension\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\AssertNoDuplicateKcpIdentity;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\CleanKcpRecordOnUserDelete;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\CleanKcpRecordOnUserWithdraw;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\CompleteKcpRecordAfterRegister;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\RegisterKcpProviderListener;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\ValidateKcpSettingsListener;
use Plugins\Sirsoft\VerificationNhnkcp\Plugin;
use Plugins\Sirsoft\VerificationNhnkcp\Providers\KcpVerificationServiceProvider;
use Tests\TestCase;

/**
 * NHN KCP 휴대폰 본인확인 플러그인 테스트 베이스.
 *
 * RefreshDatabase 로 매 테스트마다 DB 초기화 + plugin migration 포함.
 * ServiceProvider / 라우트 / 훅 listener 도 setUp 에서 등록한다 — 코어 plugin 시스템은
 * 테스트 환경에서 자동 부팅되지 않으므로 수동 등록이 필요하다.
 *
 * _bundled 소스를 그대로 검사하도록 오토로드/마이그레이션/라우트 경로를 _bundled 기준으로 잡되,
 * 활성 디렉토리에 설치본이 있으면 그쪽을 우선한다 (실 환경 동형 검증).
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    /** 플러그인 식별자 */
    protected const PLUGIN_IDENTIFIER = 'sirsoft-verification_nhnkcp';

    /** 테스트 격리용 임시 plugins 스토리지 루트 (setUp 에서 생성, tearDown 에서 제거) */
    private ?string $isolatedPluginsRoot = null;

    /**
     * 플러그인 마이그레이션을 포함하여 DB 를 초기화한다.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        // 모든 번들 확장 migrations 포함 — 여러 확장 스위트를 한 프로세스에서 함께 돌릴 때
        // 가장 먼저 실행된 TestCase 가 스키마를 확정하므로, 자기 확장만 넘기면 뒤따르는
        // 확장의 테이블이 생성되지 않는다 (troubleshooting-backend.md 사례 21).
        $paths = ['database/migrations'];
        foreach (glob(base_path('modules/_bundled/*/database/migrations'), GLOB_ONLYDIR) as $p) {
            $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $p);
        }
        foreach (glob(base_path('plugins/_bundled/*/database/migrations'), GLOB_ONLYDIR) as $p) {
            $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $p);
        }

        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => false,
            '--path' => $paths,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolatePluginStorage();
        $this->registerPluginAutoload();

        // 플러그인 lang 네임스페이스 등록 — 실 환경에서는 코어 TranslationServiceProvider 가
        // 수행하나 테스트 환경에서는 미등록이므로 동일 경로로 직접 등록한다.
        $translator = $this->app['translator'];
        $translator->getLoader()->addNamespace(
            self::PLUGIN_IDENTIFIER,
            base_path($this->pluginRelativePath().'/lang'),
        );

        // 부팅 과정에서 plugin 네임스페이스 + 코어 validation 그룹이 준비 전에 빈 값으로
        // 캐시되어 원본 키가 노출되는 것을 막기 위해 무효화 후 실제 파일을 다시 읽힌다.
        $loadedProp = new \ReflectionProperty($translator, 'loaded');
        $loadedProp->setAccessible(true);
        $loaded = $loadedProp->getValue($translator);
        unset($loaded[self::PLUGIN_IDENTIFIER]);
        unset($loaded['*']['validation']);
        $loadedProp->setValue($translator, $loaded);

        $translator->get('validation.required');

        $this->app->register(KcpVerificationServiceProvider::class);

        // 검증 에러 메시지의 항목 라벨 — 실 환경에서는 요청 처리 시점 listener 가 등록하나,
        // 테스트 환경의 캐시 무효화 순서와 어긋날 수 있어 동일 라벨을 미리 적용한다.
        foreach (['ko', 'en'] as $locale) {
            $translator->addLines([
                'validation.attributes.live_site_cd' => __(self::PLUGIN_IDENTIFIER.'::messages.settings.live_site_cd_attribute', [], $locale),
                'validation.attributes.live_enc_key' => __(self::PLUGIN_IDENTIFIER.'::messages.settings.live_enc_key_attribute', [], $locale),
            ], $locale);
        }

        $this->registerPluginInstance();
        $this->registerPluginRoutes();
        $this->registerPluginHookListeners();
    }

    protected function tearDown(): void
    {
        $this->restorePluginStorage();

        parent::tearDown();
    }

    /**
     * 검사 대상 플러그인 경로 (프로젝트 루트 기준 상대경로).
     *
     * 활성 설치본이 있으면 그것을, 없으면 _bundled 소스를 사용한다.
     */
    protected function pluginRelativePath(): string
    {
        $active = 'plugins/'.self::PLUGIN_IDENTIFIER;

        return is_dir(base_path($active))
            ? $active
            : 'plugins/_bundled/'.self::PLUGIN_IDENTIFIER;
    }

    /**
     * 플러그인 스토리지('plugins' 디스크)를 테스트 전용 임시 디렉토리로 격리한다.
     *
     * 설정 저장 테스트가 실제 로컬 런타임 설정 파일을 덮어써 운영 모드/자격증명이 오염되는 것을
     * 막는다 (RefreshDatabase 는 DB 만 롤백하고 파일시스템은 되돌리지 않는다).
     */
    private function isolatePluginStorage(): void
    {
        $this->isolatedPluginsRoot = storage_path(
            'framework/testing/plugin-storage-'.uniqid('', true)
        );

        File::ensureDirectoryExists($this->isolatedPluginsRoot);

        config(['filesystems.disks.plugins.root' => $this->isolatedPluginsRoot]);

        Storage::forgetDisk('plugins');
    }

    /**
     * 격리 임시 디렉토리를 제거한다.
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
     * Plugin 의 hook listener 들을 코어 HookManager 에 등록한다.
     */
    protected function registerPluginHookListeners(): void
    {
        $source = 'plugin:'.self::PLUGIN_IDENTIFIER;

        foreach ([
            RegisterKcpProviderListener::class,
            CompleteKcpRecordAfterRegister::class,
            CleanKcpRecordOnUserWithdraw::class,
            CleanKcpRecordOnUserDelete::class,
            AssertNoDuplicateKcpIdentity::class,
            ValidateKcpSettingsListener::class,
        ] as $listener) {
            HookListenerRegistrar::register($listener, $source);
        }
    }

    /**
     * 플러그인 src/ PSR-4 오토로드를 등록한다.
     */
    protected function registerPluginAutoload(): void
    {
        $root = base_path($this->pluginRelativePath().'/');
        $base = $root.'src/';

        spl_autoload_register(function (string $class) use ($base, $root): void {
            $prefix = 'Plugins\\Sirsoft\\VerificationNhnkcp\\';
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, $len));

            // composer.json 의 psr-4 는 src/ 와 플러그인 루트를 함께 매핑한다 (Plugin 클래스는 plugin.php).
            foreach ([$base.$relative.'.php', $root.strtolower($relative).'.php'] as $file) {
                if (file_exists($file)) {
                    require $file;

                    return;
                }
            }
        });
    }

    /**
     * 코어 PluginManager 에 본 플러그인 인스턴스를 등록한다.
     *
     * PluginSettingsService::get()/save() 와 UpdatePluginSettingsRequest 는 PluginManager 가
     * 반환하는 플러그인 인스턴스(기본값/스키마)를 전제로 동작한다. 테스트 환경에서는 플러그인
     * 디스커버리가 돌지 않으므로 설치·활성 상태와 동형이 되도록 수동 등록한다.
     */
    protected function registerPluginInstance(): void
    {
        $manager = app(PluginManager::class);

        $property = new \ReflectionProperty($manager, 'plugins');
        $property->setAccessible(true);

        $plugins = $property->getValue($manager);
        $plugins[self::PLUGIN_IDENTIFIER] = new Plugin;
        $property->setValue($manager, $plugins);
    }

    /**
     * Plugin 라우트를 등록한다 — 코어 PluginManager 의 자동 prefix 를 흉내낸다.
     *
     * 실 환경: `/plugins/sirsoft-verification_nhnkcp/{path}` (web),
     *          `/api/plugins/sirsoft-verification_nhnkcp/{path}` (api)
     */
    protected function registerPluginRoutes(): void
    {
        $webRoutesFile = base_path($this->pluginRelativePath().'/src/routes/web.php');
        $apiRoutesFile = base_path($this->pluginRelativePath().'/src/routes/api.php');

        if (file_exists($webRoutesFile)) {
            Route::prefix('plugins/'.self::PLUGIN_IDENTIFIER)
                ->name('web.plugins.'.self::PLUGIN_IDENTIFIER.'.')
                ->middleware('web')
                ->group($webRoutesFile);
        }

        if (file_exists($apiRoutesFile)) {
            Route::prefix('api/plugins/'.self::PLUGIN_IDENTIFIER)
                ->name('api.plugins.'.self::PLUGIN_IDENTIFIER.'.')
                ->middleware('api')
                ->group($apiRoutesFile);
        }
    }
}
