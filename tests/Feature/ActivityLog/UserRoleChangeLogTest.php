<?php

namespace Tests\Feature\ActivityLog;

use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 회원 등록·수정의 역할 변경 기록 테스트 (#736 D23)
 *
 * 개인정보의 안전성 확보조치 기준 제5조③(권한 부여·변경·말소 내역 3년 보관)의 기록 지점. 회원 등록·수정 화면에서
 * 역할이나 최고관리자 지정이 실제로 바뀌면 `core.user.after_update_roles` 가 나가고 활동 로그 `user.roles_update` 가
 * 붙은·떨어진 역할 식별자와 함께 남는다. 바뀐 것이 없으면 남기지 않는다.
 */
class UserRoleChangeLogTest extends TestCase
{
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 권한이 없는 사용자 정의 역할을 만듭니다 (관리자 상한 검사를 통과하도록 권한을 붙이지 않는다).
     *
     * @param  string  $identifier  역할 식별자
     * @return Role 역할
     */
    private function makeRole(string $identifier): Role
    {
        return Role::create([
            'identifier' => $identifier,
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'description' => ['ko' => $identifier, 'en' => $identifier],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);
    }

    /**
     * 회원의 역할 변경 로그 행을 모두 읽습니다.
     *
     * @param  User  $user  회원
     * @return array<int, object> 로그 행
     */
    private function roleLogs(User $user): array
    {
        return DB::table('activity_logs')
            ->where('action', 'user.roles_update')
            ->where('loggable_id', $user->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * 관리자가 역할을 정해 회원을 등록하면 붙은 역할이 등록 출처로 남는다.
     *
     * @scenario entry_point=admin_create_user, change=attach
     *
     * @effects role_change_logged_with_identifiers
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function creating_a_user_logs_the_assigned_roles(): void
    {
        $admin = $this->makeAdmin();
        $role = $this->makeRole('editor-d23');

        $response = $this->withToken($this->issueToken($admin))->postJson('/api/admin/users', [
            'name' => '역할 회원',
            'email' => 'role-d23@example.com',
            'password' => 'Str0ng!Passw0rd#2026',
            'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'role_ids' => [$role->id],
        ]);
        $response->assertCreated();

        $created = User::where('email', 'role-d23@example.com')->firstOrFail();
        $logs = $this->roleLogs($created);

        $this->assertCount(1, $logs);
        $properties = json_decode($logs[0]->properties, true);
        $this->assertSame('create', $properties['source']);
        $this->assertSame(['editor-d23'], $properties['added']);
        $this->assertSame([], $properties['removed']);
        $this->assertNull($properties['is_super']);
        $this->assertSame('activity_log.description.user_roles_update', $logs[0]->description_key);
        $this->assertSame($admin->id, (int) $logs[0]->user_id, '행위자가 관리자로 남아야 한다');
    }

    /**
     * 회원 수정에서 역할을 바꾸면 추가·회수가 한 행에 남고, 역할을 그대로 두고 저장하면 남지 않는다.
     *
     * @scenario entry_point=admin_update_user, change=attach_and_detach|unchanged|roles_absent
     *
     * @effects role_change_logged_with_identifiers, unchanged_roles_not_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function updating_roles_logs_added_and_removed_only_when_they_change(): void
    {
        $admin = $this->makeAdmin();
        $token = $this->issueToken($admin);
        $before = $this->makeRole('before-d23');
        $after = $this->makeRole('after-d23');
        $member = $this->makeMember();
        $member->roles()->sync([$before->id]);
        $member = $member->fresh();

        $this->withToken($token)->putJson('/api/admin/users/'.$member->uuid, [
            'name' => $member->name,
            'email' => $member->email,
            'role_ids' => [$after->id],
        ])->assertOk();

        $logs = $this->roleLogs($member);
        $this->assertCount(1, $logs);
        $properties = json_decode($logs[0]->properties, true);
        $this->assertSame('update', $properties['source']);
        $this->assertSame(['after-d23'], $properties['added']);
        $this->assertSame(['before-d23'], $properties['removed']);

        // 같은 역할로 다시 저장 / 역할을 싣지 않는 경로(회원 본인 정보수정)로 저장 — 둘 다 새 행이 없어야 한다.
        $this->withToken($token)->putJson('/api/admin/users/'.$member->uuid, [
            'name' => '이름만 변경',
            'email' => $member->email,
            'role_ids' => [$after->id],
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->issueToken($member))->putJson('/api/me', [
            'name' => '이름만 다시 변경',
            'email' => $member->email,
        ])->assertOk();

        $this->assertCount(1, $this->roleLogs($member), '역할이 그대로면 역할 변경 로그를 남기지 않는다');
    }

    /**
     * 최고관리자 지정이 바뀌면 전후 값이 남는다. 이 값은 회원 수정 요청 규칙에 없어 화면에서 바꿀 수 없고
     * 서비스를 직접 부르는 경로(확장·설치)에서만 바뀌므로 서비스로 측정한다.
     *
     * @scenario entry_point=service_update_user, change=is_super
     *
     * @effects is_super_change_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function super_admin_designation_change_is_logged(): void
    {
        $super = $this->makeAdmin(['is_super' => true]);
        $member = $this->makeMember();
        Auth::login($super);

        app(UserService::class)->updateUser($member, ['is_super' => true]);

        $logs = $this->roleLogs($member);
        $this->assertCount(1, $logs);
        $properties = json_decode($logs[0]->properties, true);
        $this->assertSame(['from' => false, 'to' => true], $properties['is_super']);
        $this->assertSame([], $properties['added']);
    }

    /**
     * 훅 인자 계약 — (회원, {attached, detached, is_super}, 출처). 역할 ID 로 싣는다(일괄 경로 훅과 같은 단위).
     *
     * @scenario entry_point=admin_update_user, change=attach_and_detach
     *
     * @effects hook_carries_ids_and_source
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_hook_carries_role_ids_and_source(): void
    {
        $admin = $this->makeAdmin();
        $before = $this->makeRole('hook-before-d23');
        $after = $this->makeRole('hook-after-d23');
        $member = $this->makeMember();
        $member->roles()->sync([$before->id]);
        $member = $member->fresh();

        $captured = [];
        HookManager::addAction('core.user.after_update_roles', function (User $user, array $changes, string $source) use (&$captured) {
            $captured[] = [$user->id, $changes, $source];
        });

        $this->withToken($this->issueToken($admin))->putJson('/api/admin/users/'.$member->uuid, [
            'name' => $member->name,
            'email' => $member->email,
            'role_ids' => [$after->id],
        ])->assertOk();

        $this->assertSame([[$member->id, [
            'attached' => [$after->id],
            'detached' => [$before->id],
            'is_super' => null,
        ], 'update']], $captured);
    }
}
