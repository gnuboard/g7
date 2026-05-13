<?php

namespace Plugins\Gnuboard7\HelloPlugin\Tests;

use App\Extension\HookManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Hello 플러그인 테스트 베이스 클래스
 *
 * 플러그인 오토로드, ServiceProvider 등록, 훅 리스너 수동 등록을 담당합니다.
 * 실 런타임에서는 PluginManager 가 처리하는 부분을 테스트 환경에서 대신 수행합니다.
 */
abstract class PluginTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * 마이그레이션 완료 플래그 (프로세스당 한 번만 실행)
     */
    protected static bool $migrated = false;

    /**
     * HookManager static state 스냅샷 — tearDown 에서 복원하여 테스트 간 훅 격리 보장.
     *
     * 테스트 내에서 `HookManager::addFilter()` / `addAction()` 으로 등록한 훅이
     * 다음 테스트로 누수되어 예상치 못한 콜백 호출 및 doAction::$dispatching 가드
     * 상태 leak 을 차단한다.
     *
     * @var array{hooks: array, filters: array, dispatching: array}|null
     */
    private ?array $hookSnapshot = null;

    /**
     * 플러그인 루트 경로를 반환합니다.
     *
     * @return string 플러그인 루트 절대 경로
     */
    protected function getPluginBasePath(): string
    {
        return dirname(__DIR__);
    }

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerPluginAutoload();

        $this->app->register(\Plugins\Gnuboard7\HelloPlugin\Providers\HelloPluginServiceProvider::class);

        $this->runCoreMigrationIfNeeded();

        $this->registerPluginHookListeners();

        // 훅 등록 완료 후 스냅샷 — tearDown 에서 이 시점으로 복원하여
        // 테스트 본문이 추가한 훅만 제거 (framework 및 setUp 훅은 보존)
        $this->snapshotHookManager();
    }

    /**
     * tearDown 에 HookManager 상태 복원.
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();

        parent::tearDown();
    }

    /**
     * HookManager static $hooks / $filters / $dispatching 를 스냅샷.
     */
    private function snapshotHookManager(): void
    {
        $ref = new \ReflectionClass(HookManager::class);
        $hooks = $ref->getProperty('hooks');
        $filters = $ref->getProperty('filters');
        $dispatching = $ref->getProperty('dispatching');

        $this->hookSnapshot = [
            'hooks' => $hooks->getValue(),
            'filters' => $filters->getValue(),
            'dispatching' => $dispatching->getValue(),
        ];
    }

    /**
     * 스냅샷 시점으로 HookManager 복원 — 테스트 내 추가된 훅만 제거.
     */
    private function restoreHookManager(): void
    {
        if ($this->hookSnapshot === null) {
            return;
        }

        $ref = new \ReflectionClass(HookManager::class);
        $ref->getProperty('hooks')->setValue(null, $this->hookSnapshot['hooks']);
        $ref->getProperty('filters')->setValue(null, $this->hookSnapshot['filters']);
        $ref->getProperty('dispatching')->setValue(null, $this->hookSnapshot['dispatching']);

        $this->hookSnapshot = null;
    }

    /**
     * 코어 마이그레이션이 필요한 경우 실행합니다.
     *
     * 플러그인 자체는 마이그레이션이 없지만 PluginSettingsService 등
     * 코어 기능을 호출하려면 기본 테이블들이 필요할 수 있습니다.
     */
    protected function runCoreMigrationIfNeeded(): void
    {
        if (static::$migrated) {
            return;
        }

        if (! Schema::hasTable('users') || ! Schema::hasTable('plugins')) {
            // 코어 + 모든 번들 확장 마이그레이션을 함께 실행
            // (Plugin suite 전체 실행 시 다른 플러그인의 테이블도 필요)
            $paths = ['database/migrations'];
            foreach (glob(base_path('modules/_bundled/*/database/migrations'), GLOB_ONLYDIR) as $p) {
                $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $p);
            }
            foreach (glob(base_path('plugins/_bundled/*/database/migrations'), GLOB_ONLYDIR) as $p) {
                $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $p);
            }
            $this->artisan('migrate', ['--path' => $paths]);
        }

        static::$migrated = true;
    }

    /**
     * 플러그인 오토로드를 등록합니다.
     */
    protected function registerPluginAutoload(): void
    {
        $pluginBasePath = $this->getPluginBasePath();

        spl_autoload_register(function ($class) use ($pluginBasePath) {
            $prefix = 'Plugins\\Gnuboard7\\HelloPlugin\\';
            $len = strlen($prefix);

            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $pluginBasePath.'/src/'.str_replace('\\', '/', $relativeClass).'.php';

            if (file_exists($file)) {
                require $file;
            }
        });
    }

    /**
     * 플러그인 훅 리스너를 HookManager 에 수동 등록합니다.
     *
     * 실 런타임에서는 PluginManager 가 자동 등록하지만 테스트 환경에서는
     * 수동으로 주입해 실제 훅 체인이 동작하도록 합니다.
     *
     * @return void
     */
    protected function registerPluginHookListeners(): void
    {
        $pluginClass = 'Plugins\\Gnuboard7\\HelloPlugin\\Plugin';

        if (! class_exists($pluginClass)) {
            require_once $this->getPluginBasePath().'/plugin.php';
        }

        $plugin = new $pluginClass([
            'identifier' => 'gnuboard7-hello_plugin',
            'vendor' => 'gnuboard7',
            'name' => ['ko' => 'Hello 플러그인', 'en' => 'Hello Plugin'],
            'version' => '0.1.0-beta.1',
        ]);

        foreach ($plugin->getHookListeners() as $listenerClass) {
            foreach ($listenerClass::getSubscribedHooks() as $hookName => $config) {
                $method = is_array($config) ? ($config['method'] ?? 'handle') : 'handle';
                $priority = is_array($config) ? ($config['priority'] ?? 10) : 10;
                $type = is_array($config) ? ($config['type'] ?? 'action') : 'action';

                $listenerInstance = new $listenerClass();
                $callback = [$listenerInstance, $method];

                if ($type === 'filter') {
                    HookManager::addFilter($hookName, $callback, $priority);
                } else {
                    HookManager::addAction($hookName, $callback, $priority);
                }
            }
        }
    }
}
