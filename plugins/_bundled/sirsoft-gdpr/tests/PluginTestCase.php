<?php

namespace Plugins\Sirsoft\Gdpr\Tests;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $ref = new \ReflectionClass(\App\Extension\HookManager::class);
        $this->hookSnapshot = [
            'hooks'       => $ref->getProperty('hooks')->getValue(),
            'filters'     => $ref->getProperty('filters')->getValue(),
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

        $ref = new \ReflectionClass(\App\Extension\HookManager::class);
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
            $paths[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $p);
        }
        foreach (glob(base_path('plugins/_bundled/*/database/migrations'), GLOB_ONLYDIR) as $p) {
            $paths[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $p);
        }

        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed'       => false,
            '--path'       => $paths,
        ];
    }
}
