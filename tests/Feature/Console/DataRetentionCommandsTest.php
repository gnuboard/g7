<?php

namespace Tests\Feature\Console;

use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\ActivityLog;
use App\Models\IdentityVerificationLog;
use App\Models\NotificationLog;
use App\Models\Schedule as ScheduleModel;
use App\Models\ScheduleHistory;
use App\Models\SeoCacheStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 만료/누적 데이터 정리 커맨드 테스트 (공개이슈 #110 전수 해소분)
 *
 * 각 커맨드가 보존 기간을 넘긴 행만 지우고 기간 이내 행은 보존하는지 고정합니다.
 *
 * @scenario row_age=within_retention, row_age=beyond_retention
 *
 * @effects rows_beyond_retention_are_deleted, rows_within_retention_are_kept, identity_challenges_transition_without_deletion, automated_prune_emits_its_own_hooks
 */
class DataRetentionCommandsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * B2: seo:prune-stats — 보존 기간 초과 통계만 삭제.
     */
    public function test_seo_prune_stats_removes_only_expired_rows(): void
    {
        $old = SeoCacheStat::create(['url' => '/old', 'locale' => 'ko', 'type' => 'hit']);
        $old->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();

        $recent = SeoCacheStat::create(['url' => '/recent', 'locale' => 'ko', 'type' => 'hit']);
        $recent->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('seo:prune-stats', ['--days' => 30])->assertSuccessful();

        $this->assertDatabaseMissing('seo_cache_stats', ['id' => $old->id]);
        $this->assertDatabaseHas('seo_cache_stats', ['id' => $recent->id]);
    }

    /**
     * B3: schedules:prune-history — 보존 기간 초과 이력만 삭제.
     */
    public function test_schedule_history_prune_removes_only_expired_rows(): void
    {
        $schedule = ScheduleModel::create([
            'name' => '테스트 스케줄',
            'type' => 'artisan',
            'command' => 'inspire',
            'expression' => '0 4 * * *',
            'frequency' => 'daily',
            'is_active' => true,
        ]);

        $old = ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now()->subDays(120),
            'status' => 'success',
        ]);
        $recent = ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now()->subDays(10),
            'status' => 'success',
        ]);

        $this->artisan('schedules:prune-history', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseMissing('schedule_histories', ['id' => $old->id]);
        $this->assertDatabaseHas('schedule_histories', ['id' => $recent->id]);
    }

    /**
     * B4: identity:expire-challenges — 만료 challenge 만 상태 전환(비파괴).
     */
    public function test_identity_expire_challenges_transitions_only_past_due(): void
    {
        $pastDue = $this->createIdentityLog(IdentityVerificationStatus::Requested->value, now()->subMinutes(10));
        $future = $this->createIdentityLog(IdentityVerificationStatus::Requested->value, now()->addMinutes(10));

        $this->artisan('identity:expire-challenges')->assertSuccessful();

        $this->assertSame(
            IdentityVerificationStatus::Expired->value,
            IdentityVerificationLog::find($pastDue->id)->status->value
        );
        $this->assertSame(
            IdentityVerificationStatus::Requested->value,
            IdentityVerificationLog::find($future->id)->status->value
        );

        // 비파괴 — 행 자체는 남는다.
        $this->assertDatabaseHas('identity_verification_logs', ['id' => $pastDue->id]);
    }

    /**
     * B6: identity:prune-logs — 보관 기간 초과 이력만 파기.
     */
    public function test_identity_prune_logs_removes_only_expired_rows(): void
    {
        $old = $this->createIdentityLog(IdentityVerificationStatus::Verified->value, now()->subDays(200));
        $old->forceFill(['created_at' => now()->subDays(200)])->saveQuietly();

        $recent = $this->createIdentityLog(IdentityVerificationStatus::Verified->value, now()->subDays(10));
        $recent->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        $this->artisan('identity:prune-logs', ['--days' => 180])->assertSuccessful();

        $this->assertDatabaseMissing('identity_verification_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('identity_verification_logs', ['id' => $recent->id]);
    }

    /**
     * B7: activity-log:prune — 보존 기간 초과 로그만 삭제.
     */
    public function test_activity_log_prune_removes_only_expired_rows(): void
    {
        $old = ActivityLog::create([
            'log_type' => 'system',
            'action' => 'test.old',
            'created_at' => now()->subDays(400),
        ]);
        $recent = ActivityLog::create([
            'log_type' => 'system',
            'action' => 'test.recent',
            'created_at' => now()->subDays(10),
        ]);

        $this->artisan('activity-log:prune', ['--days' => 365])->assertSuccessful();

        $this->assertDatabaseMissing('activity_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('activity_logs', ['id' => $recent->id]);
    }

    /**
     * B8: notification-log:prune — 보존 기간 초과 발송 이력만 삭제.
     */
    public function test_notification_log_prune_removes_only_expired_rows(): void
    {
        $old = NotificationLog::create([
            'channel' => 'mail',
            'notification_type' => 'test',
            'recipient_identifier' => 'a@example.com',
            'status' => 'sent',
        ]);
        $old->forceFill(['created_at' => now()->subDays(120)])->saveQuietly();

        $recent = NotificationLog::create([
            'channel' => 'mail',
            'notification_type' => 'test',
            'recipient_identifier' => 'b@example.com',
            'status' => 'sent',
        ]);
        $recent->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        $this->artisan('notification-log:prune', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseMissing('notification_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('notification_logs', ['id' => $recent->id]);
    }

    /**
     * 자동 파기는 운영자 일괄 삭제와 별개의 훅을 발행한다.
     *
     * 운영자 삭제 훅(`*.before_delete_many` 등)에는 본인인증 같은 대화형 가드가 물려
     * 있어 무인 예약이 탈 수 없다. 그렇다고 훅 없이 지우면 확장은 가장 큰 삭제 경로를
     * 볼 방법이 사라진다 — 전용 훅이 그 자리를 대신한다.
     *
     * @effects automated_prune_emits_its_own_hooks
     */
    public function test_automated_prune_emits_its_own_hooks(): void
    {
        $fired = [];

        foreach ([
            'core.activity_log.before_prune',
            'core.activity_log.after_prune',
            'core.notification_log.before_prune',
            'core.notification_log.after_prune',
            'core.schedule.before_prune_history',
            'core.schedule.after_prune_history',
        ] as $hook) {
            HookManager::addAction($hook, function () use (&$fired, $hook) {
                $fired[] = $hook;
            });
        }

        $this->artisan('activity-log:prune', ['--days' => 365])->assertSuccessful();
        $this->artisan('notification-log:prune', ['--days' => 90])->assertSuccessful();
        $this->artisan('schedules:prune-history', ['--days' => 90])->assertSuccessful();

        $this->assertSame([
            'core.activity_log.before_prune',
            'core.activity_log.after_prune',
            'core.notification_log.before_prune',
            'core.notification_log.after_prune',
            'core.schedule.before_prune_history',
            'core.schedule.after_prune_history',
        ], $fired, '자동 파기 커맨드가 도메인 계층을 건너뛰면 이 훅들이 발행되지 않습니다.');
    }

    /**
     * 보존 기간 하한은 도메인 계층이 소유한다 — `--days=0` 이어도 오늘 데이터는 남는다.
     *
     * 커맨드가 클램프를 들고 있으면 다른 호출자가 생길 때 그 보호가 따라오지 않는다.
     *
     * @effects retention_floor_is_owned_by_the_domain_layer
     */
    public function test_retention_floor_is_enforced_below_the_command(): void
    {
        $today = ActivityLog::create([
            'log_type' => 'system',
            'action' => 'test.today',
            'description' => '오늘 기록',
        ]);

        $this->artisan('activity-log:prune', ['--days' => 0])->assertSuccessful();

        $this->assertDatabaseHas('activity_logs', ['id' => $today->id]);
    }

    /**
     * 본인인증 로그 생성 헬퍼
     *
     * @param  string  $status  상태값
     * @param  Carbon  $expiresAt  만료 시각
     * @return IdentityVerificationLog 생성된 로그
     */
    private function createIdentityLog(string $status, $expiresAt): IdentityVerificationLog
    {
        return IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'test-provider',
            'purpose' => 'signup',
            'channel' => 'sms',
            'target_hash' => hash('sha256', Str::random()),
            'status' => $status,
            'expires_at' => $expiresAt,
        ]);
    }
}
