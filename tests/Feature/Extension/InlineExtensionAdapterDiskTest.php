<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\StorageInterface;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\BaseModuleServiceProvider;
use App\Extension\BasePluginServiceProvider;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * 확장 인스턴스가 아직 적재되지 않았을 때의 폴백 저장소 디스크 (#916 A6·D8)
 *
 * 서비스 프로바이더는 저장소를 요청 시점에 확장 관리자에서 찾는다. 관리자에 그 확장이 아직 없으면(관리자를 새로 만든 콘솔·큐 문맥,
 * 적재 전 해석) 폴백 어댑터가 저장소를 직접 만드는데, 그때 디스크가 정상 경로(`modules`·`plugins`)와 달리 드라이버 생성자 기본값
 * `local` 이었다 — 같은 서비스가 문맥에 따라 다른 디스크에 파일을 썼다. 폴백도 정상 경로와 같은 디스크·같은 비공개 첨부 저장소 규칙을 따른다.
 */
class InlineExtensionAdapterDiskTest extends TestCase
{
    /**
     * 확장 관리자에 확장이 없는 상태에서 모듈 프로바이더의 폴백 저장소를 해석합니다.
     *
     * @param  string  $category  `getStorageFor()` 카테고리(null 이면 `getStorage()`)
     * @return StorageInterface 저장소
     */
    private function moduleFallbackStorage(?string $category = null): StorageInterface
    {
        $this->app->instance(ModuleManager::class, Mockery::mock(ModuleManager::class)->shouldReceive('getModule')->andReturnNull()->getMock());
        $provider = new class($this->app) extends BaseModuleServiceProvider
        {
            protected string $moduleIdentifier = 'fx916-shop';

            /**
             * 폴백 어댑터를 그대로 돌려줍니다.
             *
             * @return mixed 어댑터
             */
            public function adapter(): mixed
            {
                $this->extensionIdentifier = $this->moduleIdentifier;

                return $this->resolveExtension();
            }
        };
        $adapter = $provider->adapter();

        return $category === null ? $adapter->getStorage() : $adapter->getStorageFor($category);
    }

    /**
     * 확장 관리자에 확장이 없는 상태에서 플러그인 프로바이더의 폴백 저장소를 해석합니다.
     *
     * @param  string  $category  `getStorageFor()` 카테고리(null 이면 `getStorage()`)
     * @return StorageInterface 저장소
     */
    private function pluginFallbackStorage(?string $category = null): StorageInterface
    {
        $this->app->instance(PluginManager::class, Mockery::mock(PluginManager::class)->shouldReceive('getPlugin')->andReturnNull()->getMock());
        $provider = new class($this->app) extends BasePluginServiceProvider
        {
            protected string $pluginIdentifier = 'fx916-pay';

            /**
             * 폴백 어댑터를 그대로 돌려줍니다.
             *
             * @return mixed 어댑터
             */
            public function adapter(): mixed
            {
                $this->extensionIdentifier = $this->pluginIdentifier;

                return $this->resolveExtension();
            }
        };
        $adapter = $provider->adapter();

        return $category === null ? $adapter->getStorage() : $adapter->getStorageFor($category);
    }

    /**
     * 모듈 폴백 저장소는 정상 경로와 같은 `modules` 디스크다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=inline_adapter_fallback
     *
     * @effects inline_adapter_fallback_uses_extension_base_disk
     */
    public function test_module_fallback_uses_modules_disk(): void
    {
        $this->assertSame('modules', $this->moduleFallbackStorage()->getDisk());
        $this->assertSame('modules', $this->moduleFallbackStorage('attachments')->getDisk());
    }

    /**
     * 플러그인 폴백 저장소는 정상 경로와 같은 `plugins` 디스크다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=inline_adapter_fallback
     *
     * @effects inline_adapter_fallback_uses_extension_base_disk
     */
    public function test_plugin_fallback_uses_plugins_disk(): void
    {
        $this->assertSame('plugins', $this->pluginFallbackStorage()->getDisk());
    }

    /**
     * 폴백도 비공개 첨부 저장소 바인딩 키는 관리자가 고른 디스크를 따른다(정의되지 않은 디스크면 기본).
     *
     * @return void 반환값 없음
     *
     * @scenario case=inline_adapter_fallback
     *
     * @effects inline_adapter_private_attachment_binding_follows_selection
     */
    public function test_fallback_private_attachment_binding_follows_selection(): void
    {
        Config::set('filesystems.disks.fx916-private', ['driver' => 'local', 'root' => storage_path('framework/testing/fx916-private')]);
        Config::set('core.storage.private_attachment_disk', 'fx916-private');

        $this->assertSame('fx916-private', $this->moduleFallbackStorage(AbstractModule::PRIVATE_ATTACHMENT_BINDING)->getDisk());
        $this->assertSame('fx916-private', $this->pluginFallbackStorage(AbstractPlugin::PRIVATE_ATTACHMENT_BINDING)->getDisk());

        Config::set('core.storage.private_attachment_disk', 'fx916-gone');
        $this->assertSame('modules', $this->moduleFallbackStorage(AbstractModule::PRIVATE_ATTACHMENT_BINDING)->getDisk());
    }
}
