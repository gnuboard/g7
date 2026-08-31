<?php

namespace Plugins\Sirsoft\Gdpr\Tests;

use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprPolicyVersionRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentHistoryRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\GdprPolicyVersionRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentHistoryRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentRepository;
use Tests\TestCase;

/**
 * GDPR 플러그인 테스트 베이스 클래스
 *
 * 모든 GDPR 플러그인 테스트는 이 클래스를 상속받아야 합니다.
 * 코어 + 플러그인 마이그레이션을 자동으로 처리합니다.
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * HookManager static state 스냅샷 — tearDown 에서 복원하여 테스트 간 훅 격리 보장.
     *
     * @var array{hooks: array, filters: array, dispatching: array}|null
     */
    private ?array $hookSnapshot = null;

    /**
     * 테스트 환경 설정
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // HookManager 상태 스냅샷 (tearDown 에서 복원)
        $this->snapshotHookManager();

        // Repository 인터페이스 ↔ 구현체 바인딩 (GdprServiceProvider와 동일)
        $this->app->bind(GdprUserConsentRepositoryInterface::class, GdprUserConsentRepository::class);
        $this->app->bind(GdprUserConsentHistoryRepositoryInterface::class, GdprUserConsentHistoryRepository::class);
        $this->app->bind(GdprPolicyVersionRepositoryInterface::class, GdprPolicyVersionRepository::class);

        $this->registerPluginApiRoutes();
    }

    /**
     * 플러그인 API 라우트를 테스트 앱에 등록합니다.
     *
     * `PluginRouteServiceProvider` 는 `plugins` 테이블의 활성 행을 대조해서만 라우트를
     * 등록합니다(#603). 테스트는 `RefreshDatabase` 로 매번 빈 테이블에서 부팅하므로 그
     * 게이트가 항상 닫히고, 이 플러그인의 모든 엔드포인트가 404 가 됩니다 — 실패는
     * 권한·검증이 아니라 "주소 없음" 으로 나타나 원인이 드러나지 않습니다.
     *
     * 활성 행을 심는 것으로는 낫지 않습니다. 라우트 등록은 `parent::setUp()` 의 앱 부팅
     * 시점에 끝나고 DB 초기화는 그 뒤에 오기 때문입니다. 다른 번들 확장의 테스트 베이스도
     * 같은 이유로 라우트 파일을 직접 그룹에 물립니다.
     *
     * prefix·name·middleware 는 프로바이더와 동일하게 맞춥니다 — 어긋나면 테스트가
     * 통과해도 운영 주소와 다른 곳을 밟게 됩니다.
     *
     * @return void
     */
    protected function registerPluginApiRoutes(): void
    {
        $apiRoutesFile = dirname(__DIR__).'/src/routes/api.php';

        if (! file_exists($apiRoutesFile)) {
            return;
        }

        Route::prefix('api/plugins/sirsoft-gdpr')
            ->name('api.plugins.sirsoft-gdpr.')
            ->middleware('api')
            ->group($apiRoutesFile);
    }

    /**
     * tearDown 에 HookManager 상태 복원.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();

        parent::tearDown();
    }

    /**
     * HookManager static $hooks / $filters / $dispatching 를 스냅샷.
     *
     * @return void
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
     * @return void
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
     * 관리자 권한을 가진 사용자를 생성합니다.
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
     * 개인정보 운영자(privacy) 권한을 가진 사용자를 생성합니다.
     *
     * @return User
     */
    protected function createPrivacyOperatorUser(): User
    {
        $role = Role::firstOrCreate(
            ['identifier' => 'sirsoft-gdpr.privacy'],
            ['name' => ['ko' => '개인정보 운영자', 'en' => 'Privacy Operator'], 'description' => ['ko' => 'Privacy', 'en' => 'Privacy']]
        );

        // plugin.php::getPermissions() 의 categories.privacy 와 동기화.
        // view: 동의 이력·설정 조회 / update: 설정 변경
        $permissions = [
            ['identifier' => 'sirsoft-gdpr.privacy.view', 'name' => ['ko' => '개인정보 조회', 'en' => 'View Privacy'], 'type' => PermissionType::Admin],
            ['identifier' => 'sirsoft-gdpr.privacy.update', 'name' => ['ko' => '개인정보 설정 변경', 'en' => 'Update Privacy Settings'], 'type' => PermissionType::Admin],
        ];

        foreach ($permissions as $pData) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $pData['identifier']],
                ['name' => $pData['name'], 'type' => $pData['type']]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

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
