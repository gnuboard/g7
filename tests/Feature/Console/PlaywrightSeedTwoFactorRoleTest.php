<?php

namespace Tests\Feature\Console;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Playwright 시험 계정 명령의 역할 부여 검증.
 *
 * 일반(관리자 아님) 계정은 회원 화면을 열 수 있어야 한다 — 종전에는 역할을 모두 떼기만 하고 일반 회원 역할(`user`)을
 * 붙이지 않아, 그 계정으로 연 사용자 화면이 상품·알림 API 에서 403 을 냈다(engine-v1.91.1 브라우저 실측 중 발견).
 */
class PlaywrightSeedTwoFactorRoleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 역할을 시드하고 명령의 옵트인 환경변수를 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        putenv('G7_PLAYWRIGHT_BYPASS=1');
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';
    }

    /**
     * 옵트인 환경변수를 지웁니다.
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
     * 일반 계정은 앞선 실행의 관리자 역할을 떼고 일반 회원 역할만 갖는다.
     *
     * @scenario case=non_admin
     *
     * @effects non_admin_account_has_only_member_role
     *
     * @return void 반환값 없음
     */
    public function test_non_admin_account_gets_only_the_member_role(): void
    {
        $this->artisan('playwright:seed-two-factor', ['--ensure-user' => 'roletest', '--admin' => true, '--gc-hours' => 0])->assertSuccessful();
        $this->artisan('playwright:seed-two-factor', ['--ensure-user' => 'roletest', '--gc-hours' => 0])->assertSuccessful();

        $user = User::where('email', 'playwright_2fa_roletest@example.test')->firstOrFail();

        $this->assertSame(['user'], $user->roles()->pluck('identifier')->all());
    }

    /**
     * 관리자 계정은 종전대로 관리자 역할을 갖는다(일반 회원 역할을 덧붙이지 않는다).
     *
     * @scenario case=admin
     *
     * @effects admin_account_keeps_admin_role
     *
     * @return void 반환값 없음
     */
    public function test_admin_account_keeps_the_admin_role(): void
    {
        $this->artisan('playwright:seed-two-factor', ['--ensure-user' => 'roleadmin', '--admin' => true, '--gc-hours' => 0])->assertSuccessful();

        $user = User::where('email', 'playwright_2fa_roleadmin@example.test')->firstOrFail();

        $this->assertContains('admin', $user->roles()->pluck('identifier')->all());
        $this->assertTrue(Role::where('identifier', 'user')->exists(), '시드에 일반 회원 역할이 있어야 이 시험이 의미가 있다');
    }
}
