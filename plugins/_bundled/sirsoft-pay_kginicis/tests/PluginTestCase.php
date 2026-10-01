<?php

namespace Plugins\Sirsoft\PayKginicis\Tests;

use App\Enums\ExtensionStatus;
use App\Enums\PermissionType;
use App\Extension\ExtensionMiddlewareRegistry;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Database\Seeders\TestingSeeder;
use Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider;
use Plugins\Sirsoft\PayKginicis\Providers\PayKginicisServiceProvider;
use Tests\TestCase;

abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    private static bool $pluginAutoloadRegistered = false;

    /**
     * 데이터베이스 초기화 뒤 시더를 실행하도록 true 를 돌려줍니다.
     *
     * @return bool 시더 실행 여부(항상 true)
     */
    protected function shouldSeed(): bool
    {
        return true;
    }

    /**
     * 데이터베이스 초기화 때 실행할 이커머스 테스트 시더 클래스를 돌려줍니다.
     *
     * @return string 시더 클래스 FQCN
     */
    protected function seeder(): string
    {
        return TestingSeeder::class;
    }

    /**
     * 코어와 모든 번들 모듈·플러그인의 마이그레이션 경로를 포함한 migrate:fresh 옵션을 만듭니다.
     *
     * @return array<string, mixed> migrate:fresh 명령 옵션
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
            '--seed' => $this->shouldSeed(),
            '--seeder' => $this->seeder(),
            '--path' => $paths,
        ];
    }

    /**
     * 오토로드·서비스 프로바이더·번역·라우트를 등록하고 플러그인을 활성화한 뒤 앱 URL 과 모듈 등록을 맞춥니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        $this->registerPluginAutoload();

        parent::setUp();

        $this->registerModuleAutoload();

        $this->app->register(EcommerceServiceProvider::class);
        $this->app->register(PayKginicisServiceProvider::class);
        $this->app['translator']->addNamespace('sirsoft-pay_kginicis', dirname(__DIR__).'/lang');

        $this->registerModuleRoutes();
        $this->registerPluginRoutes();
        $this->rebuildRouteNameLookups();

        // 코어 self-gate 미들웨어 실행을 위해 이 플러그인을 활성 상태로 등록한다
        // (getMiddleware() 선언이 게이트 인덱스에 수집되려면 getActivePlugins() 에 포함돼야 함).
        $this->activateSelfForMiddlewareGate('sirsoft-pay_kginicis', \Plugins\Sirsoft\PayKginicis\Plugin::class);

        // SettingsServiceProvider 가 storage/app/settings/general.json 의 site_url 로
        // app.url 을 override 하면 Laravel 의 assertRedirect (APP_URL 기반) 와 mismatch.
        // 테스트 환경에서는 APP_URL 그대로 사용하도록 명시 리셋.
        Config::set('app.url', env('APP_URL', 'http://localhost'));

        // BaseModuleServiceProvider::registerStorageBindings 가 ModuleManager 에서
        // 모듈 인스턴스를 조회해 StorageInterface 를 바인딩하는데, 테스트 환경의
        // ModuleManager 는 _bundled 스캔에서 sirsoft-ecommerce 를 자동 등록하지 못함.
        // 명시 등록으로 storage 의존 컨트롤러(상품/이미지 서비스 등)가 500 없이 동작.
        $this->registerEcommerceModuleInManager();
    }

    /**
     * 기준·주문 통화가 모두 KRW 인 통화 스냅샷을 돌려줍니다.
     *
     * @return array<string, mixed> KRW 통화 스냅샷
     */
    protected static function krwCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'base_unit' => 1,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1,
                ],
            ],
        ];
    }

    /**
     * 기준·주문 통화가 모두 JPY 인 통화 스냅샷을 돌려줍니다.
     *
     * @return array<string, mixed> JPY 통화 스냅샷
     */
    protected static function jpyCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'JPY',
            'order_currency' => 'JPY',
            'base_unit' => 1,
            'exchange_rates' => [
                'JPY' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1,
                ],
            ],
        ];
    }

    /**
     * 기준 통화는 USD, 주문 통화는 KRW 이고 KRW 환율이 0 이라 결제 금액을 환산할 수 없는 통화 스냅샷을 돌려줍니다.
     *
     * @return array<string, mixed> 환산 불가 KRW 통화 스냅샷
     */
    protected static function unchargeableKrwCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'USD',
            'order_currency' => 'KRW',
            'base_unit' => 1,
            'exchange_rates' => [
                'USD' => [
                    'rate' => 1,
                    'rounding_unit' => '0.01',
                    'rounding_method' => 'round',
                    'decimal_places' => 2,
                    'base_unit' => 1,
                ],
                'KRW' => [
                    'rate' => 0,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1,
                ],
            ],
        ];
    }

    /**
     * 통화 코드가 JPY 면 JPY 스냅샷을, 그 밖에는 KRW 스냅샷을 돌려줍니다.
     *
     * @param  string  $currency  통화 코드(대소문자 무관)
     * @return array<string, mixed> 해당 통화 스냅샷
     */
    protected static function currencySnapshotFor(string $currency): array
    {
        return strtoupper($currency) === 'JPY'
            ? self::jpyCurrencySnapshot()
            : self::krwCurrencySnapshot();
    }

    /**
     * 테스트 환경에서 sirsoft-ecommerce 모듈 인스턴스를 ModuleManager 에 명시 등록합니다.
     *
     * 모듈 클래스를 찾지 못하거나 등록 중 예외가 나면 조용히 건너뜁니다.
     *
     * @return void 반환값 없음
     */
    protected function registerEcommerceModuleInManager(): void
    {
        try {
            $moduleClass = '\\Modules\\Sirsoft\\Ecommerce\\Module';
            if (! class_exists($moduleClass)) {
                $moduleFile = base_path('modules/sirsoft-ecommerce/module.php');
                if (file_exists($moduleFile)) {
                    require_once $moduleFile;
                }
            }
            if (! class_exists($moduleClass)) {
                return;
            }
            $manager = $this->app->make(ModuleManager::class);
            $ref = new \ReflectionClass($manager);
            $prop = $ref->getProperty('modules');
            $prop->setAccessible(true);
            $modules = $prop->getValue($manager);
            if (! isset($modules['sirsoft-ecommerce'])) {
                $modules['sirsoft-ecommerce'] = new $moduleClass;
                $prop->setValue($manager, $modules);
            }
        } catch (\Throwable $e) {
            // ModuleManager 미바인딩 등 — 테스트 자체는 진행. 의존 컨트롤러만 영향.
        }
    }

    /**
     * sirsoft-ecommerce 모듈 네임스페이스 오토로더를 등록하고 모듈 헬퍼 파일을 불러옵니다.
     *
     * @return void 반환값 없음
     */
    protected function registerModuleAutoload(): void
    {
        $moduleBasePath = base_path('modules/sirsoft-ecommerce/src/');

        spl_autoload_register(function ($class) use ($moduleBasePath) {
            $prefix = 'Modules\\Sirsoft\\Ecommerce\\';
            $len = strlen($prefix);

            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $moduleBasePath.str_replace('\\', '/', $relativeClass).'.php';

            if (file_exists($file)
                && ! class_exists($class, false) && ! interface_exists($class, false)
                && ! trait_exists($class, false) && ! enum_exists($class, false)) {
                // 활성 디렉토리 사본이 이미 로드된 심볼을 다시 선언하면 fatal 이 된다 —
                // 선언 여부를 자체 확인하고 require_once 로 이중 방어한다
                require_once $file;
            }
        });

        $helpersFile = $moduleBasePath.'Helpers/helpers.php';
        if (file_exists($helpersFile)) {
            require_once $helpersFile;
        }
    }

    /**
     * 이 플러그인 네임스페이스 오토로더를 프로세스당 한 번만 오토로드 스택 맨 앞에 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginAutoload(): void
    {
        if (self::$pluginAutoloadRegistered) {
            return;
        }

        $pluginBasePath = dirname(__DIR__).'/src/';

        spl_autoload_register(function ($class) use ($pluginBasePath) {
            $prefix = 'Plugins\\Sirsoft\\PayKginicis\\';
            $len = strlen($prefix);

            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $pluginBasePath.str_replace('\\', '/', $relativeClass).'.php';

            if (file_exists($file)
                && ! class_exists($class, false) && ! interface_exists($class, false)
                && ! trait_exists($class, false) && ! enum_exists($class, false)) {
                // 활성 디렉토리 사본이 이미 로드된 심볼을 다시 선언하면 fatal 이 된다 —
                // 선언 여부를 자체 확인하고 require_once 로 이중 방어한다
                require_once $file;
            }
        }, true, true);

        self::$pluginAutoloadRegistered = true;
    }

    /**
     * sirsoft-ecommerce 모듈 API 라우트를 api.modules.sirsoft-ecommerce. 이름 접두사로 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function registerModuleRoutes(): void
    {
        $apiRoutesFile = base_path('modules/sirsoft-ecommerce/src/routes/api.php');

        if (file_exists($apiRoutesFile)) {
            Route::prefix('api/modules/sirsoft-ecommerce')
                ->name('api.modules.sirsoft-ecommerce.')
                ->middleware('api')
                ->group($apiRoutesFile);
        }
    }

    /**
     * 이 플러그인을 활성 상태로 등록해 코어 self-gate 미들웨어가 실행되도록 합니다.
     *
     * 프로덕션에서는 PluginManager 가 활성 플러그인 인스턴스를 보유하고 plugins 테이블의
     * status='active' 를 캐시한다. RefreshDatabase 테스트에는 둘 다 없으므로,
     * 라우트명 self-gate 타게팅(web.plugins.{id}.*)이 동작하도록 (1) plugins active 행 삽입
     * (2) PluginManager 인스턴스 등록 후 상태 캐시·미들웨어 인덱스를 무효화한다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  class-string  $pluginClass  플러그인 클래스 FQCN
     * @return void 반환값 없음
     */
    protected function activateSelfForMiddlewareGate(string $identifier, string $pluginClass): void
    {
        Plugin::query()->updateOrCreate(
            ['identifier' => $identifier],
            [
                'vendor' => 'sirsoft',
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
            ]
        );
        PluginManager::invalidatePluginStatusCache();

        $pluginManager = $this->app->make(PluginManager::class);
        $property = new \ReflectionProperty($pluginManager, 'plugins');
        $property->setAccessible(true);
        $plugins = $property->getValue($pluginManager);
        $plugins[$identifier] = new $pluginClass;
        $property->setValue($pluginManager, $plugins);

        ExtensionMiddlewareRegistry::flush();
    }

    /**
     * 이 플러그인의 web·api 라우트를 프로덕션과 같은 이름 접두사로 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginRoutes(): void
    {
        $webRoutesFile = base_path('plugins/_bundled/sirsoft-pay_kginicis/src/routes/web.php');

        if (file_exists($webRoutesFile)) {
            // 프로덕션 PluginRouteServiceProvider 와 동일한 'web.plugins.' 이름 접두사 —
            // 코어 self-gate 미들웨어가 라우트명(web.plugins.{id}.*)으로 타게팅하므로 정합 필수.
            Route::prefix('plugins/sirsoft-pay_kginicis')
                ->name('web.plugins.sirsoft-pay_kginicis.')
                ->middleware('web')
                ->group($webRoutesFile);
        }

        $apiRoutesFile = base_path('plugins/_bundled/sirsoft-pay_kginicis/src/routes/api.php');

        if (file_exists($apiRoutesFile)) {
            Route::prefix('api/plugins/sirsoft-pay_kginicis')
                ->name('api.plugins.sirsoft-pay_kginicis.')
                ->middleware('api')
                ->group($apiRoutesFile);
        }
    }

    /**
     * 런타임에 등록한 라우트의 이름·액션 조회표를 다시 만듭니다.
     *
     * fluent `->name()` 은 컬렉션에 추가된 **뒤** 이름을 붙이므로 추가 시점의 조회표에 오르지 않는다.
     * 라우트 캐시가 있는 머신의 `CompiledRouteCollection` 은 `refreshNameLookups()` 가 이 몫을 채우지
     * 못하므로 새 `RouteCollection` 에 옮겨 담는다.
     *
     * @return void 반환값 없음
     */
    protected function rebuildRouteNameLookups(): void
    {
        $routes = Route::getRoutes();

        if ($routes instanceof CompiledRouteCollection) {
            $rebuilt = new RouteCollection;
            foreach ($routes->getRoutes() as $route) {
                $rebuilt->add($route);
            }
            Route::setRoutes($rebuilt);

            return;
        }

        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }

    /**
     * 관리자 접근 권한과 지정 권한을 가진 전용 역할을 붙인 사용자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  추가로 부여할 권한 식별자 목록
     * @return User 생성된 관리자 사용자
     */
    protected function createAdminUser(array $permissions = []): User
    {
        $user = User::factory()->create();

        $uniqueRoleIdentifier = 'admin-test-'.$user->id.'-'.time();
        $userRole = Role::create([
            'identifier' => $uniqueRoleIdentifier,
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
        ]);
        $user->roles()->attach($userRole->id);

        $adminAccessPermission = Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            [
                'name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'],
                'type' => PermissionType::Admin,
            ]
        );
        $userRole->permissions()->attach($adminAccessPermission->id);

        if (! empty($permissions)) {
            foreach ($permissions as $permissionIdentifier) {
                $permission = Permission::firstOrCreate(
                    ['identifier' => $permissionIdentifier],
                    [
                        'name' => ['ko' => $permissionIdentifier, 'en' => $permissionIdentifier],
                        'type' => 'admin',
                    ]
                );
                $userRole->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }

        return $user;
    }

    /**
     * 사용자를 만들고 user 역할이 있으면 붙입니다.
     *
     * @return User 생성된 일반 사용자
     */
    protected function createUser(): User
    {
        $userRole = Role::where('identifier', 'user')->first();
        $user = User::factory()->create();

        if ($userRole) {
            $user->roles()->attach($userRole->id);
        }

        return $user;
    }
}
