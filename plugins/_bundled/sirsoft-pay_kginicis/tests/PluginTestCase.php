<?php

namespace Plugins\Sirsoft\PayKginicis\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    private static bool $pluginAutoloadRegistered = false;

    protected function shouldSeed(): bool
    {
        return true;
    }

    protected function seeder(): string
    {
        return \Modules\Sirsoft\Ecommerce\Database\Seeders\TestingSeeder::class;
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
        $this->registerPluginAutoload();

        parent::setUp();

        $this->registerModuleAutoload();

        $this->app->register(\Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider::class);
        $this->app->register(\Plugins\Sirsoft\PayKginicis\Providers\PayKginicisServiceProvider::class);
        $this->app['translator']->addNamespace('sirsoft-pay_kginicis', dirname(__DIR__).'/lang');

        $this->registerModuleRoutes();
        $this->registerPluginRoutes();

        // SettingsServiceProvider 가 storage/app/settings/general.json 의 site_url 로
        // app.url 을 override 하면 Laravel 의 assertRedirect (APP_URL 기반) 와 mismatch.
        // 테스트 환경에서는 APP_URL 그대로 사용하도록 명시 리셋.
        \Illuminate\Support\Facades\Config::set('app.url', env('APP_URL', 'http://localhost'));

        // BaseModuleServiceProvider::registerStorageBindings 가 ModuleManager 에서
        // 모듈 인스턴스를 조회해 StorageInterface 를 바인딩하는데, 테스트 환경의
        // ModuleManager 는 _bundled 스캔에서 sirsoft-ecommerce 를 자동 등록하지 못함.
        // 명시 등록으로 storage 의존 컨트롤러(상품/이미지 서비스 등)가 500 없이 동작.
        $this->registerEcommerceModuleInManager();
    }

    /**
     * 테스트 환경에서 sirsoft-ecommerce 모듈을 ModuleManager 에 명시 등록.
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
            $manager = $this->app->make(\App\Extension\ModuleManager::class);
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
            $file = $moduleBasePath . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        });

        $helpersFile = $moduleBasePath . 'Helpers/helpers.php';
        if (file_exists($helpersFile)) {
            require_once $helpersFile;
        }
    }

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
            $file = $pluginBasePath . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        }, true, true);

        self::$pluginAutoloadRegistered = true;
    }

    protected function registerModuleRoutes(): void
    {
        $apiRoutesFile = base_path('modules/sirsoft-ecommerce/src/routes/api.php');

        if (file_exists($apiRoutesFile)) {
            \Illuminate\Support\Facades\Route::prefix('api/modules/sirsoft-ecommerce')
                ->name('api.modules.sirsoft-ecommerce.')
                ->middleware('api')
                ->group($apiRoutesFile);
        }
    }

    protected function registerPluginRoutes(): void
    {
        $webRoutesFile = base_path('plugins/_bundled/sirsoft-pay_kginicis/src/routes/web.php');

        if (file_exists($webRoutesFile)) {
            \Illuminate\Support\Facades\Route::prefix('plugins/sirsoft-pay_kginicis')
                ->name('plugins.sirsoft-pay_kginicis.')
                ->middleware('web')
                ->group($webRoutesFile);
        }

        $apiRoutesFile = base_path('plugins/_bundled/sirsoft-pay_kginicis/src/routes/api.php');

        if (file_exists($apiRoutesFile)) {
            \Illuminate\Support\Facades\Route::prefix('api/plugins/sirsoft-pay_kginicis')
                ->name('api.plugins.sirsoft-pay_kginicis.')
                ->middleware('api')
                ->group($apiRoutesFile);
        }
    }

    protected function createAdminUser(array $permissions = []): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();

        $uniqueRoleIdentifier = 'admin-test-' . $user->id . '-' . time();
        $userRole = \App\Models\Role::create([
            'identifier' => $uniqueRoleIdentifier,
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
        ]);
        $user->roles()->attach($userRole->id);

        $adminAccessPermission = \App\Models\Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            [
                'name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'],
                'type' => \App\Enums\PermissionType::Admin,
            ]
        );
        $userRole->permissions()->attach($adminAccessPermission->id);

        if (! empty($permissions)) {
            foreach ($permissions as $permissionIdentifier) {
                $permission = \App\Models\Permission::firstOrCreate(
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

    protected function createUser(): \App\Models\User
    {
        $userRole = \App\Models\Role::where('identifier', 'user')->first();
        $user = \App\Models\User::factory()->create();

        if ($userRole) {
            $user->roles()->attach($userRole->id);
        }

        return $user;
    }
}
