<?php

namespace Tests\Feature\Console;

use App\Enums\UserStatus;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Listeners\CoreNotificationDataListener;
use App\Listeners\NotificationHookListener;
use App\Notifications\GenericNotification;
use App\Privacy\ManualRun\PasswordExpiryStageRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 만료 처리 커맨드 테스트 (#736 D4·D5·D25·A7·A8)
 *
 * `auth:process-password-expiry` 가 사전 안내 창·만료 경계를 지키고, 주기마다 한 번만 처리하며(표지),
 * '변경해야 로그인' 모드에서만 로그인을 해제하고, 상한·순서·dry-run·락을 지키는지 본다.
 * 기간 90일·안내 7일·시작일 200일 전 기준으로, 변경일 84일 전 = 안내 창 안, 100일 전 = 만료다.
 */
class PasswordExpiryCommandTest extends TestCase
{
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 발행된 훅 이름 => 회원 ID 목록
     *
     * @var array<string, array<int, int>>
     */
    private array $hooks = [];

    /**
     * 훅 캡처 콜백
     *
     * @var array<string, \Closure>
     */
    private array $captures = [];

    /**
     * 두 처리 훅의 발행을 캡처합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['core.auth.password_expiry_notice', 'core.auth.password_expired'] as $hook) {
            $this->captures[$hook] = function ($user) use ($hook) {
                $this->hooks[$hook][] = $user->id;
            };
            HookManager::addAction($hook, $this->captures[$hook], 1);
        }
    }

    /**
     * 캡처 콜백을 떼어 냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->captures as $hook => $callback) {
            HookManager::removeAction($hook, $callback);
        }

        parent::tearDown();
    }

    /**
     * 꺼져 있으면 실행하지 않았다고 알리고 아무것도 처리하지 않는다.
     *
     * @scenario toggle=off
     *
     * @effects disabled_command_processes_nothing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_does_nothing_when_disabled(): void
    {
        $user = $this->makeMember(['password_changed_at' => now()->subDays(100)]);

        $this->artisan('auth:process-password-expiry')
            ->expectsOutputToContain('꺼져 있어 실행하지 않았습니다')
            ->assertSuccessful();

        $this->assertNull($user->fresh()->password_expired_processed_at);
        $this->assertSame([], $this->hooks);
    }

    /**
     * 안내 창(만료 7일 전 이내)의 회원에게만 안내하고, 두 번째 실행은 0건이다.
     *
     * @scenario stage=notice, boundary=inside|outside, rerun=yes
     *
     * @effects notice_window_respected_and_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function notices_respect_the_window_and_are_idempotent(): void
    {
        $this->enableExpiry();
        $inside = $this->makeMember(['password_changed_at' => now()->subDays(84)]);
        $outside = $this->makeMember(['password_changed_at' => now()->subDays(80)]);
        $expired = $this->makeMember(['password_changed_at' => now()->subDays(100)]);

        $this->artisan('auth:process-password-expiry')->assertSuccessful();

        $this->assertSame([$inside->id], $this->hooks['core.auth.password_expiry_notice'] ?? []);
        $this->assertNotNull($inside->fresh()->password_expiry_notified_at);
        $this->assertNull($outside->fresh()->password_expiry_notified_at);
        $this->assertSame([$expired->id], $this->hooks['core.auth.password_expired'] ?? []);

        $this->hooks = [];
        $this->artisan('auth:process-password-expiry')->assertSuccessful();
        $this->assertSame([], $this->hooks, '같은 주기에 두 번 보냈다');
    }

    /**
     * 기능을 다시 켜면(시작일이 지난 안내보다 뒤) 새 주기로 다시 안내한다.
     *
     * @scenario stage=notice, transition=re_enable
     *
     * @effects re_enable_starts_new_cycle
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function re_enabling_starts_a_new_cycle(): void
    {
        $this->enableExpiry(['password_expiry_started_at' => now()->subDays(85)->toIso8601String()]);
        $user = $this->makeMember(['password_changed_at' => null, 'password_expiry_notified_at' => now()->subDays(100)]);

        $this->artisan('auth:process-password-expiry')->assertSuccessful();

        $this->assertSame([$user->id], $this->hooks['core.auth.password_expiry_notice'] ?? []);
    }

    /**
     * '변경해야 로그인' 모드의 만료 처리는 토큰을 전부 지우고 로그인 해제 로그를 남긴다. '다음에 변경 허용' 은 지우지 않는다.
     *
     * @scenario stage=expire, action=force|remind
     *
     * @effects force_mode_revokes_sessions_on_expiry
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function force_mode_revokes_sessions_and_remind_mode_does_not(): void
    {
        $this->enableExpiry();
        $forced = $this->makeMember(['password_changed_at' => now()->subDays(100)]);
        $this->issueToken($forced);

        $this->artisan('auth:process-password-expiry')->assertSuccessful();

        $this->assertSame(0, $forced->tokens()->count());
        $this->assertTrue(DB::table('activity_logs')->where('action', 'auth.password_expiry_logout')->where('loggable_id', $forced->id)->exists());

        $this->enableExpiry(['password_expiry_action' => 'remind']);
        $reminded = $this->makeMember(['password_changed_at' => now()->subDays(100)]);
        $this->issueToken($reminded);

        $this->artisan('auth:process-password-expiry')->assertSuccessful();

        $this->assertSame(1, $reminded->tokens()->count());
        $this->assertNotNull($reminded->fresh()->password_expired_processed_at);
    }

    /**
     * 비활성 상태·관리자 지정 변경 대기·적용 범위 밖 회원은 처리하지 않는다(A7).
     *
     * @scenario stage=expire, exclusion=inactive|change_required|out_of_scope
     *
     * @effects excluded_members_untouched
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function excluded_members_are_left_alone(): void
    {
        $this->enableExpiry(['password_expiry_scope' => 'admin']);
        $blocked = $this->makeMember(['password_changed_at' => now()->subDays(100), 'status' => UserStatus::Blocked->value]);
        $required = $this->makeAdmin(['password_changed_at' => now()->subDays(100), 'password_change_required' => true]);
        $member = $this->makeMember(['password_changed_at' => now()->subDays(100)]);
        $admin = $this->makeAdmin(['password_changed_at' => now()->subDays(100)]);

        $this->artisan('auth:process-password-expiry')->assertSuccessful();

        $this->assertSame([$admin->id], $this->hooks['core.auth.password_expired'] ?? []);
        $this->assertNull($blocked->fresh()->password_expired_processed_at);
        $this->assertNull($required->fresh()->password_expired_processed_at);
        $this->assertNull($member->fresh()->password_expired_processed_at);
    }

    /**
     * dry-run 은 건수만 세고 쓰기·훅이 없다.
     *
     * @scenario run_mode=dry_run
     *
     * @effects dry_run_writes_nothing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $this->enableExpiry();
        $user = $this->makeMember(['password_changed_at' => now()->subDays(100)]);
        $this->issueToken($user);

        $this->artisan('auth:process-password-expiry --dry-run')
            ->expectsOutputToContain('[DRY RUN] 만료일 처리: 1건')
            ->assertSuccessful();

        $this->assertNull($user->fresh()->password_expired_processed_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame([], $this->hooks);
    }

    /**
     * 상한 2 면 만료가 가까운(기준일이 이른) 2명만 처리하고 남은 수를 알린다 — 두 단계 각각.
     *
     * @scenario batch_size=2, stage=notice|expire
     *
     * @effects batch_cap_processes_closest_first
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_batch_cap_processes_the_closest_first(): void
    {
        $this->enableExpiry(['password_expiry_batch_size' => 2]);
        $e1 = $this->makeMember(['password_changed_at' => now()->subDays(120)]);
        $e2 = $this->makeMember(['password_changed_at' => now()->subDays(110)]);
        $e3 = $this->makeMember(['password_changed_at' => now()->subDays(100)]);
        $n1 = $this->makeMember(['password_changed_at' => now()->subDays(89)]);
        $n2 = $this->makeMember(['password_changed_at' => now()->subDays(88)]);
        $n3 = $this->makeMember(['password_changed_at' => now()->subDays(84)]);

        $this->artisan('auth:process-password-expiry')
            ->expectsOutputToContain('만료 사전 안내: 2건 (남은 대상 1건)')
            ->expectsOutputToContain('만료일 처리: 2건 (남은 대상 1건)')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing([$e1->id, $e2->id], $this->hooks['core.auth.password_expired'] ?? []);
        $this->assertEqualsCanonicalizing([$n1->id, $n2->id], $this->hooks['core.auth.password_expiry_notice'] ?? []);
        $this->assertNull($e3->fresh()->password_expired_processed_at);
        $this->assertNull($n3->fresh()->password_expiry_notified_at);
    }

    /**
     * 직권 실행이 락을 잡고 있으면 이번 주기를 건너뛴다.
     *
     * @scenario lock=held
     *
     * @effects lock_skips_run
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_skips_when_the_lock_is_held(): void
    {
        $this->enableExpiry();
        $user = $this->makeMember(['password_changed_at' => now()->subDays(100)]);

        $lock = Cache::lock(PasswordExpiryStageRunner::LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('auth:process-password-expiry')
                ->expectsOutputToContain('건너뛰었습니다')
                ->assertSuccessful();
        } finally {
            $lock->release();
        }

        $this->assertNull($user->fresh()->password_expired_processed_at);
    }

    /**
     * 두 알림 정의가 실제로 회원에게 도달한다(사이트 알림).
     *
     * @scenario stage=notice|expire, delivery=database
     *
     * @effects notifications_reach_members
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function both_notifications_reach_the_member(): void
    {
        $this->artisan('db:seed', ['--class' => 'NotificationDefinitionSeeder']);
        app(NotificationHookListener::class)->registerDynamicHooks();
        HookListenerRegistrar::register(CoreNotificationDataListener::class, 'core');

        $this->enableExpiry();
        $notice = $this->makeMember(['password_changed_at' => now()->subDays(84)]);
        $expired = $this->makeMember(['password_changed_at' => now()->subDays(100)]);
        $other = $this->makeMember(['password_changed_at' => now()->subDays(10)]);

        Notification::fake();

        $this->artisan('auth:process-password-expiry')->assertSuccessful();

        Notification::assertSentTo($notice, GenericNotification::class);
        Notification::assertSentTo($expired, GenericNotification::class);
        Notification::assertNotSentTo($other, GenericNotification::class);
    }
}
