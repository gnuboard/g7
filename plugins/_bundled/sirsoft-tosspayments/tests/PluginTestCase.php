<?php

namespace Plugins\Sirsoft\Tosspayments\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * 시딩 활성화
     *
     * @return bool
     */
    protected function shouldSeed(): bool
    {
        return true;
    }

    /**
     * 테스트용 시더 클래스
     *
     * @return string
     */
    protected function seeder(): string
    {
        return \Modules\Sirsoft\Ecommerce\Database\Seeders\TestingSeeder::class;
    }

    /**
     * 마이그레이션 경로를 반환합니다.
     *
     * @return array
     */
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

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 이커머스 모듈 오토로드 등록
        $this->registerModuleAutoload();

        // 플러그인 오토로드 등록
        $this->registerPluginAutoload();

        // 이커머스 모듈 ServiceProvider 등록 (Repository 바인딩)
        $this->app->register(\Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider::class);

        // 모듈 라우트를 수동으로 등록
        $this->registerModuleRoutes();

        // 플러그인 라우트를 수동으로 등록
        $this->registerPluginRoutes();
    }

    /**
     * 이커머스 모듈 오토로드를 등록합니다.
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
            $file = $moduleBasePath . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        });

        // composer.json files 오토로드 (헬퍼 함수 등록)
        $helpersFile = $moduleBasePath . 'Helpers/helpers.php';
        if (file_exists($helpersFile)) {
            require_once $helpersFile;
        }
    }

    /**
     * 플러그인 오토로드를 등록합니다.
     */
    protected function registerPluginAutoload(): void
    {
        $pluginBasePath = base_path('plugins/sirsoft-tosspayments/src/');

        spl_autoload_register(function ($class) use ($pluginBasePath) {
            $prefix = 'Plugins\\Sirsoft\\Tosspayments\\';
            $len = strlen($prefix);

            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $pluginBasePath . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        });
    }

    /**
     * 모듈 라우트를 등록합니다.
     */
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

    /**
     * 플러그인 라우트를 등록합니다.
     */
    protected function registerPluginRoutes(): void
    {
        $webRoutesFile = base_path('plugins/sirsoft-tosspayments/src/routes/web.php');

        if (file_exists($webRoutesFile)) {
            \Illuminate\Support\Facades\Route::prefix('plugins/sirsoft-tosspayments')
                ->name('plugins.sirsoft-tosspayments.')
                ->middleware('web')
                ->group($webRoutesFile);
        }
    }

    /**
     * 관리자 사용자를 생성합니다.
     *
     * @param array $permissions 추가 권한 목록
     * @return \App\Models\User
     */
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

    /**
     * 일반 사용자를 생성합니다.
     *
     * @return \App\Models\User
     */
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
