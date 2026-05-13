<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ScheduleController 일괄 작업 Feature 테스트
 *
 * 스케줄 일괄 상태 변경 및 일괄 삭제 API 엔드포인트 테스트
 * Issue #74: exists 검증 규칙이 Rule::exists(Schedule::class, 'id')로 올바르게 동작하는지 검증
 */
class ScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 사용자 생성 헬퍼
     *
     * @param  array  $permissions  권한 식별자 목록
     */
    private function createAdminUser(array $permissions = [
        'core.schedules.read',
        'core.schedules.create',
        'core.schedules.update',
        'core.schedules.delete',
    ]): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'extension_type' => 'core',
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $roleIdentifier = 'admin_test_'.uniqid();
        $testRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'is_active' => true,
        ]);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $testRole->permissions()->sync($permissionIds);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now()]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now()]);

        return $user->fresh();
    }

    /**
     * 인증된 요청 헬퍼
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 테스트용 스케줄 생성 헬퍼
     *
     * @param  array  $attributes  오버라이드할 속성
     */
    private function createSchedule(array $attributes = []): Schedule
    {
        return Schedule::create(array_merge([
            'name' => '테스트 스케줄',
            'description' => '테스트용 스케줄입니다',
            'type' => 'artisan',
            'command' => 'inspire',
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    // ────────────────────────────────────────────────────────
    // Bulk Update Status (BulkUpdateScheduleStatusRequest)
    // ────────────────────────────────────────────────────────

    /**
     * 일괄 상태 변경 성공 테스트
     * (Rule::exists(Schedule::class, 'id') 검증)
     */
    public function test_bulk_update_status_activates_schedules(): void
    {
        $schedule1 = $this->createSchedule(['name' => '스케줄 1', 'is_active' => false]);
        $schedule2 = $this->createSchedule(['name' => '스케줄 2', 'is_active' => false]);

        $response = $this->authRequest()
            ->patchJson('/api/admin/schedules/bulk-status', [
                'ids' => [$schedule1->id, $schedule2->id],
                'is_active' => true,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('schedules', ['id' => $schedule1->id, 'is_active' => true]);
        $this->assertDatabaseHas('schedules', ['id' => $schedule2->id, 'is_active' => true]);
    }

    /**
     * 일괄 상태 변경 - 비활성화 테스트
     */
    public function test_bulk_update_status_deactivates_schedules(): void
    {
        $schedule1 = $this->createSchedule(['name' => '스케줄 1', 'is_active' => true]);
        $schedule2 = $this->createSchedule(['name' => '스케줄 2', 'is_active' => true]);

        $response = $this->authRequest()
            ->patchJson('/api/admin/schedules/bulk-status', [
                'ids' => [$schedule1->id, $schedule2->id],
                'is_active' => false,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('schedules', ['id' => $schedule1->id, 'is_active' => false]);
        $this->assertDatabaseHas('schedules', ['id' => $schedule2->id, 'is_active' => false]);
    }

    /**
     * 존재하지 않는 ID로 일괄 상태 변경 시 검증 실패 테스트
     */
    public function test_bulk_update_status_fails_with_nonexistent_ids(): void
    {
        $response = $this->authRequest()
            ->patchJson('/api/admin/schedules/bulk-status', [
                'ids' => [99999, 99998],
                'is_active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids.0', 'ids.1']);
    }

    /**
     * 빈 배열로 일괄 상태 변경 시 검증 실패 테스트
     */
    public function test_bulk_update_status_fails_with_empty_ids(): void
    {
        $response = $this->authRequest()
            ->patchJson('/api/admin/schedules/bulk-status', [
                'ids' => [],
                'is_active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids']);
    }

    /**
     * is_active 필드 누락 시 검증 실패 테스트
     */
    public function test_bulk_update_status_fails_without_is_active(): void
    {
        $schedule = $this->createSchedule();

        $response = $this->authRequest()
            ->patchJson('/api/admin/schedules/bulk-status', [
                'ids' => [$schedule->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['is_active']);
    }

    // ────────────────────────────────────────────────────────
    // Bulk Delete (BulkDeleteScheduleRequest)
    // ────────────────────────────────────────────────────────

    /**
     * 일괄 삭제 성공 테스트
     * (Rule::exists(Schedule::class, 'id') 검증)
     */
    public function test_bulk_delete_removes_schedules_successfully(): void
    {
        $schedule1 = $this->createSchedule(['name' => '삭제 대상 1']);
        $schedule2 = $this->createSchedule(['name' => '삭제 대상 2']);

        $response = $this->authRequest()
            ->deleteJson('/api/admin/schedules/bulk', [
                'ids' => [$schedule1->id, $schedule2->id],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('schedules', ['id' => $schedule1->id]);
        $this->assertDatabaseMissing('schedules', ['id' => $schedule2->id]);
    }

    /**
     * 존재하지 않는 ID로 일괄 삭제 시 검증 실패 테스트
     */
    public function test_bulk_delete_fails_with_nonexistent_ids(): void
    {
        $response = $this->authRequest()
            ->deleteJson('/api/admin/schedules/bulk', [
                'ids' => [99999],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids.0']);
    }

    /**
     * 빈 배열로 일괄 삭제 시 검증 실패 테스트
     */
    public function test_bulk_delete_fails_with_empty_ids(): void
    {
        $response = $this->authRequest()
            ->deleteJson('/api/admin/schedules/bulk', [
                'ids' => [],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids']);
    }

    // ────────────────────────────────────────────────────────
    // 인증 테스트
    // ────────────────────────────────────────────────────────

    /**
     * 미인증 사용자 일괄 상태 변경 거부 테스트
     */
    public function test_unauthenticated_bulk_update_status_gets_401(): void
    {
        $response = $this->patchJson('/api/admin/schedules/bulk-status', [
            'ids' => [1],
            'is_active' => true,
        ]);

        $response->assertStatus(401);
    }

    /**
     * 미인증 사용자 일괄 삭제 거부 테스트
     */
    public function test_unauthenticated_bulk_delete_gets_401(): void
    {
        $response = $this->deleteJson('/api/admin/schedules/bulk', [
            'ids' => [1],
        ]);

        $response->assertStatus(401);
    }
}
