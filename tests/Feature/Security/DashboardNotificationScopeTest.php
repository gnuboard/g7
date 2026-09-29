<?php

namespace Tests\Feature\Security;

use App\Enums\NotificationLogStatus;
use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\NotificationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ScopedPermissionEnforcement;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 대시보드 최근 알림의 self 스코프 우회 회귀 테스트 (KVE-2026-2374)
 *
 * 배포 기본 역할 `manager` 는 `core.notification-logs.read` 를 self 스코프로 가진다 — 발송
 * 이력 목록에는 자기가 보내거나 받은 알림만 보인다. 대시보드 「최근 알림」 은 같은 권한으로
 * 열리지만 스코프 없이 전체 로그를 읽어, 다른 회원의 비밀번호 재설정 안내 같은 알림이 그대로
 * 보였다. 이 권한은 소유 컬럼이 발신자·수신자 둘이라 라우트 미들웨어의 단일 owner_key 검사를
 * 받지 못하고, 저장소 쿼리 스코프가 유일한 정본이다 — 그래서 「쿼리 스코프가 정본인 권한」
 * 목록과, 스코프가 붙었는데 어느 쪽으로도 강제되지 않는 부여를 찾아내는 정합성 검사를 함께
 * 잠근다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/manager-scope-and-ceiling-bypass.yaml 참조.
 */
class DashboardNotificationScopeTest extends TestCase
{
    use DatabaseTransactions;

    private const RECENT_URL = '/api/admin/dashboard/recent-notifications';

    private User $manager;

    private User $superAdmin;

    private User $victim;

