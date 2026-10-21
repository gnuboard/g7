<?php

namespace Tests\Feature\Upgrades;

use App\Enums\ScopeType;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Extension\UpgradeContext;
use App\Models\Permission;
use App\Models\Role;
use App\Upgrades\Data\V7_1_1\Migrations\RevokeGuestScopedPermissionGrants;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\TestCase;

/**
 * 7.1.1 업그레이드 스텝이 비회원 역할의 권한 범위 부여를 회수하는지 검증합니다 (D8).
 *
 * 비회원은 범위를 판정할 신원이 없어 「본인만」 이 실제로는 「전체」 였다. 스텝은 범위가 붙은 비회원 부여만 지우고,
 * 회수 목록을 user_overrides 에 남겨 다음 동기화가 범위 없이(전체) 되살리지 못하게 한다.
 */
class Upgrade_7_1_1_RevokeGuestScopedGrantsTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, array{level: string, message: string, context: array}> upgrade 채널 기록 */
    private array $logs = [];

    private Role $guest;

    /**
     * 코어 역할·권한을 심고 upgrade 로그 채널을 기록기로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/data/7.1.1/migrations/02_RevokeGuestScopedPermissionGrants.php');
        $this->seed(RolePermissionSeeder::class);
        $this->guest = Role::where('identifier', 'guest')->firstOrFail();

        $logs = &$this->logs;
        $collector = new class($logs) extends AbstractLogger
        {
            /**
             * 기록 대상 배열을 참조로 받습니다.
             *
             * @param  array<int, array{level: string, message: string, context: array}>  $logs  기록 대상 배열(참조)
             */
            public function __construct(private array &$logs) {}

            /**
             * 로그 한 줄을 기록합니다.
             *
             * @param  mixed  $level  수준
             * @param  string|Stringable  $message  메시지
             * @param  array  $context  문맥
             * @return void 반환값 없음
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        Log::partialMock()->shouldReceive('channel')->with('upgrade')->andReturn($collector);
    }

    /**
     * 역할에 권한을 범위와 함께 붙입니다.
     *
     * @param  Role  $role  역할
     * @param  string  $identifier  권한 식별자
     * @param  ScopeType|null  $scope  범위
     * @return void 반환값 없음
     */
    private function grant(Role $role, string $identifier, ?ScopeType $scope): void
    {
        $permission = Permission::where('identifier', $identifier)->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id => ['scope_type' => $scope?->value, 'granted_at' => now()]]);
    }

    /**
     * 역할이 가진 권한별 범위를 돌려줍니다.
     *
     * @param  Role  $role  역할
     * @return array<string, string|null> 식별자 => 범위
     */
    private function grantsOf(Role $role): array
    {
        return DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $role->id)
            ->orderBy('permissions.identifier')
            ->pluck('role_permissions.scope_type', 'permissions.identifier')
            ->map(fn ($scope) => $scope === null ? null : (string) $scope)
            ->all();
    }

    /**
     * 데이터 스텝을 7.1.0 → 7.1.1 로 한 번 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new RevokeGuestScopedPermissionGrants)->run(new UpgradeContext('7.1.0', '7.1.1', '7.1.1'));
    }

    /**
     * 비회원의 범위 부여만 지우고 건마다 경고 로그를 남긴다. 범위 없는 비회원 부여·다른 역할의 범위 부여는 그대로다.
     *
     * @scenario case=revoke_scoped_guest_grants
     *
     * @effects guest_scoped_grants_deleted, revoke_logged_per_grant, other_roles_untouched, unscoped_guest_grants_untouched
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_revokes_only_scoped_guest_grants_and_logs_each(): void
    {
        $this->grant($this->guest, 'core.users.read', ScopeType::Self);
        $this->grant($this->guest, 'core.menus.read', ScopeType::Role);
        $manager = Role::where('identifier', 'manager')->firstOrFail();
        $managerBefore = $this->grantsOf($manager);
        $guestUnscopedBefore = array_filter($this->grantsOf($this->guest), fn ($scope) => $scope === null);

        $this->runStep();

        $after = $this->grantsOf($this->guest);
        $this->assertArrayNotHasKey('core.users.read', $after);
        $this->assertArrayNotHasKey('core.menus.read', $after);
        $this->assertSame($guestUnscopedBefore, $after, '범위 없는 비회원 부여는 그대로 남아야 한다');
        $this->assertSame($managerBefore, $this->grantsOf($manager), '다른 역할의 범위 부여는 건드리지 않는다');

        $warnings = array_values(array_filter($this->logs, fn ($log) => $log['level'] === 'warning'));
        $this->assertSame(
            [['core.menus.read', 'role'], ['core.users.read', 'self']],
            array_map(fn ($log) => [$log['context']['permission'], $log['context']['scope_type']], $warnings)
        );
    }

    /**
     * 회수한 식별자를 user_overrides 에 합집합으로 남겨, 이후 코어·확장 동기화가 범위 없이(전체) 되살리지 못한다.
     *
     * @scenario case=resync_after_revoke
     *
     * @effects revoked_identifiers_recorded_in_user_overrides, later_sync_does_not_reattach_as_full_access
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_records_user_overrides_so_later_sync_does_not_reattach(): void
    {
        DB::table('roles')->where('id', $this->guest->id)->update(['user_overrides' => json_encode(['name.ko'])]);
        $this->grant($this->guest, 'core.users.read', ScopeType::Self);

        $this->runStep();

        $this->assertSame(['name.ko', 'core.users.read'], $this->guest->fresh()->user_overrides);
        $this->assertContains('[7.1.1] 비회원 역할 user_overrides 에 회수한 권한 1건을 기록했습니다', array_column($this->logs, 'message'));

        // 확장 정의가 같은 권한을 비회원에게 다시 준다고 해도 운영자 결정(회수)이 이긴다.
        app(ExtensionRoleSyncHelper::class)->syncAllRoleAssignments(
            ['core.users.read' => ['guest']],
            ['core.users.read'],
        );
        $this->assertArrayNotHasKey('core.users.read', $this->grantsOf($this->guest->fresh()));
    }

    /**
     * 회수한 식별자가 user_overrides 에 이미 있으면 그 칸을 바꾸지 않고, 그 분기도 로그로 남긴다.
     *
     * @scenario case=overrides_already_recorded
     *
     * @effects already_recorded_overrides_untouched_and_logged
     *
     * @return void 반환값 없음
     */
    public function test_already_recorded_overrides_are_left_and_logged(): void
    {
        DB::table('roles')->where('id', $this->guest->id)->update(['user_overrides' => json_encode(['core.users.read'])]);
        $this->grant($this->guest, 'core.users.read', ScopeType::Self);

        $this->runStep();

        $this->assertArrayNotHasKey('core.users.read', $this->grantsOf($this->guest));
        $this->assertSame(['core.users.read'], $this->guest->fresh()->user_overrides);
        $this->assertContains('[7.1.1] 비회원 역할 user_overrides 에 회수한 권한이 이미 모두 기록돼 있어 바꾸지 않습니다', array_column($this->logs, 'message'));
    }

    /**
     * 두 번째 실행은 바꿀 것이 없다 — 행·user_overrides 그대로, 「없음」 로그만 남는다.
     *
     * @scenario case=idempotent
     *
     * @effects revoke_step_is_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_second_run_changes_nothing(): void
    {
        $this->grant($this->guest, 'core.users.read', ScopeType::Self);
        $this->runStep();
        $grants = $this->grantsOf($this->guest);
        $overrides = $this->guest->fresh()->user_overrides;
        $this->logs = [];

        $this->runStep();

        $this->assertSame($grants, $this->grantsOf($this->guest));
        $this->assertSame($overrides, $this->guest->fresh()->user_overrides);
        $this->assertSame(['info'], array_column($this->logs, 'level'));
        $this->assertStringContainsString('부여가 없습니다', $this->logs[0]['message']);
    }

    /**
     * 비회원 역할이 없는 설치본은 로그만 남기고 끝낸다.
     *
     * @scenario case=no_guest_role
     *
     * @effects missing_guest_role_is_skipped_with_log
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_missing_guest_role_is_skipped_with_log(): void
    {
        DB::table('roles')->where('id', $this->guest->id)->update(['identifier' => 'guest_renamed_for_test']);

        $this->runStep();

        $this->assertCount(1, $this->logs);
        $this->assertSame('info', $this->logs[0]['level']);
        $this->assertStringContainsString('비회원 역할이 없음', $this->logs[0]['message']);
    }
}
