<?php

namespace Plugins\Sirsoft\Tosspayments\Tests;

use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Database\Seeders\TestingSeeder;
use Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider;
use Tests\TestCase;

/**
 * 토스페이먼츠 플러그인 테스트 베이스 클래스
 *
 * 모든 토스페이먼츠 플러그인 테스트는 이 클래스를 상속받아야 합니다.
 * 모듈/플러그인 오토로드, ServiceProvider 등록, 마이그레이션, 라우트 등록을 자동으로 처리합니다.
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

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
        // RefreshDatabase 는 첫 테스트의 migrateFreshUsing 만 적용 — 모든 번들 확장
        // migrations 를 포함시켜 Plugin suite 전체 실행 시에도 스키마 보장.
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
     * HookManager static state 스냅샷 — tearDown 에서 복원하여 테스트 간 훅 격리 보장.
     *
     * @var array{hooks: array, filters: array, dispatching: array}|null
     */
    private ?array $hookSnapshot = null;

    /**
     * 오토로드·이커머스 서비스 프로바이더·라우트를 등록하고 앱 URL 을 APP_URL 로 되돌린 뒤 HookManager 상태를 스냅샷합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 이커머스 모듈 오토로드 등록
        $this->registerModuleAutoload();

        // 플러그인 오토로드 등록
        $this->registerPluginAutoload();

        // 이커머스 모듈 ServiceProvider 등록 (Repository 바인딩)
        $this->app->register(EcommerceServiceProvider::class);

        // 모듈 라우트를 수동으로 등록
        $this->registerModuleRoutes();

        // 플러그인 라우트를 수동으로 등록
        $this->registerPluginRoutes();
        $this->rebuildRouteNameLookups();

        // SettingsServiceProvider 가 storage/app/settings/general.json 의 site_url 로
        // app.url 을 override 하면 Laravel 의 assertRedirect (APP_URL 기반) 와 mismatch.
        // 테스트 환경에서는 APP_URL 그대로 사용하도록 명시 리셋.
        Config::set('app.url', env('APP_URL', 'http://localhost'));

        // HookManager 상태 스냅샷 (tearDown 에서 복원)
        $this->snapshotHookManager();
    }

    /**
     * setUp 때 찍어 둔 HookManager 상태를 복원한 뒤 부모 tearDown 을 실행합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();

        parent::tearDown();
    }

    /**
     * HookManager 의 static $hooks·$filters·$dispatching 값을 인스턴스 속성에 저장합니다.
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
     * 저장해 둔 스냅샷이 있으면 HookManager static 상태를 그 값으로 되돌리고 스냅샷을 비웁니다.
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

        // composer.json files 오토로드 (헬퍼 함수 등록)
        $helpersFile = $moduleBasePath.'Helpers/helpers.php';
        if (file_exists($helpersFile)) {
            require_once $helpersFile;
        }
    }

    /**
     * 이 테스트 파일이 속한 플러그인 디렉터리의 src 를 기준으로 Plugins\Sirsoft\Tosspayments\ 네임스페이스 오토로더를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginAutoload(): void
    {
        // 활성 디렉토리(plugins/sirsoft-tosspayments)가 아닌 자기 자신 기준 경로 —
        // _bundled 에서 직접 실행할 때도 소스를 찾도록 한다.
        $pluginBasePath = dirname(__DIR__).'/src/';

        spl_autoload_register(function ($class) use ($pluginBasePath) {
            $prefix = 'Plugins\\Sirsoft\\Tosspayments\\';
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
        });
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
     * 이 플러그인의 web 라우트를 web.plugins.sirsoft-tosspayments., api 라우트를 api.plugins.sirsoft-tosspayments. 이름 접두사로 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginRoutes(): void
    {
        $webRoutesFile = dirname(__DIR__).'/src/routes/web.php';

        if (file_exists($webRoutesFile)) {
            // 프로덕션 PluginRouteServiceProvider 와 동일한 'web.plugins.' 이름 접두사 —
            // 코어 self-gate 미들웨어가 라우트명(web.plugins.{id}.*)으로 타게팅하므로 정합 필수.
            Route::prefix('plugins/sirsoft-tosspayments')
                ->name('web.plugins.sirsoft-tosspayments.')
                ->middleware('web')
                ->group($webRoutesFile);
        }

        $apiRoutesFile = dirname(__DIR__).'/src/routes/api.php';

        if (file_exists($apiRoutesFile)) {
            // 프로덕션 PluginRouteServiceProvider 와 동일한 프리픽스·이름 규약을 따른다.
            Route::prefix('api/plugins/sirsoft-tosspayments')
                ->name('api.plugins.sirsoft-tosspayments.')
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
