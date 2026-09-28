<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 회원 본인용 활동 기록 응답의 접속 IP 노출 회귀 테스트
 *
 * 접속 IP 는 관리자 전용 정보라 코어 회원 Resource 도 관리자 정보 블록에만 싣는다. 본인 활동 기록
 * 조회는 Resource 없이 Service 가 배열을 손수 조립해 그 기준에서 빠져 있었다. 키는 호환을 위해 두고
 * 값만 비운다. 관리자 활동 로그 API 가 IP 를 그대로 싣는 것은 `ActivityLogControllerTest` 가 검증한다.
 */
class ProfileActivityLogIpExposureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 본인 활동 기록 응답에는 접속 IP 가 비어 있고 나머지 항목은 그대로 실린다.
     *
     * @scenario case=note_activity_log_self
     *
     * @effects activity_log_ip_null_for_self
     *
     * @return void 반환값 없음
     */
    public function test_self_activity_log_hides_ip_address(): void
    {
        $user = User::factory()->create();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.profile.read'],
            [
                'name' => json_encode(['ko' => 'core.profile.read', 'en' => 'core.profile.read']),
                'description' => json_encode(['ko' => 'core.profile.read', 'en' => 'core.profile.read']),
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'type' => 'user',
            ]
        );
        $role = Role::create([
            'identifier' => 'user_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $role->permissions()->sync([$permission->id]);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        ActivityLog::create([
            'log_type' => ActivityLogType::User,
            'action' => 'profile.update',
            'description_key' => 'activity_log.description.profile_update',
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.77',
        ]);

        $token = $user->fresh()->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user/profile/activity-log');

        $response->assertOk();
        $activity = $response->json('data.activities.0');
        $this->assertNotNull($activity, '본인 활동 기록이 사라졌다 (과잉 차단)');
        $this->assertSame('profile.update', $activity['action']);
        $this->assertArrayHasKey('ip_address', $activity, '응답 구조가 바뀌었다 — 키는 유지하고 값만 비워야 한다');
        $this->assertNull($activity['ip_address']);
        $this->assertStringNotContainsString('203.0.113.77', $response->getContent(), '본인 활동 기록 응답에 접속 IP 가 실렸다');
    }
}