    /**
     * 코어 역할·권한을 심고 매니저·슈퍼관리자·피해 회원과 알림 로그를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->clearPermissionHelperCache();

        $this->manager = $this->createUserWithRole('manager');
        $this->superAdmin = $this->createUserWithRole('admin', ['is_super' => true]);
        $this->victim = User::factory()->create(['email' => 'kve2374-victim-'.uniqid().'@example.test']);

        // 피해 회원이 받은 비밀번호 재설정 안내 — 가장 최근 로그라 스코프가 없으면 맨 위에 온다.
        $this->createLog('reset_password', '비밀번호 재설정 안내', recipient: $this->victim, minutesAgo: 1);
        // 매니저 본인이 받은 알림 · 매니저가 보낸 알림 — self 스코프에서 보여야 하는 두 갈래.
        $this->createLog('welcome', '매니저 수신 알림', recipient: $this->manager, minutesAgo: 5);
        $this->createLog('notice', '매니저 발신 알림', recipient: $this->victim, sender: $this->manager, minutesAgo: 6);
    }

    /**
     * `PermissionHelper` 의 권한 메타 정적 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    private function clearPermissionHelperCache(): void
    {
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * 시드된 역할을 가진 사용자를 만듭니다.
     *
     * @param  string  $roleIdentifier  역할 식별자
     * @param  array<string, mixed>  $attributes  사용자 속성
     * @return User 역할이 붙은 사용자
     */
    private function createUserWithRole(string $roleIdentifier, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['status' => 'active'], $attributes));
        $role = Role::where('identifier', $roleIdentifier)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 알림 발송 로그를 만듭니다.
     *
     * @param  string  $type  알림 유형
     * @param  string  $subject  제목
     * @param  User  $recipient  수신 회원
     * @param  User|null  $sender  발신 회원
     * @param  int  $minutesAgo  발송 시각(분 전)
     * @return NotificationLog 생성된 로그
     */
    private function createLog(string $type, string $subject, User $recipient, ?User $sender = null, int $minutesAgo = 0): NotificationLog
    {
        return NotificationLog::create([
            'channel' => 'mail',
            'notification_type' => $type,
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'recipient_user_id' => $recipient->id,
            'recipient_identifier' => $recipient->email,
            'recipient_name' => $recipient->name,
            'sender_user_id' => $sender?->id,
            'subject' => $subject,
            'status' => NotificationLogStatus::Sent,
            'sent_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    /**
     * 토큰 인증 헤더를 붙인 요청 빌더를 돌려줍니다.
     *
     * @param  User  $user  요청 주체
     * @return static 헤더가 붙은 테스트 인스턴스
     */
    private function asUser(User $user): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 대조군 — 발송 이력 목록(주 경로)은 self 매니저에게 당사자 로그만 준다.
     *
     * @scenario case=notification_list_control
     *
     * @effects notification_list_is_party_only_for_scoped_manager
     *
     * @return void 반환값 없음
     */
    public function test_notification_log_list_shows_only_party_logs_to_scoped_manager(): void
    {
        $response = $this->asUser($this->manager)->getJson('/api/admin/notification-logs')->assertOk();

        $this->assertStringNotContainsString('비밀번호 재설정 안내', $response->getContent());
        $this->assertStringContainsString('매니저 수신 알림', $response->getContent());
    }

    /**
     * 대시보드 최근 알림이 목록과 같은 범위(발신·수신 당사자)만 준다.
     *
     * @scenario case=dashboard_recent_notifications_scoped
     *
     * @effects dashboard_recent_notifications_follow_list_scope
     *
     * @return void 반환값 없음
     */
    public function test_dashboard_recent_notifications_follow_list_scope(): void
    {
        $response = $this->asUser($this->manager)->getJson(self::RECENT_URL)->assertOk();

        $subjects = array_column($response->json('data'), 'subject');
        sort($subjects);
        $this->assertSame(['매니저 발신 알림', '매니저 수신 알림'], $subjects);
        $this->assertStringNotContainsString('비밀번호 재설정 안내', $response->getContent());
    }

    /**
     * role 스코프로 받은 권한은 같은 역할 회원이 당사자인 로그까지만 본다 — self 만 처리하고
     * role 은 전체로 흘려보내던 분기도 같은 구멍이다.
     *
     * @scenario case=dashboard_recent_notifications_role_scoped
     *
     * @effects role_scope_limits_notifications_to_role_members
     *
     * @return void 반환값 없음
     */
    public function test_role_scoped_reader_sees_only_role_member_logs(): void
    {
        $permission = Permission::where('identifier', 'core.notification-logs.read')->firstOrFail();
        $role = Role::create([
            'identifier' => 'notif_role_scope_'.uniqid(),
            'name' => ['ko' => '알림 role 스코프', 'en' => 'Notif role scope'],
            'is_active' => true,
        ]);
        $role->permissions()->attach($permission->id, ['scope_type' => ScopeType::Role->value, 'granted_at' => now()]);

        $reader = User::factory()->create(['status' => 'active']);
        $colleague = User::factory()->create(['status' => 'active']);
        foreach ([$reader, $colleague] as $member) {
            $member->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $this->createLog('colleague', '동료 수신 알림', recipient: $colleague, minutesAgo: 2);

        $response = $this->asUser($reader->fresh())->getJson(self::RECENT_URL)->assertOk();

        $this->assertSame(['동료 수신 알림'], array_column($response->json('data'), 'subject'));
    }

    /**
     * 슈퍼관리자는 전체 최근 알림을 그대로 본다(무회귀).
     *
     * @scenario case=dashboard_recent_notifications_super_admin
     *
     * @effects super_admin_sees_all_recent_notifications
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_still_sees_all_recent_notifications(): void
    {
        $response = $this->asUser($this->superAdmin)->getJson(self::RECENT_URL)->assertOk();

        $this->assertSame('비밀번호 재설정 안내', $response->json('data.0.subject'));
        $this->assertCount(3, $response->json('data'));
    }

    /**
     * 배포 역할 정의가 스코프를 거는 권한은 전부 (a) 라우트 스코프 메타(resource_route_key +
     * owner_key)를 갖거나 (b) 쿼리 스코프가 정본인 권한 목록에 있다. 어느 쪽도 아니면 스코프가
     * 어디서도 강제되지 않는다.
     *
     * @scenario case=config_scope_integrity
     *
     * @effects configured_scopes_are_enforced_somewhere
     *
     * @return void 반환값 없음
     */
    public function test_every_configured_scope_is_enforced_by_metadata_or_query_scope(): void
    {
        $violations = ScopedPermissionEnforcement::findUnenforcedInConfig(
            config('core.permissions'),
            config('core.roles'),
        );

        $this->assertSame([], $violations);
    }

    /**
     * DB 에 저장된 부여 중 스코프가 붙었는데 강제 수단이 없는 것을 찾아낸다 — 관리자가 역할
     * 편집으로 메타 없는 권한에 스코프를 걸어도 시드·코어 업데이트 때 경고로 드러난다.
     *
     * @scenario case=db_scope_integrity
     *
     * @effects unenforced_scoped_grant_is_reported
     *
     * @return void 반환값 없음
     */
    public function test_unenforced_scoped_grant_in_database_is_reported(): void
    {
        $this->assertSame([], app(ScopedPermissionEnforcement::class)->findUnenforcedScopedGrants());

        $permission = Permission::where('identifier', 'core.dashboard.read')->firstOrFail();
        $role = Role::where('identifier', 'manager')->firstOrFail();
        $role->permissions()->updateExistingPivot($permission->id, ['scope_type' => ScopeType::Self->value]);

        $this->assertSame(
            [['role' => 'manager', 'permission' => 'core.dashboard.read', 'scope_type' => 'self']],
            app(ScopedPermissionEnforcement::class)->findUnenforcedScopedGrants(),
        );
    }
}
