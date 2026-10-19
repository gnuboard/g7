<?php

namespace Plugins\Sirsoft\SocialLogin\Tests;

use App\Extension\HookManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\SocialLogin\Plugin;
use Plugins\Sirsoft\SocialLogin\Providers\SocialLoginServiceProvider;
use Tests\TestCase;

/**
 * 소셜 로그인 플러그인 테스트 베이스.
 *
 * 테스트 앱은 `plugins` 테이블의 활성 행으로 확장을 고르지 않으므로 서비스 프로바이더, 라우트, 훅 리스너를 여기서 직접
 * 등록한다. 라우트 접두·이름은 `PluginRouteServiceProvider` 와 같게 맞춘다 — 어긋나면 시험이 운영 주소와 다른 곳을 밟는다.
 * 데이터는 `DatabaseTransactions` 로 되돌린다(시험 DB 에 플러그인 마이그레이션을 미리 적용해 둔다).
 *
 * @since 1.0.0
 */
abstract class PluginTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var array{hooks: array, filters: array, dispatching: array}|null 훅 스냅샷 */
    private ?array $hookSnapshot = null;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(SocialLoginServiceProvider::class);
        $this->registerPluginRoutes();

        $this->snapshotHookManager();
        $this->registerPluginHookListeners();
    }

    /**
     * 테스트를 정리합니다 — 시험이 더한 훅을 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();

        parent::tearDown();
    }

    /**
     * 플러그인 웹·API 라우트를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginRoutes(): void
    {
        $base = dirname(__DIR__).'/src/routes';

        Route::prefix('plugins/sirsoft-social_login')
            ->name('web.plugins.sirsoft-social_login.')
            ->middleware('web')
            ->group($base.'/web.php');

        Route::prefix('api/plugins/sirsoft-social_login')
            ->name('api.plugins.sirsoft-social_login.')
            ->middleware('api')
            ->group($base.'/api.php');

        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    /**
     * plugin.php 의 리스너를 컨테이너로 만들어 등록합니다(생성자 주입 포함).
     *
     * @return void 반환값 없음
     */
    protected function registerPluginHookListeners(): void
    {
        $plugin = new Plugin([
            'identifier' => 'sirsoft-social_login',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '소셜 로그인', 'en' => 'Social Login'],
            'version' => '1.0.0',
        ]);

        foreach ($plugin->getHookListeners() as $listenerClass) {
            foreach ($listenerClass::getSubscribedHooks() as $hookName => $config) {
                $method = $config['method'] ?? 'handle';
                $priority = $config['priority'] ?? 10;
                $callback = fn (...$args) => app($listenerClass)->{$method}(...$args);

                if (($config['type'] ?? 'action') === 'filter') {
                    HookManager::addFilter($hookName, $callback, $priority);
                } else {
                    HookManager::addAction($hookName, $callback, $priority);
                }
            }
        }
    }

    /**
     * HookManager 상태를 저장합니다.
     *
     * @return void 반환값 없음
     */
    private function snapshotHookManager(): void
    {
        $ref = new \ReflectionClass(HookManager::class);
        $this->hookSnapshot = [
            'hooks' => $ref->getProperty('hooks')->getValue(),
            'filters' => $ref->getProperty('filters')->getValue(),
            'dispatching' => $ref->getProperty('dispatching')->getValue(),
        ];
    }

    /**
     * 저장한 시점으로 HookManager 를 되돌립니다.
     *
     * @return void 반환값 없음
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
     * 제공자 응답 픽스처를 읽습니다.
     *
     * @param  string  $name  파일 이름(확장자 제외)
     * @return array<string, mixed> 본문
     */
    protected function fixture(string $name): array
    {
        $path = dirname(__DIR__).'/tests/fixtures/provider-responses/'.$name.'.json';

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
