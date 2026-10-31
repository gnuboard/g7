<?php

namespace Tests\Feature\Api\Admin;

use App\Jobs\RunScheduledTaskJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Schedule\ScheduleHeartbeat;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ScheduleController — 즉시 실행(디스패처 경유)·상태 카드 API·소유 행 삭제 보호 Feature 테스트
 *
 * 즉시 실행은 예약 실행과 같은 디스패처를 수동 트리거로 부른다: 바로 실행은 200 + 이력(실패도 이력),
 * 큐로 보내기는 202, 실패만 기록하는 행의 성공은 200 + 이력 없음, 소유 확장 비활성은 422 다.
 */
class ScheduleControllerRunStatusTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private string $token;

    private string $marker;

    /**
     * 관리자·토큰·행 표식을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
        $this->marker = 'run'.Str::lower(Str::random(8));
    }

    /**
     * 스케줄 권한 전부를 가진 관리자를 만듭니다.
     *
     * @return User 관리자 사용자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach (['core.schedules.read', 'core.schedules.create', 'core.schedules.update', 'core.schedules.delete', 'core.schedules.run'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => 'core',
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $testRole = Role::create([
            'identifier' => 'schedule_run_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'is_active' => true,
        ]);
        $testRole->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now()]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now()]);

        return $user->fresh();
    }

    /**
     * 인증 헤더를 붙인 요청을 돌려줍니다.
     *
     * @return static 인증된 테스트 요청
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 표식이 붙은 스케줄 행을 만듭니다(중복 방지 잠금 없음).
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return Schedule 생성된 스케줄
     */
    private function makeSchedule(array $attributes = []): Schedule
    {
        return Schedule::create(array_merge([
            'name' => $this->marker,
            'label' => ['ko' => $this->marker, 'en' => $this->marker],
            'type' => 'artisan',
            'command' => 'inspire',
            'expression' => '0 0 * * *',
            'frequency' => 'daily',
            'without_overlapping' => 0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    // ── 즉시 실행 ───────────────────────────────────────────

    /**
     * 바로 실행 행은 200 + 성공 이력을 돌려준다.
     *
     * @scenario case=run_branches
     *
     * @effects schedule_manual_run_branches
     *
     * @return void 반환값 없음
     */
    public function test_run_sync_returns_history(): void
    {
        $schedule = $this->makeSchedule();

        $response = $this->authRequest()->postJson('/api/admin/schedules/'.$schedule->id.'/run')->assertOk();

        $this->assertSame('success', $response->json('data.status'));
        $this->assertSame('manual', $response->json('data.trigger_type'));
        $this->assertSame(1, $schedule->histories()->count());
    }

    /**
     * 바로 실행이 실패해도 예외가 아니라 실패 이력(200)으로 와서 창이 결과를 보여 준다.
     *
     * @scenario case=run_branches
     *
     * @effects schedule_manual_run_branches
     *
     * @return void 반환값 없음
     */
    public function test_run_sync_failure_returns_failed_history(): void
    {
        $schedule = $this->makeSchedule(['command' => 'definitely-not-a-command:'.$this->marker]);

        $response = $this->authRequest()->postJson('/api/admin/schedules/'.$schedule->id.'/run')->assertOk();

        $this->assertSame('failed', $response->json('data.status'));
        $this->assertSame('failed', $schedule->fresh()->last_result->value);
    }

    /**
     * 큐로 보내기 행은 202 로 접수만 알리고 Job 을 큐에 넣는다.
     *
     * @scenario case=run_branches
     *
     * @effects schedule_manual_run_branches
     *
     * @return void 반환값 없음
     */
    public function test_run_queue_mode_returns_202_and_pushes_job(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule(['dispatch_mode' => 'queue']);

        $response = $this->authRequest()->postJson('/api/admin/schedules/'.$schedule->id.'/run')->assertStatus(202);

        $this->assertTrue($response->json('data.queued'));
        Queue::assertPushed(RunScheduledTaskJob::class, fn (RunScheduledTaskJob $job) => $job->scheduleId === $schedule->id);
        $this->assertSame(0, $schedule->histories()->count());
    }

    /**
     * 실패만 기록하는 행이 성공하면 이력 없이 200 이다.
     *
     * @scenario case=run_branches
     *
     * @effects schedule_manual_run_branches
     *
     * @return void 반환값 없음
     */
    public function test_run_failures_only_success_records_nothing(): void
    {
        $schedule = $this->makeSchedule(['history_mode' => 'failures_only']);

        $response = $this->authRequest()->postJson('/api/admin/schedules/'.$schedule->id.'/run')->assertOk();

        $this->assertSame('success', $response->json('data.status'));
        $this->assertFalse($response->json('data.recorded'));
        $this->assertSame(0, $schedule->histories()->count());
    }

    /**
     * 소유 확장이 비활성(설치되지 않음 포함)이면 실행하지 않고 422 다.
     *
     * @scenario case=run_branches
     *
     * @effects schedule_manual_run_branches
     *
     * @return void 반환값 없음
     */
    public function test_run_rejects_inactive_owner_extension(): void
    {
        $schedule = $this->makeSchedule([
            'extension_type' => 'module',
            'extension_identifier' => 'vendor-missing-'.$this->marker,
        ]);

        $this->authRequest()->postJson('/api/admin/schedules/'.$schedule->id.'/run')->assertStatus(422);
        $this->assertSame(0, $schedule->histories()->count());
    }

    // ── 상태 카드 ───────────────────────────────────────────

    /**
     * 하트비트가 방금 찍혔으면 정상, 기록이 없으면 비정상(크론 안내 대상)이다. 큐 요약도 함께 온다.
     *
     * @scenario case=status_heartbeat
     *
     * @effects schedule_status_heartbeat_health
     *
     * @return void 반환값 없음
     */
    public function test_status_reports_heartbeat_and_queue_summary(): void
    {
        Cache::flush();

        $missing = $this->authRequest()->getJson('/api/admin/schedules/status')->assertOk();
        $this->assertFalse($missing->json('data.heartbeat.healthy'));
        $this->assertNull($missing->json('data.heartbeat.last_tick_at'));
        $this->assertNull($missing->json('data.heartbeat.minutes_ago'));
        $this->assertIsArray($missing->json('data.queue'));
        $this->assertArrayHasKey('driver', $missing->json('data.queue'));

        app(ScheduleHeartbeat::class)->beat();

        $fresh = $this->authRequest()->getJson('/api/admin/schedules/status')->assertOk();
        $this->assertTrue($fresh->json('data.heartbeat.healthy'));
        $this->assertSame(0, $fresh->json('data.heartbeat.minutes_ago'));
        $this->assertNotNull($fresh->json('data.heartbeat.last_tick_at'));
    }

    /**
     * 마지막 틱이 3분을 넘기면 비정상이다.
     *
     * @scenario case=status_heartbeat
     *
     * @effects schedule_status_heartbeat_health
     *
     * @return void 반환값 없음
     */
    public function test_status_stale_heartbeat_is_unhealthy(): void
    {
        $this->travelTo(now()->subMinutes(5));
        app(ScheduleHeartbeat::class)->beat();
        $this->travelBack();

        $response = $this->authRequest()->getJson('/api/admin/schedules/status')->assertOk();

        $this->assertFalse($response->json('data.heartbeat.healthy'));
        $this->assertGreaterThanOrEqual(4, $response->json('data.heartbeat.minutes_ago'));
    }

    // ── 소유 행 삭제 보호 ───────────────────────────────────

    /**
     * 코어·확장 소유 행은 단건·일괄 삭제 모두 422, 직접 등록 행은 지워진다.
     *
     * @scenario case=owned_delete
     *
     * @effects schedule_owned_rows_not_deletable
     *
     * @return void 반환값 없음
     */
    public function test_owned_rows_cannot_be_deleted(): void
    {
        $owned = $this->makeSchedule(['extension_type' => 'core', 'extension_identifier' => 'core']);
        $custom = $this->makeSchedule(['name' => $this->marker.'-custom']);

        $this->authRequest()->deleteJson('/api/admin/schedules/'.$owned->id)->assertStatus(422);
        $this->authRequest()
            ->deleteJson('/api/admin/schedules/bulk', ['ids' => [$owned->id, $custom->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids.0');
        $this->assertNotNull(Schedule::find($owned->id));
        $this->assertNotNull(Schedule::find($custom->id));

        $this->authRequest()->deleteJson('/api/admin/schedules/'.$custom->id)->assertOk();
        $this->assertNull(Schedule::find($custom->id));
    }
}
