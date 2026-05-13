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
    /**
     * HookManager static state 스냅샷 — tearDown 에서 복원하여 테스트 간 훅 격리를 보장.
     *
     * 테스트 내에서 `HookManager::addFilter()` / `addAction()` 으로 등록한 훅이
     * 다음 테스트로 누수되어 OrderAdjustmentService 등의 계산 경로에 영향을 주는
     * cross-test state leak 을 차단한다.
     *
     * @var array{hooks: array, filters: array}|null
     */
    private ?array $hookSnapshot = null;

    /**
     * setUpTraits 단계에서 모듈 마이그레이션 부재를 검사해 RefreshDatabase 의 process-static
     * `$migrated` 플래그를 리셋한다 — 그래야 곧이은 RefreshDatabase 초기화가 본 클래스의
     * `migrateFreshUsing()` 으로 migrate:fresh 를 재실행한다.
     *
     * 다른 테스트 클래스(예: 코어 전용 Tests\TestCase 상속)가 같은 프로세스에서 먼저 실행되어
     * 모듈 마이그레이션 없이 schema 를 만든 경우의 회귀 가드.
     *
     * setUp() 안에서 Artisan migrate 를 별도 호출하면 DDL 의 implicit commit 이
     * 진행 중인 테스트 트랜잭션을 깨뜨려 첫 테스트가 transaction 외부에서 실행되는
     * side-effect 가 발생하므로, 트랜잭션 시작 *전* 에 처리해야 한다.
     */
    protected function setUpTraits()
    {
        if (\Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated) {
            try {
                if (! \Illuminate\Support\Facades\Schema::hasTable('ecommerce_shipping_types')) {
                    \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
                }
            } catch (\Throwable $e) { /* DB 미초기화 / 연결 부재 — RefreshDatabase 가 처리 */ }
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 모듈 오토로드 등록 (테스트 환경)
        $this->registerModuleAutoload();

        // 모듈 ServiceProvider 등록 (Repository 바인딩)
        $this->app->register(\Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider::class);

        // 모듈 인스턴스를 ModuleManager 에 등록 (Storage/Cache 바인딩에 필수)
        // BaseModuleServiceProvider::registerStorageBindings 가 런타임에
        // ModuleManager->getModule($identifier)->getStorage() 를 호출하므로,
        // _bundled 에서만 실행되는 테스트 환경에서는 ModuleManager.modules 에
        // 수동으로 인스턴스를 등록해 둬야 한다 (loadModules() 는 modules/ 만 스캔).
        $this->registerModuleInstance();

        // 모듈 예외 핸들러 등록 (테스트 환경)
        $this->registerModuleExceptionHandler();

        // 모듈 라우트를 수동으로 등록
        $this->registerModuleRoutes();

        // HookManager 현재 상태 스냅샷 (tearDown 에서 복원)
        $this->snapshotHookManager();

        // 모델 static cache 초기화 (RefreshDatabase 의 트랜잭션 롤백과 static 상태 불일치 방지)
        // - ShippingType::$codeCache: getCachedByCode() 가 첫 호출 시 self::all() 결과를 캐시하는데,
        //   첫 테스트가 ShippingType 시드 전에 호출하면 empty cache 로 고정되어 이후 테스트에서
        //   Resource::resolveShippingMethodLabel() 이 null 반환
        if (method_exists(\Modules\Sirsoft\Ecommerce\Models\ShippingType::class, 'clearCodeCache')) {
            \Modules\Sirsoft\Ecommerce\Models\ShippingType::clearCodeCache();
        }
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
        $ref = new \ReflectionClass(\App\Extension\HookManager::class);
        $hooks = $ref->getProperty('hooks');
        $hooks->setAccessible(true);
        $filters = $ref->getProperty('filters');
        $filters->setAccessible(true);
        $dispatching = $ref->getProperty('dispatching');
        $dispatching->setAccessible(true);

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

        $ref = new \ReflectionClass(\App\Extension\HookManager::class);
        $hooks = $ref->getProperty('hooks');
        $hooks->setAccessible(true);
        $hooks->setValue(null, $this->hookSnapshot['hooks']);

        $filters = $ref->getProperty('filters');
        $filters->setAccessible(true);
        $filters->setValue(null, $this->hookSnapshot['filters']);

        $dispatching = $ref->getProperty('dispatching');
        $dispatching->setAccessible(true);
        $dispatching->setValue(null, $this->hookSnapshot['dispatching']);

        $this->hookSnapshot = null;
    }

    /**
     * 모듈 인스턴스를 ModuleManager 에 수동 등록합니다.
     */
    protected function registerModuleInstance(): void
    {
        $moduleClass = \Modules\Sirsoft\Ecommerce\Module::class;

        if (! class_exists($moduleClass)) {
            require_once $this->getModuleBasePath() . '/module.php';
        }

        /** @var \App\Extension\ModuleManager $manager */
        $manager = $this->app->make(\App\Extension\ModuleManager::class);

        $reflection = new \ReflectionClass($manager);
        $modulesProp = $reflection->getProperty('modules');
        $modulesProp->setAccessible(true);
        $current = $modulesProp->getValue($manager);
        $current['sirsoft-ecommerce'] = new $moduleClass();
        $modulesProp->setValue($manager, $current);
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
