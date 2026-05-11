<?php

namespace Modules\Sirsoft\Board\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Models\Board;
use Tests\TestCase;

/**
 * Board 모듈 테스트 베이스 클래스
 *
 * 모든 Board 모듈 테스트는 이 클래스를 상속받아야 합니다.
 * 모듈 오토로드, ServiceProvider 등록, 마이그레이션, 라우트 등록을 자동으로 처리합니다.
 *
 * 성능 최적화:
 * - 각 테스트 메서드는 DatabaseTransactions로 트랜잭션 롤백만 수행 (빠름)
 * - board_posts/board_comments/board_attachments 단일 테이블 사용 (DDL 불필요)
 */
abstract class ModuleTestCase extends TestCase
{
    use DatabaseTransactions;

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
     * 마이그레이션 완료 플래그 (클래스당 한 번만 실행)
     */
    protected static bool $migrated = false;

    /**
     * 테스트용 공유 게시판 slug (클래스 내 모든 테스트가 공유)
     */
    protected static string $sharedBoardSlug = '';

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

        // 모듈 ServiceProvider 등록 (Repository 바인딩)
        $this->app->register(\Modules\Sirsoft\Board\Providers\BoardServiceProvider::class);

        // 모듈 마이그레이션 실행 (boards 테이블 등)
        $this->runModuleMigrationIfNeeded();

        // 모듈을 활성화 상태로 등록 (테스트 환경)
        $this->registerModuleAsActive();

        // ModuleManager 메모리 맵에 sirsoft-board 로드 (테스트 환경)
        // CoreServiceProvider::boot()에서 loadModules()가 호출되지만,
        // 테스트 컨테이너에서 ModuleManager 싱글톤이 비어있는 경우를 대비해 명시적으로 재로드
        $this->app->make(\App\Extension\ModuleManager::class)->loadModules();

        // _bundled 디렉토리 모듈은 loadModules() 가 스캔하지 않아
        // 모듈 인스턴스 등록 + 훅 리스너 자동 등록이 누락된다.
        // 테스트 환경에서 module.php 가 선언한 getHookListeners() 의 리스너들을 수동 등록.
        $this->registerBundledModuleInstance();

        // 모듈 라우트를 수동으로 등록
        $this->registerModuleRoutes();

        // 기본 역할 생성
        $this->createDefaultRoles();

        // HookManager 상태 스냅샷 (tearDown 에서 복원하여 테스트 내 추가 훅만 제거)
        $this->snapshotHookManager();

