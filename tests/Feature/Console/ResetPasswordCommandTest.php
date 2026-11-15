<?php

namespace Tests\Feature\Console;

use App\Console\Commands\User\ResetPasswordCommand;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\PasswordChangeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * `user:reset-password` — 메일 없이 서버 터미널에서 비밀번호를 재설정한다 (메일 재설정과 같은 서비스·같은 규칙).
 *
 * 회귀 성격: 메일을 보낼 수 없는 서버에서는 tinker 로 비밀번호를 바꾸는 것 말고 방법이 없었고, 그렇게 바꾸면
 * 정책 검증·잠금 해제·로그아웃·알림이 빠지고 활동 로그가 「회원 본인 변경」으로 남았다.
 */
class ResetPasswordCommandTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /** 테스트 회원 */
    private User $user;

    /** 비밀번호 파일 */
    private string $passwordFile = '';

    /**
     * 잠긴 회원(로그인 토큰 2개)과 비밀번호 파일을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'reset-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => Hash::make('Old-Passw0rd!x'),
        ]);
        $this->user->forceFill(['failed_login_attempts' => 9, 'locked_until' => now()->addDay()])->save();
        $this->user->createToken('a');
        $this->user->createToken('b');

        $this->passwordFile = sys_get_temp_dir().'/g7-reset-'.bin2hex(random_bytes(4)).'.txt';
    }

    /**
     * 정리.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        @unlink($this->passwordFile);
        putenv('G7_RESET_PASSWORD');

        parent::tearDown();
    }

    /**
     * 파일로 준 비밀번호로 재설정 — 잠금 해제·모든 기기 로그아웃·완료 훅(알림)·변경 강제 없음.
     *
     * @effects reset_password_unlocks_revokes_sessions_and_notifies
     *
     * @return void 반환값 없음
     */
    public function test_resets_from_file_unlocks_revokes_and_notifies(): void
    {
        file_put_contents($this->passwordFile, "New-Passw0rd!Strong\n");
        $notified = 0;
        $listener = function () use (&$notified) {
            $notified++;
        };
        HookManager::addAction('core.auth.after_password_changed', $listener);

        try {
            $this->artisan('user:reset-password', ['email' => $this->user->email, '--password-file' => $this->passwordFile])
                ->assertExitCode(0);
        } finally {
            HookManager::removeAction('core.auth.after_password_changed', $listener);
        }

        $fresh = $this->user->fresh();
        $this->assertTrue(Hash::check('New-Passw0rd!Strong', $fresh->password), '파일 끝 개행 하나는 제거된다');
        $this->assertSame(0, $fresh->tokens()->count(), '모든 기기 로그아웃');
        $this->assertNull($fresh->locked_until, '잠금 해제');
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
        $this->assertFalse((bool) $fresh->password_change_required, '기본은 변경 강제 없음');
        $this->assertSame(1, $notified, '비밀번호 변경 완료 훅(알림 발송 지점) 1회');
    }

    /**
     * 활동 로그는 행위자 없는 「시스템(CLI)」 재설정이다 — 회원 본인 변경으로 남지 않는다.
     *
     * @effects reset_password_logs_system_actor_without_user
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_has_no_user_and_system_type(): void
    {
        putenv('G7_RESET_PASSWORD=Env-Passw0rd!Strong');

        $this->artisan('user:reset-password', ['email' => $this->user->email])->assertExitCode(0);

        $row = DB::table('activity_logs')->where('action', 'auth.reset_password')->where('loggable_id', $this->user->id)->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
        $this->assertSame('system', $row->log_type);
        $this->assertSame('activity_log.description.auth_reset_password_by_console', $row->description_key);
    }

    /**
     * 웹 재설정 직전 가드 훅(본인인증 정책·인증 대기 회원 거부)은 터미널에서 발화하지 않는다 —
     * 브라우저 본인인증을 마칠 수 없는 셸에서 정책을 켠 사이트의 복구 수단이 막히지 않게.
     *
     * @effects reset_password_console_skips_web_reset_guard_hook
     *
     * @return void 반환값 없음
     */
    public function test_console_reset_does_not_fire_web_reset_guard_hook(): void
    {
        putenv('G7_RESET_PASSWORD=Env-Passw0rd!Strong');
        $guard = static function (): void {
            throw new \RuntimeException('web-only guard');
        };
        HookManager::addAction('core.auth.before_reset_password', $guard);

        try {
            $this->artisan('user:reset-password', ['email' => $this->user->email])->assertExitCode(0);
        } finally {
            HookManager::removeAction('core.auth.before_reset_password', $guard);
        }

        $this->assertTrue(Hash::check('Env-Passw0rd!Strong', $this->user->fresh()->password));
    }

    /**
     * 확장이 filter 훅으로 답한 안내를 결과에 덧붙인다 — 코어는 어느 확장이 있는지 묻지 않는다.
     *
     * @effects reset_password_prints_extension_notices_from_filter
     *
     * @return void 반환값 없음
     */
    public function test_prints_extension_notices_from_filter(): void
    {
        putenv('G7_RESET_PASSWORD=Env-Passw0rd!Strong');
        $filter = static fn (array $notices, User $user): array => [...$notices, 'extension-notice-for '.$user->email];
        HookManager::addFilter('core.auth.console_password_reset_notices', $filter);

        try {
            $this->artisan('user:reset-password', ['email' => $this->user->email])
                ->expectsOutputToContain('extension-notice-for '.$this->user->email)
                ->assertExitCode(0);
        } finally {
            HookManager::removeFilter('core.auth.console_password_reset_notices', $filter);
        }
    }

    /**
     * 재설정 도중 실패하면 사유 한 줄로 끝내고(종료 1) 비밀번호는 그대로다.
     *
     * @effects reset_password_reports_failure_without_change
     *
     * @return void 반환값 없음
     */
    public function test_failure_inside_reset_is_reported_and_rolled_back(): void
    {
        putenv('G7_RESET_PASSWORD=Env-Passw0rd!Strong');
        $this->mock(PasswordChangeService::class)
            ->shouldReceive('recordChange')->andThrow(new \RuntimeException('history write failed'));

        $this->artisan('user:reset-password', ['email' => $this->user->email])
            ->expectsOutputToContain('history write failed')
            ->assertExitCode(1);

        $this->assertTrue(Hash::check('Old-Passw0rd!x', $this->user->fresh()->password));
    }

    /**
     * 정책 위반(짧은 비밀번호)은 거부하고 아무것도 바꾸지 않는다.
     *
     * @effects reset_password_rejects_policy_violation
     *
     * @return void 반환값 없음
     */
    public function test_rejects_policy_violation(): void
    {
        putenv('G7_RESET_PASSWORD=short');

        $this->artisan('user:reset-password', ['email' => $this->user->email])->assertExitCode(1);

        $this->assertTrue(Hash::check('Old-Passw0rd!x', $this->user->fresh()->password));
        $this->assertSame(2, $this->user->fresh()->tokens()->count());
    }

    /**
     * 재사용 금지가 켜져 있으면 지금 비밀번호를 다시 쓰는 재설정을 거부한다 (웹 메일 재설정과 같은 규칙).
     *
     * @effects reset_password_rejects_reused_password
     *
     * @return void 반환값 없음
     */
    public function test_rejects_reused_password_when_prevention_enabled(): void
    {
        $this->setSecurity(['password_reuse_prevention_enabled' => true, 'password_reuse_count' => 3]);
        putenv('G7_RESET_PASSWORD=Old-Passw0rd!x');

        $this->artisan('user:reset-password', ['email' => $this->user->email])
            ->expectsOutputToContain(trans_choice('validation.password_policy.reused', 3))
            ->assertExitCode(1);

        $this->assertSame(2, $this->user->fresh()->tokens()->count(), '거부되면 로그아웃도 없다');
        $this->assertNotNull($this->user->fresh()->locked_until, '거부되면 잠금도 그대로다');
    }

    /**
     * --generate 는 정책을 만족하는 비밀번호를 한 번만 출력하고 다음 로그인 변경을 강제한다.
     *
     * @effects reset_password_generate_prints_once_and_requires_change
     *
     * @return void 반환값 없음
     */
    public function test_generate_prints_password_once_and_requires_change(): void
    {
        $exit = Artisan::call('user:reset-password', ['email' => $this->user->email, '--generate' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $lines = array_map('trim', preg_split('/\R/u', $output));
        $noticeAt = array_search(__('user.commands.reset_password.generated_once'), $lines, true);
        $this->assertNotFalse($noticeAt, '1회 출력 안내 문구가 없다');
        $printed = (string) ($lines[$noticeAt + 1] ?? '');

        // 안내 다음 줄이 실제 새 비밀번호이고, 출력 전체에서 딱 한 번만 나온다.
        $this->assertTrue(Hash::check($printed, $this->user->fresh()->password), '출력한 값이 저장된 새 비밀번호가 아니다');
        $this->assertSame(1, substr_count($output, $printed), '생성 비밀번호가 두 번 이상 출력됐다');
        $this->assertTrue((bool) $this->user->fresh()->password_change_required);
        $this->assertFalse(Hash::check('Old-Passw0rd!x', $this->user->fresh()->password));
    }

    /**
     * --require-change 는 변경 강제를 켠다.
     *
     * @effects reset_password_require_change_flag
     *
     * @return void 반환값 없음
     */
    public function test_require_change_flag(): void
    {
        putenv('G7_RESET_PASSWORD=Env-Passw0rd!Strong');

        $this->artisan('user:reset-password', ['email' => $this->user->email, '--require-change' => true])->assertExitCode(0);

        $this->assertTrue((bool) $this->user->fresh()->password_change_required);
    }

    /**
     * 없는 이메일은 실패한다.
     *
     * @effects reset_password_unknown_email_fails
     *
     * @return void 반환값 없음
     */
    public function test_unknown_email_fails(): void
    {
        $this->artisan('user:reset-password', ['email' => 'nobody-'.bin2hex(random_bytes(3)).'@example.test'])->assertExitCode(1);
    }

    /**
     * 비밀번호를 명령줄 인자로 받는 옵션이 없다 (프로세스 목록·셸 기록 노출 방지).
     *
     * @effects reset_password_has_no_password_argument
     *
     * @return void 반환값 없음
     */
    public function test_has_no_command_line_password_option(): void
    {
        $definition = Artisan::all()['user:reset-password']->getDefinition();

        $this->assertFalse($definition->hasOption('password'));
        $this->assertSame(['email'], array_keys($definition->getArguments()));
    }

    /**
     * 이메일을 생략하면 단 하나의 최고관리자를 대상으로 보여 주고, 확인을 받은 뒤 그 계정만 재설정한다 (#790).
     *
     * 회귀 성격: 비밀번호를 잊은 최고관리자는 설치 때 넣은 이메일도 기억하지 못해 이 명령을 쓸 수 없었다(tinker 로 우회).
     *
     * @effects reset_password_without_email_targets_single_super_admin_after_confirm
     *
     * @return void 반환값 없음
     */
    public function test_without_email_resets_single_super_admin_after_confirm(): void
    {
        $super = $this->onlySuperAdmins(1)[0];
        putenv('G7_RESET_PASSWORD=Super-Passw0rd!Strong');

        $this->artisan('user:reset-password')
            ->expectsOutputToContain(__('user.commands.reset_password.super_admin_target', ['email' => $super->email]))
            ->expectsQuestion(__('user.commands.reset_password.confirm_super_admin').' (yes/no) [no]', 'yes')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('Super-Passw0rd!Strong', $super->fresh()->password), '최고관리자 비밀번호가 바뀌어야 한다');
        $this->assertTrue(Hash::check('Old-Passw0rd!x', $this->user->fresh()->password), '최고관리자가 아닌 회원은 그대로');
    }

    /**
     * 확인에 「아니오」로 답하면 아무것도 바꾸지 않고 의도한 취소(0)로 끝난다.
     *
     * @effects reset_password_without_email_declined_changes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_without_email_declined_changes_nothing(): void
    {
        $super = $this->onlySuperAdmins(1)[0];
        putenv('G7_RESET_PASSWORD=Super-Passw0rd!Strong');

        $this->artisan('user:reset-password')
            ->expectsQuestion(__('user.commands.reset_password.confirm_super_admin').' (yes/no) [no]', 'no')
            ->expectsOutputToContain(__('user.commands.reset_password.aborted'))
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('Old-Passw0rd!x', $super->fresh()->password), '거절했는데 비밀번호가 바뀌었다');
        $this->assertSame(1, $super->fresh()->tokens()->count(), '거절했는데 로그아웃됐다');
    }

    /**
     * 최고관리자가 여럿이면 고르게 하고, 고른 계정만 재설정한다.
     *
     * @effects reset_password_without_email_chooses_among_super_admins
     *
     * @return void 반환값 없음
     */
    public function test_without_email_chooses_among_super_admins(): void
    {
        [$first, $second] = $this->onlySuperAdmins(2);
        putenv('G7_RESET_PASSWORD=Super-Passw0rd!Strong');

        $this->artisan('user:reset-password')
            ->expectsChoice(__('user.commands.reset_password.choose_super_admin'), $second->email, [$first->email, $second->email])
            ->expectsQuestion(__('user.commands.reset_password.confirm_super_admin').' (yes/no) [no]', 'yes')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('Super-Passw0rd!Strong', $second->fresh()->password), '고른 계정이 바뀌어야 한다');
        $this->assertTrue(Hash::check('Old-Passw0rd!x', $first->fresh()->password), '고르지 않은 계정은 그대로');
    }

    /**
     * 확인에 답할 수 없는 실행(--no-interaction, 콘솔 없는 원격 명령)은 대상을 추측하지 않는다 —
     * 최고관리자 이메일을 보여 주고 다시 실행할 명령을 안내한 뒤 실패(1)로 끝난다.
     *
     * @effects reset_password_without_email_unanswerable_lists_super_admins_and_fails
     *
     * @return void 반환값 없음
     */
    public function test_without_email_unanswerable_lists_super_admins_and_fails(): void
    {
        $super = $this->onlySuperAdmins(1)[0];
        putenv('G7_RESET_PASSWORD=Super-Passw0rd!Strong');

        $this->artisan('user:reset-password', ['--no-interaction' => true])
            ->expectsOutputToContain(__('user.commands.reset_password.super_admin_unconfirmable'))
            ->expectsOutputToContain('- '.$super->email)
            ->expectsOutputToContain('php artisan user:reset-password '.$super->email)
            ->assertExitCode(1);
        $this->assertTrue(Hash::check('Old-Passw0rd!x', $super->fresh()->password));

        // `--no-interaction` 없이도 콘솔이 없는 실행(Windows 원격 명령 등)은 같은 길로 간다
        $this->app[Kernel::class]->registerCommand(new class extends ResetPasswordCommand
        {
            /**
             * 응답을 받을 수 없는 실행으로 판정합니다.
             *
             * @return bool 늘 false
             */
            protected function canPromptForAnswer(): bool
            {
                return false;
            }
        });
        $this->artisan('user:reset-password')
            ->expectsOutputToContain(__('user.commands.reset_password.super_admin_unconfirmable'))
            ->assertExitCode(1);
        $this->assertTrue(Hash::check('Old-Passw0rd!x', $super->fresh()->password));
    }

    /**
     * 최고관리자가 없으면(탈퇴한 최고관리자만 있는 경우 포함) 이메일을 지정하라고 하고 실패한다.
     *
     * @effects reset_password_without_email_fails_when_no_super_admin
     *
     * @return void 반환값 없음
     */
    public function test_without_email_fails_when_no_super_admin(): void
    {
        $withdrawn = $this->onlySuperAdmins(1)[0];
        $withdrawn->forceFill(['status' => UserStatus::Withdrawn->value, 'withdrawn_at' => now()])->save();
        putenv('G7_RESET_PASSWORD=Super-Passw0rd!Strong');

        $this->artisan('user:reset-password')
            ->expectsOutputToContain(__('user.commands.reset_password.no_super_admin'))
            ->assertExitCode(1);
    }

    /**
     * 회원 상태 때문에 비밀번호를 바꿔도 로그인할 수 없으면(차단 등) 그 이유를 경고한다. 상태는 바꾸지 않는다.
     *
     * @effects reset_password_warns_when_status_still_blocks_login
     *
     * @return void 반환값 없음
     */
    public function test_warns_when_status_still_blocks_login(): void
    {
        putenv('G7_RESET_PASSWORD=Env-Passw0rd!Strong');

        // 로그인이 막히는 상태(사용 중지·차단·인증 대기)는 경고하고, 상태는 바꾸지 않는다
        foreach ([UserStatus::Inactive, UserStatus::Blocked, UserStatus::PendingVerification] as $blocked) {
            $this->user->forceFill(['status' => $blocked->value])->save();

            $this->artisan('user:reset-password', ['email' => $this->user->email])
                ->expectsOutputToContain(__('user.commands.reset_password.status_blocks_login', ['status' => $blocked->label()]))
                ->assertExitCode(0);
            $this->assertSame($blocked->value, (string) $this->user->fresh()->getRawOriginal('status'), $blocked->value.' 상태는 바꾸지 않는다');
        }

        // 로그인이 되는 상태(활성·휴면)에는 경고가 없다 — 판정은 로그인 경로와 같은 UserStatus::allowsLogin()
        foreach ([UserStatus::Active, UserStatus::Dormant] as $allowed) {
            $this->user->forceFill(['status' => $allowed->value])->save();
            $this->artisan('user:reset-password', ['email' => $this->user->email])
                ->doesntExpectOutputToContain(__('user.commands.reset_password.status_blocks_login', ['status' => $allowed->label()]))
                ->assertExitCode(0);
        }
    }

    /**
     * 시험용 최고관리자만 남긴다 — 기존 최고관리자는 이 트랜잭션 안에서만 내려 둔다(롤백으로 복원).
     *
     * @param  int  $count  만들 최고관리자 수
     * @return array<int, User> 만든 최고관리자 (가입 순, 각자 로그인 토큰 1개)
     */
    private function onlySuperAdmins(int $count): array
    {
        User::query()->where('is_super', true)->update(['is_super' => false]);

        $supers = [];
        for ($i = 0; $i < $count; $i++) {
            $super = User::factory()->create([
                'email' => 'super-'.$i.'-'.bin2hex(random_bytes(4)).'@example.test',
                'password' => Hash::make('Old-Passw0rd!x'),
            ]);
            $super->forceFill(['is_super' => true])->save();
            $super->createToken('s');
            $supers[] = $super;
        }

        return $supers;
    }
}
