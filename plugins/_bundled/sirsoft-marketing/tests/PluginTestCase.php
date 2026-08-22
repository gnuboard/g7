<?php

namespace Plugins\Sirsoft\Marketing\Tests;

use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Marketing\Repositories\Contracts\MarketingConsentRepositoryInterface;
use Plugins\Sirsoft\Marketing\Repositories\MarketingConsentRepository;
use Tests\TestCase;

/**
 * Marketing 플러그인 테스트 베이스 클래스
 *
 * 모든 Marketing 플러그인 테스트는 이 클래스를 상속받아야 합니다.
 * 코어 + 플러그인 마이그레이션을 자동으로 처리합니다.
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 환경 설정
     *
     * 플러그인 라우트는 테스트 환경에서 플러그인이 로드되지 않으므로
     * 직접 등록합니다.
     */
    /**
     * HookManager static state 스냅샷 — tearDown 에서 복원하여 테스트 간 훅 격리 보장.
     *
     * @var array{hooks: array, filters: array, dispatching: array}|null
     */
    private ?array $hookSnapshot = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(MarketingConsentRepositoryInterface::class, MarketingConsentRepository::class);

        $this->registerPluginRoutes();

        // HookManager 상태 스냅샷 (tearDown 에서 복원)
        $this->snapshotHookManager();
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
        $ref = new \ReflectionClass(HookManager::class);
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

        $ref = new \ReflectionClass(HookManager::class);
        $ref->getProperty('hooks')->setValue(null, $this->hookSnapshot['hooks']);
        $ref->getProperty('filters')->setValue(null, $this->hookSnapshot['filters']);
        $ref->getProperty('dispatching')->setValue(null, $this->hookSnapshot['dispatching']);

        $this->hookSnapshot = null;
    }

    /**
     * 플러그인 API 라우트를 실제 라우트 파일에서 등록합니다.
     *
     * 테스트 안에 라우트 정의를 복제하면 라우트 파일의 미들웨어 변경이 테스트에 도달하지
     * 않아, 권한 게이트가 빠져도 검사가 통과한다. 프로덕션 PluginRouteServiceProvider 와
     * 동일한 prefix/name 으로 실제 파일을 그대로 로드한다.
     *
     * @return void
     */
    protected function registerPluginRoutes(): void
    {
        $apiRoutesFile = base_path('plugins/sirsoft-marketing/src/routes/api.php');

        if (file_exists($apiRoutesFile)) {
            Route::prefix('api/plugins/sirsoft-marketing')
                ->name('api.plugins.sirsoft-marketing.')
                ->middleware('api')
                ->group($apiRoutesFile);
        }
    }

    /**
     * 관리자 권한을 가진 사용자를 생성합니다.
     *
     * isAdmin()이 Role/Permission 기반이므로 admin Role과 type=admin Permission을 직접 생성합니다.
     *
     * @param  array<int, string>  $permissions  추가로 부여할 업무 권한 식별자 목록
     * @return User 생성된 관리자
     */
    protected function createAdminUser(array $permissions = []): User
    {
        $user = User::factory()->create();

        // 사용자마다 고유 역할을 만든다 — 공용 'admin' 역할을 재사용하면 한 테스트 안에서
        // 권한 보유 관리자에게 부여한 권한이 무권한 관리자에게도 새어 음성 케이스가 무력화된다.
        $adminRole = Role::create([
            'identifier' => 'admin-test-'.$user->id.'-'.uniqid(),
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
            'description' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
        ]);

        $permission = Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            ['name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'], 'type' => PermissionType::Admin]
        );

        $adminRole->permissions()->syncWithoutDetaching([$permission->id]);

        foreach ($permissions as $identifier) {
            $granted = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin]
            );
            $adminRole->permissions()->syncWithoutDetaching([$granted->id]);
        }

        $user->roles()->attach($adminRole->id);

        return $user;
    }

    /**
     * 마이그레이션 경로를 반환합니다.
     *
     * RefreshDatabase의 migrate:fresh 명령에 코어 + 플러그인 마이그레이션 경로를 전달합니다.
     *
     * @return array
     */
    protected function migrateFreshUsing(): array
    {
        // RefreshDatabase 는 첫 테스트의 migrateFreshUsing 만 적용하므로
        // Plugin suite 실행 시에도 필요한 테이블이 모두 있도록 모든 번들 확장의
        // migrations 를 포함시킨다.
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
            '--seed' => false,
            '--path' => $paths,
        ];
    }
}
