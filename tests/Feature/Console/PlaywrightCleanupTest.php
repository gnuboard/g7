<?php

namespace Tests\Feature\Console;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Playwright E2E 잔여물 회수 커맨드 검증.
 *
 * `playwright:issue-token` 은 호출마다 격리용 역할과 계정을 새로 만들고 지우지 않는다.
 * g7.dev 실측에서 활성 역할 2,264개 중 2,242개가 그 잔여물이었고, 전량을 반환하는
 * `/api/admin/roles/active` 가 1.7MB · 8초를 쓰게 되어 그 목록을 당기는 관리자 화면이
 * 함께 느려졌다. 회수 커맨드는 **자기가 만든 것만** 지워야 한다.
 *
 * ## 왜 RefreshDatabase 를 쓰지 않는가
 *
 * `RefreshDatabase` 는 `migrate:fresh` 로 전 스키마를 drop + recreate 한다. 이 저장소의
 * 로컬 MySQL(8.4) 은 그 DDL 도중 반복적으로 OOM 으로 죽어 왔다 —
 * `mysqld.log` 의 크래시 7건이 전부 마이그레이션 중이며 유발 쿼리가
 * `alter table g7_ecommerce_shipping_carriers` · `g7_board_posts` ·
 * `g7_gnuboard7_hello_module_memos` 같은 인덱스 생성이다.
 * 게시판 스위트가 `BoardTestCase::refreshTestDatabase()` 를 무력화해 그 연산을 피하는 것도
 * 같은 이유다.
 *
 * 이 테스트가 필요로 하는 것은 `roles` · `users` · 그 피벗뿐이라 스키마를 다시 만들 이유가 없다.
 * 트랜잭션 격리로 바꿔 **검증 범위는 그대로 두고** 위험한 DDL 만 뺐다. 대신 기존 데이터와
 * 공존해야 하므로 고정 식별자를 쓰지 않는다 — 역할 이름에 매 실행 고유 접미어를 붙이고,
 * `admin` 처럼 이미 있을 수 있는 행은 `firstOrCreate` 로 받는다.
 *
 * @group console
 */
class PlaywrightCleanupTest extends TestCase
{
    use DatabaseTransactions;

    /** 이 실행에서만 쓰는 접미어 — 기존 데이터·병렬 실행과 충돌하지 않게 한다 */
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->suffix = uniqid();

