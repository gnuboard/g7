<?php

namespace Modules\Gnuboard7\HelloModule\Tests;

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
 * Hello 모듈 테스트 베이스 클래스
 *
 * 모듈 오토로드, ServiceProvider 등록, 마이그레이션 실행을 담당합니다.
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

        $this->registerModuleAutoload();

        $this->app->register(\Modules\Gnuboard7\HelloModule\Providers\HelloModuleServiceProvider::class);

        $this->runModuleMigrationIfNeeded();

        $this->createDefaultRoles();

        // 훅 리스너 수동 등록 (모듈 매니저를 거치지 않는 테스트 환경 대응)
        $this->registerModuleHookListeners();

        // 훅 등록 완료 후 스냅샷 — tearDown 에서 이 시점으로 복원
        $this->snapshotHookManager();
    }

    /**
     * tearDown 에 HookManager 상태 복원 — 테스트 본문이 추가한 훅만 제거.
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
     * 모듈 마이그레이션을 필요 시 실행합니다.
     */
    protected function runModuleMigrationIfNeeded(): void
    {
        if (static::$migrated) {
            return;
        }

        // 매 PHP process 첫 setUp 시 DB 완전 초기화 후 코어+모듈 마이그레이션을 처음부터 실행한다.
        // (DatabaseTransactions 사용 시 schema 자동 재구축 없음 — 잔재로 인한 컬럼 충돌 차단)
        $this->artisan('migrate:fresh');

        $this->artisan('migrate', [
            '--path' => $this->getModuleBasePath().'/database/migrations',
            '--realpath' => true,
        ]);

        static::$migrated = true;
    }

    /**
     * 모듈 오토로드를 등록합니다.
     */
    protected function registerModuleAutoload(): void
    {
        $moduleBasePath = $this->getModuleBasePath();

        spl_autoload_register(function ($class) use ($moduleBasePath) {
            $prefix = 'Modules\\Gnuboard7\\HelloModule\\';
            $len = strlen($prefix);

            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);

            if (str_starts_with($relativeClass, 'Database\\Factories\\')) {
                $factoryClass = substr($relativeClass, strlen('Database\\Factories\\'));
                $file = $moduleBasePath.'/database/factories/'.str_replace('\\', '/', $factoryClass).'.php';
            } elseif (str_starts_with($relativeClass, 'Database\\Seeders\\')) {
                $seederClass = substr($relativeClass, strlen('Database\\Seeders\\'));
                $file = $moduleBasePath.'/database/seeders/'.str_replace('\\', '/', $seederClass).'.php';
            } else {
                $file = $moduleBasePath.'/src/'.str_replace('\\', '/', $relativeClass).'.php';
            }

            if (file_exists($file)) {
                require $file;
            }
        });
    }

    /**
     * 모듈을 활성화 상태로 등록합니다.
     */
    protected function registerModuleAsActive(): void
    {
        if (Module::where('identifier', 'gnuboard7-hello_module')->exists()) {
            return;
        }

        Module::create([
            'identifier' => 'gnuboard7-hello_module',
            'vendor' => 'gnuboard7',
            'name' => ['ko' => 'Hello 모듈', 'en' => 'Hello Module'],
            'status' => ExtensionStatus::Active->value,
            'version' => '0.1.0-beta.1',
            'config' => [],
        ]);
    }

    /**
     * 모듈 라우트를 등록합니다.
     */
    protected function registerModuleRoutes(): void
    {
        $apiRoutesFile = $this->getModuleBasePath().'/src/routes/api.php';
        $webRoutesFile = $this->getModuleBasePath().'/src/routes/web.php';

        if (file_exists($webRoutesFile)) {
            Route::prefix('api/modules/gnuboard7-hello_module')
                ->name('api.modules.gnuboard7-hello_module.')
                ->middleware('api')
                ->group($webRoutesFile);
        }

        if (file_exists($apiRoutesFile)) {
            Route::prefix('api/modules/gnuboard7-hello_module')
                ->name('api.modules.gnuboard7-hello_module.public.')
                ->middleware('api')
                ->group($apiRoutesFile);
        }
    }

    /**
     * 모듈 훅 리스너를 수동 등록합니다.
     *
     * 실제 런타임에서는 ModuleManager 가 자동 등록하지만
     * 테스트 환경에서는 수동으로 HookManager 에 주입해야 합니다.
     */
    protected function registerModuleHookListeners(): void
    {
        $moduleClass = 'Modules\\Gnuboard7\\HelloModule\\Module';
        if (! class_exists($moduleClass)) {
            require_once $this->getModuleBasePath().'/module.php';
        }

        $module = new $moduleClass([
            'identifier' => 'gnuboard7-hello_module',
            'vendor' => 'gnuboard7',
            'name' => ['ko' => 'Hello 모듈', 'en' => 'Hello Module'],
            'version' => '0.1.0-beta.1',
        ]);

        $hookManager = app(\App\Extension\HookManager::class);
        foreach ($module->getHookListeners() as $listenerClass) {
            foreach ($listenerClass::getSubscribedHooks() as $hookName => $config) {
                $method = is_array($config) ? ($config['method'] ?? 'handle') : 'handle';
                $priority = is_array($config) ? ($config['priority'] ?? 10) : 10;
                $hookManager->addAction($hookName, [new $listenerClass(), $method], $priority);
            }
        }
    }

    /**
     * 기본 역할을 생성합니다.
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
     * @return User 관리자 사용자
     */
    protected function createAdminUser(array $permissions = []): User
    {
        $adminRole = Role::where('identifier', 'admin')->first();
        $user = User::factory()->create();
        $user->roles()->attach($adminRole->id);

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

        return $user;
    }

    /**
     * 일반 사용자를 생성합니다.
     *
     * @return User 일반 사용자
     */
    protected function createUser(): User
    {
        $userRole = Role::where('identifier', 'user')->first();
        $user = User::factory()->create();
        $user->roles()->attach($userRole->id);

        return $user;
    }
}
