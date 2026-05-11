<?php

namespace Tests\Feature\Seeders;

use App\Enums\NotificationLogStatus;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\NotificationDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\Sample\NotificationLogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 코어 NotificationLogSeeder 통합 테스트.
 *
 * 코어 정의(extension_type='core')만 채워지는지 + 운영 분포 검증.
 */
class NotificationLogSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 샘플 시더 의존성(역할/관리자/일반 사용자/알림 정의)을 부트스트랩한다.
     */
    private function bootstrapDependencies(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationDefinitionSeeder::class);

        $adminRole = Role::query()->where('identifier', 'admin')->firstOrFail();
        $admin = User::factory()->create(['email' => 'admin@test.local', 'name' => 'Admin']);
        $admin->roles()->attach($adminRole->id, ['assigned_at' => now()]);

        User::factory()->count(15)->create();
    }

    public function test_seeder_creates_default_count(): void
    {
        $this->bootstrapDependencies();

        $this->seed(NotificationLogSeeder::class);

        $this->assertSame(100, NotificationLog::count(), '코어 시더 기본 100건이 생성되어야 합니다');
    }

    public function test_seeder_respects_count_option(): void
    {
        $this->bootstrapDependencies();

        $this->artisan('db:seed', [
            '--class' => NotificationLogSeeder::class,
            '--count' => ['core_notification_logs=25'],
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(25, NotificationLog::count());
    }

    public function test_seeder_only_seeds_core_scope(): void
    {
        $this->bootstrapDependencies();

        $this->seed(NotificationLogSeeder::class);

        $coreCount = NotificationLog::query()->where('extension_type', 'core')->count();
        $moduleCount = NotificationLog::query()->where('extension_type', 'module')->count();

        $this->assertSame(100, $coreCount, '코어 영역만 채워야 합니다');
        $this->assertSame(0, $moduleCount, '모듈 영역은 코어 시더가 채우지 않아야 합니다');
    }

    public function test_seeder_uses_real_users_and_core_definitions(): void
    {
        $this->bootstrapDependencies();

        $this->seed(NotificationLogSeeder::class);

        $userIds = User::query()->pluck('id')->all();
        $coreDefinitionTypes = NotificationDefinition::query()
            ->where('is_active', true)
            ->where('extension_type', 'core')
            ->pluck('type')
            ->all();

        $logs = NotificationLog::query()->get();

        $this->assertNotEmpty($logs);
        foreach ($logs as $log) {
            $this->assertContains($log->recipient_user_id, $userIds);
            $this->assertContains($log->notification_type, $coreDefinitionTypes);
        }
    }

    public function test_seeder_distributes_status_realistically(): void
    {
        $this->bootstrapDependencies();

        $this->seed(NotificationLogSeeder::class);

        $statuses = NotificationLog::query()->pluck('status')->countBy();

        $this->assertGreaterThan(
            $statuses[NotificationLogStatus::Failed->value] ?? 0,
            $statuses[NotificationLogStatus::Sent->value] ?? 0,
        );
    }

    public function test_failed_logs_have_error_message(): void
    {
        $this->bootstrapDependencies();

        $this->seed(NotificationLogSeeder::class);

        foreach (NotificationLog::query()->where('status', NotificationLogStatus::Failed->value)->get() as $log) {
            $this->assertNotEmpty($log->error_message);
        }
    }

    public function test_mail_channel_uses_email_and_database_uses_user_id(): void
    {
        $this->bootstrapDependencies();

        $this->seed(NotificationLogSeeder::class);

        foreach (NotificationLog::query()->where('channel', 'mail')->get() as $log) {
            $this->assertStringContainsString('@', $log->recipient_identifier);
        }

        foreach (NotificationLog::query()->where('channel', 'database')->get() as $log) {
            $this->assertTrue(ctype_digit((string) $log->recipient_identifier));
        }
    }
}
