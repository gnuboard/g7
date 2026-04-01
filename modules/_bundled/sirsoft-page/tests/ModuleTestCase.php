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
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 모듈 오토로드 등록 (테스트 환경)
        $this->registerModuleAutoload();

        // 모듈 ServiceProvider 등록 (Repository 바인딩)
        $this->app->register(\Modules\Sirsoft\Page\Providers\PageServiceProvider::class);

        // 모듈 마이그레이션 실행 (pages 테이블 등)
        $this->runModuleMigrationIfNeeded();

        // 기본 역할 생성
        $this->createDefaultRoles();
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

        // 코어 마이그레이션 먼저 실행 (users, roles, modules 등 의존 테이블 생성)
        if (! Schema::hasTable('users') || ! Schema::hasTable('roles') || ! Schema::hasTable('modules')) {
            $this->artisan('migrate');
        }

        // 모듈 마이그레이션 실행 (코어 테이블 생성 후)
        // pages 테이블이 없거나 스키마가 오래된 경우 재마이그레이션
        if (! Schema::hasTable('pages') || ! Schema::hasColumn('pages', 'published')) {
            if (Schema::hasTable('pages')) {
                Schema::disableForeignKeyConstraints();
                Schema::dropIfExists('page_attachments');
                Schema::dropIfExists('page_versions');
                Schema::dropIfExists('pages');
                Schema::enableForeignKeyConstraints();
            }

            $this->artisan('migrate', [
                '--path' => $this->getModuleBasePath().'/database/migrations',
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
