<?php

namespace Modules\Sirsoft\Ecommerce\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ecommerce 모듈 테스트 베이스 클래스
 *
 * 모든 Ecommerce 모듈 테스트는 이 클래스를 상속받아야 합니다.
 * 모듈 오토로드, ServiceProvider 등록, 마이그레이션, 라우트 등록을 자동으로 처리합니다.
 *
 * 성능 최적화: 마이그레이션은 테스트 클래스당 1회만 실행됩니다.
 * 트랜잭션 충돌 방지: 시딩을 통해 기본 데이터를 트랜잭션 시작 전에 삽입합니다.
 */
abstract class ModuleTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * 모듈 루트 경로를 반환합니다.
     *
     * __DIR__을 기반으로 동적 해석하여 _bundled/활성 디렉토리 모두에서 동작합니다.
     *
     * @return string 모듈 루트 절대 경로
     */
    protected function getModuleBasePath(): string
    {
        // __DIR__ = {module_root}/tests/ → dirname = {module_root}
        return dirname(__DIR__);
    }

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
     * RefreshDatabase의 migrate:fresh 명령에 코어 + 모듈 마이그레이션 경로를 전달합니다.
     * 이를 통해 트랜잭션 시작 전에 모든 마이그레이션이 완료됩니다.
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
                base_path('database/migrations'),
                $this->getModuleBasePath() . '/database/migrations',
            ],
            '--realpath' => true,
        ];
    }

    /**
     * 테스트 환경 설정
     *
     * 모듈/역할/권한 등록은 TestingSeeder에서 처리됩니다.
     * (트랜잭션 시작 전에 실행되어 락 충돌 방지)
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 모듈 오토로드 등록 (테스트 환경)
        $this->registerModuleAutoload();

        // 모듈 ServiceProvider 등록 (Repository 바인딩)
        $this->app->register(\Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider::class);

        // 모듈 예외 핸들러 등록 (테스트 환경)
        $this->registerModuleExceptionHandler();

        // 모듈 라우트를 수동으로 등록
        $this->registerModuleRoutes();
    }

    /**
     * 모듈 예외 핸들러를 등록합니다.
     *
     * 테스트 환경에서 모듈의 커스텀 예외가 적절한 HTTP 응답으로 변환되도록 합니다.
     */
    protected function registerModuleExceptionHandler(): void
    {
        /** @var \Illuminate\Foundation\Exceptions\Handler $handler */
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        $handler->renderable(function (\Modules\Sirsoft\Ecommerce\Exceptions\UnauthorizedPresetAccessException $e) {
            return \App\Helpers\ResponseHelper::forbidden($e->getMessage());
        });
    }

    /**
     * 모듈 오토로드를 등록합니다.
     */
    protected function registerModuleAutoload(): void
    {
        $moduleBasePath = $this->getModuleBasePath() . '/src/';

        // PSR-4 클래스 오토로드 등록
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
     * 모듈 라우트를 등록합니다.
     */
    protected function registerModuleRoutes(): void
    {
        $apiRoutesFile = $this->getModuleBasePath() . '/src/routes/api.php';

        if (file_exists($apiRoutesFile)) {
            // Route Model Binding 등록 (테스트 환경)
            \Illuminate\Support\Facades\Route::bind('product', function ($value) {
                $model = new \Modules\Sirsoft\Ecommerce\Models\Product();

                return $model->resolveRouteBinding($value);
            });
            \Illuminate\Support\Facades\Route::model('preset', \Modules\Sirsoft\Ecommerce\Models\SearchPreset::class);

            \Illuminate\Support\Facades\Route::prefix('api/modules/sirsoft-ecommerce')
                ->name('api.modules.sirsoft-ecommerce.')
                ->middleware('api')
                ->group($apiRoutesFile);
        }
    }

    /**
     * 기본 역할들을 생성합니다.
     */
    protected function createDefaultRoles(): void
    {
        $adminRole = \App\Models\Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => ['ko' => '관리자', 'en' => 'Administrator']]
        );

        // admin 역할에 admin 타입 권한 부여 (isAdmin() 체크용)
        $adminPermission = \App\Models\Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            [
                'name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'],
                'type' => \App\Enums\PermissionType::Admin,
            ]
        );
        $adminRole->permissions()->syncWithoutDetaching([$adminPermission->id]);

        \App\Models\Role::firstOrCreate(
            ['identifier' => 'user'],
            ['name' => ['ko' => '일반 사용자', 'en' => 'User']]
        );
    }

    /**
     * 관리자 역할을 가진 사용자를 생성합니다.
     *
     * 각 사용자에 대해 고유한 역할을 생성하여 권한을 독립적으로 관리합니다.
     *
     * @param  array  $permissions  추가 권한 목록
     * @return \App\Models\User
     */
    protected function createAdminUser(array $permissions = []): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();

        // 사용자별 고유 역할 생성 (권한 격리를 위함)
        $uniqueRoleIdentifier = 'admin-test-' . $user->id . '-' . time();
        $userRole = \App\Models\Role::create([
            'identifier' => $uniqueRoleIdentifier,
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
        ]);
        $user->roles()->attach($userRole->id);

        // admin.access 권한 추가 (isAdmin() 체크용)
        $adminAccessPermission = \App\Models\Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            [
                'name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'],
                'type' => \App\Enums\PermissionType::Admin,
            ]
        );
        $userRole->permissions()->attach($adminAccessPermission->id);

        // 추가 권한이 있으면 역할에 할당
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
        $user->roles()->attach($userRole->id);

        return $user;
    }
}
