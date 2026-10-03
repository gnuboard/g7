<?php

namespace Tests\Feature\Privacy;

use App\Enums\PermissionType;
use App\Enums\UserStatus;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Listeners\CorePrivacyNotificationDataListener;
use App\Listeners\Identity\ActivateUserOnIdentityVerified;
use App\Listeners\NotificationHookListener;
use App\Models\IdentityVerificationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Privacy\DormantAccountService;
use App\Privacy\Handlers\WithdrawnUserRetentionHandler;
use App\Services\AuthService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\Identity\EnrollmentRequiredProvider;
use Tests\TestCase;

/**
 * 휴면 계정 생명주기 회귀 테스트 (이슈 #636 S4 · 2단계형 설계 확정)
 *
 * 통지 → 전환 → 해제 → 탈퇴의 각 단계와, **단계마다 상태가 어떻게 남아 있어야 하는지**를
 * 고정합니다. 특히 두 가지를 구조적으로 못박습니다.
 *
 * 1. 사전 안내 없이는 전환도 탈퇴도 일어나지 않는다 (법정 사전 안내).
 * 2. 활동 기준은 로그인 시각만이 아니라 토큰 사용 시각까지 본다 (설계 확정) — 앱·토큰으로만
 *    쓰는 활동 중인 회원이 자동 탈퇴로 사라지는 것을 막는다.
 *
 * @scenario target=privacy_dormant_accounts, row_age=within_retention, row_age=beyond_retention
 *
 * @effects dormancy_requires_advance_notice_before_conversion, dormant_account_is_restored_on_login, dormant_activity_basis_includes_token_last_used_at
 */
class DormantLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 휴면 설정을 켭니다.
     *
     * @param  bool  $withdraw  휴면 후 자동 탈퇴까지 켤지 여부
     * @return void 반환값 없음
     */
    private function enableDormancy(bool $withdraw = false): void
    {
        Config::set('g7_settings.core.privacy.dormant_enabled', true);
        Config::set('g7_settings.core.privacy.dormant_days', 730);
        Config::set('g7_settings.core.privacy.dormant_notice_days', 30);
        Config::set('g7_settings.core.privacy.dormant_withdraw_enabled', $withdraw);
        Config::set('g7_settings.core.privacy.dormant_withdraw_days', 365);
    }

    /**
     * 마지막 로그인이 N일 전인 활성 회원을 만듭니다.
     *
     * @param  int  $daysAgo  마지막 로그인 경과일
     * @return User 생성된 회원
     */
    private function createInactiveUser(int $daysAgo): User
    {
        return User::factory()->create([
            'status' => UserStatus::Active->value,
            'last_login_at' => now()->subDays($daysAgo),
            'created_at' => now()->subDays($daysAgo + 10),
        ]);
    }

    /**
     * B1: 전환 예정일이 가까운 회원에게 사전 안내를 보내고 표지를 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=pre_notice, toggle=on
     *
     * @effects dormancy_requires_advance_notice_before_conversion
     */
    public function test_advance_notice_is_sent_and_marked(): void
    {
        $this->enableDormancy();
        $user = $this->createInactiveUser(705); // 730 - 30 = 700일 경과분이 대상

        $service = app(DormantAccountService::class);

        $this->assertSame(1, $service->notifyUpcomingDormancy(dryRun: true));
        $this->assertSame(1, $service->notifyUpcomingDormancy(dryRun: false));

        $this->assertNotNull($user->fresh()->dormant_notified_at);
        $this->assertSame(UserStatus::Active->value, $user->fresh()->status, '안내 단계에서 상태는 그대로다');
    }

    /**
     * B2: 사전 안내는 한 번만 나간다 (매일 재발송 금지).
     *
     * @return void 반환값 없음
     *
     * @scenario stage=pre_notice, toggle=on
     *
     * @effects dormancy_requires_advance_notice_before_conversion
     */
    public function test_advance_notice_is_not_repeated(): void
    {
        $this->enableDormancy();
        $this->createInactiveUser(705);

        $service = app(DormantAccountService::class);
        $service->notifyUpcomingDormancy(dryRun: false);

        $this->assertSame(0, $service->notifyUpcomingDormancy(dryRun: false));
    }

    /**
     * B3: 안내를 받지 않은 회원은 아무리 오래 미접속이어도 전환되지 않는다.
     *
     * 운영자가 기능을 오늘 처음 켰을 때 이미 오래 미접속인 회원이 그날 바로 휴면이
     * 되면 법이 요구하는 사전 안내가 사라진다 — 그 경로를 구조적으로 막는다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=convert, toggle=on
     *
     * @effects dormancy_requires_advance_notice_before_conversion
     */
    public function test_conversion_never_happens_without_advance_notice(): void
    {
        $this->enableDormancy();
        $user = $this->createInactiveUser(3000);

        $service = app(DormantAccountService::class);

        $this->assertSame(0, $service->convertToDormant(dryRun: false));
        $this->assertSame(UserStatus::Active->value, $user->fresh()->status);

        // 같은 실행에서 안내를 보냈더라도 통지 기간이 지나기 전에는 전환되지 않는다.
        $service->notifyUpcomingDormancy(dryRun: false);
        $this->assertSame(0, $service->convertToDormant(dryRun: false));
        $this->assertSame(UserStatus::Active->value, $user->fresh()->status);
    }

    /**
     * B4: 안내 후 통지 기간이 지나면 휴면으로 전환되고 표지가 초기화된다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=convert, toggle=on
     *
     * @effects dormancy_requires_advance_notice_before_conversion
     */
    public function test_conversion_happens_after_the_notice_period(): void
    {
        $this->enableDormancy();
        $user = $this->createInactiveUser(3000);

        $user->forceFill(['dormant_notified_at' => now()->subDays(31)])->save();

        $this->assertSame(1, app(DormantAccountService::class)->convertToDormant(dryRun: false));

        $fresh = $user->fresh();
        $this->assertSame(UserStatus::Dormant->value, $fresh->status);
        $this->assertNotNull($fresh->dormant_at);
        $this->assertNull($fresh->dormant_notified_at, '다음 단계(탈퇴 예고)가 표지를 다시 쓰므로 비워야 한다');
    }

    /**
     * B5: 전환 시 기존 토큰이 회수된다.
     *
     * 휴면인데 토큰은 살아 있는 어긋난 상태를 남기지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=convert, toggle=on
     *
     * @effects dormant_conversion_revokes_existing_tokens
     */
    public function test_tokens_are_revoked_on_conversion(): void
    {
        $this->enableDormancy();
        $user = $this->createInactiveUser(3000);
        $user->forceFill(['dormant_notified_at' => now()->subDays(31)])->save();
        $user->createToken('test-token');

        $this->assertSame(1, $user->tokens()->count());

        app(DormantAccountService::class)->convertToDormant(dryRun: false);

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    /**
     * B6: 로그인하면 휴면이 해제되고 응답이 그 사실을 알린다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=restore, toggle=on
     *
     * @effects dormant_account_is_restored_on_login
     */
    public function test_login_restores_a_dormant_account(): void
    {
        $this->enableDormancy();
        $user = User::factory()->create([
            'status' => UserStatus::Dormant->value,
            'dormant_at' => now()->subDays(10),
            'dormant_notified_at' => now()->subDays(5),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.dormancy_restored', true);

        $fresh = $user->fresh();
        $this->assertSame(UserStatus::Active->value, $fresh->status);
        $this->assertNull($fresh->dormant_at);
        $this->assertNull($fresh->dormant_notified_at, '표지를 비우지 않으면 다음 휴면 때 예고 없이 전환된다');
    }

    /**
     * B7: 평범한 로그인 응답에는 해제 표시가 실리지 않는다.
     *
     * 늘 실려 나가면 화면이 그 키의 존재를 조건으로 써서 매 로그인마다 안내가 뜬다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=restore, toggle=on
     *
     * @effects dormant_account_is_restored_on_login
     */
    public function test_normal_login_carries_no_dormancy_flag(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active->value]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonMissingPath('data.dormancy_restored');
    }

    /**
     * B8: 휴면 회원이 가진 기존 토큰은 API 에서 막힌다.
     *
     * 로그인 화면과 정책이 어긋나면 "장기 미접속이라 휴면으로 돌렸다" 는 판정이 무의미해진다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=restore, toggle=on
     *
     * @effects dormant_token_reuse_is_blocked_by_status_middleware
     */
    public function test_dormant_user_token_is_rejected_by_the_status_middleware(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active->value]);
        $token = $user->createToken('test-token')->plainTextToken;

        $user->forceFill(['status' => UserStatus::Dormant->value, 'dormant_at' => now()])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me')
            ->assertForbidden();
    }

    /**
     * B9: 활동 기준은 토큰 마지막 사용 시각까지 본다 (설계 확정).
     *
     * `last_login_at` 은 로그인 폼을 통과할 때만 갱신되므로, 토큰이 만료되지 않는 사이트에서는
     * 매일 쓰는 회원도 그 값이 첫 로그인 날짜에 얼어붙는다. 로그인 시각만 보면 **활동 중인
     * 회원이 휴면 → 자동 탈퇴로 사라진다.**
     *
     * @return void 반환값 없음
     *
     * @scenario stage=pre_notice, toggle=on
     *
     * @effects dormant_activity_basis_includes_token_last_used_at
     */
    public function test_activity_basis_includes_token_last_used_at(): void
    {
        $this->enableDormancy();
        $user = $this->createInactiveUser(3000);

        $service = app(DormantAccountService::class);
        $this->assertSame(1, $service->notifyUpcomingDormancy(dryRun: true), '토큰이 없으면 대상이다');

        // 어제 토큰을 쓴 흔적을 남긴다 — 로그인은 3000일 전이지만 활동은 어제다.
        $token = $user->createToken('app-token');
        DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->getKey())
            ->update(['last_used_at' => now()->subDay()]);

        $this->assertSame(0, $service->notifyUpcomingDormancy(dryRun: true), '토큰 활동이 있으면 대상이 아니다');
        $this->assertSame(0, $service->convertToDormant(dryRun: true));
    }

    /**
     * B10: 자동 탈퇴가 꺼져 있으면 탈퇴 단계가 아무 일도 하지 않는다 (기본 꺼짐).
     *
     * @return void 반환값 없음
     *
     * @scenario stage=convert, toggle=off
     *
     * @effects dormancy_is_off_by_default_and_falls_back_to_false
     */
    public function test_withdrawal_stage_is_inert_when_disabled(): void
    {
        $this->enableDormancy(withdraw: false);
        $user = User::factory()->create([
            'status' => UserStatus::Dormant->value,
            'dormant_at' => now()->subDays(1000),
            'dormant_notified_at' => now()->subDays(100),
        ]);

        $service = app(DormantAccountService::class);

        $this->assertSame(0, $service->notifyUpcomingWithdrawal(dryRun: false));
        $this->assertSame(0, $service->withdrawDormant(dryRun: false));
        $this->assertSame(UserStatus::Dormant->value, $user->fresh()->status);
    }

    /**
     * B11: 자동 탈퇴도 사전 안내를 거쳐야 한다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=withdraw, toggle=on
     *
     * @effects dormant_withdrawal_requires_its_own_advance_notice
     */
    public function test_withdrawal_requires_its_own_advance_notice(): void
    {
        $this->enableDormancy(withdraw: true);
        $user = User::factory()->create([
            'status' => UserStatus::Dormant->value,
            'dormant_at' => now()->subDays(1000),
            'dormant_notified_at' => null,
        ]);

        $service = app(DormantAccountService::class);

        $this->assertSame(0, $service->withdrawDormant(dryRun: false), '안내 전에는 탈퇴하지 않는다');
        $this->assertSame(1, $service->notifyUpcomingWithdrawal(dryRun: false));
        $this->assertSame(0, $service->withdrawDormant(dryRun: false), '안내 당일에도 탈퇴하지 않는다');

        $this->assertSame(UserStatus::Dormant->value, $user->fresh()->status);
    }

    /**
     * B12: 안내 후 통지 기간이 지나면 기존 탈퇴 경로로 탈퇴 처리된다.
     *
     * 상태 컬럼만 바꾸면 익명화·훅이 통째로 생략되어 본인 탈퇴와 결과가 달라지므로,
     * 탈퇴 접미사가 실제로 붙었는지까지 본다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=withdraw, toggle=on
     *
     * @effects dormant_withdrawal_reuses_the_standard_withdraw_path
     */
    public function test_dormant_account_is_withdrawn_after_its_notice_period(): void
    {
        $this->enableDormancy(withdraw: true);
        $user = User::factory()->create([
            'status' => UserStatus::Dormant->value,
            'dormant_at' => now()->subDays(1000),
            'dormant_notified_at' => now()->subDays(31),
        ]);
        $originalEmail = $user->email;

        $this->assertSame(1, app(DormantAccountService::class)->withdrawDormant(dryRun: false));

        $fresh = $user->fresh();
        $this->assertSame(UserStatus::Withdrawn->value, $fresh->status);
        $this->assertNotNull($fresh->withdrawn_at);
        $this->assertNotSame($originalEmail, $fresh->email, '정식 탈퇴 경로를 거쳐 익명화 접미사가 붙어야 한다');
    }

    /**
     * B12-b: 관리자 계정은 탈퇴 예고도 받지 않고 탈퇴되지도 않는다.
     *
     * 집행 단계의 `assertWithdrawable()` 만으로는 부족하다 — 예고가 그보다 먼저 나가므로,
     * 제외가 예고 쿼리에 없으면 관리자는 「되돌릴 수 없으니 로그인하라」는 탈퇴 예고를
     * **절대 일어나지 않을 일에 대해** 받고 예정일마다 실패 경고만 쌓인다. 루트 CHANGELOG 가
     * 「관리자 계정은 자동 탈퇴 대상에서 제외됩니다」라고 약속한 성질을 전 경로에서 고정한다.
     *
     * 슈퍼 관리자(`is_super`)와 관리자 역할 보유자 두 갈래를 각각 세운다 —
     * `assertWithdrawable()` 이 막는 것이 정확히 그 둘이고, 한쪽만 재면 나머지가 샌다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=withdraw, toggle=on
     *
     * @effects dormant_withdrawal_excludes_admin_accounts_from_notice_and_execution
     */
    public function test_admin_accounts_are_excluded_from_withdrawal_notice_and_execution(): void
    {
        $this->enableDormancy(withdraw: true);

        $dormant = [
            'status' => UserStatus::Dormant->value,
            'dormant_at' => now()->subDays(1000),
            'dormant_notified_at' => null,
        ];

        $superAdmin = User::factory()->create($dormant + ['is_super' => true]);

        $roleAdmin = User::factory()->create($dormant);
        $permission = Permission::create([
            'identifier' => 'test.privacy.admin_guard',
            'name' => '휴면 자동 탈퇴 제외 시험용 권한',
            'type' => PermissionType::Admin->value,
        ]);
        $role = Role::create([
            'identifier' => 'test-privacy-admin-guard',
            'name' => '휴면 자동 탈퇴 제외 시험용 역할',
        ]);
        $role->permissions()->attach($permission->id);
        $roleAdmin->roles()->attach($role->id);

        $plain = User::factory()->create($dormant);

        $service = app(DormantAccountService::class);

        $this->assertSame(1, $service->notifyUpcomingWithdrawal(dryRun: true), '예고 대상은 일반 회원 1명뿐이어야 한다');
        $this->assertSame(1, $service->notifyUpcomingWithdrawal(dryRun: false));

        $this->assertNull($superAdmin->fresh()->dormant_notified_at, '슈퍼 관리자에게 탈퇴 예고가 나갔다');
        $this->assertNull($roleAdmin->fresh()->dormant_notified_at, '관리자 역할 보유자에게 탈퇴 예고가 나갔다');
        $this->assertNotNull($plain->fresh()->dormant_notified_at);

        $this->travel(31)->days();

        $this->assertSame(1, $service->withdrawDormant(dryRun: true), '탈퇴 대상도 일반 회원 1명뿐이어야 한다');
        $this->assertSame(1, $service->withdrawDormant(dryRun: false));

        $this->assertSame(UserStatus::Dormant->value, $superAdmin->fresh()->status);
        $this->assertSame(UserStatus::Dormant->value, $roleAdmin->fresh()->status);
        $this->assertSame(UserStatus::Withdrawn->value, $plain->fresh()->status);
    }

    /**
     * B12-c: 커맨드의 dry-run 건수와 화면 뱃지가 읽는 `preview()` 가 같은 숫자다.
     *
     * §8.4 T10 이 요구하는 「dry-run 건수 = 화면 뱃지 건수 일치」의 절반이다 — 커맨드와
     * `preview()` 가 같은 쿼리를 타는지 고정한다(나머지 절반 `preview()` → 화면 뱃지는
     * `settings-privacy-retention.spec.ts` 가 브라우저에서 잰다). 두 경로가 갈리면 운영자가
     * 화면에서 본 규모와 실제 처리 규모가 달라진 채 되돌릴 수 없는 파기를 켠다.
     *
     * 네 단계가 **서로 다른 숫자**가 되도록 시드해야 등식이 의미를 갖는다 — 전부 0 이면
     * 어떤 두 경로를 비교해도 통과한다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=withdraw, toggle=on, run_mode=dry_run
     *
     * @effects dry_run_command_counts_match_the_preview_badge_source
     */
    public function test_dry_run_command_counts_match_the_preview_badge_source(): void
    {
        $this->enableDormancy(withdraw: true);

        // 단계마다 다른 건수를 만든다 — pre_notice 2 · convert 1 · withdraw_notice 3 · withdraw 1
        $this->createInactiveUser(705);
        $this->createInactiveUser(706);

        $convertible = $this->createInactiveUser(1000);
        $convertible->forceFill(['dormant_notified_at' => now()->subDays(31)])->save();

        foreach (range(1, 3) as $ignored) {
            User::factory()->create([
                'status' => UserStatus::Dormant->value,
                'dormant_at' => now()->subDays(1000),
                'dormant_notified_at' => null,
            ]);
        }

        User::factory()->create([
            'status' => UserStatus::Dormant->value,
            'dormant_at' => now()->subDays(1000),
            'dormant_notified_at' => now()->subDays(31),
        ]);

        $preview = app(DormantAccountService::class)->preview();

        $this->assertNotSame(
            [0, 0, 0, 0],
            array_values($preview),
            '네 단계가 모두 0 이면 등식이 아무것도 재지 못합니다 — 시드를 확인하세요.',
        );

        Artisan::call('privacy:process-dormant', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString("휴면 전환 사전 안내: {$preview['pre_notice']}건", $output);
        $this->assertStringContainsString("휴면 전환: {$preview['convert']}건", $output);
        $this->assertStringContainsString("탈퇴 예정 안내: {$preview['withdraw_notice']}건", $output);
        $this->assertStringContainsString("탈퇴 처리: {$preview['withdraw']}건", $output);
    }

    /**
     * B13: 기능이 꺼져 있으면 커맨드가 아무것도 하지 않는다 (false 폴백).
     *
     * @return void 반환값 없음
     *
     * @scenario stage=convert, toggle=off
     *
     * @effects dormancy_is_off_by_default_and_falls_back_to_false
     */
    public function test_command_is_inert_when_feature_is_off(): void
    {
        $user = $this->createInactiveUser(3000);
        $user->forceFill(['dormant_notified_at' => now()->subDays(100)])->save();

        $this->artisan('privacy:process-dormant', ['--scheduled' => true])
            ->expectsOutputToContain('꺼져 있어 실행하지 않았습니다')
            ->assertSuccessful();

        $this->assertSame(UserStatus::Active->value, $user->fresh()->status);
    }

    /**
     * B15: 사전 안내가 실제로 회원에게 도달한다 (사이트 알림 기록).
     *
     * 훅만 발행하고 끝내면 "안내를 보냈다" 는 표지(`dormant_notified_at`)는 남는데 회원은
     * 아무것도 받지 못한 채 휴면으로 넘어간다 — 법정 사전 안내가 형식만 남는 자리다.
     * 알림 정의가 시딩돼 있어야 도달하므로 그 연결까지 함께 잰다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=pre_notice, toggle=on
     *
     * @effects dormancy_requires_advance_notice_before_conversion
     */
    public function test_advance_notice_actually_reaches_the_member(): void
    {
        // 알림 정의 시딩 + 동적 훅 재구독 + 치환값 리스너 등록.
        // 구독은 부팅 시점에 DB 를 읽어 해석되므로, 시딩 뒤에 다시 등록해야 이번 정의가 잡힌다
        // (선례: SitemapRegenerateNotificationTest).
        $this->artisan('db:seed', ['--class' => 'NotificationDefinitionSeeder']);
        app(NotificationHookListener::class)->registerDynamicHooks();
        HookListenerRegistrar::register(CorePrivacyNotificationDataListener::class, 'core');

        $this->enableDormancy();
        $user = $this->createInactiveUser(705);
        $other = User::factory()->create(['status' => UserStatus::Active->value]);

        Notification::fake();

        app(DormantAccountService::class)->notifyUpcomingDormancy(dryRun: false);

        Notification::assertSentTo($user, GenericNotification::class);
        Notification::assertNotSentTo($other, GenericNotification::class);
    }

    /**
     * B14: dry-run 은 아무것도 바꾸지 않고 건수만 센다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=convert, toggle=on
     *
     * @effects dry_run_deletes_nothing
     */
    public function test_dry_run_changes_nothing(): void
    {
        $this->enableDormancy();
        $user = $this->createInactiveUser(3000);
        $user->forceFill(['dormant_notified_at' => now()->subDays(31)])->save();

        $this->artisan('privacy:process-dormant', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(UserStatus::Active->value, $user->fresh()->status);
    }

    /**
     * 관리자가 휴면 상태로 **생성**한 회원도 전환 시각을 갖는지 확인합니다.
     *
     * 생성 검증은 `Rule::in(UserStatus::values())` 라 Enum 전량을 받는다. 그 값이 그대로 저장되면
     * `dormant_at` 이 빈 회원이 생기고, 탈퇴 전환 쿼리가 `whereNotNull('dormant_at')` 이므로
     * 그 회원만 자동 탈퇴 대상에서 영원히 빠진다 — 수정 경로는 짝을 맞추는데 생성 경로만
     * 맞추지 않던 자리다.
     *
     * @scenario stage=withdraw, toggle=on
     *
     * @effects dormant_conversion_timestamp_is_paired_on_every_write_path
     *
     * @return void 반환값 없음
     */
    public function test_manually_created_dormant_user_carries_its_conversion_timestamp(): void
    {
        $this->enableDormancy();

        $user = app(UserService::class)->createUser([
            'name' => 'dormant-seed',
            'email' => 'dormant-seed@example.test',
            'password' => 'Str0ng!Passw0rd',
            'status' => UserStatus::Dormant->value,
        ]);

        $this->assertSame(UserStatus::Dormant->value, $user->status);
        $this->assertNotNull(
            $user->fresh()->dormant_at,
            '휴면으로 생성된 회원에 전환 시각이 없습니다 — 자동 탈퇴 전환 대상에서 영구히 빠집니다.',
        );
    }

    /**
     * 낡은 통지 표지를 가진 휴면 회원을 만듭니다.
     *
     * 사전 안내를 이미 받고(`dormant_notified_at`) 휴면으로 넘어간 회원 — 관리자가 손으로
     * 상태를 바꿀 때 그 표지가 함께 정리되지 않으면 다음 배치가 낡은 값을 읽는다.
     *
     * @param  string  $email  중복을 피하기 위한 이메일
     * @return User 생성된 회원
     */
    private function createStaleDormantUser(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'status' => UserStatus::Dormant->value,
            'last_login_at' => now()->subDays(1000),
            'created_at' => now()->subDays(1100),
            'dormant_at' => now()->subDays(400),
            'dormant_notified_at' => now()->subDays(60),
        ]);
    }

    /**
     * 관리자 write path **전량**이 상태에 딸린 시각 컬럼의 짝을 맞추는지 확인합니다.
     *
     * 이 검사의 이름은 원래 「every write path」였으나 실제로는 `createUser` 한 경로의
     * `dormant_at` 만 재고 있었다. 그사이 `updateUser()`·`bulkUpdateStatus()` 는
     * `dormant_notified_at` 을 남겨 두고 있었고, 그래서 **관리자가 휴면 회원을 「활성」으로
     * 풀어 주면 낡은 표지 탓에 사전 안내 쿼리(`whereNull`)에서 빠지고 전환 쿼리
     * (`whereNotNull` + 기한 경과)로는 즉시 잡혀 안내 한 번 없이 다시 잠겼다.**
     *
     * 모집단은 `UserStatus::transitionTimestamps()` 에서 파생한다 — 짝 컬럼이 늘면 손대지
     * 않아도 이 단언이 함께 넓어진다. 세 경로 중 하나라도 짝을 빠뜨리면 red 가 된다.
     *
     * @scenario stage=convert, toggle=on
     *
     * @effects dormant_conversion_timestamp_is_paired_on_every_write_path
     *
     * @return void 반환값 없음
     */
    public function test_every_admin_write_path_pairs_the_status_timestamps(): void
    {
        $this->enableDormancy(withdraw: true);

        // 모집단을 저장소(Enum)에서 파생하고, 비어 있지 않은지 자체를 잰다 — 짝 표가 비면
        // 아래 루프가 0회 돌면서 조용히 통과한다.
        $activePairs = UserStatus::Active->transitionTimestamps();
        $dormantPairs = UserStatus::Dormant->transitionTimestamps();

        $this->assertNotSame([], $activePairs, '활성 전환의 짝 표가 비었습니다 — 이 검사가 아무것도 재지 않습니다.');
        $this->assertNotSame([], $dormantPairs, '휴면 전환의 짝 표가 비었습니다 — 이 검사가 아무것도 재지 않습니다.');
        $this->assertArrayHasKey(
            'dormant_notified_at',
            $activePairs,
            '활성 전환이 통지 표지를 비우지 않으면 관리자가 푼 회원이 안내 없이 다시 휴면으로 잠깁니다.',
        );
        $this->assertArrayHasKey(
            'dormant_notified_at',
            $dormantPairs,
            '휴면 지정이 통지 표지를 비우지 않으면 낡은 표지가 탈퇴 예고를 건너뛰게 합니다.',
        );

        $service = app(UserService::class);

        // ① 수정 경로 — 관리자가 회원 상세에서 「활성」으로 되돌린다.
        $viaUpdate = $this->createStaleDormantUser('pair-update@example.test');
        $service->updateUser($viaUpdate, ['status' => UserStatus::Active->value]);
        $this->assertStatusPairsAreSettled($viaUpdate->fresh(), $activePairs, 'updateUser');

        // ② 일괄 경로 — 관리자가 회원 목록에서 여러 건을 한 번에 되돌린다.
        $viaBulk = $this->createStaleDormantUser('pair-bulk@example.test');
        $service->bulkUpdateStatus([$viaBulk->uuid], UserStatus::Active->value);
        $this->assertStatusPairsAreSettled($viaBulk->fresh(), $activePairs, 'bulkUpdateStatus');

        // ③ 생성 경로 — 관리자가 처음부터 휴면으로 만든다.
        $viaCreate = $service->createUser([
            'name' => 'pair-create',
            'email' => 'pair-create@example.test',
            'password' => 'Str0ng!Passw0rd',
            'status' => UserStatus::Dormant->value,
        ]);
        $this->assertStatusPairsAreSettled($viaCreate->fresh(), $dormantPairs, 'createUser');

        // 짝이 맞았으면 되돌린 회원은 다시 사전 안내 대상으로 돌아와 있어야 한다 —
        // 「안내 없이 재잠금」이 실제로 닫혔는지를 배치 쿼리로 직접 확인한다.
        $preNoticeTargets = app(DormantAccountService::class)->preNoticeQuery()->pluck('id')->all();
        $this->assertContains(
            $viaUpdate->id,
            $preNoticeTargets,
            '관리자가 푼 회원이 사전 안내 대상에서 빠졌습니다 — 안내 없이 다시 휴면으로 잠깁니다.',
        );
        $this->assertContains(
            $viaBulk->id,
            $preNoticeTargets,
            '일괄로 푼 회원이 사전 안내 대상에서 빠졌습니다 — 안내 없이 다시 휴면으로 잠깁니다.',
        );
    }

    /**
     * 한 회원의 짝 컬럼이 표대로 정리됐는지 단언합니다.
     *
     * @param  User  $user  확인할 회원(재조회본)
     * @param  array<string, Carbon|null>  $pairs  기대하는 짝 표
     * @param  string  $path  실패 메시지에 넣을 경로 이름
     * @return void 반환값 없음
     */
    private function assertStatusPairsAreSettled(User $user, array $pairs, string $path): void
    {
        foreach ($pairs as $column => $expected) {
            if ($expected === null) {
                $this->assertNull(
                    $user->{$column},
                    "{$path}() 가 `{$column}` 을 비우지 않았습니다 — 다음 배치가 낡은 표지를 읽습니다.",
                );

                continue;
            }

            $this->assertNotNull(
                $user->{$column},
                "{$path}() 가 `{$column}` 을 채우지 않았습니다 — 그 회원만 판정 기준점을 잃습니다.",
            );
        }
    }

    /**
     * B18: 사전 통지문의 **예정일**은 집행 조건과 같은 시점을 가리킨다 (백로그 회원 포함).
     *
     * 모집단은 손으로 적지 않는다 — **예정일을 싣는 통지 단계 전량**을 이 표에서 파생하고,
     * 표가 비면 그 자체로 red 가 된다. 단계가 늘면 이 검사도 함께 넓어진다.
     *
     * 각 단계를 **기준일을 한참 넘긴 회원**(기능을 처음 켠 사이트의 백로그)으로 세우는 것이
     * 핵심이다. 종전 계산은 회원의 과거 시각에서 출발해 그런 회원에게 **이미 지난 날짜**를
     * 「예정일」로 실어 보냈다 — 법정 사전 통지가 알려야 할 바로 그 값이다. 기준일에 정확히
     * 걸린 회원만 재면 두 식이 우연히 일치해 이 결함이 보이지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=pre_notice, toggle=on
     *
     * @effects dormancy_notice_schedules_the_date_the_enforcement_query_uses
     */
    public function test_every_advance_notice_dates_the_stage_its_enforcement_query_uses(): void
    {
        $this->enableDormancy(withdraw: true);

        $service = app(DormantAccountService::class);

        // 예정일을 싣는 통지 단계 = {훅 이름 => 백로그 회원을 세우고 그 단계를 발송하는 절차}
        $stages = [
            'core.privacy.dormant_pre_notice' => function () use ($service): int {
                $this->createInactiveUser(2000); // 전환 기준 730일을 한참 넘긴 백로그

                return $service->notifyUpcomingDormancy(dryRun: false);
            },
            'core.privacy.dormant_withdraw_notice' => function () use ($service): int {
                User::factory()->create([
                    'status' => UserStatus::Dormant->value,
                    'dormant_at' => now()->subDays(2000), // 탈퇴 기준 365일을 한참 넘긴 백로그
                    'dormant_notified_at' => null,
                ]);

                return $service->notifyUpcomingWithdrawal(dryRun: false);
            },
        ];

        $this->assertNotSame([], $stages, '통지 단계 모집단이 비면 이 검사는 아무것도 재지 않습니다.');

        foreach ($stages as $hook => $sendStage) {
            $captured = [];

            HookManager::addAction($hook, function ($user, $extra) use (&$captured): void {
                $captured[] = ['user' => $user, 'extra' => $extra];
            });

            $this->assertSame(1, $sendStage(), "{$hook} 단계가 백로그 회원을 대상으로 잡지 못했습니다.");

            HookManager::clearAction($hook);

            $this->assertCount(1, $captured, "{$hook} 이 발행되지 않았습니다.");
            $this->assertArrayHasKey('scheduled_date', $captured[0]['extra'], "{$hook} 의 \$extra 에 예정일이 없습니다.");

            // 기대값은 **수신자의 시간대**로 읽는다(설계 확정 2026-09-23). 시간대 축 자체는
            // 아래 전용 케이스가 고정 시각으로 잰다 — 여기서는 「집행 시점과 같은 순간인가」만.
            $expected = now()
                ->addDays($service->noticeDays())
                ->setTimezone($captured[0]['user']->getTimezone())
                ->toDateString();

            $this->assertSame(
                $expected,
                $captured[0]['extra']['scheduled_date'],
                "{$hook} 의 예정일이 집행 조건(통지 시각 + 통지일수)과 다릅니다 — 회원이 받는 안내가 실제 집행일을 가리키지 않습니다.",
            );

            $this->assertTrue(
                Carbon::parse($captured[0]['extra']['scheduled_date'])->greaterThan(now()),
                "{$hook} 이 **이미 지난 날짜**를 예정일로 보냈습니다 — 백로그 회원 전원이 받는 증상입니다.",
            );
        }
    }

    /**
     * R8-D78: 본인인증으로 `active` 복귀시키는 경로도 **짝 시각 칸 전량**을 비운다.
     *
     * 상태에 딸린 시각 칸의 짝은 `UserStatus::transitionTimestamps()` 한 곳이 소유하고,
     * 「→ Active」 다섯 경로(관리자 생성·수정·일괄, 로그인 해제, 본인인증 통과)가 모두 그
     * 표를 읽어야 어휘가 갈라지지 않는다. 본인인증 리스너만 `status` 를 단독으로 써서 낡은
     * `dormant_notified_at` 이 남았다 — 그 표지는 재안내 쿼리(`whereNull`)에서는 회원을
     * 빼고 전환 쿼리(`whereNotNull` + 기한)에는 넣는, 정확히 반대로 작동하는 값이다.
     *
     * 비울 칸 목록을 손으로 적지 않고 **Enum 짝 표에서 파생**한다 — 칸이 하나 늘면 이
     * 검사도 함께 넓어지고, 표가 비면 그 자체로 red 가 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=pre_notice, toggle=on
     *
     * @effects dormant_conversion_timestamp_is_paired_on_every_write_path
     */
    public function test_identity_verification_activation_clears_every_paired_timestamp(): void
    {
        $paired = UserStatus::Active->transitionTimestamps();

        $this->assertNotSame(
            [],
            $paired,
            'Active 의 짝 표가 비면 이 검사는 아무것도 재지 않습니다.',
        );

        $user = User::factory()->create([
            'status' => UserStatus::PendingVerification->value,
            // 「→ Active」 로 가기 전에 낡아 있을 수 있는 값을 전부 채운다.
            'dormant_at' => now()->subDays(40),
            'dormant_notified_at' => now()->subDays(40),
            'blocked_at' => now()->subDays(40),
            'withdrawn_at' => now()->subDays(40),
        ]);

        foreach (array_keys($paired) as $column) {
            $this->assertNotNull(
                $user->{$column},
                "픽스처가 `{$column}` 을 채우지 못했습니다 — 비워지는지를 잴 수 없습니다.",
            );
        }

        $log = IdentityVerificationLog::query()->create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'signup',
            'channel' => 'email',
            'user_id' => $user->id,
            'target_hash' => hash('sha256', $user->email),
            'status' => 'verified',
            'verified_at' => now(),
        ]);

        app(ActivateUserOnIdentityVerified::class)->handle(
            VerificationResult::success(
                challengeId: $log->id,
                providerId: 'g7:core.mail',
                verifiedAt: now(),
            ),
            $log,
        );

        $user->refresh();

        $this->assertSame(
            UserStatus::Active->value,
            $user->status,
            '본인인증 통과가 회원을 활성으로 바꾸지 못했습니다 — 이 검사의 전제가 깨졌습니다.',
        );

        foreach (array_keys($paired) as $column) {
            $this->assertNull(
                $user->{$column},
                "본인인증 활성화 경로가 `{$column}` 을 비우지 않았습니다 — 「→ Active」 다섯 경로 중 이 한 곳만 짝 표를 읽지 않고 있습니다.",
            );
        }
    }

    /**
     * R8-B1: `--scheduled` 를 「기록용」이라 선언한 커맨드는 실제로 그 값을 로그에 남긴다.
     *
     * 코드로 등록한 예약(`routes/console.php`)은 `schedule_histories.trigger_type` 에 남지
     * 않는다 — 그 이력은 관리자 화면에서 등록한 예약만 대상이다. 그래서 이 로그가 「되돌릴
     * 수 없는 파기·상태 변경이 자동 실행이었나 사람이 손으로 돌린 것이었나」를 가리는
     * 유일한 자리다. 시그니처가 기록을 약속하고 기록하지 않으면 그 구분이 영영 사라진다.
     *
     * 모집단은 손으로 적지 않는다 — **`--scheduled` 를 선언하면서 그 설명에 「기록」을
     * 약속한 커맨드 전량**을 소스에서 파생한다. 약속하지 않은 형제(`attachments:prune-orphans`
     * 는 조기 종료용으로만 쓴다)는 모집단에 들지 않고, 새 커맨드가 같은 약속을 하면 이
     * 검사가 함께 넓어진다. 모집단이 비면 그 자체로 red 다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=convert, toggle=on
     *
     * @effects scheduler_flag_is_recorded_when_the_signature_promises_it
     */
    public function test_commands_promising_to_record_the_scheduler_flag_actually_record_it(): void
    {
        $promising = $this->commandsPromisingToRecordTheScheduledFlag();

        $this->assertNotSame(
            [],
            $promising,
            '「기록용」을 약속한 커맨드를 하나도 찾지 못했습니다 — 모집단 파생이 낡았거나 약속 문구가 사라졌습니다.',
        );

        // 휴면 커맨드는 기능이 꺼져 있으면 로그에 도달하기 전에 반환한다.
        $this->enableDormancy(withdraw: true);
        // 비밀번호 만료 커맨드(#736)도 같은 약속을 하는 모집단이고, 같은 이유로 기능을 켜 둔다.
        Config::set('g7_settings.core.security.password_expiry_enabled', true);
        Config::set('g7_settings.core.security.password_expiry_started_at', now()->toIso8601String());

        foreach ($promising as $command => $file) {
            foreach ([true, false] as $scheduled) {
                $contexts = [];

                // 이 앱은 `MessageLogged` 이벤트를 발행하지 않으므로(런타임 실측) 파사드를
                // 스파이로 바꿔 호출 인자를 직접 받는다.
                Log::spy();

                $parameters = $scheduled ? ['--scheduled' => true] : [];
                Artisan::call($command, $parameters);

                Log::shouldHaveReceived('info')->withArgs(
                    function (string $message, array $context = []) use (&$contexts): bool {
                        $contexts[] = $context;

                        return true;
                    }
                );

                $recorded = array_values(array_filter(
                    $contexts,
                    static fn (array $context): bool => array_key_exists('scheduled', $context)
                ));

                $this->assertNotSame(
                    [],
                    $recorded,
                    "{$command} ({$file}) 가 실행 로그에 `scheduled` 를 남기지 않았습니다 — 시그니처는 「기록용」이라 선언하고 있습니다.",
                );

                // 스파이는 같은 테스트 안에서 앞 회차의 호출까지 들고 있으므로 **마지막**
                // 기록이 이번 실행의 것이다. 첫 기록을 보면 앞 회차 값을 재게 된다.
                $latest = end($recorded);

                $this->assertSame(
                    $scheduled,
                    $latest['scheduled'],
                    "{$command} 가 기록한 `scheduled` 값이 실제 호출 형태와 다릅니다 — 자동/수동 구분이 뒤집힙니다.",
                );
            }
        }
    }

    /**
     * `--scheduled` 를 선언하면서 그 설명에 기록을 약속한 커맨드를 소스에서 파생합니다.
     *
     * @return array<string, string> 커맨드 이름 → 파일명
     */
    private function commandsPromisingToRecordTheScheduledFlag(): array
    {
        $promising = [];

        foreach (glob(app_path('Console/Commands/*.php')) ?: [] as $path) {
            $source = (string) file_get_contents($path);

            if (! preg_match('/\{--scheduled\s*:([^}]*)\}/u', $source, $option)) {
                continue;
            }

            if (! str_contains($option[1], '기록')) {
                continue;
            }

            if (! preg_match('/protected\s+\$signature\s*=\s*\'\s*([^\s\'{]+)/u', $source, $name)) {
                continue;
            }

            $promising[$name[1]] = basename($path);
        }

        return $promising;
    }

    /**
     * R8-B2: 안내문의 예정일은 **수신자 시간대**의 달력 날짜다.
     *
     * 저장 시각은 UTC 고정이고 예약은 사이트 시간대(`Asia/Seoul`)로 돈다. 그래서 계산 결과를
     * 그대로 `toDateString()` 하면 UTC 달력 날짜가 나가고, 새벽 배치(05:10 KST = 전날 20:10
     * UTC)에서는 안내문 날짜가 실제 집행일보다 **항상 하루 이르다**. 법정 사전 통지가 알려야
     * 할 바로 그 값이다.
     *
     * 종전 검사는 기대값을 **피검 코드와 같은 식**으로 적어(`now()->addDays()->toDateString()`)
     * 이 축을 구조적으로 잴 수 없었다. 그래서 두 가지를 고정한다 — ⓐ 시각을 UTC 와 KST 의
     * 달력 날짜가 **갈라지는 지점**에 못박고 ⓑ 회원마다 다른 시간대를 줘서 예정일이 그
     * 시간대를 따라가는지 본다. 모집단은 예정일을 싣는 통지 단계 전량이다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=pre_notice, toggle=on
     *
     * @effects dormancy_notice_date_is_rendered_in_the_recipient_timezone
     */
    public function test_advance_notice_dates_are_rendered_in_the_recipient_timezone(): void
    {
        // 20:10 UTC = 다음 날 05:10 Asia/Seoul — 예약이 실제로 도는 시각이자 두 달력이
        // 갈라지는 지점이다. 이 못박기가 없으면 실행 시각에 따라 우연히 통과한다.
        Carbon::setTestNow(Carbon::parse('2026-09-22 20:10:00', 'UTC'));

        $this->enableDormancy(withdraw: true);

        $service = app(DormantAccountService::class);

        // 회원 시간대 → 그 시간대에서 읽은 예정일. 값도 손으로 적지 않고 파생한다.
        $timezones = ['Asia/Seoul', 'America/New_York', null];

        // 예정일을 싣는 통지 단계 전량 = {훅 => 그 시간대를 가진 회원을 세우고 발송하는 절차}
        $stages = [
            'core.privacy.dormant_pre_notice' => function (?string $tz) use ($service): int {
                $this->createInactiveUser(2000)->forceFill(['timezone' => $tz])->save();

                return $service->notifyUpcomingDormancy(dryRun: false);
            },
            'core.privacy.dormant_withdraw_notice' => function (?string $tz) use ($service): int {
                User::factory()->create([
                    'status' => UserStatus::Dormant->value,
                    'dormant_at' => now()->subDays(2000),
                    'dormant_notified_at' => null,
                    'timezone' => $tz,
                ]);

                return $service->notifyUpcomingWithdrawal(dryRun: false);
            },
        ];

        $this->assertNotSame([], $stages, '통지 단계 모집단이 비면 이 검사는 아무것도 재지 않습니다.');
        $this->assertNotSame([], $timezones, '시간대 모집단이 비면 이 검사는 아무것도 재지 않습니다.');

        $utcDate = now()->addDays($service->noticeDays())->toDateString();
        $sawDivergence = false;

        foreach ($stages as $hook => $sendStage) {
            foreach ($timezones as $tz) {
                $captured = [];

                HookManager::addAction($hook, function ($user, $extra) use (&$captured): void {
                    $captured[] = ['user' => $user, 'extra' => $extra];
                });

                $this->assertSame(1, $sendStage($tz), "{$hook} 단계가 대상 회원을 잡지 못했습니다.");

                HookManager::clearAction($hook);

                $this->assertCount(1, $captured, "{$hook} 이 발행되지 않았습니다.");

                $recipientTimezone = $captured[0]['user']->getTimezone();
                $expected = now()->addDays($service->noticeDays())->setTimezone($recipientTimezone)->toDateString();

                $this->assertSame(
                    $expected,
                    $captured[0]['extra']['scheduled_date'],
                    "{$hook} 의 예정일이 수신자 시간대({$recipientTimezone})의 달력 날짜가 아닙니다.",
                );

                if ($expected !== $utcDate) {
                    $sawDivergence = true;
                }

                // 다음 조합이 앞 회원을 다시 잡지 않도록 정리한다.
                User::query()->delete();
            }
        }

        // UTC 와 한 번도 갈라지지 않았다면 이 검사는 UTC 구현에서도 통과한다 — 그 자체를 잰다.
        $this->assertTrue(
            $sawDivergence,
            'UTC 달력 날짜와 한 번도 달라지지 않았습니다 — 이 검사가 시간대 축을 재지 못하고 있습니다.',
        );

        Carbon::setTestNow();
    }

    /**
     * R6-C1: 로그인을 통과하는 상태 **전량**에서 낡은 통지 표지가 비워진다.
     *
     * 표지를 비우는 자리는 전부 「상태가 바뀔 때만」 도달한다(Enum 짝 표·전환 단계). 그래서
     * **사전 안내를 받고 안내대로 로그인한 회원** — 상태가 `active` 인 채로 남는 바로 그
     * 정상 케이스 — 만 그 방어에서 빠져 있었다. 그대로 두면 사전 안내 쿼리는 `whereNull` 로
     * 그 회원을 영구히 배제하는 동안 전환 쿼리는 `whereNotNull` + 기한 경과로 즉시 잡아,
     * 다시 장기 미접속이 됐을 때 **2년 전에 발송된 이미 취소된 안내가 마지막 통지인 채로**
     * 예고 없이 휴면으로 넘어간다.
     *
     * 모집단을 손으로 적지 않는다 — 로그인을 통과하는 상태를 **판정 메서드에 직접 물어**
     * 파생하고, 목록이 비면 루프가 0회 돌며 조용히 통과하므로 그 자체를 먼저 잰다. 통과
     * 목록에 상태가 더해지면 이 검사도 함께 넓어진다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=restore, toggle=on
     *
     * @effects dormancy_login_clears_a_stale_notice_marker
     */
    public function test_every_login_allowed_status_clears_a_stale_notice_marker(): void
    {
        $this->enableDormancy();

        $auth = app(AuthService::class);
        $predicate = new \ReflectionMethod($auth, 'isLoginAllowedStatus');
        $predicate->setAccessible(true);

        $loginAllowed = array_values(array_filter(
            UserStatus::cases(),
            fn (UserStatus $status): bool => (bool) $predicate->invoke($auth, $status->value),
        ));

        $this->assertNotSame(
            [],
            $loginAllowed,
            '로그인을 통과하는 상태가 하나도 없습니다 — 이 검사가 아무것도 재지 않습니다.',
        );

        $service = app(DormantAccountService::class);

        foreach ($loginAllowed as $status) {
            $attributes = [
                'email' => "stale-marker-{$status->value}@example.test",
                'status' => $status->value,
                // 사전 안내를 받은 시점 — 통지 기간(30일)을 이미 넘겼다.
                'dormant_notified_at' => now()->subDays(60),
                'last_login_at' => now()->subDays(1000),
                'created_at' => now()->subDays(1100),
            ];

            if ($status === UserStatus::Dormant) {
                $attributes['dormant_at'] = now()->subDays(400);
            }

            $user = User::factory()->create($attributes);

            $response = $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

            $response->assertOk();

            $this->assertNull(
                $user->fresh()->dormant_notified_at,
                "{$status->value} 상태로 로그인한 회원의 통지 표지가 남았습니다 — 그 회원은 사전 안내 대상에서 영구히 빠진 채 다음 주기에 예고 없이 휴면으로 전환됩니다.",
            );

            // 회원이 다시 발길을 끊은 상황을 만든다 — 표지가 비워졌다면 「예고 없이 전환」이
            // 아니라 「사전 안내부터 다시」가 되어야 한다. 이 두 단언이 실제 피해를 고정한다.
            //
            // 모델 경유로 쓰지 않는 이유: 로그인이 `last_login_at` 을 `now()` 로 갱신했는데
            // 테스트가 든 인스턴스는 생성 당시 값을 그대로 들고 있어, 같은 값을 다시 채우면
            // dirty 로 잡히지 않고 `save()` 가 아무것도 쓰지 않는다.
            User::query()->whereKey($user->id)->update([
                'last_login_at' => now()->subDays(1000),
            ]);

            $this->assertNotContains(
                $user->id,
                $service->convertQuery()->pluck('id')->all(),
                "{$status->value} 상태로 로그인한 회원이 **예고 없이** 휴면 전환 대상에 올랐습니다 — 법정 사전 안내가 소실됩니다.",
            );
            $this->assertContains(
                $user->id,
                $service->preNoticeQuery()->pluck('id')->all(),
                "{$status->value} 상태로 로그인한 회원이 사전 안내 대상으로 돌아오지 않았습니다 — 다시는 안내를 받지 못합니다.",
            );
        }
    }

    /**
     * R6-C2: 집행 중 로그인해 되살아난 계정은 그 실행에서 탈퇴되지 않는다.
     *
     * 청크의 인스턴스는 `withdrawQuery()` 의 **SELECT 시점 스냅샷**이다. 청크 하나를
     * 처리하는 동안(건별 트랜잭션 + 훅 + 아바타 파일 삭제) 그 회원이 로그인하면 `active` 로
     * 복귀하는데, `withdrawUser()` 는 상태를 보지 않고 `User::withdraw()` 의 멱등 가드는
     * DB 가 아니라 인메모리 모델을 읽는다 — 재확인이 없으면 방금 계정을 되살린 회원이 낡은
     * 인스턴스로 탈퇴·익명화된다. 이름·이메일에 접미사가 붙고 동의 이력이 지워져 **되돌릴
     * 수 없다.**
     *
     * 경합을 흉내내지 않고 **한 실행 안에서 실제로 재현**한다 — 앞 회원의 탈퇴 훅이 도는
     * 동안 뒤 회원을 해제해, 같은 청크의 남은 인스턴스가 낡은 상태를 들고 있게 만든다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=withdraw, toggle=on
     *
     * @effects dormant_withdrawal_rechecks_status_before_enforcing
     */
    public function test_withdrawal_skips_an_account_restored_mid_execution(): void
    {
        $this->enableDormancy(withdraw: true);

        $first = $this->createStaleDormantUser('race-first@example.test');
        $restored = $this->createStaleDormantUser('race-restored@example.test');

        $service = app(DormantAccountService::class);

        $targets = $service->withdrawQuery()->pluck('id')->all();
        $this->assertContains($first->id, $targets, '픽스처가 탈퇴 대상이 아닙니다 — 이 검사가 경합을 재지 못합니다.');
        $this->assertContains($restored->id, $targets, '픽스처가 탈퇴 대상이 아닙니다 — 이 검사가 경합을 재지 못합니다.');

        // 앞 회원이 탈퇴되는 순간 뒤 회원이 로그인해 해제된 것으로 만든다 — 배치가 이미
        // 읽어 둔 인스턴스는 그대로 `dormant` 를 들고 있다.
        HookManager::addAction('core.user.before_withdraw', function (User $user) use ($restored): void {
            if ($user->id !== $restored->id) {
                $restored->forceFill([
                    'status' => UserStatus::Active->value,
                    'dormant_at' => null,
                    'dormant_notified_at' => null,
                ])->save();
            }
        });

        $service->withdrawDormant(dryRun: false);

        $this->assertSame(
            UserStatus::Withdrawn->value,
            $first->fresh()->status,
            '경합과 무관한 회원이 탈퇴되지 않았습니다 — 재확인이 정상 대상까지 걸러냅니다.',
        );

        $survivor = $restored->fresh();

        $this->assertSame(
            UserStatus::Active->value,
            $survivor->status,
            '집행 중 로그인해 되살린 회원이 낡은 스냅샷으로 탈퇴됐습니다 — 되돌릴 수 없는 손실입니다.',
        );
        $this->assertNull($survivor->withdrawn_at, '되살린 회원에게 탈퇴 시각이 찍혔습니다.');
        $this->assertStringNotContainsString(
            '_deleted_',
            (string) $survivor->email,
            '되살린 회원의 이메일이 익명화됐습니다 — 원상 복구가 불가능합니다.',
        );
    }

    /**
     * R7-C1: 휴면 생명주기 활동로그 **전량**이 실명이 아니라 uuid 를 싣는다.
     *
     * 이 기능은 개인정보를 파기하는 쪽이다. 파기 사실을 남기려고 감사 로그에 실명 사본을
     * 만들면 2차 파기 핸들러가 `users` 에서 이름을 지워도 그 사본이 남아 **「지운다」가
     * 거짓이 된다** — `activity_logs` 는 그 핸들러의 대상이 아니다. 특히 탈퇴 로그는
     * `withdrawUser()` 가 익명화를 끝낸 **직후** 낡은 청크 인스턴스의 익명화 이전 실명을
     * 복사하던 자리였다. 코어 형제 `user.withdraw` 는 uuid 를 쓴다.
     *
     * 모집단은 저장소에서 파생한다 — 휴면 활동로그 키 전량을 번역 파일에서 찾아 그 각각을
     * 본다. 키가 하나 늘면 이 검사가 함께 넓어지고, 모집단이 비면 red 가 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=convert, toggle=on
     *
     * @effects dormancy_activity_log_records_uuid_not_real_name
     */
    public function test_dormancy_activity_logs_never_record_the_real_name(): void
    {
        $this->enableDormancy(withdraw: true);

        $descriptions = (array) __('activity_log.description');

        $dormancyKeys = array_keys(array_filter(
            $descriptions,
            fn (string $key): bool => str_starts_with($key, 'user_dormant_'),
            ARRAY_FILTER_USE_KEY,
        ));

        $this->assertNotSame(
            [],
            $dormancyKeys,
            '휴면 활동로그 키를 하나도 찾지 못했습니다 — 이 검사가 아무것도 재지 않습니다.',
        );

        // 문구 자체가 실명 자리표시자를 쓰지 않아야 한다. `:name` 이 남아 있으면 기록하는
        // 쪽이 uuid 를 넘겨도 번역이 그 값을 이름 자리에 그린다.
        foreach ($dormancyKeys as $key) {
            $this->assertStringNotContainsString(
                ':name',
                (string) $descriptions[$key],
                "활동로그 문구 {$key} 가 실명 자리표시자를 씁니다 — 파기 대상 회원의 이름이 감사 로그에 남습니다.",
            );
        }

        // 그리고 실제 기록 경로도 실명을 싣지 않는다 — 세 단계를 모두 돌린다.
        $converting = $this->createInactiveUser(705);
        $service = app(DormantAccountService::class);
        $service->notifyUpcomingDormancy(dryRun: false);

        $this->travel(31)->days();
        $service->convertToDormant(dryRun: false);
        $this->travelBack();

        $withdrawing = $this->createStaleDormantUser('r7-audit-withdraw@example.test');
        $service->withdrawDormant(dryRun: false);

        $restoring = User::factory()->create([
            'status' => UserStatus::Dormant->value,
            'dormant_at' => now()->subDays(10),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $restoring->email,
            'password' => 'password',
        ])->assertOk();

        $names = [$converting->name, $withdrawing->name, $restoring->name];

        $logged = DB::table('activity_logs')
            ->whereIn('action', ['user.dormant_converted', 'user.dormant_withdrawn', 'user.dormant_restored'])
            ->pluck('description_params')
            ->all();

        $this->assertNotSame([], $logged, '휴면 활동로그가 한 건도 남지 않았습니다 — 이 검사가 아무것도 재지 않습니다.');

        foreach ($logged as $params) {
            // **디코딩해서** 본다 — 저장된 JSON 은 한글을 유니코드 이스케이프로 적으므로
            // 원문 문자열을 그대로 찾으면 이름이 들어 있어도 검사가 통과한다. 이 가드가
            // 처음 공동화됐던 자리이고, 되돌림 실측이 그것을 잡아냈다.
            $decoded = json_decode((string) $params, true);

            $this->assertIsArray($decoded, '활동로그 파라미터를 해석하지 못했습니다 — 이 검사가 아무것도 재지 않습니다.');

            $this->assertArrayNotHasKey(
                'name',
                $decoded,
                '휴면 활동로그가 실명 자리(name)를 씁니다 — 형제 user.withdraw 는 uuid 를 씁니다.',
            );

            foreach ($decoded as $value) {
                foreach ($names as $name) {
                    $this->assertStringNotContainsString(
                        (string) $name,
                        (string) $value,
                        '휴면 활동로그가 회원 실명을 평문으로 담았습니다 — 2차 파기가 users 에서 이름을 지워도 이 사본이 남습니다.',
                    );
                }
            }
        }
    }

    /**
     * R7-C2: 보유기간 판독부에 바닥값이 있어 `0` 이 「탈퇴 당일 즉시 파기」가 되지 않는다.
     *
     * 저장 검증은 `min:1` 이지만 그 검증을 거치지 않는 쓰기(단건 설정 API·JSON 직접 편집·
     * 확장 훅)가 `0`·음수·빈 문자열을 남기면 `(int)` 가 0 이 되고 임계가 `now()` 로 올라붙어
     * **탈퇴 당일 회원까지 전원** 되돌릴 수 없는 2차 파기 대상이 된다. 형제 getter 셋은
     * 전부 `max(1, …)` 를 갖고 있었고 이것만 없었다.
     *
     * 모집단은 「정수로 읽히면 0 이 되는 값」 전량이다 — 하나라도 통과시키면 red.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=withdraw, toggle=on
     *
     * @effects withdrawn_retention_days_never_collapses_to_same_day_purge
     */
    public function test_withdrawn_retention_days_never_collapses_to_zero(): void
    {
        $handler = app(WithdrawnUserRetentionHandler::class);

        $collapsing = [0, -1, -365, '', 'abc', null];

        $this->assertNotSame([], $collapsing, '검사할 값이 없습니다 — 이 검사가 아무것도 재지 않습니다.');

        foreach ($collapsing as $raw) {
            Config::set('g7_settings.core.privacy.withdrawn_retention_days', $raw);

            $this->assertGreaterThanOrEqual(
                1,
                $handler->retentionDays(),
                '보유기간이 0 일 이하로 내려갔습니다 — 탈퇴 당일 회원까지 즉시 파기됩니다.',
            );
        }

        // 임계가 실제로 오늘보다 과거인지까지 본다 — getter 만 막고 쿼리가 따로 계산하면
        // 이 축은 닫히지 않는다.
        Config::set('g7_settings.core.privacy.withdrawn_retention_days', 0);
        Config::set('g7_settings.core.privacy.withdrawn_retention_enabled', true);

        $justWithdrawn = User::factory()->create([
            'status' => UserStatus::Withdrawn->value,
            'withdrawn_at' => now(),
        ]);

        $this->assertNotContains(
            $justWithdrawn->id,
            $handler->targetQuery()->pluck('id')->all(),
            '방금 탈퇴한 회원이 즉시 2차 파기 대상이 됐습니다.',
        );
    }

    /**
     * R7-C3a: 사전 안내가 실패한 회원의 통지 표지는 되돌아간다.
     *
     * 표지는 중복 발송을 막으려고 발송보다 먼저 찍는다. 그 순서는 발송이 성공할 때만 옳다 —
     * 실패한 회원의 표지를 그대로 두면 재통지 쿼리의 `whereNull` 이 그를 **영구히** 배제하는
     * 동안 집행 쿼리는 기한이 지나는 즉시 잡아, **안내를 한 번도 받지 못한 회원이 예고 없이
     * 휴면으로 전환된다.** R6-C1 이 닫은 것과 같은 부류의 피해가 예외 경로로 재현되던 자리다.
     *
     * 경합을 흉내내지 않고 훅에서 실제로 던져 재현한다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=pre_notice, toggle=on
     *
     * @effects dormancy_notice_marker_rolls_back_when_delivery_throws
     */
    public function test_failed_advance_notice_rolls_back_its_marker(): void
    {
        $this->enableDormancy();

        $failing = $this->createInactiveUser(705);
        $healthy = $this->createInactiveUser(706);

        $service = app(DormantAccountService::class);

        $this->assertSame(
            2,
            $service->preNoticeQuery()->count(),
            '픽스처가 사전 안내 대상이 아닙니다 — 이 검사가 실패 경로를 재지 못합니다.',
        );

        HookManager::addAction('core.privacy.dormant_pre_notice', function (User $user) use ($failing): void {
            if ($user->id === $failing->id) {
                throw new \RuntimeException('메일 발송 실패');
            }
        });

        $processed = $service->notifyUpcomingDormancy(dryRun: false);

        $this->assertSame(1, $processed, '실패한 회원이 처리 건수에 잡혔습니다.');

        $this->assertNotNull(
            $healthy->fresh()->dormant_notified_at,
            '한 회원의 실패가 같은 청크의 다른 회원까지 막았습니다.',
        );

        $this->assertNull(
            $failing->fresh()->dormant_notified_at,
            '안내가 실패한 회원에게 통지 표지가 남았습니다 — 재통지에서 영구히 빠진 채 예고 없이 전환됩니다.',
        );

        // 표지가 되돌아갔으므로 ① 전환 대상이 아니고 ② 다음 주기에 다시 안내 대상이다.
        $this->travel(31)->days();

        $this->assertNotContains(
            $failing->id,
            $service->convertQuery()->pluck('id')->all(),
            '안내를 받지 못한 회원이 예고 없이 휴면 전환 대상에 올랐습니다.',
        );

        $this->travelBack();

        $this->assertContains(
            $failing->id,
            $service->preNoticeQuery()->pluck('id')->all(),
            '안내가 실패한 회원이 재통지 대상으로 돌아오지 않았습니다.',
        );
    }

    /**
     * R7-C3b: 한 단계가 통째로 던져도 나머지 단계는 돌고, 커맨드가 실패를 종료 코드로 알린다.
     *
     * 감싸지 않으면 1단계의 예외 하나가 전환·탈퇴 예고·탈퇴를 그날 전부 건너뛰게 하고,
     * 종료 코드는 언제나 0 이라 스케줄러가 영구히 정상으로 읽는다 — 개인정보 파기와 법정
     * 안내가 조용히 멈춘 것을 아무도 모른다. 형제 `privacy:process-retention` 이 같은
     * 방어를 갖고 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=convert, toggle=on
     *
     * @effects dormancy_command_isolates_stage_failures_and_reports_them
     */
    public function test_a_failing_stage_neither_stops_the_others_nor_reports_success(): void
    {
        $this->enableDormancy();

        $converting = $this->createInactiveUser(705);
        $service = app(DormantAccountService::class);
        $service->notifyUpcomingDormancy(dryRun: false);

        $this->travel(31)->days();

        $this->assertContains(
            $converting->id,
            $service->convertQuery()->pluck('id')->all(),
            '픽스처가 전환 대상이 아닙니다 — 이 검사가 단계 격리를 재지 못합니다.',
        );

        // 뒤 단계(탈퇴 예고)를 통째로 죽인다 — 회원 단위가 아니라 단계 단위 실패다.
        // 격리가 없으면 이 예외가 커맨드를 뚫고 나가 앞 단계의 전환 결과까지 보고되지 않는다.
        $this->mock(DormantAccountService::class, function ($mock) use ($service) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('isWithdrawEnabled')->andReturn(true);
            $mock->shouldReceive('dormantDays')->andReturn(730);
            $mock->shouldReceive('noticeDays')->andReturn(30);
            $mock->shouldReceive('notifyUpcomingDormancy')->andReturn(0);
            $mock->shouldReceive('convertToDormant')->andReturnUsing(
                fn (bool $dryRun): int => $service->convertToDormant($dryRun)
            );
            $mock->shouldReceive('notifyUpcomingWithdrawal')->andThrow(new \RuntimeException('단계 실패'));
            $mock->shouldReceive('withdrawDormant')->andReturn(0);
        });

        $exitCode = Artisan::call('privacy:process-dormant');

        $this->travelBack();

        $this->assertSame(
            UserStatus::Dormant->value,
            $converting->fresh()->status,
            '한 단계의 실패가 다른 단계까지 막았습니다 — 그날의 전환·파기가 통째로 밀립니다.',
        );

        $this->assertNotSame(
            0,
            $exitCode,
            '단계가 실패했는데 커맨드가 성공으로 끝났습니다 — 스케줄러가 영구히 정상으로 읽습니다.',
        );
    }

    /**
     * R7-C4: 2FA 수단이 없어 차단되는 로그인은 세션을 남기지 않는다.
     *
     * `Auth::attempt()` 가 연 세션을 회수하는 자리가 2FA 판정 **뒤**에 있어,
     * `TwoFactorEnrollmentRequiredException` 경로만 회수를 건너뛰었다. 컨트롤러가 그 예외를
     * 정상 응답으로 바꾸므로 세션 쿠키가 그대로 발급되던 자리다. 휴면 회원이 이 모집단에
     * 들면 해제되지 않은 채 자동 탈퇴 시계만 돈다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=restore, toggle=on
     *
     * @effects login_blocked_for_missing_two_factor_leaves_no_session
     */
    public function test_enrollment_blocked_login_leaves_no_session(): void
    {
        Config::set('g7_settings.core.security.two_factor_auth', true);
        Config::set('g7_settings.core.security.two_factor_scope', 'all');

        // 「수단이 없고 운영자가 메일 폴백을 껐다」를 만든다 — 이 조합만
        // `TwoFactorEnrollmentRequiredException` 을 던지고, 그 예외가 세션 회수를 건너뛰던
        // 유일한 경로였다. 프로바이더 해석기를 통째로 바꿔 purpose 등록 절차에 기대지 않는다.
        $provider = new EnrollmentRequiredProvider;

        $manager = \Mockery::mock(IdentityVerificationManager::class);
        $manager->shouldReceive('resolveForPurpose')->andReturn($provider);
        $manager->shouldReceive('has')->andReturn(false);
        $this->app->instance(IdentityVerificationManager::class, $manager);

        $user = User::factory()->create(['status' => UserStatus::Active->value]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertSame(
            401,
            $response->status(),
            '2단계 인증 수단이 없는 계정이 차단되지 않았습니다 — 이 검사가 아무것도 재지 않습니다.',
        );

        $this->assertGuest('web');
    }

    /**
     * R7-B1: 관리자 로그인이 403 으로 거절해도 휴면 해제 사실은 안내된다.
     *
     * 비관리자 휴면 회원이 관리자 로그인 화면을 쓰면 비밀번호 확인은 성공하므로
     * `issueLoginSession()` 이 **먼저** 휴면을 해제하고 활동 로그까지 남긴다. 그 뒤 권한
     * 판정이 403 으로 거절하면서 발급분만 회수하는데, 403 응답에는 `dormancy_restored` 가
     * 실리지 않아 그 플래그에 묶인 토스트도 뜨지 않았다 — 회원은 **로그인이 실패한 줄 알지만
     * 실제로는 휴면이 해제된** 상태로 돌아간다. 계획서 §5.2 3단계가 약속한 「해제 + 활동 로그
     * + 안내」 중 안내만 사라지던 자리다.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=restore, toggle=on
     *
     * @effects admin_login_rejection_still_reports_dormancy_restoration
     */
    public function test_admin_login_rejection_still_announces_the_restoration(): void
    {
        $this->enableDormancy();

        $dormant = User::factory()->create([
            'status' => UserStatus::Dormant->value,
            'dormant_at' => now()->subDays(10),
        ]);

        $response = $this->postJson('/api/auth/admin/login', [
            'email' => $dormant->email,
            'password' => 'password',
        ]);

        $response->assertStatus(403);

        // 해제는 실제로 일어났다 — 이 전제가 깨지면 이 검사는 다른 것을 재고 있다.
        $this->assertSame(
            UserStatus::Active->value,
            $dormant->fresh()->status,
            '관리자 로그인 경로에서 휴면이 해제되지 않았습니다 — 이 검사가 안내 누락을 재지 못합니다.',
        );

        // 그리고 그 사실이 회원에게 전달된다.
        $this->assertSame(
            __('auth.admin_required_dormancy_restored'),
            $response->json('message'),
            '휴면이 해제됐는데 403 문구가 그 사실을 알리지 않습니다 — 회원은 로그인이 실패한 줄로만 압니다.',
        );

        // 평시(휴면이 아니던 비관리자)에는 기존 문구 그대로다 — 안내가 늘 뜨면 뜻이 사라진다.
        $active = User::factory()->create(['status' => UserStatus::Active->value]);

        $plain = $this->postJson('/api/auth/admin/login', [
            'email' => $active->email,
            'password' => 'password',
        ]);

        $plain->assertStatus(403);

        $this->assertSame(
            __('auth.admin_required'),
            $plain->json('message'),
            '휴면과 무관한 거절에도 해제 안내가 붙었습니다.',
        );
    }
}
