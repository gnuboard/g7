<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 스케줄 실행 이력 목록 — 회차별 출력 회귀 테스트
 *
 * 실행 이력 창은 회차마다 정상 출력과 오류 출력을 펼쳐 보이는데(실패 원인을 여기서 가른다), 목록 응답이
 * `output`/`error_output` 을 빼고 있어 창에서 출력이 한 번도 보이지 않았다(7.1.0).
 * 저장소는 지연 조인으로 넓은 칸까지 이번 쪽 분량만 읽으므로 응답에서도 그대로 싣는다.
 *
 * 시나리오 SSoT: tests/scenarios/schedule-history-modal.yaml
 */
#[Group('core')]
class ScheduleHistoryOutputTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 스케줄 조회 권한을 가진 관리자의 토큰 헤더로 실패 회차의 출력이 목록에 실린다.
     *
     * @scenario surface=admin_schedule_history_modal
     *
     * @effects schedule_history_modal_shows_runs_and_output
     *
     * @return void 반환값 없음
     */
    public function test_history_list_includes_output_and_error_output(): void
    {
        $admin = $this->makeScheduleReader();
        $schedule = Schedule::create([
            'name' => '출력 확인 스케줄',
            'description' => '출력 확인',
            'type' => 'artisan',
            'command' => 'inspire',
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
            'is_active' => false,
            'created_by' => $admin->id,
        ]);
        ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
            'duration' => 1,
            'status' => 'failed',
            'exit_code' => 1,
            'output' => 'stdout line',
            'error_output' => 'stderr line',
            'trigger_type' => 'manual',
            'triggered_by' => $admin->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken,
            'Accept' => 'application/json',
        ])->getJson("/api/admin/schedules/{$schedule->id}/history");

        $response->assertStatus(200);
        $rows = $response->json('data.data');
        $this->assertNotEmpty($rows);
        $this->assertSame('stdout line', $rows[0]['output'] ?? null);
        $this->assertSame('stderr line', $rows[0]['error_output'] ?? null);
        $this->assertSame($admin->name, $rows[0]['triggered_by']['name'] ?? null);
    }

    /**
     * 큐로 보낸 수동 실행의 거절 행은 상태 「거절됨」과 사유(코드, 읽는 사람 언어의 문구)를 싣고, 상태로 걸러 볼 수 있다.
     * 스케줄 목록의 「최근 결과」 조건은 거절을 받지 않는다(거절은 실행 결과가 아니다).
     *
     * @scenario surface=admin_schedule_history_modal
     *
     * @effects schedule_history_modal_shows_rejected_run_with_reason
     *
     * @return void 반환값 없음
     */
    public function test_history_list_shows_rejected_row_with_reason(): void
    {
        $admin = $this->makeScheduleReader();
        $schedule = Schedule::create([
            'name' => '거절 확인 스케줄',
            'description' => '거절 확인',
            'type' => 'artisan',
            'command' => 'inspire',
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
            'is_active' => false,
            'created_by' => $admin->id,
        ]);
        ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now()->subMinutes(2),
            'ended_at' => now()->subMinute(),
            'duration' => 1,
            'status' => 'success',
            'trigger_type' => 'scheduled',
        ]);
        ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now(),
            'ended_at' => now(),
            'status' => 'rejected',
            'reject_reason' => 'already_running',
            'trigger_type' => 'manual',
            'triggered_by' => $admin->id,
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken,
            'Accept' => 'application/json',
            'Accept-Language' => 'ko',
        ];

        $all = $this->withHeaders($headers)->getJson("/api/admin/schedules/{$schedule->id}/history");
        $all->assertStatus(200);
        $byStatus = collect($all->json('data.data'))->keyBy('status');
        $this->assertSame('already_running', $byStatus['rejected']['reject_reason'] ?? null);
        $this->assertSame(__('schedule.result.rejected', [], 'ko'), $byStatus['rejected']['status_label'] ?? null);
        $this->assertSame(__('schedule.reject_reason.already_running', [], 'ko'), $byStatus['rejected']['reject_reason_label'] ?? null);
        $this->assertSame($admin->name, $byStatus['rejected']['triggered_by']['name'] ?? null);
        $this->assertNull($byStatus['success']['reject_reason']);
        $this->assertNull($byStatus['success']['reject_reason_label']);

        $filtered = $this->withHeaders($headers)->getJson("/api/admin/schedules/{$schedule->id}/history?status=rejected");
        $filtered->assertStatus(200);
        $this->assertSame(['rejected'], array_column($filtered->json('data.data'), 'status'));

        $this->withHeaders($headers)->getJson('/api/admin/schedules?last_result=rejected')->assertStatus(422);
    }

    /**
     * 스케줄 조회 권한만 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function makeScheduleReader(): User
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.schedules.read'],
            [
                'name' => json_encode(['ko' => 'core.schedules.read', 'en' => 'core.schedules.read']),
                'description' => json_encode(['ko' => 'core.schedules.read', 'en' => 'core.schedules.read']),
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );
        $role = Role::create([
            'identifier' => 'schedule_reader_'.uniqid(),
            'name' => json_encode(['ko' => '스케줄 조회', 'en' => 'Schedule reader']),
            'is_active' => true,
        ]);
        $role->permissions()->sync([$permission->id]);
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
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user->fresh();
    }
}
