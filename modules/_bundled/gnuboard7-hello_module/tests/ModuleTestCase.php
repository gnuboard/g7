<?php

namespace Modules\Gnuboard7\HelloModule\Tests;

use App\Enums\ExtensionStatus;
use App\Extension\HookManager;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Modules\Gnuboard7\HelloModule\Providers\HelloModuleServiceProvider;
use Tests\TestCase;

/**
 * Hello 모듈 테스트 베이스 클래스
 *
 * 모듈 오토로드, ServiceProvider 등록, 마이그레이션 실행을 담당합니다.
 */
abstract class ModuleTestCase extends TestCase
{
    use DatabaseTransactions {
        beginDatabaseTransaction as protected beginTransactionAfterSchema;
    }

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
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerModuleAutoload();

        $this->app->register(HelloModuleServiceProvider::class);

        $this->createDefaultRoles();

        // 훅 리스너 수동 등록 (모듈 매니저를 거치지 않는 테스트 환경 대응)
        $this->registerModuleHookListeners();

        // 훅 등록 완료 후 스냅샷 — tearDown 에서 이 시점으로 복원
        $this->snapshotHookManager();
    }

    /**
     * tearDown 에 HookManager 상태 복원 — 테스트 본문이 추가한 훅만 제거.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();

        parent::tearDown();
    }

    /**
     * HookManager static $hooks / $filters / $dispatching 를 스냅샷.
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
     * 스냅샷 시점으로 HookManager 복원.
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
     * 첫 사용 때는 시드 없는 테스트 스키마(잔여 행만 정리한 상태)를 요구하고, 그 뒤에는 현재 상태를 받아들인다.
     *
     * 예전에는 프로세스 첫 테스트가 `migrate:fresh` 로 시드까지 지운 DB 에서 시작했다 — 같은 전제를 재사용 스키마에서 재현한다.
     *
     * @return string 요구 프로필(none-exact · any)
     */
    protected function requiredTestingSchema(): string
    {
        return static::$migrated ? 'any' : 'none-exact';
    }

    /**
     * 스키마를 먼저 끝낸 뒤 시험 트랜잭션을 엽니다.
     *
     * 예전에는 setUp 에서 트랜잭션을 연 뒤 프로세스 첫 시험의 `migrate:fresh` 를 돌려, DDL 의 암묵 커밋으로 첫 시험의 행이
     * 롤백되지 않고 남았다. 이제 스키마는 코어 TestCase 가 트레이트 초기화 전에 맞추고, 여기서는 첫 사용 표시만 남긴다.
     *
     * @return void 반환값 없음
     */
    public function beginDatabaseTransaction()
    {
        static::$migrated = true;

        $this->beginTransactionAfterSchema();
    }

    /**
     * 모듈 오토로드를 등록합니다.
     *
     * @return void 반환값 없음
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
     * 모듈을 활성화 상태로 등록합니다.
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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

        $hookManager = app(HookManager::class);
        foreach ($module->getHookListeners() as $listenerClass) {
            foreach ($listenerClass::getSubscribedHooks() as $hookName => $config) {
                $method = is_array($config) ? ($config['method'] ?? 'handle') : 'handle';
                $priority = is_array($config) ? ($config['priority'] ?? 10) : 10;
                $hookManager->addAction($hookName, [new $listenerClass, $method], $priority);
            }
        }
    }

    /**
     * 기본 역할을 생성합니다.
     *
     * @return void 반환값 없음
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
