<?php

namespace Tests\Feature\Api\Profile;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 회원 활동 기록 API — 페이지·기간·본인 활동만 (7.2.1)
 */
class ActivityLogTest extends TestCase
{
    use DatabaseTransactions;

    /** 활동 기록 주소 */
    private const URL = '/api/user/profile/activity-log';

    /**
     * 프로필 조회 권한을 가진 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function member(): User
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.profile.read'],
            [
                'name' => ['ko' => 'core.profile.read', 'en' => 'core.profile.read'],
                'description' => ['ko' => 'core.profile.read', 'en' => 'core.profile.read'],
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'type' => 'user',
            ]
        );
        $role = Role::create([
            'identifier' => 'i952_user_'.uniqid(),
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'is_active' => true,
        ]);
        $role->permissions()->sync([$permission->id]);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user->fresh();
    }

    /**
     * 활동 기록을 남깁니다.
     *
     * @param  User  $user  회원
     * @param  ActivityLogType  $type  기록 종류
     * @param  string  $createdAt  기록 시각
     * @return ActivityLog 기록
     */
    private function log(User $user, ActivityLogType $type, string $createdAt): ActivityLog
    {
        return ActivityLog::create([
            'log_type' => $type,
            'action' => $type === ActivityLogType::Admin ? 'user.update' : 'profile.update',
            'description_key' => 'activity_log.description.profile_update',
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
            'user_id' => $user->id,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * 인증 헤더를 붙인 요청 주체를 반환합니다.
     *
     * @param  User  $user  회원
     * @return static 요청 주체
     */
    private function as(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('i952')->plainTextToken);
    }

    /**
     * 기본 50건씩, 본인 회원 활동만(관리자 작업 기록 제외) 최신순으로, pagination 과 함께 준다.
     *
     * @scenario case=profile_activity_log_default
     *
     * @effects user_activity_log_paginated
     *
     * @return void 반환값 없음
     */
    public function test_default_page_has_only_user_logs_with_pagination(): void
    {
        $user = $this->member();
        for ($i = 0; $i < 55; $i++) {
            $this->log($user, ActivityLogType::User, now()->subMinutes($i + 1)->toDateTimeString());
        }
        $this->log($user, ActivityLogType::Admin, now()->toDateTimeString());

        $response = $this->as($user)->getJson(self::URL)->assertOk();

        $this->assertCount(50, $response->json('data.activities'));
        $this->assertSame(55, $response->json('data.pagination.total'));
        $this->assertTrue($response->json('data.pagination.has_more_pages'));
        $this->assertNotContains('user.update', array_column($response->json('data.activities'), 'action'));

        $second = $this->as($user)->getJson(self::URL.'?page=2')->assertOk();
        $this->assertSame(2, $second->json('data.pagination.current_page'));
        $this->assertGreaterThanOrEqual(5, count($second->json('data.activities')));
    }

    /**
     * per_page 는 50 을 넘을 수 없고(422), 기간 필터는 사이트 날짜 기준으로 거른다. 끝 날짜가 시작보다 앞이면 422 다.
     *
     * @scenario case=profile_activity_log_filters
     *
     * @effects user_activity_log_date_filter
     *
     * @return void 반환값 없음
     */
    public function test_per_page_limit_and_date_filter(): void
    {
        config(['app.default_user_timezone' => 'UTC']);
        $user = $this->member();
        $this->log($user, ActivityLogType::User, '2026-10-01 12:00:00');
        $this->log($user, ActivityLogType::User, '2026-10-05 12:00:00');
        $this->log($user, ActivityLogType::User, '2026-10-10 12:00:00');

        $this->as($user)->getJson(self::URL.'?per_page=51')->assertStatus(422);
        $this->as($user)->getJson(self::URL.'?date_from=2026-10-10&date_to=2026-10-01')->assertStatus(422);

        $filtered = $this->as($user)->getJson(self::URL.'?date_from=2026-10-02&date_to=2026-10-06&per_page=10')->assertOk();
        $this->assertSame(1, $filtered->json('data.pagination.total'));
    }

    /**
     * 남의 활동은 보이지 않는다.
     *
     * @scenario case=profile_activity_log_ownership
     *
     * @effects user_activity_log_own_only
     *
     * @return void 반환값 없음
     */
    public function test_other_members_logs_are_hidden(): void
    {
        $user = $this->member();
        $other = $this->member();
        $this->log($other, ActivityLogType::User, now()->toDateTimeString());

        $this->assertSame(0, $this->as($user)->getJson(self::URL)->assertOk()->json('data.pagination.total'));
    }
}