        // PermissionMiddleware::$guestRoleCache 초기화 — 이전 테스트에서 로드된 guest role/permissions 캐시가
        // DatabaseTransactions 롤백 후에도 남아있어 다음 테스트의 새 permission 설정이 반영되지 않는 문제 회피.
        $middlewareRef = new \ReflectionClass(\App\Http\Middleware\PermissionMiddleware::class);
        if ($middlewareRef->hasProperty('guestRoleCache')) {
            $prop = $middlewareRef->getProperty('guestRoleCache');
            $prop->setValue(null, null);
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
     * 코어 테이블이 없으면 먼저 코어 마이그레이션을 실행하고,
     * 그 후 모듈 마이그레이션을 실행합니다.
     * static $migrated 플래그로 프로세스당 한 번만 실행합니다.
     */
    protected function runModuleMigrationIfNeeded(): void
    {
        if (static::$migrated) {
            return;
        }

        // 매 PHP process 첫 setUp 시 DB 를 완전 초기화 후 코어+모듈 마이그레이션을 처음부터
        // 실행한다 (board 는 DatabaseTransactions 사용 — RefreshDatabase 가 아니므로 schema
        // 자동 재구축 없음). 이전 process 의 부분 schema 잔재(예: 컬럼은 추가됐지만 migration
        // record 누락) 로 인한 "Duplicate column" 등 충돌을 차단한다.
        $this->artisan('migrate:fresh');

        // 모듈 마이그레이션 실행 (코어 테이블 생성 후)
        $this->artisan('migrate', [
            '--path' => $this->getModuleBasePath() . '/database/migrations',
            '--realpath' => true,
        ]);

        static::$migrated = true;
    }

    /**
     * _bundled 디렉토리 모듈 인스턴스 + 훅 리스너 수동 등록.
     *
     * ModuleManager::loadModules() 는 modules/ (활성) 디렉토리만 스캔하고
     * _bundled 는 메타데이터만 로드 (loadBundledModules) — 인스턴스/훅 미등록.
     * 테스트 환경에서는 _bundled 에서 직접 실행하므로 module.php 의
     * getHookListeners() 가 선언한 리스너들을 수동으로 등록해야 실제 부트 시점과 동일한 훅 흐름이 복원된다.
     */
    protected function registerBundledModuleInstance(): void
    {
        $moduleClass = \Modules\Sirsoft\Board\Module::class;

        if (! class_exists($moduleClass)) {
            require_once $this->getModuleBasePath() . '/module.php';
        }

        $module = new $moduleClass();

        /** @var \App\Extension\ModuleManager $manager */
        $manager = $this->app->make(\App\Extension\ModuleManager::class);

        // ModuleManager.modules 에 인스턴스 주입
        $reflection = new \ReflectionClass($manager);
        $modulesProp = $reflection->getProperty('modules');
        $modulesProp->setAccessible(true);
        $current = $modulesProp->getValue($manager);
        if (! isset($current['sirsoft-board'])) {
            $current['sirsoft-board'] = $module;
            $modulesProp->setValue($manager, $current);
        }

        // 훅 리스너 등록 — module.php 의 getHookListeners() 반환 클래스들을 HookListenerRegistrar 로 등록
        if (method_exists($module, 'getHookListeners')) {
            foreach ($module->getHookListeners() as $listenerClass) {
                if (! class_exists($listenerClass)) {
                    continue;
                }
                if (! in_array(\App\Contracts\Extension\HookListenerInterface::class, class_implements($listenerClass), true)) {
                    continue;
                }
                try {
                    \App\Extension\HookListenerRegistrar::register($listenerClass, 'sirsoft-board');
                } catch (\Throwable $e) {
                    // 중복 등록 등 무해한 예외는 무시 (snapshot/restore 패턴이 정리)
                }
            }
        }
    }

    /**
     * 모듈을 활성화 상태로 등록합니다.
     */
    protected function registerModuleAsActive(): void
    {
        // 이미 등록되어 있으면 스킵
        if (\App\Models\Module::where('identifier', 'sirsoft-board')->exists()) {
            return;
        }

        \App\Models\Module::create([
            'identifier' => 'sirsoft-board',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'status' => \App\Enums\ExtensionStatus::Active->value,
            'version' => '1.0.0',
            'config' => [],
        ]);
    }

    /**
     * 모듈 오토로드를 등록합니다.
     */
    protected function registerModuleAutoload(): void
    {
        $moduleBasePath = $this->getModuleBasePath() . '/src/';

        spl_autoload_register(function ($class) use ($moduleBasePath) {
            $prefix = 'Modules\\Sirsoft\\Board\\';
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
    }

    /**
     * 모듈 라우트를 등록합니다.
     */
    protected function registerModuleRoutes(): void
    {
        $apiRoutesFile = $this->getModuleBasePath() . '/src/routes/api.php';

        if (file_exists($apiRoutesFile)) {
            \Illuminate\Support\Facades\Route::prefix('api/modules/sirsoft-board')
                ->name('api.modules.sirsoft-board.')
                ->middleware('api')
                ->group($apiRoutesFile);
        }
    }

    /**
     * 기본 역할들을 생성합니다.
     */
    protected function createDefaultRoles(): void
    {
        \App\Models\Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => ['ko' => '관리자', 'en' => 'Administrator']]
        );

        \App\Models\Role::firstOrCreate(
            ['identifier' => 'user'],
            ['name' => ['ko' => '일반 사용자', 'en' => 'User']]
        );

        \App\Models\Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest']]
        );

    }

    /**
     * 관리자 역할을 가진 사용자를 생성합니다.
     *
     * @param  array  $permissions  추가 권한 목록
     * @return \App\Models\User
     */
    protected function createAdminUser(array $permissions = []): \App\Models\User
    {
        $adminRole = \App\Models\Role::where('identifier', 'admin')->first();
        $user = \App\Models\User::factory()->create();
        $user->roles()->attach($adminRole->id);

        // 추가 권한이 있으면 생성 및 할당
        if (! empty($permissions)) {
            foreach ($permissions as $permissionIdentifier) {
                $permission = \App\Models\Permission::firstOrCreate(
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