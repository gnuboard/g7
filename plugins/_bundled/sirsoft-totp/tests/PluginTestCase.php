<?php

namespace Plugins\Sirsoft\Totp\Tests;

use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;
use Plugins\Sirsoft\Totp\Repositories\TotpCredentialRepository;
use Tests\TestCase;

/**
 * 인증 앱(TOTP) 플러그인 테스트 베이스 클래스.
 *
 * 모든 플러그인 테스트는 이 클래스를 상속합니다. 코어 + 번들 확장 마이그레이션과
 * Repository 바인딩, 플러그인 API 라우트 등록을 함께 처리합니다 (gdpr 선례 동형).
 *
 * @since 1.0.0
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * HookManager static state 스냅샷 — tearDown 에서 복원해 테스트 간 훅을 격리한다.
     *
     * @var array{hooks: array, filters: array, dispatching: array}|null
     */
    private ?array $hookSnapshot = null;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotHookManager();

        // TotpServiceProvider 와 동일한 Repository 바인딩.
        $this->app->bind(TotpCredentialRepositoryInterface::class, TotpCredentialRepository::class);

        $this->registerPluginApiRoutes();
    }

    /**
     * 테스트를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();

        parent::tearDown();
    }

    /**
     * 플러그인 API 라우트를 테스트 앱에 등록합니다.
     *
     * `PluginRouteServiceProvider` 는 `plugins` 테이블의 활성 행을 대조해서만 라우트를
     * 등록합니다(#603). 테스트는 `RefreshDatabase` 로 매번 빈 테이블에서 부팅하므로 그
     * 게이트가 항상 닫히고 이 플러그인의 모든 엔드포인트가 404 가 됩니다 — 실패가 권한·검증이
     * 아니라 "주소 없음" 으로 나타나 원인이 드러나지 않습니다.
     *
     * 활성 행을 심어도 낫지 않습니다. 라우트 등록은 `parent::setUp()` 의 앱 부팅 시점에
     * 끝나고 DB 초기화는 그 뒤에 오기 때문입니다.
     *
     * prefix·name·middleware 는 프로바이더와 동일하게 맞춥니다 — 어긋나면 테스트가
     * 통과해도 운영 주소와 다른 곳을 밟게 됩니다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginApiRoutes(): void
    {
        $apiRoutesFile = dirname(__DIR__).'/src/routes/api.php';

        if (! file_exists($apiRoutesFile)) {
            return;
        }

        Route::prefix('api/plugins/sirsoft-totp')
            ->name('api.plugins.sirsoft-totp.')
            ->middleware('api')
            ->group($apiRoutesFile);
    }

    /**
     * HookManager static 상태를 스냅샷합니다.
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
     * 스냅샷 시점으로 HookManager 를 복원합니다.
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
     * 관리자 권한을 가진 사용자를 생성합니다.
     *
     * @return User 관리자 사용자
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
     * 인증 앱 등록 해제 권한을 가진 관리자를 생성합니다.
     *
     * plugin.php::getPermissions() 의 categories.credentials 와 동기화합니다.
     *
     * @return User 인증 앱 운영자
     */
    protected function createCredentialOperatorUser(): User
    {
        $role = Role::firstOrCreate(
            ['identifier' => 'sirsoft-totp.credentials'],
            [
                'name' => ['ko' => '인증 앱 운영자', 'en' => 'Authenticator Operator'],
                'description' => ['ko' => '인증 앱 운영', 'en' => 'Authenticator operations'],
            ]
        );

        foreach ([
            ['identifier' => 'admin.access', 'name' => ['ko' => '관리자 접근', 'en' => 'Admin Access']],
            ['identifier' => 'sirsoft-totp.credentials.delete', 'name' => ['ko' => '회원 인증 앱 등록 해제', 'en' => 'Remove Member Enrollment']],
        ] as $data) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $data['identifier']],
                ['name' => $data['name'], 'type' => PermissionType::Admin]
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
     * RefreshDatabase 의 migrate:fresh 에 코어 + 번들 확장 경로를 전달합니다.
     *
     * @return array<string, mixed> migrate:fresh 옵션
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
