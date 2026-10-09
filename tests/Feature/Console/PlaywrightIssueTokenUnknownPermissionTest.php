<?php

namespace Tests\Feature\Console;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `playwright:issue-token` 이 DB 에 없는 권한 식별자를 발급 단계에서 거절하는지 검증합니다.
 *
 * 7.1.0 전수 실측 O-D2 · 결정(2026-10-08, 「발급 때 거절」): 커맨드가 없는 식별자를 받으면
 * `Permission::firstOrCreate` 로 이름·설명이 식별자 그대로인 코어 소유 권한 행을 만들어, 관리자 권한 트리에
 * 식별자가 그대로 보이는 가짜 권한이 쌓였다. 게다가 그 가짜 권한은 실제 라우트가 검사하는 권한이 아니어서
 * spec 은 「권한을 받았다」고 믿지만 실제로는 admin 역할 덕에 통과하는 거짓 녹색이 됐다.
 * 이제는 행을 만들지 않고 명령이 실패 코드와 함께 없는 식별자를 알린다. 계정·역할·토큰도 만들지 않는다.
 */
class PlaywrightIssueTokenUnknownPermissionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 커맨드의 옵트인 가드를 통과시킵니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        putenv('G7_PLAYWRIGHT_BYPASS=1');
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';
    }

    /**
     * 옵트인 가드 환경변수를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        putenv('G7_PLAYWRIGHT_BYPASS');
        unset($_ENV['G7_PLAYWRIGHT_BYPASS'], $_SERVER['G7_PLAYWRIGHT_BYPASS']);

        parent::tearDown();
    }

    /**
     * 실제로 있는 권한 행 하나를 만듭니다(설치된 확장이 정의한 권한을 흉내).
     *
     * @param  string  $identifier  권한 식별자
     * @return Permission 권한
     */
    private function makeExistingPermission(string $identifier): Permission
    {
        return Permission::create([
            'identifier' => $identifier,
            'name' => ['ko' => '실측용 권한', 'en' => 'Measured permission'],
            'description' => ['ko' => '실측용 권한', 'en' => 'Measured permission'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => 'admin',
        ]);
    }

    /**
     * 없는 식별자가 하나라도 섞이면 실패 코드로 끝나고, 없는 식별자를 출력에 알리며, 권한·역할·계정을 하나도 만들지 않는다.
     *
     * @scenario case=unknown_permission
     *
     * @effects e2e_token_rejects_unknown_permission, e2e_token_creates_nothing_on_rejection
     *
     * @return void 반환값 없음
     */
    public function test_unknown_permission_is_rejected_without_side_effects(): void
    {
        $this->makeExistingPermission('core.qa736rb.existing');

        $permissionsBefore = Permission::count();
        $rolesBefore = Role::where('identifier', 'like', 'playwright_test_%')->count();
        $usersBefore = User::count();

        $this->artisan('playwright:issue-token', [
            '--permissions' => ['core.qa736rb.existing', 'core.qa736rb.no_such_permission:self'],
            '--gc-hours' => 0,
        ])
            ->expectsOutputToContain('core.qa736rb.no_such_permission')
            ->assertFailed();

        $this->assertSame($permissionsBefore, Permission::count(), '없는 권한 행을 만들면 안 된다');
        $this->assertFalse(Permission::where('identifier', 'core.qa736rb.no_such_permission')->exists());
        $this->assertSame($rolesBefore, Role::where('identifier', 'like', 'playwright_test_%')->count(), '테스트 역할을 만들면 안 된다');
        $this->assertSame($usersBefore, User::count(), '고아 계정을 남기면 안 된다');
    }

    /**
     * 있는 식별자만 받으면 종전대로 토큰을 발급하고 그 권한을 붙인다 — 권한 행은 새로 생기지 않는다.
     *
     * @scenario case=existing_permission
     *
     * @effects e2e_token_grants_existing_permission
     *
     * @return void 반환값 없음
     */
    public function test_existing_permission_is_granted_without_new_rows(): void
    {
        $permission = $this->makeExistingPermission('core.qa736rb.existing');
        $permissionsBefore = Permission::count();

        $this->artisan('playwright:issue-token', [
            '--permissions' => ['core.qa736rb.existing'],
            '--no-admin-role' => true,
            '--gc-hours' => 0,
        ])->assertSuccessful();

        $this->assertSame($permissionsBefore, Permission::count());

        $role = Role::where('identifier', 'like', 'playwright_test_%')->orderByDesc('id')->firstOrFail();
        $this->assertSame([$permission->id], $role->permissions()->pluck('permissions.id')->all());
    }
}
