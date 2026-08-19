<?php

namespace Tests\Feature\Services;

use App\Enums\PermissionType;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 관리자 탈퇴 경로 통일 테스트 (공개이슈 #112 전수 해소분)
 *
 * 관리자가 상태만 '탈퇴'로 바꾸는 경로는 상태 컬럼만 갱신해 익명화와
 * before/after_withdraw 훅을 통째로 건너뛰었다. 본인 탈퇴와 결과가 같아야 한다.
 *
 * @scenario entry_point=admin_update_status, entry_point=admin_bulk_status, target_account=admin
 *
 * @effects admin_status_change_runs_full_withdrawal, admin_status_change_fires_withdraw_hooks, bulk_status_withdraw_delegates_per_user, bulk_status_reports_failed_count, admin_account_withdraw_blocked_consistently
 */
class AdminWithdrawPathUnificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * W8: 단건 상태 변경으로 탈퇴시켜도 익명화와 훅이 동일하게 수행된다.
     */
    public function test_update_user_with_withdrawn_status_runs_full_withdrawal(): void
    {
        $user = User::factory()->create([
            'email' => 'g7fix112.w8@example.com',
            'nickname' => '닉네임',
        ]);
        $user->createToken('t');

        $fired = $this->captureWithdrawHooks();

        app(UserService::class)->updateUser($user, [
            'status' => UserStatus::Withdrawn->value,
        ]);

        $user->refresh();

        $this->assertSame(UserStatus::Withdrawn->value, $user->status);
        $this->assertNotNull($user->withdrawn_at);
        $this->assertStringContainsString('_deleted_', $user->email);
        $this->assertStringEndsWith('_탈퇴', $user->nickname);
        $this->assertSame(0, $user->tokens()->count());

        $this->assertTrue($fired['before'], 'before_withdraw 훅이 발화해야 합니다.');
        $this->assertTrue($fired['after'], 'after_withdraw 훅이 발화해야 합니다.');
    }

    /**
     * W8: 같은 요청의 다른 필드 갱신도 함께 반영된다.
     */
    public function test_update_user_with_withdrawn_status_still_applies_other_fields(): void
    {
        $user = User::factory()->create(['email' => 'g7fix112.w8b@example.com']);

        app(UserService::class)->updateUser($user, [
            'status' => UserStatus::Withdrawn->value,
            'admin_memo' => '관리자 처리',
        ]);

        $user->refresh();

        $this->assertSame('관리자 처리', $user->admin_memo);
        $this->assertSame(UserStatus::Withdrawn->value, $user->status);
    }

    /**
     * 탈퇴가 실패하면 같은 요청의 프로필 갱신도 함께 되돌아간다.
     *
     * 프로필 갱신과 탈퇴를 따로 커밋하면, 탈퇴가 실패했을 때 이름·메모 변경만 남아
     * "전부 성공하거나 전부 취소" 가 이 경로에서만 깨진다. 익명화 결과와 같은 이메일을
     * 미리 점유해 마지막 쓰기(withdraw)를 실패시킨다.
     *
     * @effects admin_status_change_is_atomic_with_profile_update
     */
    public function test_failed_withdrawal_rolls_back_the_profile_update(): void
    {
        $user = User::factory()->create([
            'email' => 'g7fix112.w8c@example.com',
            'name' => '원래 이름',
        ]);

        // withdraw() 가 만들 이메일을 미리 점유 → unique 충돌로 마지막 쓰기 실패
        User::factory()->create([
            'email' => 'g7fix112.w8c@example.com_deleted_'.now()->format('Ymd').'_'.$user->id,
        ]);

        try {
            app(UserService::class)->updateUser($user, [
                'status' => UserStatus::Withdrawn->value,
                'name' => '바뀐 이름',
            ]);
            $this->fail('탈퇴 실패 시 예외가 전파되어야 합니다.');
        } catch (ValidationException $e) {
            // 기대한 실패
        }

        $user->refresh();

        $this->assertSame('원래 이름', $user->name, '탈퇴가 실패했는데 이름 변경이 남았습니다.');
        $this->assertNotSame(UserStatus::Withdrawn->value, $user->status);
    }

    /**
     * W9: 일괄 상태 변경도 건별 정식 탈퇴로 처리되고, 차단 대상은 실패 건수로 보고된다.
     */
    public function test_bulk_status_withdrawn_delegates_per_user_and_reports_failures(): void
    {
        $normal = User::factory()->create(['email' => 'g7fix112.w9a@example.com']);
        $admin = $this->createAdminUser('g7fix112.w9b@example.com');

        $result = app(UserService::class)->bulkUpdateStatus(
            [$normal->uuid, $admin->uuid],
            UserStatus::Withdrawn->value
        );

        $this->assertSame(1, $result['updated_count']);
        $this->assertSame(1, $result['failed_count']);

        $normal->refresh();
        $admin->refresh();

        $this->assertStringContainsString('_deleted_', $normal->email);
        $this->assertSame('g7fix112.w9b@example.com', $admin->email, '관리자는 변경되지 않아야 합니다.');
        $this->assertNull($admin->withdrawn_at);

        // 건수만으로는 화면이 "왜 안 됐는지" 를 말할 수 없다 — 사유가 응답에 실려야 한다.
        $this->assertNotEmpty($result['failed_reasons'] ?? [], '실패 사유가 응답에 없습니다.');
        $this->assertSame(
            [__('user.withdraw_admin_forbidden')],
            $result['failed_reasons'],
            '관리자 차단 사유가 그대로 전달되어야 합니다.'
        );
    }

    /**
     * W9-b: 전건이 차단되어도 200 으로 나가므로, 응답만으로 "아무 일도 없었음" 이 구분되어야 한다.
     *
     * 이 구분이 없으면 화면은 성공 안내를 띄우고 운영자는 처리된 줄 안다 (실제 제보 경로).
     */
    public function test_bulk_status_withdrawn_reports_zero_updated_when_every_target_is_blocked(): void
    {
        $admin = $this->createAdminUser('g7fix112.w9c@example.com');

        $result = app(UserService::class)->bulkUpdateStatus(
            [$admin->uuid],
            UserStatus::Withdrawn->value
        );

        $this->assertSame(0, $result['updated_count']);
        $this->assertSame(1, $result['failed_count']);
        $this->assertSame([__('user.withdraw_admin_forbidden')], $result['failed_reasons']);

        $admin->refresh();
        $this->assertSame('g7fix112.w9c@example.com', $admin->email);
        $this->assertSame(UserStatus::Active->value, $admin->status);
    }

    /**
     * W9-c: 탈퇴가 아닌 일괄 상태 변경도 같은 응답 형태를 유지한다.
     *
     * 형태가 갈리면 화면 표현식이 한쪽 경로에서만 undefined 를 만난다.
     */
    public function test_bulk_status_response_shape_is_consistent_for_non_withdraw_statuses(): void
    {
        $user = User::factory()->create(['email' => 'g7fix112.w9d@example.com']);

        $result = app(UserService::class)->bulkUpdateStatus(
            [$user->uuid],
            UserStatus::Inactive->value
        );

        $this->assertArrayHasKey('updated_count', $result);
        $this->assertArrayHasKey('failed_count', $result);
        $this->assertArrayHasKey('failed_reasons', $result);
        $this->assertSame([], $result['failed_reasons']);
    }

    /**
     * W9-d: 실패 사유에는 화면 문구 조립을 깨는 문자가 들어가지 않는다.
     *
     * 사유는 `$t:key|reason=...` 파라미터로 전달되는데, 파서가 `|` `&` `=` 를
     * 구분자로 읽으므로 그 문자가 섞이면 안내가 조용히 잘린다. 알 수 없는 예외의
     * 원문 메시지를 그대로 싣지 않는 것이 이 불변식을 지키는 방법이다.
     */
    public function test_bulk_withdraw_reasons_are_safe_for_message_parameters(): void
    {
        $admin = $this->createAdminUser('g7fix112.w9e@example.com');

        $result = app(UserService::class)->bulkUpdateStatus(
            [$admin->uuid, 'ffffffff-0000-0000-0000-000000000000'],
            UserStatus::Withdrawn->value
        );

        $this->assertNotEmpty($result['failed_reasons']);

        foreach ($result['failed_reasons'] as $reason) {
            $this->assertDoesNotMatchRegularExpression(
                '/[|&=]/u',
                $reason,
                "실패 사유에 파라미터 구분자가 들어 있습니다: {$reason}"
            );
        }
    }

    /**
     * W9: 탈퇴 외 상태의 일괄 변경은 종전 동작을 유지한다.
     */
    public function test_bulk_status_other_status_keeps_previous_behaviour(): void
    {
        $user = User::factory()->create(['email' => 'g7fix112.w9c@example.com']);

        $result = app(UserService::class)->bulkUpdateStatus(
            [$user->uuid],
            UserStatus::Inactive->value
        );

        $this->assertSame(1, $result['updated_count']);

        $user->refresh();
        $this->assertSame(UserStatus::Inactive->value, $user->status);
        $this->assertSame('g7fix112.w9c@example.com', $user->email);
    }

    /**
     * W10: 관리자를 상태 변경으로 탈퇴시키려는 시도는 일관되게 차단된다.
     */
    public function test_update_user_cannot_withdraw_admin_via_status(): void
    {
        $admin = $this->createAdminUser('g7fix112.w10@example.com');

        $this->expectException(ValidationException::class);

        try {
            app(UserService::class)->updateUser($admin, [
                'status' => UserStatus::Withdrawn->value,
                'admin_memo' => '차단되어야 함',
            ]);
        } finally {
            $admin->refresh();

            // 차단 대상이면 다른 필드도 저장되지 않아야 한다.
            $this->assertSame('g7fix112.w10@example.com', $admin->email);
            $this->assertNotSame(UserStatus::Withdrawn->value, $admin->status);
            $this->assertNull($admin->admin_memo);
        }
    }

    /**
     * 탈퇴 훅 발화 여부를 수집합니다.
     *
     * @return \ArrayObject<string, bool> 훅 발화 기록 (참조로 갱신됨)
     */
    private function captureWithdrawHooks(): \ArrayObject
    {
        // 배열은 값 타입이라 반환하면 사본이 된다 — 참조 의미를 갖는 객체로 수집한다.
        $fired = new \ArrayObject(['before' => false, 'after' => false]);

        HookManager::addAction('core.user.before_withdraw', function () use ($fired) {
            $fired['before'] = true;
        }, 10);

        HookManager::addAction('core.user.after_withdraw', function () use ($fired) {
            $fired['after'] = true;
        }, 10);

        return $fired;
    }

    /**
     * 관리자 역할을 가진 사용자를 생성합니다.
     *
     * @param  string  $email  이메일
     * @return User 생성된 사용자
     */
    private function createAdminUser(string $email): User
    {
        $role = Role::factory()->create(['identifier' => 'admin_'.uniqid()]);
        $permission = Permission::factory()->create(['type' => PermissionType::Admin]);
        $role->permissions()->attach($permission);

        $user = User::factory()->create(['email' => $email]);
        $user->roles()->attach($role);

        return $user->fresh();
    }
}
