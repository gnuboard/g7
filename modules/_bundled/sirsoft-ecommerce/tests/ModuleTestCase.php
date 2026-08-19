<?php

namespace Modules\Sirsoft\Ecommerce\Tests;

use App\Enums\ExtensionStatus;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Helpers\ResponseHelper;
use App\Models\Module as ModuleRegistration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleSettingsService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Database\Seeders\TestingSeeder;
use Modules\Sirsoft\Ecommerce\Exceptions\UnauthorizedPresetAccessException;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\SearchPreset;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Support\CurrencySettingsCache;
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
     */
    protected function shouldSeed(): bool
    {
        return true;
    }

    /**
     * 테스트용 시더 클래스
     */
    protected function seeder(): string
    {
        return TestingSeeder::class;
    }

    /**
     * 마이그레이션 경로를 반환합니다.
     *
     * RefreshDatabase의 migrate:fresh 명령에 코어 + 모듈 마이그레이션 경로를 전달합니다.
     * 이를 통해 트랜잭션 시작 전에 모든 마이그레이션이 완료됩니다.
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
        if (RefreshDatabaseState::$migrated) {
            try {
                if (! Schema::hasTable('ecommerce_shipping_types')) {
                    RefreshDatabaseState::$migrated = false;
                }
            } catch (\Throwable $e) { /* DB 미초기화 / 연결 부재 — RefreshDatabase 가 처리 */
            }
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 모듈 등록 행(modules 테이블)을 매 테스트마다 보장.
        //
        // 이 행은 TestingSeeder 가 만들지만, 시딩은 migrate:fresh 와 함께 프로세스당 1회만 돈다.
        // 같은 프로세스에서 코어 테스트가 먼저 실행되면 코어의 migrate:fresh 가 스키마를 만들면서
        // 이 행을 남기지 않고, ecommerce 테이블은 이미 존재하므로 setUpTraits() 의 재마이그레이션
        // 가드도 발동하지 않는다. 결과적으로 "모듈이 설치돼 있다" 는 전제가 조용히 깨진 채로
        // 모듈 테스트가 돌아, 모듈 활성 여부를 보는 경로(언어팩 활성화 → entity 시더 재실행 등)가
        // 통째로 skip 된다 — 단독 통과 / 전체 실행 실패의 원인.
        //
        // 트랜잭션 안에서 수행되므로 테스트 종료 시 롤백된다.
        $this->ensureModuleRegistered();

        // 모듈 오토로드 등록 (테스트 환경)
        $this->registerModuleAutoload();

        // 모듈 ServiceProvider 등록 (Repository 바인딩)
        $this->app->register(EcommerceServiceProvider::class);

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

        // 테스트 격리: 모듈 다국어 네임스페이스 + 설정 상태를 매 테스트마다 결정적으로 복원.
        // (개별 통과 / 풀 스위트 실패 = 컨테이너 싱글톤 누수 — 과거 ModuleLayoutOverrideTest 와 동일 부류)
        $this->isolateModuleTranslations();
        $this->isolateModuleSettings();

        // 모델 static cache 초기화 (RefreshDatabase 의 트랜잭션 롤백과 static 상태 불일치 방지)
        // - ShippingType::$codeCache: getCachedByCode() 가 첫 호출 시 self::all() 결과를 캐시하는데,
        //   첫 테스트가 ShippingType 시드 전에 호출하면 empty cache 로 고정되어 이후 테스트에서
        //   Resource::resolveShippingMethodLabel() 이 null 반환
        if (method_exists(ShippingType::class, 'clearCodeCache')) {
            ShippingType::clearCodeCache();
        }

        // - CurrencySettingsCache: 통화 설정을 요청 단위로 캐시한다. 비우지 않으면 앞선 테스트가
        //   읽어 둔 통화 구성(기본 통화의 소수 자릿수 등)을 뒤 테스트가 물려받아, 금액 필드가
        //   int 로 나와야 할 자리에서 float 이 나오는 등 단독 통과 / 함께 실행 실패가 발생한다.
        CurrencySettingsCache::clear();
    }

    /**
     * `modules` 테이블에 이 모듈의 등록 행이 있음을 보장합니다 (없으면 생성).
     *
     * 값은 TestingSeeder 의 모듈 등록과 동일하게 맞춥니다 — 시딩이 돈 프로세스와
     * 돌지 않은 프로세스에서 테스트 전제가 달라지지 않도록 하기 위함입니다.
     */
    private function ensureModuleRegistered(): void
    {
        ModuleRegistration::updateOrCreate(
            ['identifier' => 'sirsoft-ecommerce'],
            [
                'vendor' => 'sirsoft',
                'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
                'status' => ExtensionStatus::Active->value,
                'version' => '1.0.0',
            ]
        );
    }

    /**
     * tearDown 에 HookManager 상태 복원.
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();

        // 테스트가 saveSettings() 로 디스크에 남긴 settings JSON 정리 (다음 테스트 오염 방지)
        $this->purgeTestSettingsDirectory();

        parent::tearDown();
    }

    /**
     * 모듈 다국어 네임스페이스를 라이브 translator 에 재등록합니다.
     *
     * `sirsoft-ecommerce::enums.*` 등의 키가 풀 스위트 실행 시 원문 키로 반환되는 회귀를 차단합니다.
     * 선행 테스트가 translator 싱글톤을 모듈 hint 없이 resolve 해두면, ServiceProvider 의 boot()
     * 가 이미 부팅된 것으로 간주되어 loadTranslationsFrom() 이 재호출되지 않아 hint 가 누락됩니다.
     * 매 테스트마다 명시적으로 hint 를 재등록해 순서 무관 결정성을 보장합니다.
     */
    private function isolateModuleTranslations(): void
    {
        $langPath = $this->getModuleBasePath().'/src/lang';

        if (is_dir($langPath)) {
            $this->app['translator']->addNamespace('sirsoft-ecommerce', $langPath);
        }
    }

    /**
     * 모듈 설정 관련 싱글톤과 디스크 settings 를 초기화합니다.
     *
     * EcommerceSettingsService / ModuleSettingsService 싱글톤이 이전 테스트의 mock 또는
     * saveSettings() 영속 상태를 유지하면 후속 테스트가 오염됩니다. 싱글톤을 forget 하고
     * 테스트 settings 디렉토리를 비워 매 테스트가 깨끗한 기본값에서 시작하도록 합니다.
     */
    private function isolateModuleSettings(): void
    {
        $this->app->forgetInstance(EcommerceSettingsService::class);
        $this->app->forgetInstance(ModuleSettingsService::class);

        $this->purgeTestSettingsDirectory();
    }

    /**
     * 테스트 환경 settings 디렉토리(storage/framework/testing/...)를 삭제합니다.
     *
     * EcommerceSettingsService::getStoragePath() 가 runningUnitTests 시 사용하는 경로로,
     * RefreshDatabase 의 트랜잭션 롤백 대상이 아니므로 명시적으로 정리해야 합니다.
     */
    private function purgeTestSettingsDirectory(): void
    {
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

        if (File::isDirectory($path)) {
            File::deleteDirectory($path);
        }
    }

    /**
     * HookManager static $hooks / $filters / $dispatching 를 스냅샷.
     */
    private function snapshotHookManager(): void
    {
        $ref = new \ReflectionClass(HookManager::class);
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

        $ref = new \ReflectionClass(HookManager::class);
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
        $moduleClass = Module::class;

        if (! class_exists($moduleClass)) {
            require_once $this->getModuleBasePath().'/module.php';
        }

        /** @var ModuleManager $manager */
        $manager = $this->app->make(ModuleManager::class);

        $reflection = new \ReflectionClass($manager);
        $modulesProp = $reflection->getProperty('modules');
        $modulesProp->setAccessible(true);
        $current = $modulesProp->getValue($manager);
        $current['sirsoft-ecommerce'] = new $moduleClass;
        $modulesProp->setValue($manager, $current);
    }

    /**
     * 모듈 예외 핸들러를 등록합니다.
     *
     * 테스트 환경에서 모듈의 커스텀 예외가 적절한 HTTP 응답으로 변환되도록 합니다.
     */
    protected function registerModuleExceptionHandler(): void
    {
        /** @var Handler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->renderable(function (UnauthorizedPresetAccessException $e) {
            return ResponseHelper::forbidden($e->getMessage());
        });
    }

    /**
     * 모듈 오토로드를 등록합니다.
     */
    protected function registerModuleAutoload(): void
    {
        $moduleBasePath = $this->getModuleBasePath().'/src/';

        // PSR-4 클래스 오토로드 등록
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
     * 모듈 라우트를 등록합니다.
     */
    protected function registerModuleRoutes(): void
    {
        $apiRoutesFile = $this->getModuleBasePath().'/src/routes/api.php';

        if (file_exists($apiRoutesFile)) {
            // Route Model Binding 등록 (테스트 환경)
            Route::bind('product', function ($value) {
                $model = new Product;

                return $model->resolveRouteBinding($value);
            });
            Route::model('preset', SearchPreset::class);

            Route::prefix('api/modules/sirsoft-ecommerce')
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
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => ['ko' => '관리자', 'en' => 'Administrator']]
        );

        // admin 역할에 admin 타입 권한 부여 (isAdmin() 체크용)
        $adminPermission = Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            [
                'name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'],
                'type' => PermissionType::Admin,
            ]
        );
        $adminRole->permissions()->syncWithoutDetaching([$adminPermission->id]);

        Role::firstOrCreate(
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
     */
    protected function createAdminUser(array $permissions = []): User
    {
        $user = User::factory()->create();

        // 사용자별 고유 역할 생성 (권한 격리를 위함)
        $uniqueRoleIdentifier = 'admin-test-'.$user->id.'-'.time();
        $userRole = Role::create([
            'identifier' => $uniqueRoleIdentifier,
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
        ]);
        $user->roles()->attach($userRole->id);

        // admin.access 권한 추가 (isAdmin() 체크용)
        $adminAccessPermission = Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            [
                'name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'],
                'type' => PermissionType::Admin,
            ]
        );
        $userRole->permissions()->attach($adminAccessPermission->id);

        // 추가 권한이 있으면 역할에 할당
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
     * 일반 사용자를 생성합니다.
     */
    protected function createUser(): User
    {
        $userRole = Role::where('identifier', 'user')->first();
        $user = User::factory()->create();
        $user->roles()->attach($userRole->id);

        return $user;
    }
}
