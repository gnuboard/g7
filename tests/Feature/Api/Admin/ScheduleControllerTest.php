<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ScheduleResultStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
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

    /**
     * 관리자·라우트 시험 준비를 합니다.
     *
     * @return void 반환값 없음
     */
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
     *
     * @return User 스케줄 관리 권한을 가진 관리자
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
     *
     * @return static 인증 헤더를 단 요청
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
     *
     * @return Schedule 생성한 스케줄
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
    // 실행 이력 목록 — 관계 로드 (지연 조인 계약)
    // ────────────────────────────────────────────────────────

    /**
     * 실행 이력 목록이 실행자(triggered_by)를 함께 내려주는지 검증합니다.
     *
     * 지연 조인 트레이트는 inner 뿐 아니라 outer 에서도 `setEagerLoads([])` 로 eager load 를
     * 지운다. 따라서 저장소가 관계를 `$query->with()` 로 붙이고 `relations:` 인자를 넘기지
     * 않으면 관계가 조용히 사라지고, Resource 의 `whenLoaded('triggeredBy')` 가 항상 비어
     * `triggered_by` 키 자체가 응답에서 없어진다. 예외도 쿼리 오류도 나지 않으므로 이 단언이
     * 없으면 화면에서 실행자 칸이 빈 것으로만 드러난다.
     *
     * @scenario case=admin_history_list
     *
     * @effects schedule_admin_history_includes_trigger_user
     *
     * @return void 반환값 없음
     */
    public function test_history_list_includes_triggered_by_relation(): void
    {
        $schedule = $this->createSchedule();

        ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
            'duration' => 1,
            'status' => 'success',
            'exit_code' => 0,
            'trigger_type' => 'manual',
            'triggered_by' => $this->admin->id,
        ]);

        $response = $this->authRequest()
            ->getJson("/api/admin/schedules/{$schedule->id}/history");

        $response->assertStatus(200);

        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertNotEmpty($rows, '실행 이력이 최소 1건 조회되어야 한다');

        $this->assertArrayHasKey(
            'triggered_by',
            $rows[0],
            'triggered_by 키가 없다 — 저장소가 relations: 로 관계를 넘기지 않아 eager load 가 지워졌을 수 있다'
        );
        $this->assertSame($this->admin->name, $rows[0]['triggered_by']['name']);
        $this->assertSame($this->admin->uuid, $rows[0]['triggered_by']['uuid']);
    }

    // ────────────────────────────────────────────────────────
    // Bulk Update Status (BulkUpdateScheduleStatusRequest)
    // ────────────────────────────────────────────────────────

    /**
     * 일괄 상태 변경 성공 테스트
     * (Rule::exists(Schedule::class, 'id') 검증)
     *
     * @scenario case=admin_bulk_status
     *
     * @effects schedule_bulk_status_change
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=admin_bulk_status
     *
     * @effects schedule_bulk_status_change
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=admin_bulk_status
     *
     * @effects schedule_bulk_status_change
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=admin_bulk_status
     *
     * @effects schedule_bulk_status_change
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=admin_bulk_status
     *
     * @effects schedule_bulk_status_change
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=admin_bulk_delete
     *
     * @effects schedule_bulk_delete
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=admin_bulk_delete
     *
     * @effects schedule_bulk_delete
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=admin_bulk_delete
     *
     * @effects schedule_bulk_delete
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=admin_unauthenticated
     *
     * @effects schedule_bulk_requires_auth
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=admin_unauthenticated
     *
     * @effects schedule_bulk_requires_auth
     *
     * @return void 반환값 없음
     */
    public function test_unauthenticated_bulk_delete_gets_401(): void
    {
        $response = $this->deleteJson('/api/admin/schedules/bulk', [
            'ids' => [1],
        ]);

        $response->assertStatus(401);
    }

    // ────────────────────────────────────────────────────────
    // 지금 실행 — 「중복 실행 방지」 실행 잠금 (#161 F4)
    // ────────────────────────────────────────────────────────

    /**
     * 「지금 실행」 권한을 가진 관리자로 인증한 요청을 준비합니다.
     *
     * @return static 인증 헤더가 붙은 테스트 인스턴스
     */
    private function runnerRequest(): static
    {
        $runner = $this->createAdminUser(['core.schedules.read', 'core.schedules.run']);

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$runner->createToken('runner-token')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 외부 프로세스를 돌리지 않는 「중복 실행 방지」 Shell 스케줄을 만듭니다 — 실행은 Process 가짜가 받는다.
     *
     * @return Schedule 생성된 스케줄
     */
    private function createOverlapGuardedSchedule(): Schedule
    {
        config([
            'schedule_security.shell.enabled' => true,
            'schedule_security.shell.allowed_binaries' => ['i161-task'],
        ]);
        Process::fake();

        return $this->createSchedule([
            'type' => 'shell',
            'command' => 'i161-task',
            'without_overlapping' => true,
            'timeout' => 60,
        ]);
    }

    /**
     * 같은 스케줄이 실행 중이면 「지금 실행」은 409 와 번역 문구로 거절되고, 이력·last_result 를 남기지 않는다.
     *
     * @scenario case=run_rejected_while_running
     *
     * @effects schedule_run_rejected_while_same_schedule_runs
     *
     * @return void 반환값 없음
     */
    public function test_run_returns_409_while_same_schedule_running(): void
    {
        $schedule = $this->createOverlapGuardedSchedule();
        $this->assertTrue(Cache::lock('schedule-run:'.$schedule->id, 3600, 'other-runner')->get());

        $this->runnerRequest()->postJson("/api/admin/schedules/{$schedule->id}/run")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('schedule.already_running'));

        $this->assertSame(0, ScheduleHistory::where('schedule_id', $schedule->id)->count());
        $this->assertSame(ScheduleResultStatus::Never, $schedule->fresh()->last_result, 'last_result 를 Running 으로 바꾸지 않는다');
        Process::assertNothingRan();

        // 잠금이 풀리면 다시 실행할 수 있다
        Cache::restoreLock('schedule-run:'.$schedule->id, 'other-runner')->release();
        $this->runnerRequest()->postJson("/api/admin/schedules/{$schedule->id}/run")
            ->assertStatus(200);
        $this->assertSame(1, ScheduleHistory::where('schedule_id', $schedule->id)->count());
    }

    /**
     * 실행 잠금 저장소(캐시)가 오류를 내면 실행하지 않고 503 과 번역 문구로 답한다 — 잠금 없이 실행하면 중복 실행 방지를 보장할 수
     * 없다. 원본 저장소 오류 문구는 응답에 싣지 않고, 이력·last_result 를 남기지 않는다.
     *
     * @scenario case=run_lock_store_error
     *
     * @effects schedule_run_refused_when_lock_store_errors
     *
     * @return void 반환값 없음
     */
    public function test_run_returns_503_when_lock_store_errors(): void
    {
        $schedule = $this->createOverlapGuardedSchedule();

        $default = config('cache.default');
        config(['cache.stores.i161_broken_run_lock' => ['driver' => 'i161_broken_run_lock']]);
        Cache::extend('i161_broken_run_lock', fn () => Cache::repository(new class extends ArrayStore
        {
            /**
             * 스케줄 실행 잠금만 저장소 오류가 나는 잠금으로 돌려준다(다른 잠금은 배열 잠금 그대로).
             *
             * @param  string  $name  잠금 이름
             * @param  int  $seconds  잠금 유지 시간(초)
             * @param  string|null  $owner  소유자
             * @return \Illuminate\Contracts\Cache\Lock 잠금
             */
            public function lock($name, $seconds = 0, $owner = null)
            {
                if (! str_starts_with($name, 'schedule-run:')) {
                    return parent::lock($name, $seconds, $owner);
                }

                return new class($name, $seconds, $owner) extends Lock
                {
                    /**
                     * 잠금 저장소에 닿지 못한다.
                     *
                     * @return bool 반환하지 않음
                     *
                     * @throws \RuntimeException 언제나
                     */
                    public function acquire()
                    {
                        throw new \RuntimeException('i161 lock store down C:\\fake\\g7');
                    }

                    /**
                     * 잠금을 푼다.
                     *
                     * @return bool 언제나 true
                     */
                    public function release()
                    {
                        return true;
                    }

                    /**
                     * 소유자와 무관하게 잠금을 푼다.
                     *
                     * @return void 반환값 없음
                     */
                    public function forceRelease() {}

                    /**
                     * 현재 소유자를 돌려준다.
                     *
                     * @return string|null 소유자 없음
                     */
                    protected function getCurrentOwner()
                    {
                        return null;
                    }
                };
            }
        }));
        config(['cache.default' => 'i161_broken_run_lock']);

        try {
            $response = $this->runnerRequest()->postJson("/api/admin/schedules/{$schedule->id}/run");
        } finally {
            config(['cache.default' => $default]);
            Cache::forgetDriver('i161_broken_run_lock');
        }

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('schedule.run_lock_unavailable'));
        $this->assertStringNotContainsString('i161 lock store down', $response->getContent());
        $this->assertSame(0, ScheduleHistory::where('schedule_id', $schedule->id)->count());
        $this->assertSame(ScheduleResultStatus::Never, $schedule->fresh()->last_result, 'last_result 를 Running 으로 바꾸지 않는다');
        Process::assertNothingRan();
    }
}
