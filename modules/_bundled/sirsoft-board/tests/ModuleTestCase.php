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

        // 모듈 라우트를 수동으로 등록
        $this->registerModuleRoutes();

        // 기본 역할 생성
        $this->createDefaultRoles();
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

        // 코어 마이그레이션 먼저 실행 (users, roles, modules 등 의존 테이블 생성)
        // Board 모듈의 FK는 users 테이블을 참조하므로 코어 테이블이 먼저 필요
        if (! Schema::hasTable('users') || ! Schema::hasTable('roles') || ! Schema::hasTable('modules')) {
            $this->artisan('migrate');
        }

        // 모듈 마이그레이션 실행 (코어 테이블 생성 후)
        if (! Schema::hasTable('boards')) {
            $this->artisan('migrate', [
                '--path' => $this->getModuleBasePath() . '/database/migrations',
                '--realpath' => true,
            ]);
        }

        static::$migrated = true;
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