        // 커맨드의 옵트인 가드 통과 (CLI 가드는 PHPUnit 자체가 CLI 라 이미 충족)
        putenv('G7_PLAYWRIGHT_BYPASS=1');
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
    }

    protected function tearDown(): void
    {
        putenv('G7_PLAYWRIGHT_BYPASS');
        unset($_ENV['G7_PLAYWRIGHT_BYPASS']);

        parent::tearDown();
    }

    /**
     * 이 실행 전용 역할을 만듭니다.
     *
     * 스키마를 다시 만들지 않으므로 기존 데이터와 공존한다 — 식별자에 실행 고유 접미어를 붙여
     * 이미 있는 행과 부딪히지 않게 한다.
     *
     * @param  string  $prefix  역할 식별자 접두어 (`playwright_test_` 이면 회수 대상이 된다)
     * @return Role 생성된 역할
     */
    private function makeRole(string $prefix): Role
    {
        $identifier = $prefix.$this->suffix;

        return Role::create([
            'identifier' => $identifier,
            'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
            'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
            'is_active' => true,
        ]);
    }

    /**
     * `admin` 역할을 확보합니다 — 발급 커맨드와 같이 이미 있으면 그것을 씁니다.
     *
     * @return Role admin 역할
     */
    private function adminRole(): Role
    {
        return Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Admin']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Admin']),
                'is_active' => true,
            ]
        );
    }

    /**
     * 유저에 역할을 부여합니다.
     *
     * @param  User  $user  대상 유저
     * @param  Role  ...$roles  부여할 역할들
     */
    private function attach(User $user, Role ...$roles): void
    {
        foreach ($roles as $role) {
            $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
    }

    /**
     * 옵트인 환경변수 없이는 아무것도 지우지 않는다.
     *
     * @scenario mode=delete
     *
     * @effects cleanup_refused_without_optin
     */
    public function test_refuses_without_bypass_env(): void
    {
        putenv('G7_PLAYWRIGHT_BYPASS');
        unset($_ENV['G7_PLAYWRIGHT_BYPASS']);

        $role = $this->makeRole('playwright_test_optin_');

        $this->artisan('playwright:cleanup')->assertExitCode(1);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    /**
     * 잔여 역할과 그 전용 계정만 회수하고, 사람 계정·일반 역할은 남긴다.
     *
     * @scenario mode=delete
     *
     * @effects leftover_roles_removed, leftover_users_removed, human_and_normal_role_kept
     */
    public function test_reclaims_only_its_own_leftovers(): void
    {
        $adminRole = $this->adminRole();
        $normalRole = $this->makeRole('editor_');

        $leftoverRoleA = $this->makeRole('playwright_test_a_');
        $leftoverRoleB = $this->makeRole('playwright_test_b_');

        // 발급 커맨드가 만드는 모양: 신규 계정 + admin + 격리 역할
        $throwawayA = User::factory()->create();
        $this->attach($throwawayA, $adminRole, $leftoverRoleA);

        $throwawayB = User::factory()->create();
        $this->attach($throwawayB, $adminRole, $leftoverRoleB);

        // 사람이 쓰는 계정에 테스트 역할이 섞인 경우 — 계정은 남아야 한다
        $human = User::factory()->create();
        $this->attach($human, $normalRole, $leftoverRoleA);

        $this->artisan('playwright:cleanup')->assertExitCode(0);

        $this->assertDatabaseMissing('roles', ['id' => $leftoverRoleA->id]);
        $this->assertDatabaseMissing('roles', ['id' => $leftoverRoleB->id]);
        $this->assertDatabaseMissing('users', ['id' => $throwawayA->id]);
        $this->assertDatabaseMissing('users', ['id' => $throwawayB->id]);

        $this->assertDatabaseHas('users', ['id' => $human->id]);
        $this->assertDatabaseHas('roles', ['id' => $adminRole->id]);
        $this->assertDatabaseHas('roles', ['id' => $normalRole->id]);

        // 남긴 사람 계정에 죽은 역할 참조가 남지 않는다
        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $human->id,
            'role_id' => $leftoverRoleA->id,
        ]);
    }

    /**
     * --dry-run 은 세기만 하고 지우지 않는다.
     *
     * @scenario mode=dry_run
     *
     * @effects nothing_deleted_on_dry_run
     */
    public function test_dry_run_deletes_nothing(): void
    {
        $role = $this->makeRole('playwright_test_dry_');
        $user = User::factory()->create();
        $this->attach($user, $role);

        $this->artisan('playwright:cleanup --dry-run')->assertExitCode(0);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /**
     * --keep-hours 는 그 시간 안에 만들어진 발급분을 남긴다 (진행 중인 실행 보호).
     *
     * @scenario mode=delete
     *
     * @effects recent_leftovers_kept, old_leftovers_removed
     */
    public function test_keep_hours_protects_recent_issues(): void
    {
        $recent = $this->makeRole('playwright_test_recent_');

        $old = $this->makeRole('playwright_test_old_');
        DB::table('roles')->where('id', $old->id)->update(['created_at' => now()->subHours(5)]);

        $this->artisan('playwright:cleanup --keep-hours=2')->assertExitCode(0);

        $this->assertDatabaseHas('roles', ['id' => $recent->id]);
        $this->assertDatabaseMissing('roles', ['id' => $old->id]);
    }

    /**
     * 잔여물이 없으면 조용히 통과한다.
     *
     * @scenario mode=delete
     *
     * @effects no_op_when_clean
     */
    public function test_no_leftovers_is_a_noop(): void
    {
        // 스키마를 다시 만들지 않으므로 기존 잔여물이 있을 수 있다 — 「없는 상태」를 먼저 만든다.
        // 트랜잭션 안이라 이 삭제도 테스트 종료 시 되돌아간다.
        Role::where('identifier', 'like', 'playwright_test_%')->delete();

        $this->assertSame(
            0,
            Role::where('identifier', 'like', 'playwright_test_%')->count(),
            '사전조건: 잔여 역할이 0건이어야 이 갈래를 검증할 수 있습니다.'
        );

        $this->artisan('playwright:cleanup')
            ->expectsOutputToContain('회수할 Playwright 잔여물이 없습니다.')
            ->assertExitCode(0);
    }
}
