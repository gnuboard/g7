<?php

namespace Modules\Sirsoft\Page\Tests;

use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Page 모듈 테스트 베이스 클래스
 *
 * 모든 Page 모듈 테스트는 이 클래스를 상속받아야 합니다.
 * 모듈 오토로드, ServiceProvider 등록, 마이그레이션, 라우트 등록을 자동으로 처리합니다.
 */
abstract class ModuleTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * 마이그레이션 완료 플래그 (프로세스당 한 번만 실행)
     */
    protected static bool $migrated = false;

    /**
     * 모듈 루트 경로를 반환합니다.
     *
     * @return string 모듈 루트 절대 경로
     */
    protected function getModuleBasePath(): string
    {
        return dirname(__DIR__);
    }

    /**
     * HookManager static state 스냅샷 — tearDown 에서 복원하여 테스트 간 훅 격리 보장.
     *
     * @var array{hooks: array, filters: array, dispatching: array}|null
     */
    private ?array $hookSnapshot = null;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 모듈 오토로드 등록 (테스트 환경)
        $this->registerModuleAutoload();

        // ModuleManager 메모리에 모듈 인스턴스 등록
        // (_bundled에서 직접 실행 시 활성 디렉토리가 없어 getModule()이 null 반환하는 문제 방지)
        $this->registerModuleInManager();

        // 모듈 ServiceProvider 등록 (Repository 바인딩)
        $this->app->register(\Modules\Sirsoft\Page\Providers\PageServiceProvider::class);

        // 모듈 마이그레이션 실행 (pages 테이블 등)
        $this->runModuleMigrationIfNeeded();

        // 기본 역할 생성
        $this->createDefaultRoles();

        // _bundled 디렉토리 모듈은 ModuleManager::loadModules() 가 스캔하지 않아
        // module.php 의 getHookListeners() 가 선언한 리스너들이 자동 등록되지 않는다.
        // 테스트 환경에서 실제 부트 시점과 동일한 훅 흐름을 복원하기 위해 수동 등록.
        $this->registerBundledModuleInstance();

        // HookManager 상태 스냅샷 (tearDown 에서 복원)
        $this->snapshotHookManager();
    }

    /**
     * _bundled 디렉토리 모듈 인스턴스 + 훅 리스너 수동 등록.
     *
     * sirsoft-board ModuleTestCase 와 동일 패턴 — 자세한 설명은 board 측 주석 참조.
     */
    protected function registerBundledModuleInstance(): void
    {
        $moduleClass = \Modules\Sirsoft\Page\Module::class;

        if (! class_exists($moduleClass)) {
            require_once $this->getModuleBasePath() . '/module.php';
        }

        $module = new $moduleClass();

        /** @var \App\Extension\ModuleManager $manager */
        $manager = $this->app->make(\App\Extension\ModuleManager::class);

        $reflection = new \ReflectionClass($manager);
        $modulesProp = $reflection->getProperty('modules');
        $modulesProp->setAccessible(true);
        $current = $modulesProp->getValue($manager);
        if (! isset($current['sirsoft-page'])) {
            $current['sirsoft-page'] = $module;
            $modulesProp->setValue($manager, $current);
        }

        if (method_exists($module, 'getHookListeners')) {
            foreach ($module->getHookListeners() as $listenerClass) {
                if (! class_exists($listenerClass)) {
                    continue;
                }
                if (! in_array(\App\Contracts\Extension\HookListenerInterface::class, class_implements($listenerClass), true)) {
                    continue;
                }
                try {
                    \App\Extension\HookListenerRegistrar::register($listenerClass, 'sirsoft-page');
                } catch (\Throwable $e) {
                    // 중복 등록 등 무해한 예외는 무시
                }
            }
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
        $this->hookSnapshot = [
            'hooks' => $ref->getProperty('hooks')->getValue(),
            'filters' => $ref->getProperty('filters')->getValue(),
            'dispatching' => $ref->getProperty('dispatching')->getValue(),
        ];
    }

    /**
     * 스냅샷 시점으로 HookManager 복원.
     */
    private function restoreHookManager(): void
    {
        if ($this->hookSnapshot === null) {
            return;
        }

        $ref = new \ReflectionClass(\App\Extension\HookManager::class);
        $ref->getProperty('hooks')->setValue(null, $this->hookSnapshot['hooks']);
        $ref->getProperty('filters')->setValue(null, $this->hookSnapshot['filters']);
        $ref->getProperty('dispatching')->setValue(null, $this->hookSnapshot['dispatching']);

        $this->hookSnapshot = null;
    }

    /**
     * 모듈 마이그레이션 실행 (필요한 경우에만)
     *
     * static $migrated 플래그로 프로세스당 한 번만 실행합니다.
     */
    protected function runModuleMigrationIfNeeded(): void
    {
        if (static::$migrated) {
            return;
        }

        // 매 PHP process 첫 setUp 시 DB 를 완전 초기화 후 코어+모듈 마이그레이션을 처음부터
        // 실행한다 (page 도 board 와 동일하게 DatabaseTransactions 사용 — schema 자동 재구축 없음).
        // 이전 process 잔재(컬럼/테이블은 있으나 migration record 누락) 로 인한 충돌을 차단한다.
        $this->artisan('migrate:fresh');

        // 모듈 마이그레이션 실행 (코어 테이블 생성 후)
        $this->artisan('migrate', [
            '--path' => $this->getModuleBasePath().'/database/migrations',
            '--realpath' => true,
        ]);

        static::$migrated = true;
    }

    /**
     * 모듈을 활성화 상태로 등록합니다.
     */
    protected function registerModuleAsActive(): void
    {
        if (Module::where('identifier', 'sirsoft-page')->exists()) {
            return;
        }

        Module::create([
            'identifier' => 'sirsoft-page',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '페이지', 'en' => 'Page'],
            'status' => ExtensionStatus::Active->value,
            'version' => '0.1.1',
            'config' => [],
        ]);
    }

    /**
     * 모듈 오토로드를 등록합니다.
     */
    protected function registerModuleAutoload(): void
    {
        $moduleBasePath = $this->getModuleBasePath();

        spl_autoload_register(function ($class) use ($moduleBasePath) {
            $prefix = 'Modules\\Sirsoft\\Page\\';
            $len = strlen($prefix);

            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);

            // Database\Factories\ → database/factories/
            if (str_starts_with($relativeClass, 'Database\\Factories\\')) {
                $factoryClass = substr($relativeClass, strlen('Database\\Factories\\'));
                $file = $moduleBasePath.'/database/factories/'.str_replace('\\', '/', $factoryClass).'.php';
            }
            // Database\Seeders\ → database/seeders/
            elseif (str_starts_with($relativeClass, 'Database\\Seeders\\')) {
                $seederClass = substr($relativeClass, strlen('Database\\Seeders\\'));
                $file = $moduleBasePath.'/database/seeders/'.str_replace('\\', '/', $seederClass).'.php';
            }
            // 기본 → src/
            else {
                $file = $moduleBasePath.'/src/'.str_replace('\\', '/', $relativeClass).'.php';
            }

            if (file_exists($file)) {
                require $file;
            }
        });
    }

    /**
     * ModuleManager 메모리에 sirsoft-page 모듈 인스턴스를 수동 등록합니다.
     *
     * _bundled에서 직접 테스트 실행 시 활성 디렉토리가 없으므로
     * ModuleManager::getModule()이 null을 반환합니다.
     * PageServiceProvider의 storageServices 바인딩 클로저가 실행될 때
     * 모듈 인스턴스가 등록되어 있어야 합니다.
     *
     * @return void
     */
    protected function registerModuleInManager(): void
    {
        $moduleClass = \Modules\Sirsoft\Page\Module::class;
        if (! class_exists($moduleClass)) {
            $moduleFile = $this->getModuleBasePath().'/src/Module.php';
            if (file_exists($moduleFile)) {
                require_once $moduleFile;
            }
        }

        if (! class_exists($moduleClass)) {
            return;
        }

        $module = new $moduleClass;
        $manager = $this->app->make(\App\Extension\ModuleManager::class);

        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('modules');
        $property->setAccessible(true);
        $modules = $property->getValue($manager);
        $modules['sirsoft-page'] = $module;
        $property->setValue($manager, $modules);
    }

    /**
     * 모듈 라우트를 등록합니다.
     */
    protected function registerModuleRoutes(): void
    {
        $apiRoutesFile = $this->getModuleBasePath().'/src/routes/api.php';

        if (file_exists($apiRoutesFile)) {
            Route::prefix('api/modules/sirsoft-page')
                ->name('api.modules.sirsoft-page.')
                ->middleware('api')
                ->group($apiRoutesFile);
        }
    }

    /**
     * 기본 역할들을 생성합니다.
     */
    protected function createDefaultRoles(): void
    {
        Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => ['ko' => '관리자', 'en' => 'Administrator']]
        );

        Role::firstOrCreate(
            ['identifier' => 'user'],
            ['name' => ['ko' => '일반 사용자', 'en' => 'User']]
        );

        Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest']]
        );
    }

    /**
     * 관리자 역할을 가진 사용자를 생성합니다.
     *
     * @param  array  $permissions  추가 권한 목록
     * @return User
     */
    protected function createAdminUser(array $permissions = []): User
    {
        $adminRole = Role::where('identifier', 'admin')->first();
        $user = User::factory()->create();
        $user->roles()->attach($adminRole->id);

        if (! empty($permissions)) {
            foreach ($permissions as $permissionIdentifier) {
                $permission = Permission::firstOrCreate(
                    ['identifier' => $permissionIdentifier],
                    [
                        'name' => ['ko' => $permissionIdentifier, 'en' => $permissionIdentifier],
                        'type' => 'admin',
                    ]
                );
                $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }

        return $user;
    }

    /**
     * 일반 사용자를 생성합니다.
     *
     * @return User
     */
    protected function createUser(): User
    {
        $userRole = Role::where('identifier', 'user')->first();
        $user = User::factory()->create();
        $user->roles()->attach($userRole->id);

        return $user;
    }
}
