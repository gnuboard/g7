<?php

namespace Tests\Feature\ActivityLog;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\HookManager;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 활동 로그(접속기록) 보관 기간 테스트 (#736 D14)
 *
 * 자동 파기(`activity-log:prune`)는 환경설정 > 보안 「활동 로그 보관 기간」(365~3650일)을 따르고, `--days` 를 직접 줘도
 * 법정 최소 365일 아래로는 지우지 않는다. 역할·권한 변경 기록은 그 값과 관계없이 최소 3년(1095일) 보관한다.
 */
class ActivityLogRetentionTest extends TestCase
{
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 지정한 나이의 활동 로그 한 행을 만듭니다.
     *
     * @param  string  $action  액션
     * @param  int  $daysAgo  며칠 전 기록인지
     * @return ActivityLog 로그
     */
    private function logAged(string $action, int $daysAgo): ActivityLog
    {
        return ActivityLog::create([
            'log_type' => 'admin',
            'action' => $action,
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    /**
     * 행이 남아 있는지 돌려줍니다.
     *
     * @param  ActivityLog  $log  로그
     * @return bool 남아 있으면 true
     */
    private function kept(ActivityLog $log): bool
    {
        return ActivityLog::whereKey($log->id)->exists();
    }

    /**
     * 보안 탭 설정을 저장합니다.
     *
     * @param  mixed  $days  보관 기간 입력값
     * @return TestResponse 응답
     */
    private function saveRetention(mixed $days): TestResponse
    {
        return $this->postJson('/api/admin/settings', [
            '_tab' => 'security',
            'security' => [
                'force_https' => false,
                'login_attempt_enabled' => true,
                'activity_log_retention_days' => $days,
            ],
        ]);
    }

    /**
     * `--days` 없이 돌리면(예약 실행과 같은 형태) 환경설정의 보관 기간을 쓴다.
     *
     * @scenario retention_source=setting, action_class=regular, age=within|beyond_retention
     *
     * @effects prune_uses_configured_retention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function prune_without_days_uses_the_configured_retention(): void
    {
        config(['g7_settings.core.security.activity_log_retention_days' => 400]);

        $within = $this->logAged('user.update', 390);
        $beyond = $this->logAged('user.update', 410);

        $this->artisan('activity-log:prune')
            ->expectsOutputToContain('보존 400일')
            ->assertSuccessful();

        $this->assertTrue($this->kept($within), '설정(400일) 안의 로그가 지워졌다');
        $this->assertFalse($this->kept($beyond), '설정(400일)을 넘긴 로그가 남았다');
    }

    /**
     * `--days` 로 법정 최소보다 짧게 줘도 365일 안의 기록은 지우지 않고, 저장값이 범위 밖이어도 판독부가 클램프한다.
     *
     * @scenario retention_source=days_option|setting, action_class=regular, age=within|beyond_retention
     *
     * @effects prune_never_goes_below_legal_floor
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function prune_never_goes_below_the_legal_floor(): void
    {
        $recent = $this->logAged('user.update', 100);
        $old = $this->logAged('user.update', 370);

        $this->artisan('activity-log:prune', ['--days' => 30])
            ->expectsOutputToContain('보존 365일')
            ->assertSuccessful();

        $this->assertTrue($this->kept($recent), '--days=30 이 법정 최소 365일 안의 기록을 지웠다');
        $this->assertFalse($this->kept($old));

        // 단일 키 저장 경로는 범위를 보지 않는다 — 저장값 10 이어도 판독부가 365 로 올린다.
        config(['g7_settings.core.security.activity_log_retention_days' => 10]);
        $again = $this->logAged('user.update', 200);
        $this->artisan('activity-log:prune')->expectsOutputToContain('보존 365일')->assertSuccessful();
        $this->assertTrue($this->kept($again));
    }

    /**
     * 역할·권한 변경 기록은 일반 보관 기간과 관계없이 3년 보관하고, 일반 보관 기간이 더 길면 그 값을 따른다.
     *
     * @scenario retention_source=setting, action_class=long_retention|regular, age=beyond_retention|beyond_long_retention
     *
     * @effects long_retention_actions_kept_three_years
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function permission_change_logs_are_kept_for_three_years(): void
    {
        $regular = $this->logAged('user.update', 400);
        $roleUpdate = $this->logAged('role.update', 400);
        $userRoles = $this->logAged('user.roles_update', 1000);
        $bulkRoles = $this->logAged('user.bulk_roles', 1000);
        $menuRoles = $this->logAged('menu.sync_roles', 1000);
        $ancientRole = $this->logAged('role.sync_permissions', 1100);

        $this->artisan('activity-log:prune')->assertSuccessful();

        $this->assertFalse($this->kept($regular), '일반 로그는 365일이 지나면 지운다');
        $this->assertTrue($this->kept($roleUpdate), '역할 변경 기록이 3년 전에 지워졌다');
        $this->assertTrue($this->kept($userRoles));
        $this->assertTrue($this->kept($bulkRoles));
        $this->assertTrue($this->kept($menuRoles));
        $this->assertFalse($this->kept($ancientRole), '3년(1095일)이 지난 권한 변경 기록은 지운다');

        // 일반 보관 기간이 3년보다 길면 권한 변경 기록도 그 값을 따른다(더 짧게 지우지 않는다).
        config(['g7_settings.core.security.activity_log_retention_days' => 2000]);
        $longRole = $this->logAged('role.update', 1500);
        $this->artisan('activity-log:prune')->assertSuccessful();
        $this->assertTrue($this->kept($longRole));
    }

    /**
     * 파기 전후 훅은 인자 모양(일수 / 일수·건수)을 그대로 두고, 실제로 적용한 일수를 싣는다.
     *
     * @scenario retention_source=days_option, action_class=regular|long_retention, age=beyond_retention
     *
     * @effects prune_hooks_receive_effective_days
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function prune_hooks_receive_the_effective_days_and_total_count(): void
    {
        $this->logAged('user.update', 400);
        $this->logAged('role.update', 1200);

        $captured = [];
        HookManager::addAction('core.activity_log.before_prune', function (int $days) use (&$captured) {
            $captured[] = ['before', $days];
        });
        HookManager::addAction('core.activity_log.after_prune', function (int $days, int $count) use (&$captured) {
            $captured[] = ['after', $days, $count];
        });

        $this->artisan('activity-log:prune', ['--days' => 30])->assertSuccessful();

        $this->assertSame([['before', 365], ['after', 365, 2]], $captured);
    }

    /**
     * 환경설정 저장 — 365 미만·3650 초과는 422, 범위 안은 정수로 저장된다.
     *
     * @scenario entry_point=settings_save, retention_source=setting
     *
     * @effects retention_setting_bounds_enforced
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function retention_setting_is_bounded_and_saved_as_an_integer(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->saveRetention(364)
            ->assertStatus(422)
            ->assertJsonPath('errors', fn (array $errors) => str_contains(
                (string) ($errors['security.activity_log_retention_days'][0] ?? ''),
                '365',
            ));
        $this->saveRetention(3651)->assertStatus(422)->assertJsonValidationErrors('security.activity_log_retention_days');

        $this->saveRetention('730')->assertOk();

        $stored = app(ConfigRepositoryInterface::class)->getCategory('security');
        $this->assertSame(730, $stored['activity_log_retention_days']);
    }
}
