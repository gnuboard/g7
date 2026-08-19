<?php

namespace Tests\Feature\Services;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\PasswordResetTokenRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\AttachmentSourceType;
use App\Enums\PermissionType;
use App\Models\Attachment;
use App\Models\Menu;
use App\Models\PasswordResetToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\AuthService;
use App\Services\MenuService;
use App\Services\RoleService;
use App\Services\ScheduleService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\TestCase;

/**
 * 다단계 쓰기 원자성 회귀 테스트 (공개이슈 #112 동종 전수 해소분)
 *
 * 표준 패턴: 마지막 쓰기를 담당하는 Repository 를 컨테이너 목킹으로 실패시키고,
 * 선행 단계가 원상 복구되는지 단언합니다.
 *
 * @scenario failure_injection=last_write_fails
 *
 * @effects withdraw_is_atomic_all_or_nothing, avatar_file_removed_only_after_commit
 */
class MultiStepWriteAtomicityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * deleteUser: 마지막 delete 실패 시 역할·동의·토큰·아바타 파일이 원상 복구된다.
     */
    public function test_delete_user_rolls_back_roles_and_tokens(): void
    {
        Storage::fake('attachments');

        $user = User::factory()->create(['email' => 'atomic.delete@example.com']);
        $role = Role::factory()->create(['identifier' => 'member_'.uniqid()]);
        $user->roles()->attach($role);
        $user->createToken('t');
        $user->consents()->create([
            'consent_type' => 'terms',
            'is_agreed' => true,
            'agreed_at' => now(),
        ]);

        // 아바타는 커밋 후에만 지워야 한다 — 롤백된 계정의 파일이 사라지면 복구 불가다.
        $avatar = Attachment::factory()->create([
            'attachmentable_type' => User::class,
            'attachmentable_id' => $user->id,
            'collection' => 'avatar',
            'disk' => 'attachments',
            'path' => 'attachments/avatars/atomic-delete.jpg',
            'source_type' => AttachmentSourceType::Core,
        ]);
        Storage::disk('attachments')->put('attachments/avatars/atomic-delete.jpg', 'fake');

        $this->mockRepositoryFailure(UserRepositoryInterface::class, 'delete');

        $this->expectRollbackFailure(fn () => app(UserService::class)->deleteUser($user));

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame(1, $user->roles()->count(), '역할 연결이 복구되어야 합니다.');
        $this->assertSame(1, $user->tokens()->count(), '토큰이 복구되어야 합니다.');
        $this->assertSame(1, $user->consents()->count(), '동의 이력이 복구되어야 합니다.');

        Storage::disk('attachments')->assertExists('attachments/avatars/atomic-delete.jpg');
        $this->assertDatabaseHas('attachments', ['id' => $avatar->id]);
    }

    /**
     * deleteRole: 마지막 delete 실패 시 사용자·권한 연결이 원상 복구된다.
     */
    public function test_delete_role_rolls_back_relations(): void
    {
        $role = Role::factory()->create(['identifier' => 'member_'.uniqid()]);
        $user = User::factory()->create(['email' => 'atomic.role@example.com']);
        $user->roles()->attach($role);

        $this->mockRepositoryFailure(RoleRepositoryInterface::class, 'delete');

        $this->expectRollbackFailure(fn () => app(RoleService::class)->deleteRole($role));

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertSame(1, $role->users()->count(), '역할 보유자가 복구되어야 합니다.');
    }

    /**
     * register: 중간 실패 시 계정이 남지 않는다 (이메일 점유 유령 계정 차단).
     */
    public function test_register_rolls_back_user_on_failure(): void
    {
        $this->mockRepositoryFailure(RoleRepositoryInterface::class, 'findByIdentifier');

        $this->expectRollbackFailure(fn () => app(AuthService::class)->register([
            'name' => '테스트',
            'email' => 'atomic.register@example.com',
            'password' => 'Password!234',
        ]));

        $this->assertDatabaseMissing('users', ['email' => 'atomic.register@example.com']);
    }

    /**
     * resetPassword: 토큰 삭제 실패 시 비밀번호도 원상 복구된다 (토큰 재사용 차단).
     */
    public function test_reset_password_rolls_back_password_when_token_delete_fails(): void
    {
        $user = User::factory()->create([
            'email' => 'atomic.reset@example.com',
            'password' => Hash::make('OldPassword!1'),
        ]);
        $originalHash = $user->password;

        $token = 'reset-token-value';
        $hashedToken = Hash::make($token);

        app(PasswordResetTokenRepositoryInterface::class)
            ->updateOrCreateByEmail($user->email, [
                'token' => $hashedToken,
                'created_at' => now(),
            ]);

        // 트랜잭션의 **마지막** 쓰기는 토큰 삭제다. 1단계(비밀번호 갱신)를 실패시키면
        // 트랜잭션이 없어도 통과하므로 회귀를 검출하지 못한다.
        $failingRecord = new class extends PasswordResetToken
        {
            public function delete(): bool
            {
                throw new RuntimeException('마지막 쓰기 실패');
            }
        };
        $failingRecord->forceFill([
            'email' => $user->email,
            'token' => $hashedToken,
            'created_at' => now(),
        ])->syncOriginal();

        $tokenRepository = Mockery::mock(app(PasswordResetTokenRepositoryInterface::class));
        $tokenRepository->shouldReceive('findByEmail')->andReturn($failingRecord);
        $this->app->instance(PasswordResetTokenRepositoryInterface::class, $tokenRepository);

        $this->expectRollbackFailure(
            fn () => app(AuthService::class)->resetPassword($token, $user->email, 'NewPassword!1')
        );

        $user->refresh();
        $this->assertSame($originalHash, $user->password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    /**
     * AttachmentService::delete — DB 삭제 실패 시 파일이 남는다 (dangling 없음).
     */
    public function test_attachment_delete_keeps_file_when_db_delete_fails(): void
    {
        Storage::fake('attachments');

        $attachment = Attachment::factory()->create([
            'disk' => 'attachments',
            'path' => 'attachments/atomic.jpg',
            'source_type' => AttachmentSourceType::Core,
        ]);
        Storage::disk('attachments')->put('attachments/atomic.jpg', 'fake');

        $this->mockRepositoryFailure(AttachmentRepositoryInterface::class, 'forceDelete');

        $this->expectRollbackFailure(fn () => app(AttachmentService::class)->delete($attachment->id));

        Storage::disk('attachments')->assertExists('attachments/atomic.jpg');
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    }

    /**
     * ScheduleService::delete — 스케줄 삭제 실패 시 실행 이력이 복구된다.
     */
    public function test_schedule_delete_rolls_back_histories(): void
    {
        $schedule = Schedule::create([
            'name' => '원자성 테스트',
            'type' => 'artisan',
            'command' => 'inspire',
            'expression' => '0 4 * * *',
            'frequency' => 'daily',
            'is_active' => true,
        ]);
        ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now(),
            'status' => 'success',
        ]);

        $this->mockRepositoryFailure(ScheduleRepositoryInterface::class, 'delete');

        $this->expectRollbackFailure(fn () => app(ScheduleService::class)->delete($schedule));

        $this->assertDatabaseHas('schedules', ['id' => $schedule->id]);
        $this->assertSame(1, $schedule->histories()->count(), '실행 이력이 복구되어야 합니다.');
    }

    /**
     * updateUser — 역할 동기화 실패 시 프로필 갱신·토큰 정리가 원상 복구된다.
     */
    public function test_update_user_rolls_back_profile_and_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'atomic.update@example.com',
            'name' => '원래 이름',
        ]);
        $user->createToken('t');

        // 역할 동기화는 `core.permissions.update` 권한이 있어야 수행된다 — 권한 없이 호출하면
        // role_ids 가 통째로 무시되어 마지막 단계 자체가 실행되지 않는다.
        $this->actingAs($this->createUserWithPermission('core.permissions.update'));

        // 마지막 단계(역할 동기화)를 실패시킨다 — 존재하지 않는 역할 ID 는 외래키 제약에
        // 걸린다. 그 전에 프로필 갱신과 토큰 삭제가 이미 수행된 상태다.
        $missingRoleId = (int) (Role::max('id') ?? 0) + 1000;

        $this->expectRollbackFailure(fn () => app(UserService::class)->updateUser($user, [
            'name' => '바뀐 이름',
            'status' => 'inactive',
            'role_ids' => [$missingRoleId],
        ]));

        $user->refresh();

        $this->assertSame('원래 이름', $user->name, '프로필 갱신이 복구되어야 합니다.');
        $this->assertSame(1, $user->tokens()->count(), '토큰이 복구되어야 합니다.');
    }

    /**
     * MenuService::deleteMenu — 메뉴 삭제 실패 시 역할 연결이 복구된다.
     */
    public function test_menu_delete_rolls_back_role_links(): void
    {
        $menu = Menu::factory()->create();
        $role = Role::factory()->create(['identifier' => 'member_'.uniqid()]);
        $menu->roles()->attach($role);

        $this->mockRepositoryFailure(MenuRepositoryInterface::class, 'delete');

        $this->expectRollbackFailure(fn () => app(MenuService::class)->deleteMenu($menu));

        $this->assertDatabaseHas('menus', ['id' => $menu->id]);
        $this->assertSame(1, $menu->roles()->count(), '역할 연결이 복구되어야 합니다.');
    }

    /**
     * 지정한 권한을 가진 사용자를 만듭니다 (역할 경유).
     *
     * @param  string  $identifier  권한 식별자
     * @return User 권한 보유 사용자
     */
    private function createUserWithPermission(string $identifier): User
    {
        $permission = Permission::factory()->create([
            'identifier' => $identifier,
            'type' => PermissionType::Admin,
        ]);

        $role = Role::factory()->create(['identifier' => 'perm_'.uniqid()]);
        $role->permissions()->attach($permission);

        $actor = User::factory()->create(['email' => 'atomic.actor.'.uniqid().'@example.com']);
        $actor->roles()->attach($role);

        return $actor;
    }

    /**
     * 지정한 Repository 메서드만 실패시키고 나머지는 실제 구현에 위임합니다.
     *
     * @param  class-string  $interface  Repository 인터페이스
     * @param  string  $method  실패시킬 메서드
     */
    private function mockRepositoryFailure(string $interface, string $method): void
    {
        // 실제 인스턴스를 감싸는 프록시 부분 목 — 지정 메서드만 실패시키고
        // 나머지 호출은 실제 구현으로 그대로 흘려보낸다.
        $mock = Mockery::mock(app($interface));
        $mock->shouldReceive($method)->andThrow(new RuntimeException('마지막 쓰기 실패'));

        $this->app->instance($interface, $mock);
    }

    /**
     * 롤백을 유발하는 호출이 예외로 끝나는지 확인합니다.
     *
     * @param  callable  $operation  실행할 동작
     */
    private function expectRollbackFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('마지막 쓰기 실패로 예외가 발생해야 합니다.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
        }
    }
}
