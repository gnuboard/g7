<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 활성·설치 확장 목록 요청 메모 (#860 W1)
 *
 * 한 요청 안에서 활성 목록을 수십 번 읽어도 캐시 저장소는 한 번만 조회해야 한다. 메모는 컨테이너
 * scoped 라 요청·큐 잡이 끝나면 버려지고, 기존 상태 캐시 무효화 함수가 같이 비운다.
 */
class ExtensionStatusMemoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 저장소 조회 횟수를 세는 캐시 드라이버
     */
    private CoreCacheDriver $spy;

    /**
     * 설치 완료 상태와 조회 계수 캐시를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.installer_completed' => true]);

        $this->spy = new class('array') extends CoreCacheDriver
        {
            /**
             * 키별 조회 횟수
             *
             * @var array<string, int>
             */
            public array $reads = [];

            /**
             * 조회를 세고 원래 동작을 수행합니다.
             *
             * @param  string  $key  캐시 키
             * @param  mixed  $default  기본값
             * @return mixed 캐시 값
             */
            public function get(string $key, mixed $default = null): mixed
            {
                $this->reads[$key] = ($this->reads[$key] ?? 0) + 1;

                return parent::get($key, $default);
            }
        };

        $this->app->instance(CacheInterface::class, $this->spy);
        $this->app->forgetScopedInstances();
        ModuleManager::invalidateModuleStatusCache();
        PluginManager::invalidatePluginStatusCache();
        TemplateManager::invalidateTemplateStatusCache();
        $this->spy->reads = [];
    }

    /**
     * 설치 완료 플래그를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        config(['app.installer_completed' => false]);

        parent::tearDown();
    }

    /**
     * 활성 모듈 행을 만듭니다.
     *
     * @param  string  $identifier  식별자
     * @param  ExtensionStatus  $status  상태
     * @return void 반환값 없음
     */
    private function makeModule(string $identifier, ExtensionStatus $status = ExtensionStatus::Active): void
    {
        Module::query()->where('identifier', $identifier)->delete();
        Module::factory()->create(['identifier' => $identifier, 'status' => $status->value]);
    }

    /**
     * 같은 요청에서 다섯 번 읽어도 저장소는 한 번만 조회합니다.
     *
     * @effects active_extension_identifiers_read_store_once_per_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 같은_요청의_반복_조회는_저장소를_한_번만_읽는다(): void
    {
        $this->makeModule('memo-test-a');

        for ($i = 0; $i < 5; $i++) {
            $ids = ModuleManager::getActiveModuleIdentifiers();
        }

        $this->assertContains('memo-test-a', $ids);
        $this->assertSame(1, $this->spy->reads['ext.modules.active_identifiers'] ?? 0);

        // 플러그인·템플릿 목록도 같은 메모를 쓴다
        Plugin::query()->where('identifier', 'memo-test-p')->delete();
        Plugin::factory()->create(['identifier' => 'memo-test-p', 'status' => ExtensionStatus::Active->value]);
        for ($i = 0; $i < 3; $i++) {
            PluginManager::getActivePluginIdentifiers();
            PluginManager::getInstalledPluginIdentifiers();
        }
        $this->assertSame(1, $this->spy->reads['ext.plugins.active_identifiers'] ?? 0);
        $this->assertSame(1, $this->spy->reads['ext.plugins.installed_identifiers'] ?? 0);
    }

    /**
     * 기존 상태 캐시 무효화가 그 유형의 메모도 비웁니다 — 같은 요청 뒤쪽이 새 상태를 봅니다.
     *
     * @effects memo_is_cleared_by_status_cache_invalidation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 상태_캐시_무효화가_메모도_비운다(): void
    {
        $this->makeModule('memo-test-a');
        $this->assertContains('memo-test-a', ModuleManager::getActiveModuleIdentifiers());

        Module::query()->where('identifier', 'memo-test-a')->update(['status' => ExtensionStatus::Inactive->value]);
        $this->makeModule('memo-test-b');

        // 무효화 전에는 같은 요청이 메모를 본다(설계 — 상태 쓰기 경로는 반드시 무효화를 부른다)
        $this->assertContains('memo-test-a', ModuleManager::getActiveModuleIdentifiers());

        ModuleManager::invalidateModuleStatusCache();

        $ids = ModuleManager::getActiveModuleIdentifiers();
        $this->assertNotContains('memo-test-a', $ids);
        $this->assertContains('memo-test-b', $ids);

        // 다른 유형의 메모는 남는다
        Plugin::query()->where('identifier', 'memo-test-p')->delete();
        Plugin::factory()->create(['identifier' => 'memo-test-p', 'status' => ExtensionStatus::Active->value]);
        PluginManager::invalidatePluginStatusCache();
        PluginManager::getActivePluginIdentifiers();
        $before = $this->spy->reads['ext.plugins.active_identifiers'] ?? 0;
        ModuleManager::invalidateModuleStatusCache();
        PluginManager::getActivePluginIdentifiers();
        $this->assertSame($before, $this->spy->reads['ext.plugins.active_identifiers'] ?? 0);
    }

    /**
     * 빈 목록은 메모하지 않습니다 — 「모든 확장 비활성」이 요청 내내 고착되지 않게.
     *
     * @effects empty_active_list_is_never_memoized
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 빈_목록은_메모하지_않는다(): void
    {
        Plugin::query()->update(['status' => ExtensionStatus::Inactive->value]);
        PluginManager::invalidatePluginStatusCache();

        $this->assertSame([], PluginManager::getActivePluginIdentifiers());

        // 무효화 없이 행이 생겨도 다음 조회가 바로 본다 (빈 목록은 캐시·메모 어디에도 남지 않는다)
        Plugin::query()->where('identifier', 'memo-test-p')->delete();
        Plugin::factory()->create(['identifier' => 'memo-test-p', 'status' => ExtensionStatus::Active->value]);

        $this->assertContains('memo-test-p', PluginManager::getActivePluginIdentifiers());
    }

    /**
     * 큐 워커가 잡마다 scoped 인스턴스를 버리면 다음 잡은 저장소를 다시 읽습니다.
     *
     * @effects scoped_memo_is_rebuilt_after_forget_scoped_instances
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function scoped_인스턴스를_버리면_다음_잡이_다시_읽는다(): void
    {
        $this->makeModule('memo-test-a');
        ModuleManager::getActiveModuleIdentifiers();
        ModuleManager::getActiveModuleIdentifiers();
        $this->assertSame(1, $this->spy->reads['ext.modules.active_identifiers'] ?? 0);

        // Laravel 큐 워커는 잡 사이에 forgetScopedInstances() 를 부른다
        $this->app->forgetScopedInstances();

        ModuleManager::getActiveModuleIdentifiers();
        $this->assertSame(2, $this->spy->reads['ext.modules.active_identifiers'] ?? 0);
    }

    /**
     * 설치 미완료·스키마 변경 명령 중에는 메모를 쓰지 않습니다.
     *
     * @effects memo_is_skipped_before_installer_completion
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 설치_미완료에서는_메모를_쓰지_않는다(): void
    {
        config(['app.installer_completed' => false]);
        $this->makeModule('memo-test-a');

        ModuleManager::getActiveModuleIdentifiers();
        ModuleManager::getActiveModuleIdentifiers();

        $this->assertSame(2, $this->spy->reads['ext.modules.active_identifiers'] ?? 0);
    }
}
