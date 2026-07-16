<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests;

use App\Enums\ExtensionStatus;
use App\Enums\PermissionType;
use App\Extension\ExtensionMiddlewareRegistry;
use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Database\Seeders\TestingSeeder;
use Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider;
use Tests\TestCase;

abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    protected function shouldSeed(): bool
    {
        return true;
    }

    protected function seeder(): string
    {
        return TestingSeeder::class;
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => $this->shouldSeed(),
            '--seeder' => $this->seeder(),
            '--path' => [
                'database/migrations',
                'modules/sirsoft-ecommerce/database/migrations',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerModuleAutoload();
        $this->registerPluginAutoload();

        $this->app->register(EcommerceServiceProvider::class);

        $this->registerModuleRoutes();
        $this->registerPluginRoutes();

        // 코어 self-gate 미들웨어 실행을 위해 이 플러그인을 활성 상태로 등록한다
        // (getMiddleware() 선언이 게이트 인덱스에 수집되려면 getActivePlugins() 에 포함돼야 함).
        $this->activateSelfForMiddlewareGate('sirsoft-pay_nhnkcp', \Plugins\Sirsoft\PayNhnkcp\Plugin::class);

        // SettingsServiceProvider 가 storage/app/settings/general.json 의 site_url 로
        // app.url 을 override 하면 Laravel 의 assertRedirect (APP_URL 기반) 와 mismatch.
        // 테스트 환경에서는 APP_URL 그대로 사용하도록 명시 리셋.
        Config::set('app.url', env('APP_URL', 'http://localhost'));
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

            if (file_exists($file)) {
                require $file;
            }
        });

        $helpersFile = $moduleBasePath.'Helpers/helpers.php';
        if (file_exists($helpersFile)) {
            require_once $helpersFile;
        }
    }

    protected function registerPluginAutoload(): void
    {
        $pluginBasePath = base_path('plugins/sirsoft-pay_nhnkcp/src/');

        spl_autoload_register(function ($class) use ($pluginBasePath) {
            $prefix = 'Plugins\\Sirsoft\\Nhnkcp\\';
            $len = strlen($prefix);

            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $pluginBasePath.str_replace('\\', '/', $relativeClass).'.php';

            if (file_exists($file)) {
                require $file;
            }
        });
    }

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

    protected function registerPluginRoutes(): void
    {
        $webRoutesFile = base_path('plugins/sirsoft-pay_nhnkcp/src/routes/web.php');

        if (file_exists($webRoutesFile)) {
            // 프로덕션 PluginRouteServiceProvider 와 동일한 'web.plugins.' 이름 접두사 —
            // 코어 self-gate 미들웨어가 라우트명(web.plugins.{id}.*)으로 타게팅하므로 정합 필수.
            Route::prefix('plugins/sirsoft-pay_nhnkcp')
                ->name('web.plugins.sirsoft-pay_nhnkcp.')
                ->middleware('web')
                ->group($webRoutesFile);
        }

        $apiRoutesFile = base_path('plugins/sirsoft-pay_nhnkcp/src/routes/api.php');

        if (file_exists($apiRoutesFile)) {
            Route::prefix('api/plugins/sirsoft-pay_nhnkcp')
                ->name('api.plugins.sirsoft-pay_nhnkcp.')
                ->middleware('api')
                ->group($apiRoutesFile);
        }
    }

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
