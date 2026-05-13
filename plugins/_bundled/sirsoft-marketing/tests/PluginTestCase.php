<?php

namespace Plugins\Sirsoft\Marketing\Tests;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Marketing\Repositories\Contracts\MarketingConsentRepositoryInterface;
use Plugins\Sirsoft\Marketing\Http\Controllers\MarketingAdminController;
use Plugins\Sirsoft\Marketing\Http\Controllers\MarketingSettingsController;
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

        Route::prefix('api/plugins/sirsoft-marketing')
            ->middleware('api')
            ->group(function () {
                Route::get('/settings', [MarketingSettingsController::class, 'settings'])
                    ->name('api.sirsoft-marketing.settings');

                Route::prefix('admin')
                    ->middleware('auth:sanctum')
                    ->group(function () {
                        Route::put('/channels', [MarketingAdminController::class, 'updateChannels'])
                            ->name('api.sirsoft-marketing.admin.channels.update');
                    });
            });

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
     * 관리자 권한을 가진 사용자를 생성합니다.
     *
     * isAdmin()이 Role/Permission 기반이므로 admin Role과 type=admin Permission을 직접 생성합니다.
     *
     * @return User
     */
    protected function createAdminUser(): User
    {
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => ['ko' => '관리자', 'en' => 'Admin'], 'description' => ['ko' => '관리자', 'en' => 'Admin']]
        );

        $permission = Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            ['name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'], 'type' => PermissionType::Admin]
        );

        $adminRole->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create();
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
