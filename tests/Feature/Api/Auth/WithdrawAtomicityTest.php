<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\AttachmentSourceType;
use App\Enums\PermissionType;
use App\Enums\UserStatus;
use App\Models\Attachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 회원 탈퇴 원자성/접미사 충돌 회귀 테스트 (공개이슈 #112)
 *
 * 재현 경로: 가입 → 탈퇴 → 같은 이메일 재가입 → 같은 날 재탈퇴.
 * 수정 전에는 email unique 위반으로 500 이 났고, 그 시점에 토큰·동의 이력은
 * 이미 삭제된 뒤라 "로그인은 되는데 탈퇴는 실패한" 반파괴 상태가 남았다.
 *
 * @scenario entry_point=self_withdraw_api, email_state=reused_same_day, failure_injection=last_write_fails, field_length=exceeds_limit
 *
 * @effects withdraw_is_atomic_all_or_nothing, consents_and_tokens_restored_on_failure, avatar_file_kept_on_failure, same_day_rewithdraw_with_same_email_succeeds, long_values_truncated_within_column_limits, withdraw_is_idempotent, failed_withdraw_leaves_no_activity_log
 */
class WithdrawAtomicityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * W1: 같은 날 같은 이메일로 재가입한 회원이 다시 탈퇴해도 실패하지 않는다.
     */
    public function test_same_day_rewithdraw_with_same_email_succeeds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 10:00:00'));

        $first = User::factory()->create(['email' => 'g7fix112.a@example.com']);
        $second = User::factory()->create(['email' => 'placeholder@example.com']);

        $service = app(UserService::class);

        $this->assertTrue($service->withdrawUser($first));

        // 같은 이메일로 재가입한 상황 재현
        $second->forceFill(['email' => 'g7fix112.a@example.com'])->save();

        $this->assertTrue($service->withdrawUser($second));

        $first->refresh();
        $second->refresh();

        $this->assertSame(UserStatus::Withdrawn->value, $first->status);
        $this->assertSame(UserStatus::Withdrawn->value, $second->status);
        $this->assertNotSame($first->email, $second->email);

        // 접미사에 사용자 ID 가 포함되어 구조적으로 유일하다
        $this->assertStringEndsWith('_'.$first->id, $first->email);
        $this->assertStringEndsWith('_'.$second->id, $second->email);
    }

    /**
     * W2: 마지막 단계가 실패하면 동의 이력·토큰 삭제도 함께 되돌아간다.
     */
    public function test_failed_withdraw_rolls_back_consents_and_tokens(): void
    {
        $user = User::factory()->create(['email' => 'g7fix112.b@example.com']);
        $user->createToken('t');

        // 약관 동의 이력도 선행 삭제 대상이다 — 롤백 대상에 포함되는지 함께 본다.
        $user->consents()->create([
            'consent_type' => 'terms',
            'agreed_at' => now(),
        ]);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(1, $user->consents()->count());

        $service = app(UserService::class);

        try {
            $service->withdrawUser($this->bindFailingWithdraw($user));
            $this->fail('탈퇴가 실패해야 합니다.');
        } catch (ValidationException $e) {
            // 기대한 실패
        }

        $user->refresh();

        $this->assertSame(1, $user->tokens()->count(), '토큰이 원상 복구되어야 합니다.');
        $this->assertSame(1, $user->consents()->count(), '약관 동의 이력이 원상 복구되어야 합니다.');
        $this->assertNotSame(UserStatus::Withdrawn->value, $user->status);
        $this->assertStringNotContainsString('_deleted_', $user->email);
    }

    /**
     * W3: 탈퇴가 실패하면 아바타 파일도 남아 있어야 한다.
     */
    public function test_failed_withdraw_keeps_avatar_file(): void
    {
        Storage::fake('attachments');

        $user = User::factory()->create(['email' => 'g7fix112.c@example.com']);

        $attachment = Attachment::factory()->create([
            'attachmentable_type' => User::class,
            'attachmentable_id' => $user->id,
            'collection' => 'avatar',
            'disk' => 'attachments',
            'path' => 'attachments/avatars/w3.jpg',
            'source_type' => AttachmentSourceType::Core,
        ]);
        Storage::disk('attachments')->put('attachments/avatars/w3.jpg', 'fake');

        try {
            app(UserService::class)->withdrawUser($this->bindFailingWithdraw($user));
            $this->fail('탈퇴가 실패해야 합니다.');
        } catch (ValidationException $e) {
            // 기대한 실패
        }

        Storage::disk('attachments')->assertExists('attachments/avatars/w3.jpg');
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    }

    /**
     * W4: 이미 탈퇴한 계정에 다시 호출해도 접미사가 겹쳐 붙지 않는다 (멱등).
     */
    public function test_withdraw_is_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'g7fix112.d@example.com', 'nickname' => '닉']);

        $service = app(UserService::class);
        $service->withdrawUser($user);

        $user->refresh();
        $emailAfterFirst = $user->email;
        $nicknameAfterFirst = $user->nickname;

        $this->assertTrue($user->withdraw());

        $user->refresh();
        $this->assertSame($emailAfterFirst, $user->email);
        $this->assertSame($nicknameAfterFirst, $user->nickname);
        $this->assertSame(1, substr_count($user->email, '_deleted_'));
    }

    /**
     * W5: 컬럼 길이 상한에 걸리는 값도 절단 후 정상 저장된다.
     */
    public function test_long_values_are_truncated_within_column_limits(): void
    {
        $limit = SchemaBuilder::$defaultStringLength ?? 255;

        // 접미사를 붙이면 반드시 상한을 넘도록 컬럼 길이를 꽉 채운다 —
        // 여유가 있으면 절단 경로가 실행되지 않아 절단 없이도 통과한다.
        $user = User::factory()->create([
            'email' => str_repeat('a', $limit - 12).'@example.com',
            'name' => str_repeat('이', $limit),
            'nickname' => str_repeat('가', User::NICKNAME_MAX_LENGTH),
        ]);

        $this->assertSame($limit, mb_strlen($user->email), '이메일이 컬럼 상한을 꽉 채워야 합니다');
        $this->assertSame($limit, mb_strlen($user->name));

        $this->assertTrue(app(UserService::class)->withdrawUser($user));

        $user->refresh();

        // 절단이 실제로 일어났는지 — 원값 길이 그대로면 접미사가 안 붙은 것이다
        $this->assertSame($limit, mb_strlen($user->email), '이메일이 상한에 맞춰 절단되어야 합니다');
        $this->assertSame($limit, mb_strlen($user->name));
        $this->assertSame(User::NICKNAME_MAX_LENGTH, mb_strlen($user->nickname));

        $this->assertStringContainsString('_deleted_', $user->email);
        $this->assertStringEndsWith('_'.$user->id, $user->email);
        $this->assertStringContainsString('_탈퇴_', $user->name);
        $this->assertStringEndsWith('_탈퇴', $user->nickname);
    }

    /**
     * W7: 탈퇴가 실패하면 활동 로그도 남지 않는다.
     *
     * 관리자 계정의 탈퇴 차단은 500 이 아니라 422 로 나가야 한다 — 종전에는 예외가
     * 그대로 올라가 서버 오류로 표시됐다.
     *
     * @effects blocked_withdraw_returns_422_not_500
     */
    public function test_failed_withdraw_does_not_log_activity(): void
    {
        $role = Role::factory()->create(['identifier' => 'admin']);
        $permission = Permission::factory()->create([
            'type' => PermissionType::Admin,
        ]);
        $role->permissions()->attach($permission);

        $admin = User::factory()->create(['email' => 'g7fix112.admin@example.com']);
        $admin->roles()->attach($role);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken,
            'Accept' => 'application/json',
        ])->deleteJson('/api/me');

        $response->assertStatus(422);

        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'profile.withdraw',
            'user_id' => $admin->id,
        ]);
    }

    /**
     * 마지막 쓰기(withdraw 저장)를 실패시키는 사용자 인스턴스를 돌려줍니다.
     *
     * 접미사가 붙은 이메일을 미리 점유해 두면 unique 위반으로 저장이 실패합니다 —
     * 실제 운영에서 발생한 실패 모드와 동일한 경로입니다.
     *
     * @param  User  $user  대상 사용자
     * @return User 같은 사용자 인스턴스
     */
    private function bindFailingWithdraw(User $user): User
    {
        $suffix = '_deleted_'.now()->format('Ymd').'_'.$user->id;

        // 미끼 유저가 같은 접미사 이메일을 선점 → withdraw() 저장이 unique 위반으로 실패
        User::factory()->create(['email' => $user->email.$suffix]);

        return $user;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
