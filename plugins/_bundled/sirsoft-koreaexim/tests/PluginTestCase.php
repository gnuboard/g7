<?php

namespace Plugins\Sirsoft\Koreaexim\Tests;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Extension\ExtensionMiddlewareRegistry;
use App\Extension\PluginManager;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Plugins\Sirsoft\Koreaexim\Plugin;
use Plugins\Sirsoft\Koreaexim\Providers\KoreaeximServiceProvider;

/** 의존 모듈의 격리를 재사용하고 기존 테스트 DB를 트랜잭션으로 보호합니다. */
abstract class PluginTestCase extends ModuleTestCase
{
    private string $pluginStorage;

    /**
     * 개발 DB 재생성 없이 트랜잭션으로 테스트를 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 각 테스트의 격리된 설정과 의존 객체를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->pluginStorage = storage_path('framework/testing/koreaexim-'.uniqid());
        File::ensureDirectoryExists($this->pluginStorage);
        config(['filesystems.disks.plugins.root' => $this->pluginStorage]);
        Storage::forgetDisk('plugins');
        $manager = app(PluginManager::class);
        $property = new \ReflectionProperty($manager, 'plugins');
        $plugins = $property->getValue($manager);
        $plugins['sirsoft-koreaexim'] = new Plugin;
        $property->setValue($manager, $plugins);
        $this->app->register(KoreaeximServiceProvider::class);
        $this->app['translator']->addNamespace('sirsoft-koreaexim', dirname(__DIR__).'/lang');
        $this->configure(['auto_manage' => true, 'auth_key' => 'fixture-key', 'notify_on_failure' => false]);
        Route::prefix('api/plugins/sirsoft-koreaexim')->name('api.plugins.sirsoft-koreaexim.')->middleware('api')->group(dirname(__DIR__).'/src/routes/api.php');
        // 설치 DB 대신 활성 확장 카탈로그만 격리하며 실제 선언 정규화·URI 매칭은 그대로 검증합니다.
        $registry = new class(app(CacheInterface::class)) extends ExtensionMiddlewareRegistry
        {
            /**
             * 테스트에 필요한 활성 확장만 반환합니다.
             *
             * @return iterable 처리 결과
             */
            protected function activeExtensions(): iterable
            {
                yield [new Plugin, 'sirsoft-koreaexim', 'plugin'];
            }
        };
        ExtensionMiddlewareRegistry::flush();
        $this->app->instance(ExtensionMiddlewareRegistryInterface::class, $registry);
    }

    /**
     * 환율 플러그인 테스트 설정을 적용합니다.
     *
     * @param  array  $values  values 입력값
     * @return void 반환값 없음
     */
    protected function configure(array $values): void
    {
        app(PluginSettingsService::class)->save('sirsoft-koreaexim', $values);
    }

    /**
     * 교차 환산에 필요한 고정 고시 환율을 반환합니다.
     *
     * @return array 처리 결과
     */
    protected function quotes(): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/koreaexim/success.json'), true);
    }

    /**
     * 테스트가 변경한 설정과 시간을 복구합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (isset($this->pluginStorage)) {
            File::deleteDirectory($this->pluginStorage);
            Storage::forgetDisk('plugins');
        }
        parent::tearDown();
    }
}
