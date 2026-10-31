<?php

namespace Tests\Feature\Auth;

use App\Extension\HookManager;
use App\Models\PasswordResetToken;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 변경 기록 테스트 (#736 §4.5 — W1~W8)
 *
 * 모든 비밀번호 쓰기 경로가 한 곳(`PasswordChangeService`)에서 변경일·표지·변경 요구 플래그를 기록하고,
 * 경로·행위자를 아는 `core.auth.password_change_recorded` 를 발행하며, 활동 로그가 경로별 문구로 남는지
 * 본다. 로그인 재해시(W8)는 평문이 그대로라 변경으로 세지 않는다.
 */
class PasswordChangeBookkeepingTest extends TestCase
{
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 기록 훅 발행 내역
     *
     * @var array<int, array{user_id: int, context: array<string, mixed>}>
     */
    private array $recorded = [];

    /**
     * 기록 훅 캡처 콜백
     *
     * @var \Closure|null
     */
    private ?\Closure $capture = null;

    /**
     * 종전 변경 훅(`core.auth.after_password_changed`) 발행 대상 회원 id 목록
     *
     * @var array<int, int>
     */
    private array $changedNotified = [];

    /**
     * 종전 변경 훅 캡처 콜백
     *
     * @var \Closure|null
     */
    private ?\Closure $changedCapture = null;

    /**
     * 메일을 막고 기록 훅·종전 변경 훅을 캡처합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->capture = function ($user, array $context = []) {
            $this->recorded[] = ['user_id' => $user->id, 'context' => $context];
        };
        HookManager::addAction('core.auth.password_change_recorded', $this->capture, 1);

        // 종전 훅은 알림 `password_changed` 의 구독 대상이라 모든 변경 경로에서 계속 나가야 한다(A12).
        $this->changedCapture = function ($user) {
            $this->changedNotified[] = $user->id;
        };
        HookManager::addAction('core.auth.after_password_changed', $this->changedCapture, 1);
    }

    /**
     * 캡처 콜백을 떼어 냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->capture !== null) {
            HookManager::removeAction('core.auth.password_change_recorded', $this->capture);
        }
        if ($this->changedCapture !== null) {
            HookManager::removeAction('core.auth.after_password_changed', $this->changedCapture);
        }

        parent::tearDown();
    }

    /**
     * 공통 단언: 변경일이 오늘로 남고 이번 주기 표지·연기가 비워졌는지 봅니다.
     *
     * @param  User  $user  회원
     * @return void 반환값 없음
     */
    private function assertCycleReset(User $user): void
    {
        $fresh = $user->fresh();
        $this->assertTrue($fresh->password_changed_at?->isToday() ?? false, '변경일이 오늘로 남아야 만료 주기가 다시 시작된다');
        $this->assertNull($fresh->password_expiry_notified_at);
        $this->assertNull($fresh->password_expired_processed_at);
        $this->assertNull($fresh->password_change_postponed_until);
    }

    /**
     * 이번 주기 표지·연기가 찍혀 있는 회원 속성입니다.
     *
     * @return array<string, mixed> 속성
     */
    private function markedCycle(): array
    {
        return [
            'password_changed_at' => now()->subDays(100),
            'password_expiry_notified_at' => now()->subDays(10),
            'password_expired_processed_at' => now()->subDay(),
            'password_change_postponed_until' => now()->addDays(3),
        ];
    }

    /**
     * 가입(W1)은 변경일을 남기고 변경 요구를 걸지 않는다.
     *
     * @scenario entry_point=register
     *
     * @effects changed_at_recorded_on_create
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function register_records_the_changed_at(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => '가입 회원',
            'email' => 'pw-register@example.test',
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
            'agree_terms' => true,
            'agree_privacy' => true,
        ])->assertCreated();

        $user = User::where('email', 'pw-register@example.test')->firstOrFail();
        $this->assertNotNull($user->password_changed_at);
        $this->assertFalse($user->password_change_required);
    }

    /**
     * 메일 재설정(W2)은 변경일·표지 초기화·source=reset 기록·종전 재설정 로그를 남긴다.
     *
     * @scenario entry_point=reset_password
     *
     * @effects reset_records_change_and_logs_reset
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function reset_password_records_the_change(): void
    {
        $user = $this->makeMember([
            'password_expiry_notified_at' => now()->subDay(),
            'password_expired_processed_at' => now()->subDay(),
            'password_change_postponed_until' => now()->addDays(3),
            'password_change_required' => true,
        ]);
        $plain = Str::random(40);
        PasswordResetToken::query()->updateOrCreate(['email' => $user->email], ['token' => Hash::make($plain), 'created_at' => now()]);

        $this->postJson('/api/auth/reset-password', [
            'token' => $plain,
            'email' => $user->email,
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->password_changed_at);
        $this->assertNull($fresh->password_expiry_notified_at);
        $this->assertNull($fresh->password_expired_processed_at);
        $this->assertNull($fresh->password_change_postponed_until);
        $this->assertFalse($fresh->password_change_required, '본인이 정한 비밀번호라 변경 요구가 풀린다');

        $this->assertSame('reset', $this->lastRecordedFor($user)['source'] ?? null);
        $this->assertTrue(DB::table('activity_logs')->where('action', 'auth.reset_password')->where('loggable_id', $user->id)->exists());
        $this->assertContains($user->id, $this->changedNotified, '재설정도 비밀번호 변경 알림 훅을 발행한다');
    }

    /**
     * 마이페이지 변경(W3)은 source=self 로 기록하고 "비밀번호 변경(본인)" 로그를 남긴다.
     *
     * @scenario entry_point=change_password
     *
     * @effects self_change_records_and_logs_self
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function change_password_records_a_self_change(): void
    {
        $user = $this->makeMember($this->markedCycle());
        Sanctum::actingAs($user);

        $this->putJson('/api/me/password', [
            'current_password' => 'Passw0rd!old',
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
        ])->assertOk();

        $this->assertCycleReset($user);

        $context = $this->lastRecordedFor($user);
        $this->assertSame('self', $context['source'] ?? null);
        $this->assertSame($user->id, (int) ($context['actor_id'] ?? 0));
        $this->assertContains($user->id, $this->changedNotified);

        $row = DB::table('activity_logs')->where('action', 'auth.password_change')->where('loggable_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('activity_log.description.auth_password_change_self', $row->description_key);
    }

    /**
     * 정보수정 API(W4)로 비밀번호를 함께 바꿔도 같은 기록이 남는다 — source=self·행위자=본인·표지 초기화·종전 훅.
     *
     * @scenario entry_point=update_profile
     *
     * @effects self_change_records_and_logs_self
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function profile_update_records_a_self_change(): void
    {
        $user = $this->makeMember($this->markedCycle());
        Sanctum::actingAs($user);

        $this->putJson('/api/me', [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'Passw0rd!old',
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
        ])->assertOk();

        $this->assertTrue(Hash::check('Passw0rd!new', $user->fresh()->password));
        $this->assertCycleReset($user);

        $context = $this->lastRecordedFor($user);
        $this->assertSame('self', $context['source'] ?? null);
        $this->assertSame($user->id, (int) ($context['actor_id'] ?? 0));
        $this->assertContains($user->id, $this->changedNotified);
    }

    /**
     * 로그인 변경 단계는 source=login_required·행위자=본인으로 기록하고, 활동 로그 문구를 사유별로 남긴다.
     *
     * @scenario entry_point=login_password_change, reason=expired|admin_assigned
     *
     * @effects login_stage_records_reason_specific_log
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function login_change_step_records_a_reason_specific_change(): void
    {
        $this->enableExpiry();

        $cases = [
            // 표지·연기를 심어 두어야 assertCycleReset 이 초기화를 실제로 잰다('변경해야 로그인' 모드라 연기 값은 관문에 영향 없음)
            'expired' => [$this->makeMember($this->markedCycle()), 'activity_log.description.auth_password_change_on_login_expired'],
            'admin_assigned' => [$this->makeMember(array_merge($this->markedCycle(), ['password_change_required' => true])), 'activity_log.description.auth_password_change_on_login_admin_assigned'],
        ];

        foreach ($cases as $reason => [$user, $descriptionKey]) {
            // 사전 상태: 표지·연기가 실제로 찍혀 있어야 아래 assertCycleReset 이 초기화를 잰다
            $before = $user->fresh();
            $this->assertNotNull($before->password_expiry_notified_at, $reason);
            $this->assertNotNull($before->password_expired_processed_at, $reason);
            $this->assertNotNull($before->password_change_postponed_until, $reason);

            $challengeId = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Passw0rd!old'])
                ->assertOk()->assertJsonPath('data.reason', $reason)->json('data.challenge_id');

            $this->postJson('/api/auth/login/password-change', [
                'challenge_id' => $challengeId,
                'password' => 'Passw0rd!new',
                'password_confirmation' => 'Passw0rd!new',
            ])->assertOk();

            $this->assertCycleReset($user);

            $context = $this->lastRecordedFor($user);
            $this->assertSame('login_required', $context['source'] ?? null, $reason);
            $this->assertSame($user->id, (int) ($context['actor_id'] ?? 0), $reason);
            $this->assertContains($user->id, $this->changedNotified, $reason.' — 로그인 단계도 비밀번호 변경 알림 훅을 발행한다');

            $row = DB::table('activity_logs')->where('action', 'auth.password_change')->where('loggable_id', $user->id)->first();
            $this->assertNotNull($row, $reason);
            $this->assertSame($descriptionKey, $row->description_key);
        }
    }

    /**
     * 관리자가 다른 회원의 비밀번호를 정하면(W5) source=admin·행위자=관리자·다음 로그인 때 변경 필수.
     *
     * @scenario entry_point=admin_update_user, target=other
     *
     * @effects admin_assignment_sets_change_required
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_assignment_records_an_admin_change(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember($this->markedCycle());
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/users/'.$member->uuid, [
            'name' => $member->name,
            'email' => $member->email,
            'role_ids' => $member->roles->pluck('id')->all(),
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
        ])->assertOk();

        $this->assertTrue((bool) $member->fresh()->password_change_required);
        $this->assertCycleReset($member);

        $context = $this->lastRecordedFor($member);
        $this->assertSame('admin', $context['source'] ?? null);
        $this->assertSame($admin->id, (int) ($context['actor_id'] ?? 0));
        $this->assertContains($member->id, $this->changedNotified);

        $row = DB::table('activity_logs')->where('action', 'auth.password_change')->where('loggable_id', $member->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($admin->id, (int) $row->user_id);
    }

    /**
     * 관리자 회원 등록(W6)은 변경일을 남기고 다음 로그인 때 변경을 요구한다(D11).
     *
     * @scenario entry_point=admin_create_user
     *
     * @effects admin_assignment_sets_change_required
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_create_requires_a_change_on_next_login(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/users', [
            'name' => '등록 회원',
            'email' => 'pw-admin-create@example.test',
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
        ])->assertSuccessful();

        $user = User::where('email', 'pw-admin-create@example.test')->firstOrFail();
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue($user->password_change_required);
    }

    /**
     * 설치 마법사(W7)는 최고관리자의 변경일을 남기고 변경 요구는 걸지 않는다.
     *
     * @scenario entry_point=install
     *
     * @effects changed_at_recorded_on_create
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function install_seeder_records_the_changed_at(): void
    {
        putenv('INSTALLER_ADMIN_EMAIL=pw-install@example.test');
        putenv('INSTALLER_ADMIN_PASSWORD=Passw0rd!install');

        try {
            $this->seed(AdminUserSeeder::class);
        } finally {
            putenv('INSTALLER_ADMIN_EMAIL');
            putenv('INSTALLER_ADMIN_PASSWORD');
        }

        $user = User::where('email', 'pw-install@example.test')->firstOrFail();
        $this->assertNotNull($user->password_changed_at);
        $this->assertFalse($user->password_change_required);
    }

    /**
     * 로그인 재해시(W8)는 평문이 그대로라 변경일을 바꾸지 않고 기록 훅도 발행하지 않는다.
     *
     * @scenario entry_point=login_rehash
     *
     * @effects rehash_is_not_a_change
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function login_rehash_is_not_counted_as_a_change(): void
    {
        $user = $this->makeMember();
        // 테스트의 기본 강도(4)와 다른 강도로 만들어 두면 로그인 때 재해시된다.
        // 모델 `hashed` 캐스트는 다른 강도의 해시 저장을 거부하므로 저장소에 그대로 쓴다.
        DB::table('users')->where('id', $user->id)->update([
            'password' => Hash::make('Passw0rd!old', ['rounds' => 5]),
            'password_changed_at' => null,
        ]);
        $before = $user->fresh()->getRawOriginal('password');

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Passw0rd!old'])->assertOk();

        $fresh = $user->fresh();
        $this->assertNotSame($before, $fresh->getRawOriginal('password'), '재해시가 일어나야 이 축을 잴 수 있다');
        $this->assertNull($fresh->password_changed_at, '재해시를 변경으로 세면 만료가 무력화된다');
        $this->assertNull($this->lastRecordedFor($user));
    }

    /**
     * 회원에 대해 마지막으로 발행된 기록 훅 문맥을 돌려줍니다.
     *
     * @param  User  $user  회원
     * @return array<string, mixed>|null 문맥 (없으면 null)
     */
    private function lastRecordedFor(User $user): ?array
    {
        $matches = array_values(array_filter($this->recorded, fn (array $row) => $row['user_id'] === $user->id));

        return $matches === [] ? null : end($matches)['context'];
    }
}
